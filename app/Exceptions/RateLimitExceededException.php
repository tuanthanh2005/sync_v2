<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        public readonly string $limitType,
        public readonly string $field = 'content',
    ) {
        parent::__construct($message, 429);
    }

    public function render(Request $request): Response
    {
        $retryAfter = max(1, $this->retryAfterSeconds);
        $message = $this->getMessage();
        $headers = ['Retry-After' => (string) $retryAfter];

        if ($request->expectsJson() || $request->is('api/*') || $request->header('X-Inertia') !== null) {
            if ($request->header('X-Inertia') !== null) {
                Inertia::flash('toast', [
                    'type' => 'warning',
                    'message' => $message,
                ]);
            }

            return response()->json([
                'message' => $message,
                'retry_after' => $retryAfter,
                'limit_type' => $this->limitType,
                'errors' => [
                    $this->field => [$message],
                ],
            ], 429, $headers);
        }

        return response($message, 429, array_merge($headers, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]));
    }
}
