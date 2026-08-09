<?php

namespace App\Http\Controllers;

use App\Services\FFLogs\FFLogsPlaygroundClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminFflogsPlaygroundController extends Controller
{
    public function index(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/FflogsPlayground');
    }

    public function execute(Request $request, FFLogsPlaygroundClient $client): JsonResponse
    {
        $this->authorizeAdminAccess();

        $validated = $request->validate([
            'request' => ['required', 'string', 'max:50000'],
        ], [
            'request.required' => 'empty',
            'request.string' => 'invalid',
            'request.max' => 'too_large',
        ]);

        $payload = $this->resolvePayload($validated['request']);

        try {
            $response = $client->execute($payload);
        } catch (Throwable $exception) {
            return response()->json([
                'request' => [
                    'endpoint' => $client->endpoint(),
                    'payload' => $payload,
                ],
                'response' => [
                    'ok' => false,
                    'status' => null,
                    'body' => [
                        'message' => 'Unable to execute FF Logs request.',
                        'detail' => $exception->getMessage(),
                    ],
                ],
            ], 502);
        }

        return response()->json([
            'request' => [
                'endpoint' => $client->endpoint(),
                'payload' => $payload,
            ],
            'response' => [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $this->resolveResponseBody($response),
            ],
        ], $response->successful() ? 200 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(string $input): array
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'request' => 'empty',
            ]);
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            if (! is_array($decoded) || array_is_list($decoded)) {
                throw ValidationException::withMessages([
                    'request' => 'json_object',
                ]);
            }

            $query = $decoded['query'] ?? null;

            if (! is_string($query) || trim($query) === '') {
                throw ValidationException::withMessages([
                    'request' => 'query_required',
                ]);
            }

            $payload = ['query' => trim($query)];

            if (array_key_exists('variables', $decoded)) {
                if (! is_array($decoded['variables']) && $decoded['variables'] !== null) {
                    throw ValidationException::withMessages([
                        'request' => 'variables_invalid',
                    ]);
                }

                $payload['variables'] = $decoded['variables'];
            }

            if (array_key_exists('operationName', $decoded)) {
                if (! is_string($decoded['operationName']) && $decoded['operationName'] !== null) {
                    throw ValidationException::withMessages([
                        'request' => 'operation_name_invalid',
                    ]);
                }

                $payload['operationName'] = $decoded['operationName'];
            }

            return $payload;
        }

        return ['query' => $trimmed];
    }

    private function resolveResponseBody(\Illuminate\Http\Client\Response $response): mixed
    {
        $decoded = $response->json();

        return $decoded ?? $response->body();
    }

    private function authorizeAdminAccess(): void
    {
        if (! auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
