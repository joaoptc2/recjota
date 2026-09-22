<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Models\User;
use App\Services\Media\PublicMediaBridge;
use App\Support\Enums\UserType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Exclusão definitiva de um cliente e de tudo que é dele (LGPD, Seção 10).
 *
 * Arquivos primeiro (originais, derivados, relatórios, logo, cópias na
 * ponte), depois as pessoas que só existiam por causa deste cliente, depois
 * o registro — as chaves estrangeiras em cascata levam o resto. Fica um
 * único registro de auditoria dizendo que a exclusão aconteceu, sem dados.
 */
class DeleteClientData
{
    public function __construct(private readonly PublicMediaBridge $ponte) {}

    /** @return array{arquivos: int, pessoas: int} */
    public function __invoke(Client $client, ?User $quem): array
    {
        $arquivos = 0;
        $disco = Storage::disk('local');

        foreach ($client->mediaAssets()->withoutGlobalScope(ClientScope::class)->withTrashed()->get() as $asset) {
            try {
                $this->ponte->release($asset);
            } catch (\Throwable) {
                // A ponte pode não existir nesta instalação; o cron de órfãos cobre.
            }

            foreach ([$asset->local_path, $asset->local_thumb_path, $asset->local_preview_path] as $caminho) {
                if ($caminho !== null && $disco->delete($caminho)) {
                    $arquivos++;
                }
            }
        }

        foreach ($client->reports()->withoutGlobalScope(ClientScope::class)->get() as $relatorio) {
            if ($disco->delete($relatorio->path)) {
                $arquivos++;
            }
        }

        if ($client->logo_path !== null && $disco->delete($client->logo_path)) {
            $arquivos++;
        }

        $disco->deleteDirectory(sprintf('clients/%d', $client->getKey()));

        $pessoas = 0;

        DB::transaction(function () use ($client, $quem, &$pessoas): void {
            // Usuários do portal que só pertenciam a este cliente vão junto.
            $exclusivos = $client->users()
                ->where('users.type', UserType::Client->value)
                ->get()
                ->filter(fn (User $u) => $u->clients()->count() === 1);

            foreach ($exclusivos as $usuario) {
                $usuario->clients()->detach();
                $usuario->notifications()->delete();
                $usuario->delete();
                $pessoas++;
            }

            // Auditoria com dados pessoais do cliente sai; fica só o registro da exclusão.
            Activity::query()
                ->where('subject_type', Client::class)
                ->where('subject_id', $client->getKey())
                ->delete();

            $nome = $client->name;
            $id = $client->getKey();

            $client->forceDelete();

            activity('Client')
                ->causedBy($quem)
                ->event('erase')
                ->withProperties(['client_id' => $id, 'nome' => $nome, 'pessoas_removidas' => $pessoas])
                ->log('Cliente e todos os seus dados excluídos definitivamente (LGPD)');
        });

        return ['arquivos' => $arquivos, 'pessoas' => $pessoas];
    }
}
