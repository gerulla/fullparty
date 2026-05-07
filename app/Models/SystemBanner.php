<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemBanner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'message',
        'action_label',
        'action_url',
    ];
}
