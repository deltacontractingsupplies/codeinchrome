<?php

namespace App\Support;

use GuzzleHttp\Psr7\Stream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Where the answer of a site we do not control is written: at most $max bytes,
 * then the transfer stops.
 *
 * Without it the HTTP client keeps the whole answer - past 2 MB in a temp file
 * on the control host's disk, and all of it in memory once ->body() is read -
 * so a site answering with gigabytes (its visitors' traffic is not rate-capped,
 * and the control host's PHP CLI has no memory limit) could fill the control
 * host's disk or memory from the hourly scan or the minutely monitor.
 *
 * Measured against a local 200 MB answer (2026-09-26): the transfer stops at
 * the cap at once. A single request then THROWS (the client reports the
 * refused write as a failed connection) - the status and headers are kept here
 * as they arrive, so the answer is not lost. Inside Http::pool the client
 * returns the Response as usual.
 */
final class BoundedSink extends Stream
{
    public bool $truncated = false;

    public ?int $status = null;

    /** @var array<string, string[]> */
    public array $headers = [];

    public function __construct(private readonly int $max)
    {
        parent::__construct(fopen('php://memory', 'w+'));
    }

    public function write($string): int
    {
        $room = $this->max - (int) $this->getSize();
        if ($room <= 0) {
            $this->truncated = true;

            return 0; // curl stops the transfer on a short write
        }
        if (strlen($string) > $room) {
            $this->truncated = true;
            $string = substr($string, 0, $room);
        }

        return parent::write($string);
    }

    /** Request options that send the answer here. */
    public function options(): array
    {
        return [
            'sink' => $this,
            'on_headers' => function (ResponseInterface $r): void {
                $this->status = $r->getStatusCode();
                $this->headers = $r->getHeaders();
            },
        ];
    }

    /**
     * GET $url, reading at most $max bytes of the answer. Null: no answer.
     *
     * @return array{status: int, type: string, location: ?string, body: string, truncated: bool}|null
     */
    public static function get(PendingRequest $request, string $url, int $max): ?array
    {
        $sink = new self($max);
        try {
            $res = $request->withOptions($sink->options())->get($url);
        } catch (ConnectionException) {
            // The cap stopping the transfer looks like this too; its headers had arrived.
            return $sink->truncated && $sink->status !== null ? $sink->answer() : null;
        }

        return $sink->answerFrom($res);
    }

    /**
     * The answer of a request sent with options() - inside Http::pool the
     * client returns it (or an exception) instead of throwing.
     *
     * @return array{status: int, type: string, location: ?string, body: string, truncated: bool}|null
     */
    public function answerFrom(Response|\Throwable|null $res): ?array
    {
        if (! $res instanceof Response) {
            return $this->truncated && $this->status !== null ? $this->answer() : null;
        }
        // A faked answer (tests) never passes through the sink: the same cap.
        $body = $res->body();

        return [
            'status' => $res->status(),
            'type' => $res->header('Content-Type'),
            'location' => $res->header('Location') ?: null,
            'body' => substr($body, 0, $this->max),
            'truncated' => $this->truncated || strlen($body) > $this->max,
        ];
    }

    /** @return array{status: int, type: string, location: ?string, body: string, truncated: bool} */
    private function answer(): array
    {
        $header = fn (string $name) => collect($this->headers)
            ->first(fn ($v, $k) => strcasecmp($k, $name) === 0)[0] ?? '';

        return [
            'status' => (int) $this->status,
            'type' => $header('Content-Type'),
            'location' => $header('Location') ?: null,
            'body' => (string) $this,
            'truncated' => true,
        ];
    }
}
