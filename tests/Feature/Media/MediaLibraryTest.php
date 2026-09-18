<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Livewire\Media\MediaLibrary;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Livewire\Livewire;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seedRoles();
        $this->cliente = Client::factory()->configured()->create();
        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $this->cliente));
    }

    /** Imagem real em disco: o validador olha o MIME de verdade, não o rótulo. */
    private function arquivoDeImagem(int $largura = 1080, int $altura = 1350): string
    {
        $caminho = sys_get_temp_dir().'/'.uniqid('img', true).'.jpg';

        (new ImageManager(new Driver))->create($largura, $altura)->fill('#4f46e5')->toJpeg()->save($caminho);

        return $caminho;
    }

    private function imagem(?string $caminho = null, string $nome = 'foto-do-cliente.jpg'): File
    {
        return new File($nome, fopen($caminho ?? $this->arquivoDeImagem(), 'r'));
    }

    public function test_upload_gera_miniatura_e_preview_e_guarda_o_original_fora_do_webroot(): void
    {
        Livewire::test(MediaLibrary::class, ['client' => $this->cliente])
            ->set('uploads', [$this->imagem()])
            ->assertHasNoErrors();

        $asset = MediaAsset::firstOrFail();

        $this->assertSame('foto-do-cliente.jpg', $asset->filename);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame(1080, $asset->width);
        $this->assertSame(1350, $asset->height);
        $this->assertNotNull($asset->checksum);

        Storage::disk('local')->assertExists($asset->local_path);
        Storage::disk('local')->assertExists($asset->local_thumb_path);
        Storage::disk('local')->assertExists($asset->local_preview_path);

        // Nada disso pode estar sob public/.
        $this->assertStringNotContainsString('public', $asset->local_path);
    }

    public function test_arquivo_identico_nao_ocupa_disco_duas_vezes(): void
    {
        $caminho = $this->arquivoDeImagem();

        Livewire::test(MediaLibrary::class, ['client' => $this->cliente])
            ->set('uploads', [$this->imagem($caminho)]);

        // Mesmo conteúdo, outro nome.
        Livewire::test(MediaLibrary::class, ['client' => $this->cliente])
            ->set('uploads', [$this->imagem($caminho, 'outro-nome.jpg')]);

        $this->assertSame(1, MediaAsset::count(), 'A duplicata por checksum deve reaproveitar a mídia existente.');
    }

    public function test_arquivo_de_tipo_nao_aceito_e_recusado_com_mensagem(): void
    {
        $texto = sys_get_temp_dir().'/'.uniqid('doc', true).'.txt';
        file_put_contents($texto, 'isto não é imagem');

        Livewire::test(MediaLibrary::class, ['client' => $this->cliente])
            ->set('uploads', [new File('contrato.txt', fopen($texto, 'r'))])
            ->assertHasErrors('uploads');

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_extensao_mentirosa_nao_engana_a_validacao(): void
    {
        // Arquivo de texto com nome .jpg: o MIME real é o que vale (Seção 10).
        $falso = sys_get_temp_dir().'/'.uniqid('fake', true).'.jpg';
        file_put_contents($falso, '<?php echo "oi";');

        Livewire::test(MediaLibrary::class, ['client' => $this->cliente])
            ->set('uploads', [new File('imagem.jpg', fopen($falso, 'r'))])
            ->assertHasErrors('uploads');

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_midia_de_outro_cliente_devolve_403_na_rota_de_entrega(): void
    {
        $outro = Client::factory()->configured()->create();
        $alheia = MediaAsset::factory()->create(['client_id' => $outro->getKey()]);

        $this->get(route('midia.thumb', $alheia))->assertForbidden();
        $this->get(route('midia.original', $alheia))->assertForbidden();
    }

    public function test_biblioteca_lista_apenas_a_midia_do_cliente(): void
    {
        MediaAsset::factory()->count(3)->create(['client_id' => $this->cliente->getKey()]);
        $outro = Client::factory()->configured()->create();
        MediaAsset::factory()->count(4)->create(['client_id' => $outro->getKey()]);

        $componente = Livewire::test(MediaLibrary::class, ['client' => $this->cliente]);

        $this->assertCount(3, $componente->instance()->assets());
    }

    public function test_midia_em_uso_nao_pode_ser_excluida(): void
    {
        $asset = MediaAsset::factory()->create(['client_id' => $this->cliente->getKey()]);
        $post = Post::factory()->create(['client_id' => $this->cliente->getKey()]);
        $post->media()->attach($asset->getKey(), ['position' => 0]);

        Livewire::test(MediaLibrary::class, ['client' => $this->cliente])
            ->call('delete', $asset->getKey())
            ->assertHasErrors('uploads');

        $this->assertDatabaseHas('media_assets', ['id' => $asset->getKey(), 'deleted_at' => null]);
    }
}
