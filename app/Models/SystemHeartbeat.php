<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SystemHeartbeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Batimento do cron (Seção 8.3). Sem isto, um cron quebrado passa dias
 * despercebido na hospedagem compartilhada.
 */
class SystemHeartbeat extends Model
{
    /** @use HasFactory<SystemHeartbeatFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'last_run_at',
        'last_duration_ms',
        'last_status',
        'last_message',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'last_duration_ms' => 'integer',
        ];
    }

    public function isStale(int $minutes = 20): bool
    {
        return $this->last_run_at === null
            || $this->last_run_at->lessThan(now()->subMinutes($minutes));
    }
}
