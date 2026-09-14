<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\DecisionChannel;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use BelongsToClient;

    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'post_id',
        'post_version',
        'requested_by',
        'requested_at',
        'due_at',
        'status',
        'decided_by',
        'decided_by_name',
        'decided_at',
        'decision_note',
        'decided_via',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'decided_via' => DecisionChannel::class,
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
            'reminded_at' => 'datetime',
            'post_version' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }

    public function scopeDueWithin(Builder $query, int $hours): Builder
    {
        return $query->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addHours($hours)]);
    }

    public function isOverdue(): bool
    {
        return $this->status === ApprovalStatus::Pending
            && $this->due_at !== null
            && $this->due_at->isPast();
    }
}
