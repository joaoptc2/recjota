<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega de mídia com autorização.
 *
 * O arquivo vive fora do webroot; quem decide se ele pode ser visto é a Policy,
 * não a sorte de alguém não adivinhar o caminho.
 */
class MediaController extends Controller
{
    public function thumb(MediaAsset $asset): StreamedResponse
    {
        $this->authorize('view', $asset);

        return $this->stream($asset->local_thumb_path ?? $asset->local_path, 'image/webp');
    }

    public function preview(MediaAsset $asset): StreamedResponse
    {
        $this->authorize('view', $asset);

        return $this->stream($asset->local_preview_path ?? $asset->local_path, 'image/webp');
    }

    /** Arquivo original — usado no download e na ponte de mídia (Fase 4). */
    public function original(MediaAsset $asset): StreamedResponse
    {
        $this->authorize('view', $asset);

        return $this->stream($asset->local_path, $asset->mime_type ?? 'application/octet-stream');
    }

    public function logo(Client $client): StreamedResponse
    {
        $this->authorize('view', $client);

        return $this->stream($client->logo_path, 'image/webp');
    }

    private function stream(?string $caminho, string $mime): StreamedResponse
    {
        abort_if($caminho === null || ! Storage::disk('local')->exists($caminho), 404);

        return Storage::disk('local')->response($caminho, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
