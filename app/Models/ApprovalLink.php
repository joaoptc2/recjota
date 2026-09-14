<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use App\Support\Enums\ApprovalLinkScope;
use Database\Factories\ApprovalLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Link mágico de aprovação (Seção 6.6 / 10). O token em claro existe apenas no
 * e-mail enviado; o banco guarda somente o hash.
 */
class ApprovalLink extends Model
{
    use BelongsToClient;

    /** @use HasFactory<ApprovalLinkFactory> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = [
        'client_id',
        'post_id',
        'token',
        'scope',
        'period_start',
        'period_end',
        'expires_at',
        'max_uses',
        'used_count',
        'recipient_email',
        'recipient_name',
        'bound_version',
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'scope' => ApprovalLinkScope::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'bound_version' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Gera o token em claro (64 caracteres) que vai no e-mail. */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function matchesToken(string $plain): bool
    {
        return hash_equals($this->token, self::hashToken($plain));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->used_count < $this->max_uses
            && ! $this->isVersionStale();
    }

    /** Editar o post invalida links emitidos para a versão anterior. */
    public function isVersionStale(): bool
    {
        return $this->bound_version !== null
            && $this->post !== null
            && $this->post->current_version !== $this->bound_version;
    }

    public function reasonUnusable(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'Este link foi revogado pela agência.',
            $this->expires_at->isPast() => 'Este link expirou.',
            $this->used_count >= $this->max_uses => 'Este link atingiu o limite de usos.',
            $this->isVersionStale() => 'O post foi alterado depois que este link foi enviado.',
            default => null,
        };
    }
}
