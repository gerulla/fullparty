<?php

use App\Models\ActivityTypeVersion;
use App\Models\BozjaItem;
use App\Models\User;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('imports the tracked Bozja reference data', function () {
    Storage::fake('public');

    $this->artisan('bozja:import')
        ->expectsOutput('Imported 99 Bozja items.')
        ->assertSuccessful();

    expect(BozjaItem::query()->count())->toBe(99)
        ->and(BozjaItem::query()->where('category', 'banners')->count())->toBe(6)
        ->and(BozjaItem::query()->where('category', 'lost_actions')->count())->toBe(47);

    $item = BozjaItem::query()->where('key', 'banner-of-firm-resolve')->sole();

    expect($item->name['en'])->toBe('Banner of Firm Resolve')
        ->and($item->name['ja'])->not->toBeEmpty()
        ->and($item->classification)->toBe('banner')
        ->and($item->cache_weight)->toBe(6)
        ->and($item->icon_url)->toContain('/storage/bozja-items/banner-of-firm-resolve.webp')
        ->and($item->source_payload['en']['cache']['item_id'])->toBe(23);

    Storage::disk('public')->assertExists('bozja-items/banner-of-firm-resolve.webp');
    expect(getimagesize(Storage::disk('public')->path('bozja-items/banner-of-firm-resolve.webp'))['mime'] ?? null)
        ->toBe('image/webp');
});

it('lets admins manage Bozja system data', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post(route('admin.bozja-items.store'), [
            'key' => 'test-essence',
            'category' => 'essences',
            'name' => [
                'en' => 'Test Essence',
                'de' => 'Testessenz',
                'fr' => 'Essence de test',
                'ja' => 'テストエッセンス',
            ],
            'description' => ['en' => 'A test item.'],
            'classification' => 'essence',
            'cache_weight' => 4,
            'sort_order' => 10,
            'is_active' => true,
        ])
        ->assertRedirect();

    $item = BozjaItem::query()->sole();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'admin.bozja_item.created',
        'subject_type' => BozjaItem::class,
        'subject_id' => $item->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.system-data'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SystemData')
            ->has('bozjaItems', 1)
            ->where('bozjaItems.0.key', 'test-essence')
            ->where('bozjaItems.0.name.en', 'Test Essence')
            ->where('bozjaItems.0.has_source_payload', false)
            ->missing('bozjaItems.0.source_payload')
        );

    $this->actingAs($admin)
        ->put(route('admin.bozja-items.update', $item), [
            'key' => 'test-essence',
            'category' => 'essences',
            'name' => ['en' => 'Updated Essence'],
            'description' => ['en' => 'Updated.'],
            'classification' => 'essence',
            'cache_weight' => 5,
            'sort_order' => 20,
            'is_active' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('bozja_items', [
        'id' => $item->id,
        'cache_weight' => 5,
        'sort_order' => 20,
        'is_active' => false,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.bozja-items.destroy', $item))
        ->assertRedirect();

    $this->assertDatabaseMissing('bozja_items', ['id' => $item->id]);
});

it('prevents non-admin users from managing Bozja system data', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->post(route('admin.bozja-items.store'), [
            'key' => 'test-essence',
        ])
        ->assertForbidden();
});

it('resolves active Bozja datasets as activity schema options', function () {
    $essence = BozjaItem::query()->create([
        'key' => 'essence-of-testing',
        'category' => 'essences',
        'name' => ['en' => 'Essence of Testing', 'ja' => 'テストの秘薬'],
        'description' => ['en' => 'Testing description.'],
        'classification' => 'essence',
        'cache_weight' => 3,
        'icon_url' => '/bozja/test.webp',
        'sort_order' => 10,
        'is_active' => true,
    ]);
    BozjaItem::query()->create([
        'key' => 'inactive-essence',
        'category' => 'essences',
        'name' => ['en' => 'Inactive Essence'],
        'classification' => 'essence',
        'cache_weight' => 1,
        'sort_order' => 20,
        'is_active' => false,
    ]);
    BozjaItem::query()->create([
        'key' => 'lost-test',
        'category' => 'lost_actions',
        'name' => ['en' => 'Lost Test'],
        'classification' => 'lost_action',
        'cache_weight' => 2,
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $version = new ActivityTypeVersion([
        'slot_schema' => [[
            'key' => 'essence',
            'type' => 'single_select',
            'source' => 'bozja_essences',
            'label' => ['en' => 'Essence'],
        ]],
        'application_schema' => [],
    ]);

    $fields = app(ActivitySlotFieldDefinitionBuilder::class)->build($version);

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['options'])->toHaveCount(1)
        ->and($fields[0]['options'][0]['key'])->toBe((string) $essence->id)
        ->and($fields[0]['options'][0]['label']['ja'])->toBe('テストの秘薬')
        ->and($fields[0]['options'][0]['meta']['icon_url'])->toBe('/bozja/test.webp')
        ->and($fields[0]['options'][0]['meta']['cache_weight'])->toBe(3);
});
