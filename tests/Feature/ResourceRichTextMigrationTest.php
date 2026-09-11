<?php

use App\Models\GroupResource;
use App\Models\GroupResourceRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('converts working and historical resource bodies without changing metadata or timestamps', function () {
    $imageId = (string) str()->uuid();
    $snapshot = ['title' => 'Bridges', 'body' => "# Bridge\n\n![Map](/resource-assets/{$imageId})", 'image_ids' => [$imageId], 'access_level' => 'admin', 'command' => ['name' => 'bridge', 'embed' => ['description' => '**Discord Markdown**']]];
    $resource = GroupResource::factory()->create(['working_copy' => $snapshot]);
    $revision = GroupResourceRevision::create(['resource_id' => $resource->id, 'editor' => ['name' => 'Author'], 'snapshot' => $snapshot, 'summary' => 'Created guide']);
    $updated = $resource->updated_at;
    $migration = require database_path('migrations/2026_09_11_000001_convert_resource_bodies_to_rich_text.php');
    $migration->up();
    $working = $resource->fresh()->working_copy;
    expect($working['body']['type'])->toBe('doc')->and($working['body_text'])->toContain('Bridge')
        ->and($working['image_ids'])->toBe([$imageId])->and($working['command'])->toBe($snapshot['command'])
        ->and($working['access_level'])->toBe('admin')->and($resource->fresh()->updated_at->equalTo($updated))->toBeTrue()
        ->and($revision->fresh()->snapshot)->toBe($working)->and($revision->fresh()->summary)->toBe('Created guide');
    $migration->up();
    expect($resource->fresh()->working_copy)->toBe($working);
});

it('rolls back all resource conversions when one document cannot be preserved', function () {
    $first = GroupResource::factory()->create(['working_copy' => ['body' => '# Valid']]);
    GroupResource::factory()->create(['working_copy' => ['body' => '[unsupported](relative.md)']]);
    $migration = require database_path('migrations/2026_09_11_000001_convert_resource_bodies_to_rich_text.php');
    expect(fn () => $migration->up())->toThrow(ValidationException::class);
    expect($first->fresh()->working_copy['body'])->toBe('# Valid');
});
