<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\View\View;

/** Relatórios no portal (Seção 6.10): resultados do mês e PDFs para baixar. Nada técnico. */
class ReportController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', Report::class);

        return view('portal.reports', [
            'client' => auth()->user()->clients()->first(),
        ]);
    }
}
