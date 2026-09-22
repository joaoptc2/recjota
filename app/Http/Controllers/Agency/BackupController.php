<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download dos backups diários do banco (Seção 10). Só quem administra as
 * configurações baixa; o nome vem de uma allowlist estrita para que a rota
 * nunca sirva outro arquivo de storage.
 */
class BackupController extends Controller
{
    public function download(string $arquivo): BinaryFileResponse
    {
        $this->authorize('viewAny', Setting::class);

        abort_unless(preg_match('/^banco-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $arquivo) === 1, 404);

        $caminho = storage_path('app/backups/'.$arquivo);

        abort_unless(is_file($caminho), 404, 'Este backup não existe mais no servidor.');

        return response()->download($caminho, $arquivo, [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
