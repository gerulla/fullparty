<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportFeedback extends Model
{
    protected $table = 'report_feedback';

    protected $guarded = ['id'];

    public function recipients(): HasMany
    {
        return $this->hasMany(ReportFeedbackRecipient::class);
    }
}
