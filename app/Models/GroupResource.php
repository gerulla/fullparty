<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GroupResource extends Model
{
    use HasFactory;

    public const ACCESS_LEVELS = ['everyone', 'moderator', 'admin'];

    public const MAX_COMMANDS = 15;

    protected $guarded = ['id'];

    protected $hidden = ['editing_token_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $resource) {
            // The earlier Home backfill also creates models before the UUID migration.
            if (Schema::hasColumn('group_resources', 'uuid')) {
                $resource->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return ['is_home' => 'boolean', 'working_copy' => 'array', 'version' => 'integer', 'is_pinned' => 'boolean', 'sort_order' => 'integer', 'editing_expires_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(GroupResourceCollection::class, 'collection_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(GroupResourceRevision::class, 'published_revision_id');
    }

    public function pendingRevision(): BelongsTo
    {
        return $this->belongsTo(GroupResourceRevision::class, 'pending_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(GroupResourceRevision::class, 'resource_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(GroupResourceCommand::class, 'resource_id');
    }

    public function activityTypes(): BelongsToMany
    {
        return $this->belongsToMany(ActivityType::class, 'group_resource_activity_type', 'resource_id', 'activity_type_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(GroupResourceTag::class, 'group_resource_tag', 'resource_id', 'tag_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(GroupResourceImage::class, 'resource_id');
    }
}
