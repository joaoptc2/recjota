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
    /**
     * Novas tentativas automáticas depois da primeira falha (backoff
     * 1m/5m/15m/1h/4h): a tentativa de número MAX+1 é a última.
     */
    public const MAX_PUBLISH_ATTEMPTS = 5;

    /** Lock de publicação vence depois disto: job morto não trava o post. */
    public const LOCK_TTL_MINUTES = 15;

    /** O Instagram descarta um container não publicado depois de 24h. */
    public const CONTAINER_TTL_HOURS = 24;

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
            'container_created_at' => 'datetime',
            'container_next_check_at' => 'datetime',
            'publish_meta' => 'array',
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

    public function publishLogs(): HasMany
    {
        return $this->hasMany(PublishLog::class)->orderByDesc('id');
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

    /**
     * Posts que o despachante (posts:dispatch-due) pode enfileirar (Seção 6.7):
     * aprovados/agendados cuja hora chegou, falhos em retry cuja próxima
     * tentativa venceu, ou presos em publishing sem container (o job morreu
     * antes de falar com a API). Nunca falha permanente, nunca além do limite
     * de tentativas. Post sem conta social entra e falha com instrução clara,
     * em vez de ficar "agendado" para sempre sem ninguém saber por quê.
     */
    public function scopeEligibleForPublishing(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where(fn (Builder $q) => $q->due())
                ->orWhere(fn (Builder $q) => $q->retryDue())
                ->orWhere(fn (Builder $q) => $q->stuckPublishing());
        });
    }

    /**
     * Falhou de forma transitória e a janela de backoff já passou (Seção 8.3).
     * scheduled_at também precisa ter chegado: quem reagenda um post falho
     * para o futuro espera que ele saia na nova hora, não no próximo backoff.
     */
    public function scopeRetryDue(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Failed->value)
            ->where('last_error_is_permanent', false)
            ->where('publish_attempts', '<=', self::MAX_PUBLISH_ATTEMPTS)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
    }

    /**
     * Em publishing sem container nem mídia publicada: o job morreu entre o
     * lock e a criação do container. Combinado com unlocked() (lock vencido)
     * é seguro retomar — nada foi enviado à API.
     */
    public function scopeStuckPublishing(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Publishing->value)
            ->whereNull('external_container_id')
            ->whereNull('external_post_id');
    }

    /** Sem lock, ou com lock vencido (job que morreu no meio). */
    public function scopeUnlocked(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('locked_at')
                ->orWhere('locked_at', '<=', now()->subMinutes(self::LOCK_TTL_MINUTES));
        });
    }

    /** Publicando, com container criado e ainda sem mídia publicada. */
    public function scopeAwaitingContainer(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Publishing->value)
            ->whereNotNull('external_container_id')
            ->whereNull('external_post_id');
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
            && $this->locked_at->greaterThan(now()->subMinutes(self::LOCK_TTL_MINUTES));
    }

    /**
     * Lock pessimista contra publicação dupla (Seção 6.7). É um UPDATE
     * condicional: dois crons sobrepostos disputam a mesma linha e só um
     * ganha. Lock com mais de 15 minutos é de um job que morreu e pode ser
     * tomado.
     */
    public function acquirePublishLock(?int $userId = null): bool
    {
        $agora = now();

        $ganhou = static::query()
            ->withoutClientScope()
            ->whereKey($this->getKey())
            ->unlocked()
            ->update(['locked_at' => $agora, 'locked_by' => $userId]);

        if ($ganhou !== 1) {
            return false;
        }

        $this->forceFill(['locked_at' => $agora, 'locked_by' => $userId])->syncOriginal();

        return true;
    }

    /** Renova o lock de um job ainda vivo (checagens de container em sequência). */
    public function touchPublishLock(): void
    {
        $this->forceFill(['locked_at' => now()])->save();
    }

    public function releasePublishLock(): void
    {
        $this->forceFill(['locked_at' => null, 'locked_by' => null])->save();
    }

    /** O post ainda pode ser tentado de novo pelo motor (Seção 8.3)? */
    public function hasPublishAttemptsLeft(): bool
    {
        return $this->publish_attempts <= self::MAX_PUBLISH_ATTEMPTS;
    }

    /** Este post ainda tem um container aberto no Instagram (criado há menos de 24h)? */
    public function hasUsableContainer(): bool
    {
        return $this->external_container_id !== null
            && $this->container_created_at !== null
            && $this->container_created_at->greaterThan(now()->subHours(self::CONTAINER_TTL_HOURS));
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
