<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['respondent_key', 'submission_key'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'revision' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SurveyFormVersion::class, 'survey_form_version_id');
    }
}
