<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportFeedbackRecipient extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(ReportFeedback::class, 'report_feedback_id');
    }
}
