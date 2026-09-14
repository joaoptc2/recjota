<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Exceptions\InvalidStateTransition;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Post extends Model
{
    use Auditable;
    use BelongsToClient;

    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    /** Colunas auditadas — tokens e senhas jamais entram aqui. */
    protected array $auditable = [
        'caption',
        'first_comment',
        'scheduled_at',
        'status',
        'approval_status',
        'social_account_id',
        'campaign_id',
        'type',
        'current_version',
        'approved_version',
    ];

    protected $fillable = [
        'client_id',
        'social_account_id',
        'campaign_id',
        'created_by',
        'sibling_group_id',
        'type',
        'caption',
        'first_comment',
        'scheduled_at',
        'status',
        'approval_status',
    ];

    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'status' => PostStatus::class,
            'approval_status' => ApprovalStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'locked_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'publish_attempts' => 'integer',
            'current_version' => 'integer',
            'approved_version' => 'integer',
            'last_error_is_permanent' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- relações

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_media')
            ->withPivot(['position', 'alt_text', 'thumbnail_offset_ms', 'cover_path'])
            ->withTimestamps()
            ->orderBy('post_media.position');
    }

    public function postMedia(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PostVersion::class)->orderByDesc('version');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->latest('requested_at');
    }

    public function approvalLinks(): HasMany
    {
        return $this->hasMany(ApprovalLink::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->latest();
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MetricPost::class);
    }

    // ------------------------------------------------------------ transições

    /**
     * Único caminho para mudar o status de um post.
     *
     * @throws InvalidStateTransition
     */
    public function transitionTo(PostStatus $target): self
    {
        $this->status->assertCanTransitionTo($target);

        $this->status = $target;

        return $this;
    }

    public function canTransitionTo(PostStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    // -------------------------------------------------------------- consultas

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [PostStatus::Approved->value, PostStatus::Scheduled->value])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', PostStatus::AwaitingClient->value);
    }

    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('scheduled_at', [$from, $to]);
    }

    // ----------------------------------------------------------------- estado

    /**
     * A aprovação vale para uma versão específica. Se o post foi editado depois,
     * a publicação precisa ser barrada (Seção 6.6).
     */
    public function isApprovedVersionCurrent(): bool
    {
        return $this->approved_version !== null
            && $this->approved_version === $this->current_version;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null
            && $this->locked_at->greaterThan(now()->subMinutes(15));
    }

    /** Chave de idempotência do motor de publicação (Seção 8.3). */
    public function idempotencyKey(): string
    {
        return sprintf('post:%d:v%d', $this->getKey(), $this->current_version);
    }

    public function scheduledAtFor(?string $timezone = null): ?Carbon
    {
        return $this->scheduled_at?->copy()->setTimezone(
            $timezone ?? $this->client?->displayTimezone() ?? config('agency.default_timezone')
        );
    }

    public function captionLength(): int
    {
        return mb_strlen((string) $this->caption);
    }

    public function hashtagCount(): int
    {
        return preg_match_all('/(?<!\w)#[\p{L}\p{N}_]+/u', (string) $this->caption);
    }

    public function mentionCount(): int
    {
        return preg_match_all('/(?<!\w)@[A-Za-z0-9._]+/', (string) $this->caption);
    }
}
