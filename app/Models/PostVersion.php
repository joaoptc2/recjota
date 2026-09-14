<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico imutável do post. Nunca atualizamos nem apagamos uma versão — é a
 * prova de qual conteúdo exatamente o cliente aprovou (Seção 6.6).
 */
class PostVersion extends Model
{
    /** @use HasFactory<PostVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'post_id',
        'version',
        'snapshot',
        'change_summary',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
