<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\AccountType;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\SocialPlatform;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use Auditable;
    use BelongsToClient;

    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    /** Colunas auditadas — tokens e senhas jamais entram aqui. */
    protected array $auditable = [
        'username',
        'display_name',
        'account_type',
        'connection_status',
        'token_expires_at',
        'scopes',
    ];

    protected $fillable = [
        'client_id',
        'platform',
        'external_id',
        'username',
        'display_name',
        'avatar_url',
        'account_type',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'connection_status',
        'last_error',
        'last_synced_at',
    ];

    /** Tokens nunca são serializados para o cliente HTTP (Seção 10). */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'account_type' => AccountType::class,
            'connection_status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'consent_given_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(PublishingQuota::class);
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(MetricAccountDaily::class);
    }

    /** Usuário da agência que autorizou a conexão (consentimento LGPD). */
    public function consentGivenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_given_by');
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('connection_status', ConnectionStatus::Connected->value);
    }

    /** Tokens do Instagram valem 60 dias; renovamos abaixo de 10 (Seção 7.1.3). */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays($days));
    }

    /**
     * O Instagram só renova token com pelo menos 24h de idade (Seção 7.1.3).
     * Contas antigas, sem token_refreshed_at, usam updated_at como aproximação.
     */
    public function scopeTokenOlderThan(Builder $query, \DateTimeInterface $limit): Builder
    {
        return $query->where(function (Builder $q) use ($limit): void {
            $q->where('token_refreshed_at', '<=', $limit)
                ->orWhere(function (Builder $q) use ($limit): void {
                    $q->whereNull('token_refreshed_at')->where('updated_at', '<=', $limit);
                });
        });
    }

    /** Conta que precisa de nova autorização humana (token expirado/revogado/erro). */
    public function needsReconnection(): bool
    {
        return ! ($this->connection_status?->isHealthy() ?? false);
    }

    /** Dias até o token vencer (negativo quando já venceu). Carbon 3 devolve float. */
    public function daysUntilTokenExpires(): ?int
    {
        if ($this->token_expires_at === null) {
            return null;
        }

        return (int) round(now()->diffInDays($this->token_expires_at, absolute: false));
    }

    public function canPublishStories(): bool
    {
        return $this->account_type?->canPublishStories() ?? false;
    }

    public function handle(): string
    {
        return $this->username ? '@'.$this->username : ($this->display_name ?? 'conta');
    }
}
