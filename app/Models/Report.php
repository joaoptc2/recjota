<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relatório mensal em PDF (Seção 6.8), gerado sob demanda ou pelo envio
 * automático do dia 1. O arquivo vive no disco `local`, fora do webroot, e
 * só sai pelo controller de download (Policy).
 */
class Report extends Model
{
    use BelongsToClient;

    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = [
        'client_id',
        'period_start',
        'period_end',
        'path',
        'size_bytes',
        'generated_by',
        'generated_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'size_bytes' => 'integer',
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** "Setembro/2026" — como aparece nas listas e no nome do arquivo. */
    public function periodLabel(): string
    {
        return ucfirst($this->period_start->locale('pt_BR')->translatedFormat('F/Y'));
    }

    public function filename(): string
    {
        return sprintf('relatorio-%s-%s.pdf', $this->period_start->format('Y-m'), str($this->client?->name ?? 'cliente')->slug());
    }
}
