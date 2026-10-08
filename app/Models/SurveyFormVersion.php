<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyFormVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'published_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(SurveyForm::class, 'survey_form_id');
    }
}
