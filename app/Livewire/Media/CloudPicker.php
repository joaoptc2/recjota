<?php

declare(strict_types=1);

namespace App\Livewire\Media;

use App\Actions\Media\ImportCloudFile;
use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Integrations\Cloud\CloudStorageRegistry;
use App\Services\Integrations\Cloud\CloudTokenManager;
use App\Support\DataObjects\CloudFile;
use App\Support\Enums\CloudProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Seletor de arquivos do Google Drive e do OneDrive dentro da biblioteca
 * (Seções 7.2 e 7.3).
 *
 * OneDrive: navegação pelas pastas via Microsoft Graph, no servidor — serve
 * a contas pessoais e corporativas sem depender do File Picker. Google
 * Drive: com o escopo drive.file o app só enxerga o que o usuário escolheu,
 * então a escolha é pelo Google Picker (no navegador, com token entregue pelo
 * servidor) e a lista mostra o que já foi escolhido antes.
 */
class CloudPicker extends Component
{
    public Client $client;

    /** Pasta da biblioteca em que o arquivo importado vai entrar. */
    public ?int $folderId = null;

    /** Provedor aberto (valor de CloudProvider) ou null quando fechado. */
    public ?string $provider = null;

    public ?int $connectionId = null;

    public ?string $cloudFolderId = null;

    /** @var array<int, array{id: ?string, name: string}> */
    public array $breadcrumbs = [];

    public string $feedback = '';

    public function mount(Client $client, ?int $folderId = null): void
    {
        $this->client = $client;
        $this->folderId = $folderId;
    }

    /** @return Collection<int, CloudConnection> */
    #[Computed]
    public function connections(): Collection
    {
        return CloudConnection::query()
            ->where('client_id', $this->client->getKey())
            ->orderBy('provider')
            ->orderBy('account_email')
            ->get();
    }

    /** @return Collection<int, CloudConnection> */
    public function connectionsFor(CloudProvider $provider): Collection
    {
        return $this->connections()->filter(fn (CloudConnection $c) => $c->provider === $provider)->values();
    }

    public function open(string $provider): void
    {
        $provedor = CloudProvider::tryFrom($provider);

        if ($provedor === null) {
            return;
        }

        $this->authorize('create', MediaAsset::class);

        $this->resetErrorBag();
        $this->feedback = '';
        $this->provider = $provedor->value;
        $this->connectionId = $this->connectionsFor($provedor)->first(fn (CloudConnection $c) => ! $c->needsReconnection())?->getKey()
            ?? $this->connectionsFor($provedor)->first()?->getKey();
        $this->goToRoot();
    }

    public function close(): void
    {
        $this->provider = null;
        $this->connectionId = null;
        $this->goToRoot();
        $this->resetErrorBag();
    }

    public function updatedConnectionId(): void
    {
        $this->goToRoot();
        $this->resetErrorBag();
    }

    public function browse(string $folderId, string $name): void
    {
        $this->cloudFolderId = $folderId;
        $this->breadcrumbs[] = ['id' => $folderId, 'name' => $name];
        unset($this->items);
    }

    public function up(int $index): void
    {
        $this->breadcrumbs = array_slice($this->breadcrumbs, 0, $index + 1);
        $this->cloudFolderId = $this->breadcrumbs[$index]['id'] ?? null;
        unset($this->items);
    }

    /** @return array<int, CloudFile> */
    #[Computed]
    public function items(): array
    {
        $conexao = $this->connection();

        if ($conexao === null || $conexao->needsReconnection()) {
            return [];
        }

        try {
            $token = app(CloudTokenManager::class)->accessToken($conexao);

            return app(CloudStorageRegistry::class)->for($conexao->provider)->listChildren($token, $this->cloudFolderId);
        } catch (CloudApiException $e) {
            $this->addError('cloud', $e->actionableMessage());

            return [];
        }
    }

    /** Importa um item da lista (OneDrive) ou um escolhido no Google Picker. */
    public function import(string $fileId): void
    {
        $this->authorize('create', MediaAsset::class);
        $this->resetErrorBag();
        $this->feedback = '';

        $conexao = $this->connection();

        if ($conexao === null) {
            $this->addError('cloud', 'Escolha uma conexão antes de importar.');

            return;
        }

        $this->authorize('view', $conexao);

        try {
            $asset = app(ImportCloudFile::class)($conexao, $fileId, $this->folderId, auth()->id());
        } catch (CloudApiException $e) {
            $this->addError('cloud', $e->actionableMessage());

            return;
        } catch (RuntimeException $e) {
            $this->addError('cloud', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('cloud', 'Não foi possível importar o arquivo agora. Tente de novo; se persistir, fale com o suporte.');

            return;
        }

        $this->feedback = sprintf('"%s" entrou na biblioteca.', $asset->filename);
        $this->dispatch('midia-importada', mediaId: $asset->getKey());
        unset($this->items);
    }

    public function connection(): ?CloudConnection
    {
        if ($this->connectionId === null) {
            return null;
        }

        return $this->connections()->firstWhere('id', $this->connectionId);
    }

    public function activeProvider(): ?CloudProvider
    {
        return $this->provider === null ? null : CloudProvider::tryFrom($this->provider);
    }

    public function render(): View
    {
        return view('livewire.media.cloud-picker');
    }

    private function goToRoot(): void
    {
        $this->cloudFolderId = null;
        $this->breadcrumbs = [['id' => null, 'name' => 'Início']];
        unset($this->items);
    }
}
