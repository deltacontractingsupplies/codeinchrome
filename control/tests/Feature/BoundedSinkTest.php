<?php

namespace Tests\Feature;

use App\Support\BoundedSink;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Against a real server, not Http::fake: what is proved is that the TRANSFER
 * stops at the cap - a fake never transfers anything.
 */
class BoundedSinkTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', base_path('tests/Fixtures/answers'), base_path('tests/Fixtures/answers/index.php')],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->base = "http://127.0.0.1:$port";
        Http::allowStrayRequests(["$this->base/*", 'http://127.0.0.1:1/*']); // its own server, and a port nothing listens on
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
    }

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    public function test_a_huge_answer_stops_at_the_cap_and_keeps_its_status(): void
    {
        $t = microtime(true);
        $before = memory_get_usage();
        $a = BoundedSink::get(Http::timeout(10), "$this->base/big", 1 << 20);

        $this->assertNotNull($a);
        $this->assertSame(200, $a['status']);
        $this->assertStringContainsString('text/html', $a['type']);
        $this->assertTrue($a['truncated']);
        $this->assertSame(1 << 20, strlen($a['body']));
        $this->assertLessThan(5, microtime(true) - $t, 'the transfer stopped, it did not run to the end');
        $this->assertLessThan(16 << 20, memory_get_usage() - $before);
    }

    public function test_a_small_answer_and_a_redirect_come_back_whole(): void
    {
        $a = BoundedSink::get(Http::timeout(10), "$this->base/small", 1 << 20);
        $this->assertSame([200, '<a href="/next">next</a>', false], [$a['status'], $a['body'], $a['truncated']]);

        $r = BoundedSink::get(Http::timeout(10)->withoutRedirecting(), "$this->base/moved", 1 << 20);
        $this->assertSame([302, '/small', false], [$r['status'], $r['location'], $r['truncated']]);

        $this->assertNull(BoundedSink::get(Http::timeout(2), 'http://127.0.0.1:1/', 1 << 20), 'no answer is null');
    }

    public function test_inside_a_pool_too(): void
    {
        $sinks = ['big' => new BoundedSink(64 << 10), 'small' => new BoundedSink(64 << 10)];
        $t = microtime(true);
        $rs = Http::pool(fn (Pool $p) => array_map(
            fn ($k) => $p->as($k)->timeout(10)->withOptions($sinks[$k]->options())->get("$this->base/$k"),
            array_keys($sinks),
        ));

        $big = $sinks['big']->answerFrom($rs['big']);
        $small = $sinks['small']->answerFrom($rs['small']);
        $this->assertSame([200, true, 64 << 10], [$big['status'], $big['truncated'], strlen($big['body'])]);
        $this->assertSame([200, false], [$small['status'], $small['truncated']]);
        $this->assertLessThan(5, microtime(true) - $t);
    }

    public function test_a_faked_answer_is_held_to_the_same_cap(): void
    {
        Http::fake(['fake.test/*' => Http::response(str_repeat('x', 5000), 200, ['Content-Type' => 'text/html'])]);
        $a = BoundedSink::get(Http::timeout(5), 'https://fake.test/page', 1000);
        $this->assertSame([200, 1000, true], [$a['status'], strlen($a['body']), $a['truncated']]);
    }
}
