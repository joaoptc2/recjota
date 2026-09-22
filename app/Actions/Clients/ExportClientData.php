<?php

declare(strict_types=1);

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use ZipArchive;

/**
 * Exportação de dados de um cliente (LGPD, Seção 10): tudo que o sistema
 * guarda sobre ele, em JSON legível dentro de um .zip, com as miniaturas
 * das mídias. Tokens e senhas nunca entram — o que sai é o que a pessoa
 * tem direito de ver, não o que daria acesso às contas.
 */
class ExportClientData
{
    /** @return string Caminho absoluto do .zip gerado (pasta temporária) */
    public function __invoke(Client $client, ?User $quem): string
    {
        $pasta = storage_path('app/exports');

        if (! is_dir($pasta) && ! @mkdir($pasta, 0750, true) && ! is_dir($pasta)) {
            throw new RuntimeException('Não foi possível criar storage/app/exports. Confira a permissão de escrita.');
        }

        $caminho = $pasta.DIRECTORY_SEPARATOR.sprintf('dados-%s-%s.zip', str($client->name)->slug(), now()->format('Ymd-His'));
        $zip = new ZipArchive;

        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo de exportação.');
        }

        $dados = $this->collect($client);

        foreach ($dados as $nome => $conteudo) {
            $zip->addFromString($nome.'.json', json_encode($conteudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $zip->addFromString('LEIA-ME.txt', $this->readme($client));

        foreach ($client->mediaAssets()->withoutGlobalScope(ClientScope::class)->withTrashed()->get() as $asset) {
            $thumb = $asset->local_thumb_path;

            if ($thumb !== null && Storage::disk('local')->exists($thumb)) {
                $zip->addFromString('miniaturas/'.$asset->ulid.'.webp', (string) Storage::disk('local')->get($thumb));
            }
        }

        $zip->close();

        activity('Client')
            ->performedOn($client)
            ->causedBy($quem)
            ->event('export')
            ->withProperties(['arquivo' => basename($caminho)])
            ->log('Dados do cliente exportados (LGPD)');

        return $caminho;
    }

    /** @return array<string, mixed> */
    private function collect(Client $client): array
    {
        $semSegredos = fn (Model $m) => collect($m->getAttributes())
            ->except(['access_token', 'refresh_token', 'password', 'remember_token', 'token_hash', 'raw'])
            ->all();

        $todos = fn ($relacao) => $relacao->withoutGlobalScope(ClientScope::class)->get()->map($semSegredos)->all();

        return [
            'cliente' => collect($client->getAttributes())->all(),
            'configuracoes' => $client->settings()->withoutGlobalScope(ClientScope::class)->first()?->getAttributes(),
            'pessoas' => $client->users()->get()->map(fn (User $u) => [
                'nome' => $u->name,
                'email' => $u->email,
                'tipo' => $u->type?->value,
                'papel_no_cliente' => $u->pivot->role,
                'ativo' => (bool) $u->is_active,
                'criado_em' => $u->created_at?->toIso8601String(),
            ])->all(),
            'contas_sociais' => $todos($client->socialAccounts()),
            'conexoes_nuvem' => $todos($client->cloudConnections()),
            'posts' => $client->posts()->withoutGlobalScope(ClientScope::class)->withTrashed()
                ->with(['versions', 'postMedia', 'approvals', 'approvalLinks', 'comments' => fn ($q) => $q->withoutGlobalScope(ClientScope::class)->withTrashed(), 'metrics', 'publishLogs'])
                ->get()
                ->map(fn ($post) => $semSegredos($post) + [
                    'versoes' => $post->versions->map($semSegredos)->all(),
                    'midias' => $post->postMedia->map($semSegredos)->all(),
                    'aprovacoes' => $post->approvals->map($semSegredos)->all(),
                    'links_de_aprovacao' => $post->approvalLinks->map(fn (Model $l) => collect($l->getAttributes())->except(['token_hash'])->all())->all(),
                    'comentarios' => $post->comments->map($semSegredos)->all(),
                    'metricas' => $post->metrics->map($semSegredos)->all(),
                    'log_de_publicacao' => $post->publishLogs->map($semSegredos)->all(),
                ])->all(),
            'campanhas' => $todos($client->campaigns()),
            'midias' => $client->mediaAssets()->withoutGlobalScope(ClientScope::class)->withTrashed()->get()->map($semSegredos)->all(),
            'tarefas' => $todos($client->tasks()),
            'briefings' => $todos($client->briefs()),
            'eventos_de_agenda' => $todos($client->calendarEvents()),
            'relatorios' => $todos($client->reports()),
            'metricas_da_conta' => $client->socialAccounts()->withoutGlobalScope(ClientScope::class)->get()
                ->flatMap(fn ($conta) => $conta->dailyMetrics()->get()->map($semSegredos))->values()->all(),
            'auditoria' => Activity::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('subject_type', Client::class)->where('subject_id', $client->getKey()))
                    ->orWhere('properties->client_id', $client->getKey()))
                ->orderBy('id')
                ->get()
                ->map(fn (Activity $a) => [
                    'quando' => $a->created_at?->toIso8601String(),
                    'evento' => $a->event,
                    'descricao' => $a->description,
                    'alvo' => class_basename((string) $a->subject_type).'#'.$a->subject_id,
                    'por' => $a->causer_id,
                    'propriedades' => $a->properties,
                ])->all(),
        ];
    }

    private function readme(Client $client): string
    {
        return implode("\n", [
            sprintf('Exportação de dados do cliente "%s" — %s (UTC)', $client->name, now()->toDateTimeString()),
            '',
            'Cada arquivo .json corresponde a uma tabela do sistema. Datas estão em UTC.',
            'Tokens de acesso a redes sociais e nuvens, senhas e hashes de links não são exportados.',
            'A pasta miniaturas/ traz as miniaturas dos arquivos da biblioteca; os originais',
            'vindos de Google Drive/OneDrive continuam na nuvem do cliente.',
            '',
            'Gerado por '.config('agency.name').'.',
        ]);
    }
}
