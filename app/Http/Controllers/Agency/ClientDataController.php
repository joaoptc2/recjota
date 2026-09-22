<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Actions\Clients\DeleteClientData;
use App\Actions\Clients\ExportClientData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Direitos do titular (LGPD, Seção 10): exportar tudo que existe sobre um
 * cliente e excluir definitivamente. Exclusão exige a permissão
 * clients.delete (só o owner) e a digitação do nome do cliente.
 */
class ClientDataController extends Controller
{
    public function export(Request $request, Client $client, ExportClientData $exportar): BinaryFileResponse
    {
        $this->authorize('update', $client);

        $caminho = $exportar($client, $request->user());

        return response()->download($caminho, basename($caminho), ['Cache-Control' => 'no-store'])->deleteFileAfterSend();
    }

    public function destroy(Request $request, Client $client, DeleteClientData $excluir): RedirectResponse
    {
        $this->authorize('delete', $client);

        $dados = $request->validate(['confirmacao' => ['required', 'string']]);

        if (trim($dados['confirmacao']) !== $client->name) {
            return back()->withErrors([
                'confirmacao' => 'Digite o nome do cliente exatamente como aparece para confirmar a exclusão definitiva.',
            ]);
        }

        $resultado = $excluir($client, $request->user());

        return redirect()->route('painel.clients.index')->with('status', sprintf(
            'Cliente "%s" excluído definitivamente: %d arquivo(s) e %d usuário(s) do portal removidos. Isto não pode ser desfeito.',
            $client->name,
            $resultado['arquivos'],
            $resultado['pessoas'],
        ));
    }
}
