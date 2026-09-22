<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $inicio = now()->subMonth()->startOfMonth();

        return [
            'client_id' => Client::factory(),
            'period_start' => $inicio->toDateString(),
            'period_end' => $inicio->copy()->endOfMonth()->toDateString(),
            'path' => 'clients/1/relatorios/exemplo.pdf',
            'size_bytes' => 48_000,
            'generated_at' => now(),
        ];
    }
}
