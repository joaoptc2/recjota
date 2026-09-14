<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\CalendarEventType;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use BelongsToClient;

    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'title',
        'type',
        'starts_at',
        'ends_at',
        'all_day',
        'attendees',
        'location',
        'external_calendar_id',
        'description',
        'is_suggestion',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => CalendarEventType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'is_suggestion' => 'boolean',
            'attendees' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
