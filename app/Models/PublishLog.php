<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Enums\PublishStage;
use Database\Factories\PublishLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro imutável de cada chamada do motor de publicação (Seção 8).
 * request/response nunca guardam token inteiro: o PublishLogger mascara antes
 * de gravar.
 */
class PublishLog extends Model
{
    /** @use HasFactory<PublishLogFactory> */
    use HasFactory;

    use Prunable;

    /** Log de publicação vale para diagnóstico recente; além disto só ocupa espaço (Seção 10). */
    public const RETENTION_DAYS = 90;

    public const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'attempt',
        'stage',
        'request',
        'response',
        'succeeded',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'stage' => PublishStage::class,
            'request' => 'array',
            'response' => 'array',
            'succeeded' => 'boolean',
            'attempt' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
