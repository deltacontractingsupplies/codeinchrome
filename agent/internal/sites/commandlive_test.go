package sites

import (
	"context"
	"sync"
	"testing"
)

func TestLiveOutputIsReadWhileItIsWrittenAndNeverSplitsACharacter(t *testing.T) {
	c := &cappedBuffer{limit: 1 << 20}
	c.Write([]byte("Migrating: 2026_09_26 ✓\n"))
	out, next := c.Since(0, 1<<10)
	if out != "Migrating: 2026_09_26 ✓\n" || next != len("Migrating: 2026_09_26 ✓\n") {
		t.Fatalf("%q %d", out, next)
	}
	if out, n2 := c.Since(next, 1<<10); out != "" || n2 != next {
		t.Fatalf("nothing new: %q %d", out, n2)
	}

	// Half of "✓" (3 bytes) printed so far: it waits for the rest.
	check := []byte("✓")
	c.Write(check[:2])
	if out, n2 := c.Since(next, 1<<10); out != "" || n2 != next {
		t.Fatalf("half a character was sent: %q", out)
	}
	c.Write(check[2:])
	if out, _ := c.Since(next, 1<<10); out != "✓" {
		t.Fatalf("the whole character: %q", out)
	}

	// A page limit that falls inside a character stops before it.
	d := &cappedBuffer{limit: 1 << 20}
	d.Write([]byte("ab✓cd"))
	if out, n := d.Since(0, 3); out != "ab" || n != 2 {
		t.Fatalf("cut inside ✓: %q %d", out, n)
	}
}

func TestTheBufferIsSafeToReadWhileTheCommandWrites(t *testing.T) {
	c := &cappedBuffer{limit: 1 << 20}
	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		for i := 0; i < 2000; i++ {
			c.Write([]byte("line of output\n"))
		}
	}()
	got := 0
	go func() {
		defer wg.Done()
		for got < 2000*len("line of output\n") {
			// A page may end mid-line; what matters is that no byte is lost.
			_, next := c.Since(got, 4096)
			got = next
		}
	}()
	wg.Wait()
	if got != 2000*len("line of output\n") {
		t.Fatalf("read %d bytes", got)
	}
}

func TestCommandLiveAnswersOnlyForARunningCommand(t *testing.T) {
	m := &Manager{}
	if o, err := m.CommandLive("shop", "run12345", 7); err != nil || o.Running || o.Next != 7 {
		t.Fatalf("nothing running: %+v %v", o, err)
	}
	c := &cappedBuffer{limit: 1 << 10}
	c.Write([]byte("Installing laravel/framework\n"))
	liveCommands.Store("shop", liveRun{key: "run12345", out: c})
	defer liveCommands.Delete("shop")
	if o, _ := m.CommandLive("shop", "run12345", 0); !o.Running || o.Output != "Installing laravel/framework\n" {
		t.Fatalf("running: %+v", o)
	}
	// Another run's key, or none, sees nothing: a queued command never shows
	// the running one's output as its own.
	for _, key := range []string{"other999", ""} {
		if o, _ := m.CommandLive("shop", key, 0); o.Running || o.Output != "" {
			t.Fatalf("key %q read another run: %+v", key, o)
		}
	}
	if _, err := m.CommandLive("../x", "run12345", 0); err == nil {
		t.Fatal("an invalid site id was accepted")
	}
	// Only letters and digits, 8-64 of them, name a run.
	if WithLiveKey(context.Background(), "a b;c") != context.Background() {
		t.Fatal("a malformed key was accepted")
	}
}
