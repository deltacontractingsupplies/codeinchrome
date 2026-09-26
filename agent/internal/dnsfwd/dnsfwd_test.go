package dnsfwd

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// query builds a DNS question for name, type A.
func query(id uint16, name string) []byte {
	b := make([]byte, 12)
	binary.BigEndian.PutUint16(b[0:2], id)
	b[2] = 0x01                           // recursion desired
	binary.BigEndian.PutUint16(b[4:6], 1) // one question
	for _, l := range strings.Split(name, ".") {
		b = append(b, byte(len(l)))
		b = append(b, l...)
	}
	return append(b, 0, 0, 1, 0, 1)
}

func TestParseQuestion(t *testing.T) {
	name, qtype, ok := ParseQuestion(query(7, "API.Telegram.org"))
	if !ok || name != "api.telegram.org" || qtype != 1 {
		t.Fatalf("got %q %d %v", name, qtype, ok)
	}
	bad := [][]byte{
		nil, make([]byte, 11),
		append(query(1, "a.b")[:12], 0xC0, 0x0C, 0, 1, 0, 1), // a compression pointer in a question
		query(1, "example.com")[:20],                         // cut short
		query(1, strings.Repeat("a.", 130)+"com"),            // over 255 bytes
	}
	for i, m := range bad {
		if _, _, ok := ParseQuestion(m); ok {
			t.Errorf("bad message %d accepted", i)
		}
	}
	noQuestion := query(1, "a.b")
	binary.BigEndian.PutUint16(noQuestion[4:6], 0)
	if _, _, ok := ParseQuestion(noQuestion); ok {
		t.Error("a message with no question accepted")
	}
}

func TestUpstreamsAreTheRealResolversNotTheStub(t *testing.T) {
	p := filepath.Join(t.TempDir(), "resolv.conf")
	os.WriteFile(p, []byte("# comment\nnameserver 2a01:4ff:ff00::add:2\nnameserver 127.0.0.53\nnameserver 185.12.64.2\nsearch .\n"), 0o644)
	got := Upstreams(p)
	if strings.Join(got, ",") != "[2a01:4ff:ff00::add:2]:53,185.12.64.2:53" {
		t.Fatalf("upstreams %v", got)
	}
}

// fakeUpstream answers every UDP and TCP question with the question itself
// and the answer flag set, and counts them.
func fakeUpstream(t *testing.T) string {
	t.Helper()
	pc, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { pc.Close() })
	go func() {
		buf := make([]byte, 65535)
		for {
			n, src, err := pc.ReadFrom(buf)
			if err != nil {
				return
			}
			ans := append([]byte(nil), buf[:n]...)
			ans[2] |= 0x80
			pc.WriteTo(ans, src)
		}
	}()
	l, err := net.Listen("tcp", pc.LocalAddr().String())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { l.Close() })
	go func() {
		for {
			c, err := l.Accept()
			if err != nil {
				return
			}
			var sz [2]byte
			io.ReadFull(c, sz[:])
			m := make([]byte, binary.BigEndian.Uint16(sz[:]))
			io.ReadFull(c, m)
			m[2] |= 0x80
			c.Write(append(sz[:], m...))
			c.Close()
		}
	}()
	return pc.LocalAddr().String()
}

func TestAQuestionIsRelayedAndLoggedWithWhoAsked(t *testing.T) {
	up := fakeUpstream(t)
	var log bytes.Buffer
	// A dead upstream first: the next one must still answer.
	f := &Forwarder{Upstreams: []string{"127.0.0.1:1", up}, Log: &log, Timeout: 500 * time.Millisecond}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	pc, _ := net.ListenPacket("udp", "127.0.0.1:0")
	go f.ServeUDP(ctx, pc)
	l, _ := net.Listen("tcp", "127.0.0.1:0")
	go f.ServeTCP(ctx, l)

	// UDP.
	c, _ := net.Dial("udp", pc.LocalAddr().String())
	defer c.Close()
	c.SetDeadline(time.Now().Add(5 * time.Second))
	q := query(42, "api.telegram.org")
	c.Write(q)
	ans := make([]byte, 512)
	n, err := c.Read(ans)
	if err != nil || binary.BigEndian.Uint16(ans[0:2]) != 42 || ans[2]&0x80 == 0 || !bytes.Equal(ans[12:n], q[12:]) {
		t.Fatalf("udp answer %x %v", ans[:n], err)
	}
	// TCP.
	tc, _ := net.Dial("tcp", l.Addr().String())
	defer tc.Close()
	tc.SetDeadline(time.Now().Add(5 * time.Second))
	q2 := query(43, "discord.com")
	var sz [2]byte
	binary.BigEndian.PutUint16(sz[:], uint16(len(q2)))
	tc.Write(append(sz[:], q2...))
	io.ReadFull(tc, sz[:])
	a2 := make([]byte, binary.BigEndian.Uint16(sz[:]))
	if _, err := io.ReadFull(tc, a2); err != nil || binary.BigEndian.Uint16(a2[0:2]) != 43 {
		t.Fatalf("tcp answer %x %v", a2, err)
	}

	lines := strings.Split(strings.TrimSpace(log.String()), "\n")
	if len(lines) != 2 {
		t.Fatalf("log %q", log.String())
	}
	var first Question
	json.Unmarshal([]byte(lines[0]), &first)
	if first.Name != "api.telegram.org" || first.Src != "127.0.0.1" || first.Type != 1 || first.TS == 0 {
		t.Fatalf("logged %+v", first)
	}
}

func TestTheLogRotatesAtItsCap(t *testing.T) {
	p := filepath.Join(t.TempDir(), "q.log")
	r := &RotatingFile{Path: p, Max: 100}
	for i := 0; i < 30; i++ {
		r.Write([]byte("0123456789\n"))
	}
	info, _ := os.Stat(p)
	old, err := os.Stat(p + ".1")
	if err != nil || info.Size() > 100 || old.Size() > 100 {
		t.Fatalf("sizes %d %v %v", info.Size(), old, err)
	}
}

// A site flooding its gateway with questions must not make the forwarder
// start a goroutine (and a 64 KB buffer) per packet: with an upstream that
// never answers, what is in flight stays at the cap and the rest is dropped,
// as a busy resolver drops - the client asks again.
func TestAFloodIsBoundedNotQueued(t *testing.T) {
	silent, err := net.ListenPacket("udp", "127.0.0.1:0") // reads nothing, answers nothing
	if err != nil {
		t.Fatal(err)
	}
	defer silent.Close()
	f := &Forwarder{Upstreams: []string{silent.LocalAddr().String()}, Timeout: 2 * time.Second, MaxInflight: 8}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	pc, _ := net.ListenPacket("udp", "127.0.0.1:0")
	go f.ServeUDP(ctx, pc)

	c, _ := net.Dial("udp", pc.LocalAddr().String())
	defer c.Close()
	q := query(1, "flood.example")
	for i := 0; i < 2000; i++ {
		c.Write(q)
	}
	peak := int64(0)
	deadline := time.Now().Add(500 * time.Millisecond)
	for time.Now().Before(deadline) {
		if n := f.inflight.Load(); n > peak {
			peak = n
		}
		time.Sleep(5 * time.Millisecond)
	}
	if peak == 0 || peak > 8 {
		t.Fatalf("in flight peaked at %d, want 1..8", peak)
	}
	if f.dropped.Load() == 0 {
		t.Fatal("nothing was dropped from a 2,000-packet flood against a cap of 8")
	}
}

// TCP connections are capped the same way: past the cap a connection is
// closed at once instead of held for its 10-second deadline.
func TestTCPConnectionsAreCapped(t *testing.T) {
	f := &Forwarder{Upstreams: []string{"127.0.0.1:1"}, MaxConns: 2}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	l, _ := net.Listen("tcp", "127.0.0.1:0")
	go f.ServeTCP(ctx, l)

	var held []net.Conn
	for i := 0; i < 2; i++ { // two silent connections take both slots
		c, err := net.Dial("tcp", l.Addr().String())
		if err != nil {
			t.Fatal(err)
		}
		held = append(held, c)
	}
	defer func() {
		for _, c := range held {
			c.Close()
		}
	}()
	time.Sleep(100 * time.Millisecond)
	extra, err := net.Dial("tcp", l.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	defer extra.Close()
	extra.SetReadDeadline(time.Now().Add(2 * time.Second))
	if _, err := extra.Read(make([]byte, 1)); err == nil || errors.Is(err, os.ErrDeadlineExceeded) {
		t.Fatalf("a connection past the cap was held open (%v), not closed", err)
	}
}
