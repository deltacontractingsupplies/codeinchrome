package sites

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

// One database and one user per site, on the host's shared MySQL server.
//
// Isolation between tenants rests on MySQL's privilege system, so the rules
// here are narrow on purpose:
//
//   - The user is granted ALL on its own database and nothing on any other.
//     No global privileges, no GRANT OPTION, no FILE.
//   - It may connect only from the site-network address pool (containers) and
//     from 127.0.0.1 (this agent, for the database browser). Not from '%'.
//   - It is capped at a handful of simultaneous connections, so one tenant
//     cannot exhaust a server every other tenant on the host depends on.
//
// Identifiers cannot be bound as query parameters in MySQL, so every
// identifier used below is derived from a site id that has already passed
// ValidID, then checked again against a strict pattern before it is quoted.

const (
	// The daemon.json default-address-pools range, written as MySQL's
	// address/netmask host pattern. Every customer container lives in it.
	containerHostPattern = "172.20.0.0/255.252.0.0"
	agentHost            = "127.0.0.1"

	containerMaxConnections = 20
	agentMaxConnections     = 3

	// The name customer containers resolve to reach the server, mapped with
	// --add-host cic-db:host-gateway.
	dbHostForSites = "cic-db"
)

var safeIdent = regexp.MustCompile(`^[a-z0-9_]{1,64}$`)

// DBName is the site's database name: readable, because a customer sees it.
func DBName(id string) string {
	return "site_" + strings.ReplaceAll(id, "-", "_")
}

// DBUser is the site's MySQL user. Hashed rather than readable because MySQL
// user names are limited to 32 characters and site ids may be 40.
func DBUser(id string) string {
	sum := sha256.Sum256([]byte("codeinchrome-site:" + id))
	return "u_" + hex.EncodeToString(sum[:])[:20]
}

func quoteIdent(name string) (string, error) {
	if !safeIdent.MatchString(name) {
		return "", fmt.Errorf("refusing unsafe identifier %q", name)
	}
	return "`" + name + "`", nil
}

func randomPassword() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

// rootDB opens a connection as MySQL root over loopback. Opened per call and
// closed after: database operations are rare (create, delete, browse) and a
// pool held open forever is one more thing to reason about.
func (m *Manager) rootDB() (*sql.DB, error) {
	if m.cfg.MySQLPassword == "" {
		return nil, fmt.Errorf("no database server is configured on this host (CIC_MYSQL_ROOT_PASSWORD is unset); run infra/mysql.sh")
	}
	cfg := mysql.NewConfig()
	cfg.User = "root"
	cfg.Passwd = m.cfg.MySQLPassword
	cfg.Net = "tcp"
	cfg.Addr = agentHost + ":3306"
	cfg.Timeout = 5 * time.Second
	cfg.InterpolateParams = true // the password is sent escaped client-side; statements like CREATE USER are not preparable everywhere
	return sql.Open("mysql", cfg.FormatDSN())
}

// createDatabase makes the site's database and user, and returns the password.
func (m *Manager) createDatabase(ctx context.Context, id string) (string, error) {
	db, err := m.rootDB()
	if err != nil {
		return "", err
	}
	defer db.Close()

	name, err := quoteIdent(DBName(id))
	if err != nil {
		return "", err
	}
	user := DBUser(id)
	if !safeIdent.MatchString(user) {
		return "", fmt.Errorf("refusing unsafe user name %q", user)
	}
	password, err := randomPassword()
	if err != nil {
		return "", err
	}

	statements := []struct {
		sql  string
		args []any
	}{
		{"CREATE DATABASE " + name + " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci", nil},
		{fmt.Sprintf("CREATE USER '%s'@'%s' IDENTIFIED BY ? WITH MAX_USER_CONNECTIONS %d", user, containerHostPattern, containerMaxConnections), []any{password}},
		{fmt.Sprintf("CREATE USER '%s'@'%s' IDENTIFIED BY ? WITH MAX_USER_CONNECTIONS %d", user, agentHost, agentMaxConnections), []any{password}},
		{fmt.Sprintf("GRANT ALL PRIVILEGES ON %s.* TO '%s'@'%s'", name, user, containerHostPattern), nil},
		{fmt.Sprintf("GRANT ALL PRIVILEGES ON %s.* TO '%s'@'%s'", name, user, agentHost), nil},
	}
	for _, st := range statements {
		if _, err := db.ExecContext(ctx, st.sql, st.args...); err != nil {
			// Undo whatever part succeeded, so a retry starts clean.
			_ = m.dropDatabase(context.Background(), id)
			return "", fmt.Errorf("create database for %s: %w", id, err)
		}
	}
	return password, nil
}

// databaseExists is observed rather than assumed, for the delete report.
func (m *Manager) databaseExists(ctx context.Context, id string) (dbExists, userExists bool, err error) {
	db, err := m.rootDB()
	if err != nil {
		return false, false, err
	}
	defer db.Close()

	var n int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?", DBName(id)).Scan(&n); err != nil {
		return false, false, err
	}
	dbExists = n > 0
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM mysql.user WHERE user = ?", DBUser(id)).Scan(&n); err != nil {
		return dbExists, false, err
	}
	return dbExists, n > 0, nil
}

func (m *Manager) dropDatabase(ctx context.Context, id string) error {
	db, err := m.rootDB()
	if err != nil {
		return err
	}
	defer db.Close()

	name, err := quoteIdent(DBName(id))
	if err != nil {
		return err
	}
	user := DBUser(id)
	var firstErr error
	for _, st := range []string{
		"DROP DATABASE IF EXISTS " + name,
		fmt.Sprintf("DROP USER IF EXISTS '%s'@'%s'", user, containerHostPattern),
		fmt.Sprintf("DROP USER IF EXISTS '%s'@'%s'", user, agentHost),
	} {
		if _, err := db.ExecContext(ctx, st); err != nil && firstErr == nil {
			firstErr = err
		}
	}
	return firstErr
}

// SetEnv sets KEY=value in the text of a .env file.
//
// It replaces an existing line for the key - including a commented-out one,
// which is how Laravel's skeleton ships the database settings - and appends
// the key if it is absent. Every other line, comment and blank line is kept
// exactly as it was.
func SetEnv(content, key, value string) string {
	lines := strings.Split(content, "\n")
	pattern := regexp.MustCompile(`^\s*#?\s*` + regexp.QuoteMeta(key) + `\s*=`)
	replaced := false
	for i, line := range lines {
		if pattern.MatchString(line) {
			if replaced {
				continue // a later duplicate is left alone
			}
			lines[i] = key + "=" + value
			replaced = true
		}
	}
	if !replaced {
		if len(lines) > 0 && lines[len(lines)-1] == "" {
			lines = append(lines[:len(lines)-1], key+"="+value, "")
		} else {
			lines = append(lines, key+"="+value)
		}
	}
	return strings.Join(lines, "\n")
}

// configureAppDatabase points the site's .env at its database and runs the
// application's migrations, which is also the proof that the credentials work.
func (m *Manager) configureAppDatabase(ctx context.Context, s Site, password string) error {
	envPath := filepath.Join(m.appDir(s.ID), ".env")
	raw, err := os.ReadFile(envPath)
	if err != nil {
		return fmt.Errorf("read .env: %w", err)
	}
	content := string(raw)
	for _, kv := range [][2]string{
		{"DB_CONNECTION", "mysql"},
		{"DB_HOST", dbHostForSites},
		{"DB_PORT", "3306"},
		{"DB_DATABASE", DBName(s.ID)},
		{"DB_USERNAME", DBUser(s.ID)},
		{"DB_PASSWORD", password},
		// Daily log files kept for 14 days, not one file that grows forever:
		// a burst of errors under load wrote 157 MB into one site's
		// laravel.log in half an hour, on the site's own disk quota.
		{"LOG_STACK", "daily"},
		{"LOG_DAILY_DAYS", "14"},
	} {
		content = SetEnv(content, kv[0], kv[1])
	}
	if err := os.WriteFile(envPath, []byte(content), 0o640); err != nil {
		return fmt.Errorf("write .env: %w", err)
	}
	if err := chownAsWWW(envPath); err != nil {
		return fmt.Errorf("chown .env: %w", err)
	}

	// The skeleton's SQLite file holds the tables Laravel created at build
	// time. Left behind, it looks like the site's data and is not.
	_ = os.Remove(filepath.Join(m.appDir(s.ID), "database", "database.sqlite"))

	if _, err := run(ctx, 2*time.Minute, "docker", "exec", "-u", "33:33", s.Container,
		"php", "/var/www/html/artisan", "migrate", "--force", "--no-interaction",
	); err != nil {
		return fmt.Errorf("the site could not use its new database: %w", err)
	}
	return nil
}
