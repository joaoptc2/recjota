<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Services\Media\PublicMediaBridge;
use App\Support\Enums\MediaSource;
use App\Support\Exceptions\MediaBridgeFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Ponte de mídia pública (Seção 7.4): cópia temporária com nome imprevisível,
 * extensão pelo MIME real, prazo de validade e expurgo.
 */
class PublicMediaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->pasta = sys_get_temp_dir().'/ponte-'.uniqid('', true);

        config([
            'agency.media_bridge.path' => $this->pasta,
            'agency.media_bridge.url' => 'https://agencia.example.com/media-tmp',
            'agency.media_bridge.ttl_hours' => 6,
        ]);

        $this->cliente = Client::factory()->configured()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pasta);

        parent::tearDown();
    }

    private function ponte(): PublicMediaBridge
    {
        return app(PublicMediaBridge::class);
    }

    /** Grava conteúdo no disco de origem e devolve o asset apontando para ele. */
    private function assetCom(string $conteudo, string $nome = 'foto.jpg', array $atributos = []): MediaAsset
    {
        $relativo = 'clients/'.$this->cliente->getKey().'/midia/'.$nome;
        Storage::disk('local')->put($relativo, $conteudo);

        return MediaAsset::factory()->create(array_merge([
            'client_id' => $this->cliente->getKey(),
            'filename' => $nome,
            'local_path' => $relativo,
        ], $atributos));
    }

    private function jpeg(): string
    {
        return (string) (new ImageManager(new Driver))->create(64, 80)->fill('#4f46e5')->toJpeg();
    }

    private function png(): string
    {
        return (string) (new ImageManager(new Driver))->create(64, 80)->fill('#4f46e5')->toPng();
    }

    /** Cabeçalho ISO BMFF suficiente para o finfo reconhecer video/mp4. */
    private function mp4(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\0", 64);
    }

    public function test_publish_cria_arquivo_com_nome_imprevisivel_extensao_pelo_mime_e_prazo(): void
    {
        // O nome original mente: é PNG rotulado como .jpg.
        $asset = $this->assetCom($this->png(), 'na-verdade-png.jpg', ['mime_type' => 'image/jpeg']);

        $url = $this->ponte()->publish($asset);

        $asset->refresh();

        $this->assertMatchesRegularExpression('#^'.$asset->ulid.'/[0-9a-f]{32}\.png$#', $asset->public_temp_path);
        $this->assertStringNotContainsString('na-verdade-png', $asset->public_temp_path);
        $this->assertSame('https://agencia.example.com/media-tmp/'.$asset->public_temp_path, $url);

        $arquivo = $this->pasta.'/'.$asset->public_temp_path;
        $this->assertFileExists($arquivo);
        $this->assertSame($this->png(), file_get_contents($arquivo));

        $this->assertNotNull($asset->public_temp_expires_at);
        $this->assertEqualsWithDelta(6 * 3600, $asset->public_temp_expires_at->diffInSeconds(now(), true), 5);
    }

    public function test_publish_aceita_jpeg_e_mp4_com_a_extensao_certa(): void
    {
        $jpeg = $this->assetCom($this->jpeg(), 'foto.jpeg');
        $video = $this->assetCom($this->mp4(), 'clipe.bin', ['mime_type' => 'video/mp4']);

        $this->ponte()->publish($jpeg);
        $this->ponte()->publish($video);

        $this->assertStringEndsWith('.jpg', $jpeg->fresh()->public_temp_path);
        $this->assertStringEndsWith('.mp4', $video->fresh()->public_temp_path);
    }

    public function test_url_em_http_e_reescrita_para_https(): void
    {
        config(['agency.media_bridge.url' => 'http://agencia.example.com/media-tmp/']);

        $asset = $this->assetCom($this->jpeg());

        $this->assertStringStartsWith('https://agencia.example.com/media-tmp/'.$asset->ulid.'/', $this->ponte()->publish($asset));
    }

    public function test_publish_reaproveita_copia_valida_sem_gravar_de_novo(): void
    {
        $asset = $this->assetCom($this->jpeg());

        $primeira = $this->ponte()->publish($asset);
        $caminho = $asset->fresh()->public_temp_path;

        $segunda = $this->ponte()->publish($asset->fresh());

        $this->assertSame($primeira, $segunda);
        $this->assertSame($caminho, $asset->fresh()->public_temp_path);
        $this->assertCount(1, File::files($this->pasta.'/'.$asset->ulid));
    }

    public function test_publish_regrava_quando_a_copia_registrada_venceu_ou_sumiu(): void
    {
        $asset = $this->assetCom($this->jpeg());

        $this->ponte()->publish($asset);
        $antigo = $asset->fresh()->public_temp_path;

        // Vencida: regrava com outro nome.
        $asset->forceFill(['public_temp_expires_at' => now()->subMinute()])->save();
        $this->ponte()->publish($asset->fresh());
        $novo = $asset->fresh()->public_temp_path;

        $this->assertNotSame($antigo, $novo);
        $this->assertFileDoesNotExist($this->pasta.'/'.$antigo);
        $this->assertFileExists($this->pasta.'/'.$novo);

        // Arquivo sumiu do disco (restauração de backup): regrava também.
        unlink($this->pasta.'/'.$novo);
        $this->ponte()->publish($asset->fresh());

        $this->assertFileExists($this->pasta.'/'.$asset->fresh()->public_temp_path);
    }

    public function test_mime_invalido_e_recusado_e_nada_e_gravado(): void
    {
        $asset = $this->assetCom("isto é texto puro, não uma imagem\n", 'texto-renomeado.jpg');

        try {
            $this->ponte()->publish($asset);
            $this->fail('Esperava MediaBridgeFailed para MIME text/plain.');
        } catch (MediaBridgeFailed $e) {
            $this->assertStringContainsString('texto-renomeado.jpg', $e->getMessage());
            $this->assertStringContainsString('text/plain', $e->getMessage());
            $this->assertStringContainsString('image/jpeg', $e->getMessage());
        }

        $asset->refresh();
        $this->assertNull($asset->public_temp_path);
        $this->assertNull($asset->public_temp_expires_at);
        $this->assertDirectoryDoesNotExist($this->pasta.'/'.$asset->ulid);
    }

    public function test_origem_ausente_ou_nao_suportada_gera_erro_acionavel(): void
    {
        $semArquivo = MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'local_path' => 'clients/x/nao-existe.jpg',
        ]);

        $this->expectException(MediaBridgeFailed::class);
        $this->expectExceptionMessage('Envie a mídia novamente');
        $this->ponte()->publish($semArquivo);
    }

    public function test_origem_externa_ainda_nao_e_suportada(): void
    {
        $drive = MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'source' => MediaSource::GoogleDrive,
            'external_file_id' => 'abc',
            'local_path' => null,
        ]);

        $this->expectException(MediaBridgeFailed::class);
        $this->expectExceptionMessage('Google Drive');
        $this->ponte()->publish($drive);
    }

    public function test_release_remove_arquivo_pasta_e_limpa_colunas(): void
    {
        $asset = $this->assetCom($this->jpeg());
        $this->ponte()->publish($asset);
        $asset->refresh();

        $arquivo = $this->pasta.'/'.$asset->public_temp_path;
        $this->assertFileExists($arquivo);

        $this->ponte()->release($asset);

        $this->assertFileDoesNotExist($arquivo);
        $this->assertDirectoryDoesNotExist($this->pasta.'/'.$asset->ulid);
        $this->assertNull($asset->fresh()->public_temp_path);
        $this->assertNull($asset->fresh()->public_temp_expires_at);

        // Chamar de novo, sem cópia, não é erro.
        $this->ponte()->release($asset->fresh());
    }

    public function test_purge_expired_remove_so_as_vencidas_de_todos_os_clientes(): void
    {
        $outroCliente = Client::factory()->configured()->create();

        $vencida = $this->assetCom($this->jpeg(), 'vencida.jpg');
        $valida = $this->assetCom($this->jpeg(), 'valida.jpg');
        $vencidaDeOutro = MediaAsset::factory()->create([
            'client_id' => $outroCliente->getKey(),
            'local_path' => 'clients/'.$outroCliente->getKey().'/midia/outra.jpg',
        ]);
        Storage::disk('local')->put($vencidaDeOutro->local_path, $this->jpeg());

        foreach ([$vencida, $valida, $vencidaDeOutro] as $asset) {
            $this->ponte()->publish($asset);
        }

        $vencida->forceFill(['public_temp_expires_at' => now()->subMinute()])->save();
        $vencidaDeOutro->forceFill(['public_temp_expires_at' => now()])->save();

        // Escopo travado num cliente não pode esconder a cópia do outro.
        app(TenantContext::class)->restrictToClient($this->cliente);

        $this->assertSame(2, $this->ponte()->purgeExpired());

        $this->assertNull($vencida->fresh()->public_temp_path);
        $this->assertNull($vencidaDeOutro->fresh()->public_temp_path);
        $this->assertDirectoryDoesNotExist($this->pasta.'/'.$vencida->ulid);
        $this->assertDirectoryDoesNotExist($this->pasta.'/'.$vencidaDeOutro->ulid);

        $this->assertNotNull($valida->fresh()->public_temp_path);
        $this->assertFileExists($this->pasta.'/'.$valida->fresh()->public_temp_path);

        $this->assertSame(0, $this->ponte()->purgeExpired());
    }

    public function test_sweep_orphans_remove_pastas_sem_asset_e_preserva_as_validas(): void
    {
        $valido = $this->assetCom($this->jpeg());
        $this->ponte()->publish($valido);

        // Asset que existe mas não tem cópia registrada: pasta é lixo.
        $semCopia = $this->assetCom($this->jpeg(), 'sem-copia.jpg');
        File::ensureDirectoryExists($this->pasta.'/'.$semCopia->ulid);
        file_put_contents($this->pasta.'/'.$semCopia->ulid.'/'.str_repeat('a', 32).'.jpg', 'x');

        // Pasta de ulid que nunca existiu (job morreu, asset apagado à força).
        $fantasma = $this->pasta.'/01J0000000000000000000FANT';
        File::ensureDirectoryExists($fantasma);
        file_put_contents($fantasma.'/'.str_repeat('b', 32).'.mp4', 'x');

        $this->assertSame(2, $this->ponte()->sweepOrphans());

        $this->assertDirectoryDoesNotExist($this->pasta.'/'.$semCopia->ulid);
        $this->assertDirectoryDoesNotExist($fantasma);
        $this->assertFileExists($this->pasta.'/'.$valido->fresh()->public_temp_path);
        $this->assertFileExists($this->pasta.'/.htaccess');

        $this->assertSame(0, $this->ponte()->sweepOrphans());
    }

    public function test_sweep_orphans_ignora_pastas_que_nao_sao_ulid_e_recusa_pasta_do_sistema(): void
    {
        $manual = $this->pasta.'/backup-manual';
        File::ensureDirectoryExists($manual);
        file_put_contents($manual.'/nota.txt', 'não sou da ponte');

        $this->assertSame(0, $this->ponte()->sweepOrphans());
        $this->assertDirectoryExists($manual);

        foreach ([base_path(), public_path(), storage_path(), storage_path('app')] as $protegido) {
            config(['agency.media_bridge.path' => $protegido]);

            try {
                $this->ponte()->sweepOrphans();
                $this->fail('Deveria recusar varrer '.$protegido);
            } catch (MediaBridgeFailed $e) {
                $this->assertStringContainsString('pasta do sistema', $e->getMessage());
            }
        }
    }

    public function test_sweep_orphans_sem_diretorio_nao_falha(): void
    {
        $this->assertDirectoryDoesNotExist($this->pasta);
        $this->assertSame(0, $this->ponte()->sweepOrphans());
    }

    public function test_htaccess_e_criado_junto_com_o_diretorio(): void
    {
        $this->assertDirectoryDoesNotExist($this->pasta);

        $this->ponte()->publish($this->assetCom($this->jpeg()));

        $this->assertFileExists($this->pasta.'/.htaccess');
        $this->assertStringEqualsFile($this->pasta.'/.htaccess', file_get_contents(public_path('media-tmp/.htaccess')));
        $this->assertStringContainsString('Options -Indexes', file_get_contents($this->pasta.'/.htaccess'));
    }
}
