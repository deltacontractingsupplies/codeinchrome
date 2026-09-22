package sites

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/go-sql-driver/mysql"
)

// The database browser.
//
// Every query runs as the SITE'S OWN MySQL user, connecting from 127.0.0.1.
// So the browser grants nothing: whatever it can do, the site's own code could
// already do with the credentials in its .env. Isolation between tenants is
// MySQL's privilege system, proved by infra/probe-db.php, and not something
// this file has to get right.
//
// What this file does add is protection against accidents:
//
//   - A statement is a READ only if it starts with SELECT, SHOW, DESCRIBE,
//     DESC, EXPLAIN or WITH. Reads run inside START TRANSACTION READ ONLY, so
//     MySQL itself refuses a write that slipped past the keyword check (for
//     instance `WITH x AS (...) DELETE ...`). The keyword check decides the
//     mode; the server enforces it.
//   - Anything else is a WRITE and is refused unless the caller passes
//     write=true. An agent exploring a schema should not be one careless call
//     away from DROP TABLE.
//   - One statement per call. Multi-statement mode stays off in the driver,
//     so "SELECT 1; DROP TABLE users" is an error, not two statements.
//   - Answers are bounded: 500 rows, 64 KB per cell, a 10 second execution
//     limit on reads, 30 seconds overall.

const (
	maxRows        = 500
	maxCellBytes   = 64 << 10
	readTimeoutMs  = 10_000
	overallTimeout = 30 * time.Second
	maxSQLBytes    = 100 << 10
)

// ErrNeedsWrite means the statement may change data and write was not set.
var ErrNeedsWrite = errors.New("needs write")

type QueryResult struct {
	Columns      []string `json:"columns"`
	Rows         [][]any  `json:"rows"`
	Truncated    bool     `json:"truncated"`
	RowsAffected int64    `json:"rowsAffected"`
	ElapsedMs    int64    `json:"elapsedMs"`
	Mode         string   `json:"mode"` // "read" or "write"
}

type TableInfo struct {
	Name      string `json:"name"`
	RowsEst   int64  `json:"rowsEstimate"`
	DataBytes int64  `json:"dataBytes"`
	Engine    string `json:"engine"`
}

var leadingComments = regexp.MustCompile(`^(\s+|--[^\n]*\n?|#[^\n]*\n?|/\*.*?\*/)+`)
var readKeyword = regexp.MustCompile(`(?i)^(select|show|describe|desc|explain|with)\b`)

// IsRead reports whether a statement is classified as a read. Deliberately
// conservative: anything it does not recognise is a write.
func IsRead(stmt string) bool {
	s := leadingComments.ReplaceAllString(stmt, "")
	// A leading ( is a parenthesised SELECT.
	s = strings.TrimLeft(s, "( \t\r\n")
	return readKeyword.MatchString(s)
}

func (m *Manager) siteDB(id string) (*sql.DB, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	password, err := os.ReadFile(filepath.Join(m.dir(id), "db.secret"))
	if err != nil {
		return nil, fmt.Errorf("site %q has no database", id)
	}
	cfg := mysql.NewConfig()
	cfg.User = DBUser(id)
	cfg.Passwd = strings.TrimSpace(string(password))
	cfg.Net = "tcp"
	cfg.Addr = agentHost + ":3306"
	cfg.DBName = DBName(id)
	cfg.Timeout = 5 * time.Second
	cfg.ReadTimeout = overallTimeout
	cfg.MultiStatements = false // explicit: one statement per call
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1) // the agent's account is capped at 3; never hold more than one
	return db, nil
}

func (m *Manager) Tables(ctx context.Context, id string) ([]TableInfo, error) {
	db, err := m.siteDB(id)
	if err != nil {
		return nil, err
	}
	defer db.Close()

	rows, err := db.QueryContext(ctx, `SELECT table_name, COALESCE(table_rows,0), COALESCE(data_length,0), COALESCE(engine,'')
		FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name`)
	if err != nil {
		return nil, fmt.Errorf("cannot list tables: %w", err)
	}
	defer rows.Close()

	out := []TableInfo{}
	for rows.Next() {
		var t TableInfo
		if err := rows.Scan(&t.Name, &t.RowsEst, &t.DataBytes, &t.Engine); err != nil {
			return nil, err
		}
		out = append(out, t)
	}
	return out, rows.Err()
}

func (m *Manager) Query(ctx context.Context, id, stmt string, write bool) (QueryResult, error) {
	if strings.TrimSpace(stmt) == "" {
		return QueryResult{}, fmt.Errorf("empty statement")
	}
	if len(stmt) > maxSQLBytes {
		return QueryResult{}, fmt.Errorf("statement is %d bytes; the limit is %d", len(stmt), maxSQLBytes)
	}
	read := IsRead(stmt)
	if !read && !write {
		return QueryResult{}, ErrNeedsWrite
	}

	db, err := m.siteDB(id)
	if err != nil {
		return QueryResult{}, err
	}
	defer db.Close()

	ctx, cancel := context.WithTimeout(ctx, overallTimeout)
	defer cancel()

	// One pinned connection, so the session settings and the transaction
	// apply to the statement that follows them.
	conn, err := db.Conn(ctx)
	if err != nil {
		return QueryResult{}, fmt.Errorf("cannot connect as the site: %w", err)
	}
	defer conn.Close()

	res := QueryResult{Mode: "write", Columns: []string{}, Rows: [][]any{}}
	started := time.Now()

	if read {
		res.Mode = "read"
		if _, err := conn.ExecContext(ctx, fmt.Sprintf("SET SESSION MAX_EXECUTION_TIME=%d", readTimeoutMs)); err != nil {
			return res, err
		}
		if _, err := conn.ExecContext(ctx, "START TRANSACTION READ ONLY"); err != nil {
			return res, err
		}
		defer conn.ExecContext(context.Background(), "ROLLBACK") //nolint:errcheck
	}

	rows, err := conn.QueryContext(ctx, stmt)
	if err != nil {
		return res, cleanMySQLError(err)
	}
	defer rows.Close()

	cols, err := rows.Columns()
	if err != nil {
		return res, err
	}
	res.Columns = cols

	for rows.Next() {
		if len(res.Rows) >= maxRows {
			res.Truncated = true
			break
		}
		raw := make([]sql.RawBytes, len(cols))
		ptrs := make([]any, len(cols))
		for i := range raw {
			ptrs[i] = &raw[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return res, err
		}
		row := make([]any, len(cols))
		for i, b := range raw {
			switch {
			case b == nil:
				row[i] = nil
			case !utf8.Valid(b):
				// Never sent as a string: JSON would replace the bytes with
				// U+FFFD and the value shown would not be the value stored.
				row[i] = fmt.Sprintf("(binary, %d bytes)", len(b))
			case len(b) > maxCellBytes:
				row[i] = string(b[:maxCellBytes]) + fmt.Sprintf("… (%d bytes total)", len(b))
			default:
				row[i] = string(b)
			}
		}
		res.Rows = append(res.Rows, row)
	}
	if err := rows.Err(); err != nil {
		return res, cleanMySQLError(err)
	}

	// For a statement that returned no result set, report what it changed.
	if len(cols) == 0 {
		rows.Close()
		var affected int64
		if err := conn.QueryRowContext(ctx, "SELECT ROW_COUNT()").Scan(&affected); err == nil {
			res.RowsAffected = affected
		}
	}

	res.ElapsedMs = time.Since(started).Milliseconds()
	return res, nil
}

// cleanMySQLError keeps MySQL's own message - it is the most useful thing the
// caller can see - but drops the driver's wrapping.
func cleanMySQLError(err error) error {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		return fmt.Errorf("MySQL %d: %s", me.Number, me.Message)
	}
	return err
}
