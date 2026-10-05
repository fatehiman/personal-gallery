<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolderAccess extends Model
{
    protected $fillable = ['user_id', 'title', 'path', 'sort'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
