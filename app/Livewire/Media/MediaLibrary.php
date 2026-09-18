<?php

declare(strict_types=1);

namespace App\Livewire\Media;

use App\Actions\Media\DeleteMediaAsset;
use App\Actions\Media\StoreUploadedMedia;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * Biblioteca de mídia (Seção 6.4).
 *
 * O upload do Livewire já chega em pedaços e com barra de progresso, o que
 * mantém cada requisição curta — requisito de ambiente, não de conforto (R5).
 */
class MediaLibrary extends Component
{
    use WithFileUploads;
    use WithPagination;

    public Client $client;

    /** Modo seleção: usado quando o composer abre a biblioteca. */
    public bool $picker = false;

    /** @var array<int, int> */
    public array $selected = [];

    public array $uploads = [];

    public string $search = '';

    public string $filterType = 'todos';

    public ?int $folderId = null;

    public function mount(Client $client, bool $picker = false, array $selected = []): void
    {
        $this->client = $client;
        $this->picker = $picker;
        $this->selected = $selected;
    }

    #[Computed]
    public function folders()
    {
        return MediaFolder::where('client_id', $this->client->getKey())
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get();
    }

    public function updatedUploads(): void
    {
        $this->authorize('create', MediaAsset::class);

        $acao = app(StoreUploadedMedia::class);

        foreach ($this->uploads as $arquivo) {
            try {
                $acao($this->client, $arquivo, $this->folderId);
            } catch (Throwable $e) {
                $this->addError('uploads', $e->getMessage());
            }
        }

        $this->uploads = [];
        unset($this->assets);
        $this->resetPage();
    }

    public function toggle(int $assetId): void
    {
        $indice = array_search($assetId, $this->selected, true);

        if ($indice !== false) {
            unset($this->selected[$indice]);
            $this->selected = array_values($this->selected);
        } else {
            $this->selected[] = $assetId;
        }

        $this->dispatch('midia-selecionada', mediaId: $assetId);
    }

    public function delete(int $assetId): void
    {
        $asset = MediaAsset::findOrFail($assetId);
        $this->authorize('delete', $asset);

        try {
            app(DeleteMediaAsset::class)($asset);
            unset($this->assets);
        } catch (DomainException $e) {
            $this->addError('uploads', $e->getMessage());
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function assets()
    {
        return MediaAsset::query()
            ->where('client_id', $this->client->getKey())
            ->when($this->folderId !== null, fn ($q) => $q->where('folder_id', $this->folderId))
            ->when($this->search !== '', fn ($q) => $q->where('filename', 'like', '%'.$this->search.'%'))
            ->when($this->filterType === 'imagem', fn ($q) => $q->where('mime_type', 'like', 'image/%'))
            ->when($this->filterType === 'video', fn ($q) => $q->where('mime_type', 'like', 'video/%'))
            ->latest()
            ->paginate(24);
    }

    public function render(): View
    {
        return view('livewire.media.media-library');
    }
}
