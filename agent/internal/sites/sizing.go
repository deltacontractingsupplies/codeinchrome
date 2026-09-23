package sites

import (
	"context"
	"fmt"
	"strconv"
	"strings"
)

// Sizing: how many PHP workers a site's container runs, and so how many
// database connections it may hold. The container computes the same number
// for Apache at start (infra/images/laravel-8.3/Dockerfile, cic-start); this
// is the agent's copy, used for the site's MySQL connection cap. Keep them in
// step: if Apache can run more workers than the database allows connections,
// a traffic burst fails with max_user_connections instead of queueing - which
// is exactly what tests/load measured before this existed.
const (
	sizingBaseMB      = 64
	sizingPerWorkerMB = 24
	minWorkers        = 4
	maxWorkers        = 150
	// Beyond the web workers: artisan commands and the like.
	connectionHeadroom = 5
	// Memory set aside for each background process a site runs (queue worker,
	// scheduler, Reverb), taken from the web workers' budget.
	backgroundMB = 48
)

// WorkersFor returns the Apache worker count for a memory limit ("512m", "2g")
// and the number of background processes the site runs.
func WorkersFor(memLimit string, background int) int {
	mb := memoryMB(memLimit)
	if mb <= 0 {
		mb = 1024
	}
	w := (mb - sizingBaseMB - background*backgroundMB) / sizingPerWorkerMB
	return max(minWorkers, min(maxWorkers, w))
}

// ConnectionsFor is the site's database connection cap: every web worker and
// every background process may hold one, plus headroom.
func ConnectionsFor(memLimit string, background int) int {
	return WorkersFor(memLimit, background) + background + connectionHeadroom
}

func memoryMB(limit string) int {
	s := strings.ToLower(strings.TrimSpace(limit))
	if s == "" {
		return 0
	}
	unit := s[len(s)-1]
	num := s
	if unit < '0' || unit > '9' {
		num = s[:len(s)-1]
	}
	n, err := strconv.ParseFloat(num, 64)
	if err != nil {
		return 0
	}
	switch unit {
	case 'g':
		return int(n * 1024)
	case 'k':
		return int(n / 1024)
	case 'm':
		return int(n)
	default: // bytes
		return int(n / (1 << 20))
	}
}

// setDBConnections sets the site's MySQL connection cap for its memory size.
func (m *Manager) setDBConnections(ctx context.Context, id, memLimit string, background int) error {
	db, err := m.rootDB()
	if err != nil {
		return err
	}
	defer db.Close()
	user := DBUser(id)
	if !safeIdent.MatchString(user) {
		return fmt.Errorf("refusing unsafe user name %q", user)
	}
	_, err = db.ExecContext(ctx, fmt.Sprintf("ALTER USER '%s'@'%s' WITH MAX_USER_CONNECTIONS %d", user, containerHostPattern, ConnectionsFor(memLimit, background)))
	return err
}
