<?php

namespace App\Http\Controllers;

use App\Billing\WebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function __invoke(Request $request, WebhookHandler $handler): Response
    {
        $secret = config('billing.webhook_secret');
        if (! $secret) {
            // Refuse rather than accept unverified payloads. An endpoint that
            // processes whatever it is sent when a secret is missing is a way
            // for anyone who finds the URL to grant themselves a paid plan.
            return response('webhook secret not configured', 503);
        }

        $signature = (string) $request->header('X-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // Constant-time: a timing-variable comparison leaks the signature one
        // byte at a time to anyone willing to send enough requests.
        if (! hash_equals($expected, $signature)) {
            return response('invalid signature', 401);
        }

        $eventName = (string) $request->header('X-Event-Name', '');
        // Lemon Squeezy sends no delivery id header, so the id is derived from
        // the exact bytes we verified. Identical bytes are a replay; any
        // change at all - including a resend with a new timestamp - is a new
        // event. Deriving it from the payload rather than trusting a header
        // also means a caller cannot force reprocessing by varying an id.
        $eventId = hash('sha256', $request->getContent());

        try {
            $result = $handler->handle($eventId, $eventName, $request->json()->all());
        } catch (\Throwable $e) {
            // 500 asks Lemon Squeezy to retry. The event row is already stored
            // with its error, so the retry is cheap and the payment is not lost.
            return response('could not process: ' . $e->getMessage(), 500);
        }

        return response($result, 200);
    }
}
