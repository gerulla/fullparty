<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupUserNoteAddendum extends Model
{
    use HasFactory;

    protected $table = 'group_user_note_addenda';

    protected $fillable = [
        'group_user_note_id',
        'author_user_id',
        'body',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(GroupUserNote::class, 'group_user_note_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
