<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupUserNote extends Model
{
    use HasFactory;

    public const BODY_MAX_LENGTH = 5000;

    public const ADDENDUM_MAX_LENGTH = 3000;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_COMMENDATION = 'commendation';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_COMMENDATION,
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_CRITICAL,
    ];

    protected $fillable = [
        'group_id',
        'user_id',
        'author_user_id',
        'severity',
        'body',
        'is_shared_with_groups',
    ];

    protected $casts = [
        'is_shared_with_groups' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(GroupUserNoteAddendum::class)->orderBy('created_at');
    }
}
