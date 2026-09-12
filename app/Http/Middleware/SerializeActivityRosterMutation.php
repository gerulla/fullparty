<?php

namespace App\Http\Middleware;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Services\Groups\ActivityRosterLock;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SerializeActivityRosterMutation
{
    public function __construct(private readonly ActivityRosterLock $rosterLock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $activity = $request->route('activity');
        abort_unless($activity instanceof Activity, 404);

        try {
            return $this->rosterLock->run((int) $activity->id, function () use ($request, $next): Response {
                // Route binding occurred before the lock, so its models may be stale.
                foreach ($request->route()->parameters() as $model) {
                    if ($model instanceof Activity || $model instanceof ActivitySlot
                        || $model instanceof ActivitySlotAssignment || $model instanceof ActivityApplication) {
                        $model->setRelations([]);
                        $model->refresh();
                    }
                }

                $response = $next($request);
                // Laravel may render a validation exception as a 302 redirect.
                if ($response->getStatusCode() >= 400 || ($response->exception ?? null) !== null) {
                    throw new HttpResponseException($response);
                }

                return $response;
            });
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }
    }
}
