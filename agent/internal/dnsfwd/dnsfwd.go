// Package dnsfwd is the hosts' own DNS forwarder for sites (audit A21).
//
// Docker's resolver in a site container forwards to the upstream it is given
// FROM THE CONTAINER'S network namespace (measured 2026-09-26 on h4: queries
// to the bridge gateway arrived with the container's own address). So a
// forwarder on the host sees which site asked for which name - the one thing
// a host-side query log could not tell - and writes it down: one JSON line
// per question. It answers nothing itself: every packet goes to the host's
// own upstream resolvers and the answer comes back unchanged.
//
// It runs as its own service (cic-dns), apart from the agent, so an agent
// restart never interrupts a site's name resolution; it is small on purpose.
package dnsfwd

import (
	"bufio"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"strings"
	"sync"
	"time"
)

// Question is what is logged of one query.
type Question struct {
	TS   int64  `json:"ts"`
	Src  string `json:"src"`
	Name string `json:"name"`
	Type uint16 `json:"type"`
}

// ParseQuestion reads the first question of a DNS message: its name
// (lower-case, no trailing dot) and type. Compression pointers are not
// allowed in a question, so any is refused, as is anything malformed.
func ParseQuestion(msg []byte) (string, uint16, bool) {
	if len(msg) < 12 || binary.BigEndian.Uint16(msg[4:6]) < 1 {
		return "", 0, false
	}
	var labels []string
	i, total := 12, 0
	for {
		if i >= len(msg) {
			return "", 0, false
		}
		n := int(msg[i])
		i++
		if n == 0 {
			break
		}
		if n&0xC0 != 0 || i+n > len(msg) {
			return "", 0, false
		}
		if total += n + 1; total > 255 {
			return "", 0, false
		}
		labels = append(labels, strings.ToLower(string(msg[i:i+n])))
		i += n
	}
	if i+4 > len(msg) {
		return "", 0, false
	}
	return strings.Join(labels, "."), binary.BigEndian.Uint16(msg[i : i+2]), true
}

// Upstreams reads the host's real resolvers (systemd-resolved's list, not
// its 127.0.0.53 stub, which a forwarder here cannot usefully ask).
func Upstreams(resolvConf string) []string {
	f, err := os.Open(resolvConf)
	if err != nil {
		return nil
	}
	defer f.Close()
	var out []string
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) >= 2 && fields[0] == "nameserver" {
			if ip := net.ParseIP(fields[1]); ip != nil && !ip.IsLoopback() {
				out = append(out, net.JoinHostPort(ip.String(), "53"))
			}
		}
	}
	return out
}

// Forwarder relays questions to Upstreams and logs them to Log.
type Forwarder struct {
	Upstreams []string
	Log       io.Writer
	Timeout   time.Duration

	mu sync.Mutex
}

func (f *Forwarder) record(src net.Addr, msg []byte) {
	name, qtype, ok := ParseQuestion(msg)
	if !ok || f.Log == nil {
		return
	}
	host := src.String()
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}
	b, _ := json.Marshal(Question{TS: time.Now().Unix(), Src: host, Name: name, Type: qtype})
	f.mu.Lock()
	_, _ = f.Log.Write(append(b, '\n'))
	f.mu.Unlock()
}

func (f *Forwarder) timeout() time.Duration {
	if f.Timeout > 0 {
		return f.Timeout
	}
	return 3 * time.Second
}

// exchangeUDP asks each upstream in turn and returns the first answer.
func (f *Forwarder) exchangeUDP(msg []byte) ([]byte, error) {
	buf := make([]byte, 65535)
	for _, up := range f.Upstreams {
		c, err := net.DialTimeout("udp", up, f.timeout())
		if err != nil {
			continue
		}
		_ = c.SetDeadline(time.Now().Add(f.timeout()))
		if _, err := c.Write(msg); err == nil {
			if n, err := c.Read(buf); err == nil {
				c.Close()
				return append([]byte(nil), buf[:n]...), nil
			}
		}
		c.Close()
	}
	return nil, errors.New("no upstream answered")
}

// ServeUDP answers until ctx ends.
func (f *Forwarder) ServeUDP(ctx context.Context, pc net.PacketConn) error {
	go func() { <-ctx.Done(); pc.Close() }()
	buf := make([]byte, 65535)
	for {
		n, src, err := pc.ReadFrom(buf)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			continue
		}
		msg := append([]byte(nil), buf[:n]...)
		f.record(src, msg)
		go func() {
			if ans, err := f.exchangeUDP(msg); err == nil {
				_, _ = pc.WriteTo(ans, src)
			}
		}()
	}
}

// ServeTCP answers length-prefixed queries (a truncated answer is re-asked
// over TCP) until ctx ends.
func (f *Forwarder) ServeTCP(ctx context.Context, l net.Listener) error {
	go func() { <-ctx.Done(); l.Close() }()
	for {
		c, err := l.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			continue
		}
		go f.handleTCP(c)
	}
}

func (f *Forwarder) handleTCP(c net.Conn) {
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(10 * time.Second))
	var size [2]byte
	if _, err := io.ReadFull(c, size[:]); err != nil {
		return
	}
	msg := make([]byte, binary.BigEndian.Uint16(size[:]))
	if _, err := io.ReadFull(c, msg); err != nil {
		return
	}
	f.record(c.RemoteAddr(), msg)
	for _, up := range f.Upstreams {
		u, err := net.DialTimeout("tcp", up, f.timeout())
		if err != nil {
			continue
		}
		_ = u.SetDeadline(time.Now().Add(f.timeout()))
		if _, err := u.Write(append(size[:], msg...)); err == nil {
			var rs [2]byte
			if _, err := io.ReadFull(u, rs[:]); err == nil {
				ans := make([]byte, binary.BigEndian.Uint16(rs[:]))
				if _, err := io.ReadFull(u, ans); err == nil {
					u.Close()
					_, _ = c.Write(append(rs[:], ans...))
					return
				}
			}
		}
		u.Close()
	}
}

// RotatingFile is an append-only log that starts over (keeping one previous
// file) once it passes Max bytes, so the query log cannot fill a disk.
type RotatingFile struct {
	Path string
	Max  int64

	mu   sync.Mutex
	f    *os.File
	size int64
}

func (r *RotatingFile) Write(p []byte) (int, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.f == nil || r.size+int64(len(p)) > r.Max {
		if r.f != nil {
			r.f.Close()
			_ = os.Rename(r.Path, r.Path+".1")
		}
		f, err := os.OpenFile(r.Path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
		if err != nil {
			return 0, err
		}
		info, _ := f.Stat()
		r.f, r.size = f, 0
		if info != nil {
			r.size = info.Size()
		}
	}
	n, err := r.f.Write(p)
	r.size += int64(n)
	return n, err
}
