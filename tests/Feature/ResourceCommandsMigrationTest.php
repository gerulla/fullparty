<?php

use App\Models\GroupResource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('preserves existing commands and every retained snapshot when converting to multiple embeds', function () {
    Schema::table('group_resource_commands', function (Blueprint $table) {
        $table->dropIndex(['resource_id']);
        $table->unique('resource_id');
    });
    $command = ['name' => 'bridges', 'enabled' => true, 'embed' => ['title' => 'Bridge plan']];
    $snapshot = ['title' => 'Resource', 'command' => $command, 'image_ids' => [], 'author' => ['name' => 'Original author']];
    $resource = GroupResource::factory()->create(['working_copy' => $snapshot]);
    $stored = $resource->commands()->create(['group_id' => $resource->group_id] + $command);
    foreach (['draft', 'pending', 'published', 'discarded'] as $state) {
        $resource->revisions()->create(['snapshot' => $snapshot, 'state' => $state, 'editor' => ['name' => 'Editor'], 'summary' => 'Original summary']);
    }
    $version = $resource->fresh()->version;
    $migration = require database_path('migrations/2026_09_11_000003_allow_multiple_resource_commands.php');
    $migration->up();
    expect($resource->fresh()->working_copy)->toBe(['title' => 'Resource', 'image_ids' => [], 'author' => ['name' => 'Original author'], 'commands' => [$command]])
        ->and($resource->fresh()->version)->toBe($version)->and($stored->fresh()->embed)->toBe($command['embed']);
    foreach ($resource->revisions as $revision) {
        expect($revision->snapshot['commands'])->toBe([$command])->and($revision->snapshot)->not->toHaveKey('command')
            ->and($revision->summary)->toBe('Original summary');
    }
    $resource->commands()->create(['group_id' => $resource->group_id, 'name' => 'east', 'enabled' => true, 'embed' => ['title' => 'East bridge']]);
    expect($resource->commands()->count())->toBe(2);
});
