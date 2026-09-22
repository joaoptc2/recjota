<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** O PDF vive fora do webroot; quem decide se pode baixar é a Policy. */
class ReportDownloadController extends Controller
{
    public function __invoke(Report $report): StreamedResponse
    {
        $this->authorize('view', $report);

        abort_unless(Storage::disk('local')->exists($report->path), 404, 'O arquivo deste relatório não está mais no servidor. Gere-o de novo.');

        return Storage::disk('local')->download($report->path, $report->filename(), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
