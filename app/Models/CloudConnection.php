<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\CloudProvider;
use App\Support\Enums\ConnectionStatus;
use Database\Factories\CloudConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conexão de um cliente com Google Drive ou OneDrive (Seções 7.2 e 7.3).
 * Guarda os tokens (criptografados, fora de qualquer serialização) para que
 * a ponte de mídia baixe o original na hora de publicar, sem ninguém logado.
 */
class CloudConnection extends Model
{
    use Auditable;
    use BelongsToClient;

    /** @use HasFactory<CloudConnectionFactory> */
    use HasFactory;

    use HasUlidKey;

    /** Colunas auditadas — tokens jamais entram aqui. */
    protected array $auditable = [
        'provider',
        'account_email',
        'account_name',
        'status',
        'token_expires_at',
        'root_folder_id',
    ];

    protected $fillable = [
        'client_id',
        'user_id',
        'provider',
        'account_id',
        'account_email',
        'account_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'root_folder_id',
        'scopes',
        'status',
        'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'provider' => CloudProvider::class,
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'consent_given_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'external_account_id');
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', ConnectionStatus::Connected->value);
    }

    public function scopeProvider(Builder $query, CloudProvider $provider): Builder
    {
        return $query->where('provider', $provider->value);
    }

    public function needsReconnection(): bool
    {
        return ! ($this->status?->isHealthy() ?? false);
    }

    public function isTokenExpiring(int $marginSeconds = 0): bool
    {
        return $this->token_expires_at === null
            || $this->token_expires_at->lessThanOrEqualTo(now()->addSeconds($marginSeconds));
    }

    /** Como a conexão aparece na interface: e-mail da conta, ou o nome, ou o provedor. */
    public function label(): string
    {
        return $this->account_email ?? $this->account_name ?? $this->provider->label();
    }
}
