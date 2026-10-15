<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedElementLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_element_id',
        'old_value',
        'new_value',
        'has_changed',
        'status',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'has_changed' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function trackedElement(): BelongsTo
    {
        return $this->belongsTo(TrackedElement::class);
    }
}
