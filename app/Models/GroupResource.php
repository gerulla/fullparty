<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GroupResource extends Model
{
    use HasFactory;

    public const ACCESS_LEVELS = ['everyone', 'moderator', 'admin'];

    protected $guarded = ['id'];

    protected $hidden = ['editing_token_hash'];

    protected function casts(): array
    {
        return ['working_copy' => 'array', 'version' => 'integer', 'is_pinned' => 'boolean', 'sort_order' => 'integer', 'editing_expires_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime'];
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

    public function command(): HasOne
    {
        return $this->hasOne(GroupResourceCommand::class, 'resource_id');
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
