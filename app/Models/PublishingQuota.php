<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PublishingQuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache local da cota de publicação (Seção 7.1.5). Consultado antes de cada
 * publicação para reagendar em vez de tentar e falhar.
 */
class PublishingQuota extends Model
{
    /** @use HasFactory<PublishingQuotaFactory> */
    use HasFactory;

    protected $fillable = [
        'social_account_id',
        'window_start',
        'used_count',
        'quota_total',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'checked_at' => 'datetime',
            'used_count' => 'integer',
            'quota_total' => 'integer',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function remaining(): int
    {
        return max(0, $this->quota_total - $this->used_count);
    }

    public function isExhausted(): bool
    {
        return $this->remaining() === 0;
    }
}
