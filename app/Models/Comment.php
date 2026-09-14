<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use BelongsToClient;

    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'commentable_type',
        'commentable_id',
        'user_id',
        'guest_name',
        'guest_email',
        'body',
        'is_internal',
        'parent_id',
        'post_version',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'attachments' => 'array',
            'resolved_at' => 'datetime',
            'post_version' => 'integer',
        ];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Comentário interno JAMAIS chega ao usuário do tipo client (Seção 5). */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null || $user->isClient()) {
            return $query->where('is_internal', false);
        }

        return $query;
    }

    public function authorName(): string
    {
        return $this->user?->name ?? $this->guest_name ?? 'Convidado';
    }
}
