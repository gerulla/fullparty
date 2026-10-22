<?php

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Services\Groups\GuestSelfAssignmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['database']['database'] ?? null) !== 'fullparty_test' || ($input['database']['driver'] ?? null) !== 'pgsql') {
    throw new RuntimeException('Concurrency workers require the isolated PostgreSQL test database.');
}
putenv('APP_ENV=testing');
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, function () use ($input): void {
    config()->set([
        'app.env' => 'testing', 'database.default' => 'pgsql', 'database.connections.pgsql' => $input['database'],
        'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
        'broadcasting.default' => 'null', 'mail.default' => 'array',
        'pulse.enabled' => false, 'telescope.enabled' => false, 'nightwatch.enabled' => false,
    ]);
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
DB::select("SELECT set_config('application_name', ?, false)", [$input['worker_name']]);
DB::statement("SET lock_timeout TO '15s'");

try {
    $run = Activity::findOrFail($input['activity_id']);
    app(GuestSelfAssignmentService::class)->assign($run->group, $run, ActivitySlot::findOrFail($input['slot_id']), $input['input']);
    echo json_encode(['status' => 201]);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 422]);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode()]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
