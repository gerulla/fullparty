<?php

use App\Jobs\DispatchRosterPublishedAssignmentNotificationJob;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\AssignmentNotificationService;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

dataset('specialist notification designations', [
    'DRS Duelist' => ['delubrum-reginae-savage', 'duelist', 'Duelist'],
    'DRS Trapper' => ['delubrum-reginae-savage', 'trapper', 'Trapper'],
    'BA Trapper' => ['the-baldesion-arsenal', 'trapper', 'Trapper'],
    'BA Darter' => ['the-baldesion-arsenal', 'darter', 'Darter'],
]);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://integration.fullparty.test/events' => Http::response(['ok' => true])]);
    IntegrationClient::factory()->create([
        'allowed_events' => [IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY],
    ]);
    $this->owner = User::factory()->create();
    Character::factory()->primary()->create(['user_id' => $this->owner->id]);
    $this->member = User::factory()->create(['assignment_notifications' => true]);
    DiscordUserIntegration::create([
        'user_id' => $this->member->id,
        'discord_user_id' => '234567890123456789',
        'user_app_installed_at' => now(),
    ]);
    $this->character = Character::factory()->primary()->create(['user_id' => $this->member->id]);
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);
    $this->type = ActivityType::factory()->create();
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $this->type->id,
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 1]]],
        'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
    ]);
    $this->activity = Activity::factory()->create([
        'group_id' => $this->group->id,
        'activity_type_id' => $this->type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $this->owner->id,
        'status' => Activity::STATUS_ASSIGNED,
    ]);
    $this->slot = $this->activity->slots()->firstOrFail();
    $this->slot->update(['assigned_character_id' => $this->character->id]);
    $this->toggle = fn (string $designation) => $this->actingAs($this->owner)->postJson(
        route('groups.dashboard.activities.slot-designations.store', [$this->group, $this->activity, $this->slot]),
        ['designation' => $designation, 'expected_slot_state_token' => activity_slot_state_token($this->slot->fresh())],
    );
});

it('delivers specialist assignment and removal through the documented Discord notification webhook', function (string $slug, string $designation, string $label) {
    $this->type->update(['slug' => $slug]);
    ($this->toggle)($designation)->assertOk();
    ($this->toggle)($designation)->assertOk();

    Http::assertSentCount(2);
    $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach (Http::recorded() as $index => [$request]) {
        $assigned = $index === 0;
        $eventType = $assigned ? 'assignments.designation_assigned' : 'assignments.designation_removed';
        $body = $request->data();
        expect($body['event'])->toBe('discord.notification.delivery')
            ->and($body['data']['type'])->toBe($eventType)
            ->and($body['data']['category'])->toBe('assignments')
            ->and($body['data']['discord_user']['id'])->toBe('234567890123456789')
            ->and($body['data']['user']['id'])->toBe($this->member->id)
            ->and($body['data']['notification']['type'])->toBe($eventType)
            ->and($body['data']['notification']['params']['designation'])->toBe($label)
            ->and($body['data']['notification']['payload'])->toMatchArray([
                'activity_id' => $this->activity->id,
                'group_id' => $this->group->id,
                'character_id' => $this->character->id,
                'slot_id' => $this->slot->id,
                'designation_key' => $designation,
                'designation_label' => $label,
                'designation_assigned' => $assigned,
            ]);
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks']['discord.notification.delivery']['post']['requestBody']['content']['application/json']['schema'], $document);
    }
    expect(NotificationDelivery::where('channel', NotificationChannel::DISCORD)->pluck('status')->all())
        ->toBe([NotificationDelivery::STATUS_SENT, NotificationDelivery::STATUS_SENT]);
})->with('specialist notification designations');

it('delivers preselected specialist designations when the roster is published', function (string $slug, string $designation, string $label, bool $hasApplication) {
    $this->type->update(['slug' => $slug]);
    $this->activity->update(['status' => Activity::STATUS_SCHEDULED]);
    if ($hasApplication) {
        ActivityApplication::factory()->approved($this->owner)->create([
            'activity_id' => $this->activity->id,
            'user_id' => $this->member->id,
            'selected_character_id' => $this->character->id,
            'applicant_lodestone_id' => $this->character->lodestone_id,
        ]);
    }
    ($this->toggle)($designation)->assertOk();
    Http::assertNothingSent();
    expect(NotificationEvent::count())->toBe(0);

    $this->actingAs($this->owner)->post(route('groups.dashboard.activities.publish-roster', [$this->group, $this->activity]))
        ->assertRedirect()->assertSessionHasNoErrors();
    Queue::assertPushed(DispatchRosterPublishedAssignmentNotificationJob::class, 1);
    Queue::pushed(DispatchRosterPublishedAssignmentNotificationJob::class)->each(
        fn (DispatchRosterPublishedAssignmentNotificationJob $job) => $job->handle(app(AssignmentNotificationService::class)),
    );

    Http::assertSentCount(2);
    $event = NotificationEvent::where('type', 'assignments.designation_assigned')->sole();
    expect($event->payload['designation_key'])->toBe($designation)
        ->and($event->message_params['designation'])->toBe($label);
    $delivery = NotificationDelivery::where('notification_event_id', $event->id)->where('channel', NotificationChannel::DISCORD)->sole();
    expect($delivery->target)->toBe('234567890123456789')->and($delivery->status)->toBe(NotificationDelivery::STATUS_SENT);
})->with('specialist notification designations')->with([true, false]);

it('respects the Discord designation notification preference', function () {
    $this->type->update(['slug' => 'the-baldesion-arsenal']);
    UserNotificationPreference::create([
        'user_id' => $this->member->id,
        'topic' => NotificationTopic::ASSIGNMENTS_DESIGNATIONS,
        'channel' => NotificationChannel::DISCORD,
        'enabled' => false,
    ]);
    ($this->toggle)('darter')->assertOk();
    ($this->toggle)('darter')->assertOk();
    expect(NotificationEvent::count())->toBe(2);
    Http::assertNothingSent();
});

it('does not send a queued publication notification after its applicant has been replaced', function () {
    $application = ActivityApplication::factory()->approved($this->owner)->create([
        'activity_id' => $this->activity->id,
        'user_id' => $this->member->id,
        'selected_character_id' => $this->character->id,
        'applicant_lodestone_id' => $this->character->lodestone_id,
    ]);
    $this->slot->update(['assigned_character_id' => Character::factory()->create()->id, 'is_trapper' => true]);
    (new DispatchRosterPublishedAssignmentNotificationJob($this->slot->id, $application->id, null, $this->owner->id))
        ->handle(app(AssignmentNotificationService::class));
    expect(NotificationEvent::count())->toBe(0);
    Http::assertNothingSent();
});
