<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Webhooks\ProcessInstagramWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Instagram (Seção 7.1.6).
 *
 * GET: a Meta confirma a assinatura do webhook com hub.challenge.
 * POST: cada evento chega assinado com HMAC-SHA256 do corpo bruto usando o
 * app secret. Sem assinatura válida, 403 — e nada é enfileirado. Com ela,
 * o payload vai para a fila e a resposta sai na hora: a Meta desliga
 * webhooks que demoram, e a hospedagem derruba requisições longas (R5).
 */
class InstagramWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $esperado = (string) config('services.instagram.webhook_verify_token', '');
        $recebido = (string) $request->query('hub_verify_token', '');

        if (
            $esperado === ''
            || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals($esperado, $recebido)
        ) {
            Log::warning('Webhook do Instagram: verificação recusada', ['ip' => $request->ip()]);

            return response('Token de verificação inválido.', 403)->header('Content-Type', 'text/plain');
        }

        return response((string) $request->query('hub_challenge', ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            Log::warning('Webhook do Instagram: assinatura ausente ou inválida', ['ip' => $request->ip()]);

            return response('Assinatura inválida.', 403)->header('Content-Type', 'text/plain');
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('Corpo inválido.', 400)->header('Content-Type', 'text/plain');
        }

        ProcessInstagramWebhookJob::dispatch($payload);

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }

    /** X-Hub-Signature-256: "sha256=<hex do HMAC-SHA256 do corpo bruto com o app secret>". */
    private function signatureIsValid(Request $request): bool
    {
        $segredo = (string) config('services.instagram.app_secret', '');
        $cabecalho = (string) $request->header('X-Hub-Signature-256', '');

        if ($segredo === '' || ! str_starts_with($cabecalho, 'sha256=')) {
            return false;
        }

        $esperada = hash_hmac('sha256', $request->getContent(), $segredo);

        return hash_equals($esperada, substr($cabecalho, 7));
    }
}
