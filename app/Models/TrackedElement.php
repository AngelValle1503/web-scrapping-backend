<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedElement extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'selector',
        'attribute',
        'current_value',
        'status',
        'error_message',
        'check_interval_minutes',
        'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'check_interval_minutes' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(TrackedElementLog::class)->orderByDesc('checked_at');
    }
}
