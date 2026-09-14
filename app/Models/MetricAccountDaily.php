<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MetricAccountDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Métricas diárias da conta. Colunas nulas significam "indisponível" — nunca
 * exibimos zero no lugar de um dado que a API não entregou (Seção 14).
 */
class MetricAccountDaily extends Model
{
    /** @use HasFactory<MetricAccountDailyFactory> */
    use HasFactory;

    protected $table = 'metrics_account_daily';

    protected $fillable = [
        'social_account_id',
        'date',
        'followers',
        'follows',
        'reach',
        'impressions',
        'profile_views',
        'website_clicks',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'raw' => 'array',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
