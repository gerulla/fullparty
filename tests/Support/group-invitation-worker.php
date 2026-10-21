<?php

use App\Models\Activity;
use App\Models\ActivityGroupFollowUp;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\ActivityGroupFollowUpService;
use App\Services\Groups\GroupPersonalInvitationService;
use App\Support\Quotas\QuotaKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

// A separate PHP process is needed to exercise real PostgreSQL row-lock contention.
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['database']['database'] ?? null) !== 'fullparty_test' || ($input['database']['driver'] ?? null) !== 'pgsql') {
    throw new RuntimeException('Concurrency workers require the isolated PostgreSQL test database.');
}
putenv('APP_ENV=testing');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, function () use ($input): void {
    config()->set([
        'app.env' => 'testing', 'app.url' => 'http://fullparty.test',
        'database.default' => 'pgsql', 'database.connections.pgsql' => $input['database'],
        'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
        'broadcasting.default' => 'null', 'mail.default' => 'array',
        'pulse.enabled' => false, 'telescope.enabled' => false, 'nightwatch.enabled' => false,
        'quotas.mode' => 'enforce',
    ]);
    config()->set('quotas.limits', [...config('quotas.limits'), QuotaKey::GROUPS_JOINED => 1]);
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
URL::defaults(['locale' => 'en']);
DB::select("SELECT set_config('application_name', ?, false)", [$input['worker_name']]);
DB::statement("SET lock_timeout TO '15s'");
$actor = User::findOrFail($input['actor_id']);
$service = app(GroupPersonalInvitationService::class);

try {
    $result = match ($input['action']) {
        'send' => $service->send(Group::findOrFail($input['group_id']), User::findOrFail($input['recipient_id']), $actor)->id,
        // Preload membership to verify that cached caller state cannot defeat idempotency.
        'accept' => $service->accept(GroupInvite::with('group.memberships')->findOrFail($input['invite_id']), $actor)->id,
        'present' => app(ActivityGroupFollowUpService::class)->acknowledge(ActivityGroupFollowUp::findOrFail($input['prompt_id']), $actor),
        'complete' => app(ActivityCompletionService::class)->completeAndNotify(Activity::findOrFail($input['activity_id']), [], $actor),
        default => throw new RuntimeException('Unknown concurrency action.'),
    };
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
