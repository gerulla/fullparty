<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Activity extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_ASSIGNED,
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
        self::STATUS_COMPLETE,
        self::STATUS_CANCELLED,
    ];

    public const ARCHIVED_STATUSES = [
        self::STATUS_COMPLETE,
        self::STATUS_CANCELLED,
    ];

    public const MODERATOR_ONLY_STATUSES = [
        self::STATUS_DRAFT,
    ];

    public const APPLICATION_OPEN_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_ASSIGNED,
        self::STATUS_UPCOMING,
    ];

    public const ASSIGNABLE_STATUSES = [
        self::STATUS_SCHEDULED,
    ];

    public const SCHEDULABLE_STATUSES = [
        self::STATUS_DRAFT,
    ];

    public const COMPLETABLE_STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
    ];

    public const CANCELLABLE_STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
    ];

    public const DELETABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
    ];

    public const INTENSITY_CASUAL = 'casual';

    public const INTENSITY_MIDCORE = 'midcore';

    public const INTENSITY_HARDCORE = 'hardcore';

    public const INTENSITIES = [
        self::INTENSITY_CASUAL,
        self::INTENSITY_MIDCORE,
        self::INTENSITY_HARDCORE,
    ];

    public const RUN_STYLE_PROGRESSION = 'progression';

    public const RUN_STYLE_CLEAR = 'clear';

    public const RUN_STYLE_RECLEAR = 'reclear';

    public const RUN_STYLE_FARM = 'farm';

    public const RUN_STYLE_MARATHON = 'marathon';

    public const RUN_STYLE_SPEEDRUN = 'speedrun';

    public const RUN_STYLE_PRACTICE = 'practice';

    public const RUN_STYLE_BLIND = 'blind';

    public const RUN_STYLE_CHAOS = 'chaos';

    public const RUN_STYLES = [
        self::RUN_STYLE_PROGRESSION,
        self::RUN_STYLE_CLEAR,
        self::RUN_STYLE_RECLEAR,
        self::RUN_STYLE_FARM,
        self::RUN_STYLE_MARATHON,
        self::RUN_STYLE_SPEEDRUN,
        self::RUN_STYLE_PRACTICE,
        self::RUN_STYLE_BLIND,
        self::RUN_STYLE_CHAOS,
    ];

    public const TITLE_MAX_LENGTH = 255;

    public const DESCRIPTION_MAX_LENGTH = 5000;

    public const NOTES_MAX_LENGTH = 5000;

    public const PROGRESS_NOTES_MAX_LENGTH = 5000;

    public const DURATION_MIN_HOURS = 1.0;

    public const DURATION_MAX_HOURS = 24.0;

    public const DURATION_STEP_HOURS = 0.5;

    public const DEFAULT_DURATION_HOURS = 2.0;

    public const SETTING_CANCELLATION_REASON = 'cancellation_reason';

    protected $fillable = [
        'group_id',
        'activity_type_id',
        'activity_type_version_id',
        'organized_by_user_id',
        'organized_by_character_id',
        'status',
        'title',
        'description',
        'notes',
        'starts_at',
        'duration_hours',
        'datacenter',
        'intensity',
        'min_item_level',
        'beginner_friendly',
        'run_style',
        'target_prog_point_key',
        'is_public',
        'needs_application',
        'allow_guest_applications',
        'secret_key',
        'settings',
        'progress_entry_mode',
        'progress_link_url',
        'progress_notes',
        'furthest_progress_key',
        'furthest_progress_percent',
        'is_completed',
        'completed_at',
        'progress_recorded_by_user_id',
        'progress_recorded_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'duration_hours' => 'float',
        'min_item_level' => 'integer',
        'beginner_friendly' => 'boolean',
        'is_public' => 'boolean',
        'needs_application' => 'boolean',
        'allow_guest_applications' => 'boolean',
        'settings' => 'array',
        'furthest_progress_percent' => 'decimal:2',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'progress_recorded_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function activityTypeVersion(): BelongsTo
    {
        return $this->belongsTo(ActivityTypeVersion::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organized_by_user_id');
    }

    public function organizerCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'organized_by_character_id');
    }

    public function progressRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'progress_recorded_by_user_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ActivitySlot::class)->orderBy('sort_order');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ActivityApplication::class);
    }

    public function partyFinderInfo(): HasOne
    {
        return $this->hasOne(ActivityPartyFinderInfo::class);
    }

    public function progressMilestones(): HasMany
    {
        return $this->hasMany(ActivityProgressMilestone::class)->orderBy('sort_order');
    }

    public function slotAssignments(): HasMany
    {
        return $this->hasMany(ActivitySlotAssignment::class)->latest('assigned_at');
    }

    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_saves')
            ->withTimestamps();
    }

    public function isArchived(): bool
    {
        return in_array($this->status, self::ARCHIVED_STATUSES, true);
    }

    public static function isArchivedStatus(?string $status): bool
    {
        return in_array($status, self::ARCHIVED_STATUSES, true);
    }

    public static function isModeratorOnlyStatus(?string $status): bool
    {
        return in_array($status, self::MODERATOR_ONLY_STATUSES, true);
    }

    public function acceptsApplications(): bool
    {
        if (! self::isAcceptingApplicationsStatus($this->status)) {
            return false;
        }

        return $this->starts_at === null || $this->starts_at->isFuture();
    }

    public static function isAcceptingApplicationsStatus(?string $status): bool
    {
        return in_array($status, self::APPLICATION_OPEN_STATUSES, true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    public function canBeDeleted(): bool
    {
        return in_array($this->status, self::DELETABLE_STATUSES, true);
    }

    public function canBeMarkedAssigned(): bool
    {
        return in_array($this->status, self::ASSIGNABLE_STATUSES, true);
    }

    public function canBeScheduled(): bool
    {
        return in_array($this->status, self::SCHEDULABLE_STATUSES, true);
    }

    public function canBeCompleted(): bool
    {
        return in_array($this->status, self::COMPLETABLE_STATUSES, true);
    }

    public function cancellationReason(): ?string
    {
        $reason = ($this->settings ?? [])[self::SETTING_CANCELLATION_REASON] ?? null;

        return filled($reason) ? (string) $reason : null;
    }

    public function resolvedCancellationReason(): ?string
    {
        $storedReason = $this->cancellationReason();

        if ($storedReason !== null) {
            return $storedReason;
        }

        $reason = $this->relationLoaded('applications')
            ? $this->applications
                ->where('status', ActivityApplication::STATUS_CANCELLED)
                ->pluck('review_reason')
                ->filter(fn ($value) => filled($value))
                ->first()
            : $this->applications()
                ->where('status', ActivityApplication::STATUS_CANCELLED)
                ->whereNotNull('review_reason')
                ->value('review_reason');

        return filled($reason) ? (string) $reason : null;
    }

    public static function generateSecretKey(): string
    {
        do {
            $key = Str::random(40);
        } while (self::query()->where('secret_key', $key)->exists());

        return $key;
    }
}
