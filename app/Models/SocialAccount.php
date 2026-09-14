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
            'last_synced_at' => 'datetime',
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

    public function daysUntilTokenExpires(): ?int
    {
        return $this->token_expires_at?->diffInDays(now(), absolute: false) * -1;
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
