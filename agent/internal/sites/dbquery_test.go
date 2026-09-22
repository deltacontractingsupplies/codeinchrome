package sites

import "testing"

func TestIsReadIsConservative(t *testing.T) {
	reads := []string{
		"SELECT * FROM users",
		"  select 1",
		"-- a comment\nSELECT 1",
		"/* why */ SELECT 1",
		"# hash comment\nSHOW TABLES",
		"DESCRIBE users", "DESC users", "EXPLAIN SELECT 1",
		"WITH x AS (SELECT 1) SELECT * FROM x",
		"(SELECT 1) UNION (SELECT 2)",
	}
	for _, q := range reads {
		if !IsRead(q) {
			t.Errorf("IsRead(%q) = false", q)
		}
	}

	// Everything else needs write=true. Unknown means write.
	writes := []string{
		"DELETE FROM users", "UPDATE users SET name='x'", "INSERT INTO t VALUES (1)",
		"DROP TABLE users", "TRUNCATE users", "ALTER TABLE t ADD c INT",
		"CREATE TABLE t (id INT)", "SET @x = 1", "CALL p()", "LOCK TABLES t WRITE",
		"selectx 1", "", "   ", "/* SELECT */ DELETE FROM users",
		"REPLACE INTO t VALUES (1)", "GRANT ALL ON *.* TO x",
	}
	for _, q := range writes {
		if IsRead(q) {
			t.Errorf("IsRead(%q) = true: a write would run without write=true", q)
		}
	}
}
