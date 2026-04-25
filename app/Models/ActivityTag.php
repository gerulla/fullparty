<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ActivityTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function activityTypes(): BelongsToMany
    {
        return $this->belongsToMany(ActivityType::class, 'activity_type_activity_tag')
            ->withTimestamps();
    }
}
