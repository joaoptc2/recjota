<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Actions\Media\ImportCloudFile;
use App\Livewire\Media\CloudPicker;
use App\Livewire\Media\MediaLibrary;
use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Media\CloudTempStore;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\MediaSource;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Integrations\CloudFixtures;
use Tests\TestCase;

/**
 * Vinculação de arquivos do Drive/OneDrive à biblioteca (Seções 7.2/7.3):
 * o original fica na nuvem, aqui entram referência, MIME real, checksum e
 * miniatura. O seletor OneDrive navega pelas pastas via Graph.
 */
class CloudImportTest extends TestCase
{
    use CloudFixtures;
    use RefreshDatabase;

    private Client $cliente;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->configureCloud();
        Storage::fake('local');

        $this->cliente = Client::factory()->configured()->create();
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('agency.cloud_temp.path'));

        parent::tearDown();
    }

    public function test_importar_do_google_drive_grava_referencia_mime_real_checksum_e_miniatura_sem_guardar_o_original(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?alt=media*' => Http::response($this->jpegBinary(), 200, ['Content-Type' => 'image/jpeg']),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?fields*' => $this->cloudResponse('google', 'file'),
        ]);
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);

        $asset = app(ImportCloudFile::class)($conexao, self::GOOGLE_FILE_ID, null, $this->gestor->getKey());

        $this->assertSame(MediaSource::GoogleDrive, $asset->source);
        $this->assertSame(self::GOOGLE_FILE_ID, $asset->external_file_id);
        $this->assertSame($conexao->getKey(), $asset->external_account_id);
        $this->assertSame('banner-promocao.jpg', $asset->filename);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame(1080, $asset->width);
        $this->assertSame(1350, $asset->height);
        $this->assertNotNull($asset->checksum);
        $this->assertNull($asset->local_path, 'O original não fica no servidor (R8)');
        Storage::disk('local')->assertExists($asset->local_thumb_path);
        Storage::disk('local')->assertExists($asset->local_preview_path);

        // O download temporário foi apagado; a pasta fica (fora do webroot).
        $this->assertSame([], File::files((string) config('agency.cloud_temp.path')));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'alt=media') && $r->hasHeader('Authorization'));

        // Importar de novo devolve o mesmo asset sem baixar de novo.
        $deNovo = app(ImportCloudFile::class)($conexao, self::GOOGLE_FILE_ID, null, $this->gestor->getKey());
        $this->assertTrue($deNovo->is($asset));
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'alt=media'))->count());
    }

    public function test_pdf_pasta_e_arquivo_pesado_demais_sao_recusados_antes_de_baixar(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/1PdfNaoServeParaInstagram00000000000?fields*' => $this->cloudResponse('google', 'file_pdf'),
            'www.googleapis.com/drive/v3/files/pasta?fields*' => Http::response(['id' => 'pasta', 'name' => 'Fotos', 'mimeType' => 'application/vnd.google-apps.folder']),
            'www.googleapis.com/drive/v3/files/pesado?fields*' => Http::response(['id' => 'pesado', 'name' => 'filme.mp4', 'mimeType' => 'video/mp4', 'size' => (string) (300 * 1024 * 1024)]),
        ]);
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);
        $acao = app(ImportCloudFile::class);

        foreach ([
            '1PdfNaoServeParaInstagram00000000000' => 'não é imagem nem vídeo',
            'pasta' => 'é uma pasta',
            'pesado' => 'o limite para publicar no Instagram é 100 MB',
        ] as $id => $trecho) {
            try {
                $acao($conexao, $id);
                $this->fail('Deveria recusar '.$id);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($trecho, $e->getMessage());
            }
        }

        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'alt=media'))->count());
        $this->assertSame(0, MediaAsset::withoutGlobalScopes()->count());
    }

    public function test_arquivo_que_diz_ser_imagem_mas_nao_e_e_recusado_pelo_mime_real(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?alt=media*' => Http::response('%PDF-1.4 conteudo que nao e jpeg', 200),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?fields*' => $this->cloudResponse('google', 'file'),
        ]);
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);

        try {
            app(ImportCloudFile::class)($conexao, self::GOOGLE_FILE_ID);
            $this->fail('Deveria recusar pelo MIME real');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não é um tipo aceito', $e->getMessage());
        }

        $this->assertSame(0, MediaAsset::withoutGlobalScopes()->count());
        $this->assertSame([], File::files((string) config('agency.cloud_temp.path')));
    }

    public function test_duplicata_por_checksum_devolve_o_upload_existente(): void
    {
        $binario = $this->jpegBinary();
        Storage::disk('local')->put('clients/'.$this->cliente->getKey().'/midia/ja-existe.jpg', $binario);
        $existente = MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'checksum' => hash('sha256', $binario),
            'local_path' => 'clients/'.$this->cliente->getKey().'/midia/ja-existe.jpg',
        ]);

        Http::fake([
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?alt=media*' => Http::response($binario, 200),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?fields*' => $this->cloudResponse('google', 'file'),
        ]);
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);

        $asset = app(ImportCloudFile::class)($conexao, self::GOOGLE_FILE_ID);

        $this->assertTrue($asset->is($existente));
        $this->assertSame(1, MediaAsset::withoutGlobalScopes()->count());
    }

    public function test_token_vencido_e_renovado_antes_de_importar_e_refresh_revogado_marca_a_conexao(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push($this->cloudFixture('google', 'token_refreshed'))
                ->push($this->cloudFixture('google', 'invalid_grant'), 400),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?alt=media*' => Http::response($this->jpegBinary(), 200),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?fields*' => $this->cloudResponse('google', 'file'),
        ]);
        $conexao = CloudConnection::factory()->expiredToken()->create(['client_id' => $this->cliente->getKey()]);

        $asset = app(ImportCloudFile::class)($conexao, self::GOOGLE_FILE_ID);

        $this->assertSame(MediaSource::GoogleDrive, $asset->source);
        $this->assertStringContainsString('tokenRenovadoDoGoogle', $conexao->fresh()->access_token);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'alt=media') && $r->header('Authorization')[0] === 'Bearer '.$conexao->fresh()->access_token);

        // Vence de novo; desta vez a Google recusa o refresh.
        $conexao->forceFill(['token_expires_at' => now()->subMinute()])->save();

        try {
            app(ImportCloudFile::class)($conexao->fresh(), 'outro');
            $this->fail('Esperava CloudApiException');
        } catch (CloudApiException $e) {
            $this->assertTrue($e->isAuthorizationLost());
            $this->assertStringContainsString('Reconectar', $e->actionableMessage());
        }

        $conexao->refresh();
        $this->assertSame(ConnectionStatus::Expired, $conexao->status);
        $this->assertStringContainsString('Reconectar', (string) $conexao->last_error);
    }

    public function test_seletor_onedrive_navega_pelas_pastas_e_importa_com_a_miniatura(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children*' => $this->cloudResponse('microsoft', 'children'),
            'graph.microsoft.com/v1.0/me/drive/items/01PASTACLIENTE0000000000000000000/children*' => $this->cloudResponse('microsoft', 'children_pasta'),
            'graph.microsoft.com/v1.0/me/drive/items/'.self::ONEDRIVE_ITEM_ID.'/content' => Http::response($this->jpegBinary(), 200, ['Content-Type' => 'image/jpeg']),
            'graph.microsoft.com/v1.0/me/drive/items/'.self::ONEDRIVE_ITEM_ID.'?*' => $this->cloudResponse('microsoft', 'item'),
        ]);
        $conexao = CloudConnection::factory()->onedrive()->create(['client_id' => $this->cliente->getKey(), 'account_email' => 'one@padaria.com.br']);

        $this->actingAsUser($this->gestor);

        $componente = Livewire::test(CloudPicker::class, ['client' => $this->cliente])
            ->assertSee('Escolher do OneDrive')
            ->assertSee('Conectar Google Drive')
            ->call('open', 'onedrive')
            ->assertSet('connectionId', $conexao->getKey())
            ->assertSee('Padaria Central')
            ->assertSee('vitrine.jpg')
            ->assertSee('precos.xlsx')
            ->assertSee('não é mídia')
            ->call('browse', '01PASTACLIENTE0000000000000000000', 'Padaria Central')
            ->assertSee('reels-paes.mp4')
            ->call('up', 0)
            ->assertSee('vitrine.jpg')
            ->call('import', self::ONEDRIVE_ITEM_ID)
            ->assertHasNoErrors()
            ->assertSee('entrou na biblioteca')
            ->assertDispatched('midia-importada');

        $asset = MediaAsset::withoutGlobalScopes()->sole();
        $this->assertSame(MediaSource::OneDrive, $asset->source);
        $this->assertSame(self::ONEDRIVE_ITEM_ID, $asset->external_file_id);
        $this->assertSame('vitrine.jpg', $asset->filename);
        $this->assertSame($this->gestor->getKey(), $asset->uploaded_by);
        Storage::disk('local')->assertExists($asset->local_thumb_path);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/content') && $r->hasHeader('Authorization'));

        // A biblioteca no modo seleção recebe o evento e já marca o arquivo.
        Livewire::test(MediaLibrary::class, ['client' => $this->cliente, 'picker' => true])
            ->dispatch('midia-importada', mediaId: $asset->getKey())
            ->assertSet('selected', [$asset->getKey()])
            ->assertSee('vitrine.jpg');
    }

    public function test_seletor_mostra_erro_acionavel_quando_o_item_sumiu_ou_a_conexao_quebrou(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children*' => $this->cloudResponse('microsoft', 'children'),
            'graph.microsoft.com/v1.0/me/drive/items/sumiu?*' => $this->cloudResponse('microsoft', 'item_not_found', 404),
        ]);
        $conexao = CloudConnection::factory()->onedrive()->create(['client_id' => $this->cliente->getKey()]);

        $this->actingAsUser($this->gestor);

        Livewire::test(CloudPicker::class, ['client' => $this->cliente])
            ->call('open', 'onedrive')
            ->call('import', 'sumiu')
            ->assertHasErrors('cloud')
            ->assertSee('não existe mais no OneDrive');

        $conexao->fill(['status' => ConnectionStatus::Expired, 'last_error' => 'O OneDrive não reconhece mais o acesso desta conexão. Clique em "Reconectar" e autorize novamente.'])->save();

        Livewire::test(CloudPicker::class, ['client' => $this->cliente])
            ->call('open', 'onedrive')
            ->assertSee('não reconhece mais o acesso')
            ->assertSee('Reconectar OneDrive')
            ->assertDontSee('vitrine.jpg');

        $this->assertSame(0, MediaAsset::withoutGlobalScopes()->count());
    }

    public function test_seletor_google_lista_o_que_ja_foi_escolhido_e_importa_pelo_id_do_picker(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => $this->cloudResponse('google', 'files_list'),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?alt=media*' => Http::response($this->jpegBinary(), 200),
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?fields*' => $this->cloudResponse('google', 'file'),
        ]);
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);

        $this->actingAsUser($this->gestor);

        Livewire::test(CloudPicker::class, ['client' => $this->cliente])
            ->call('open', 'google_drive')
            ->assertSee('Abrir o Google Drive')
            ->assertSee('googlePicker', false)
            ->assertSee($conexao->ulid.'\/token', false)
            ->assertSee('banner-promocao.jpg')
            ->assertSee('reels-lancamento.mp4')
            ->call('import', self::GOOGLE_FILE_ID)
            ->assertHasNoErrors()
            ->assertDispatched('midia-importada');

        $this->assertSame(MediaSource::GoogleDrive, MediaAsset::withoutGlobalScopes()->sole()->source);
    }

    public function test_usuario_do_portal_nao_importa_da_nuvem(): void
    {
        CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);
        $clienteViewer = $this->userWithRole(RoleName::ClientViewer, $this->cliente);

        $this->actingAsUser($clienteViewer);

        Livewire::test(CloudPicker::class, ['client' => $this->cliente])
            ->call('open', 'google_drive')
            ->assertForbidden();
    }

    public function test_expurgo_horario_apaga_downloads_temporarios_vencidos(): void
    {
        $loja = app(CloudTempStore::class);
        $velho = $loja->pathFor('velho', 'a.jpg');
        $novo = $loja->pathFor('novo', 'b.jpg');
        file_put_contents($velho, 'x');
        file_put_contents($novo, 'y');
        touch($velho, time() - 3 * 3600);

        $this->artisan('media:cleanup-temp')
            ->expectsOutputToContain('1 download(s) temporário(s) da nuvem removido(s).')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($velho);
        $this->assertFileExists($novo);
    }
}
