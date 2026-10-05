<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPerson extends Model
{
    protected $table = 'media_person';

    protected $fillable = ['media_id', 'person_id', 'user_id', 'x', 'y', 'w', 'h', 'source'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
