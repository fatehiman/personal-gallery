<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanJob extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'recursive' => 'boolean',
            'stop_requested' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function active(): ?self
    {
        return static::whereIn('status', ['queued', 'running'])->orderBy('id')->first();
    }
}
