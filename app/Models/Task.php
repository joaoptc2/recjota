<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\TaskPriority;
use App\Support\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use BelongsToClient;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'title',
        'description',
        'assignee_id',
        'created_by',
        'due_at',
        'priority',
        'status',
        'checklist',
        'related_post_id',
        'related_campaign_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'checklist' => 'array',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function relatedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'related_post_id');
    }

    public function relatedCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'related_campaign_id');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->where('status', '!=', TaskStatus::Done->value);
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && $this->status !== TaskStatus::Done;
    }
}
