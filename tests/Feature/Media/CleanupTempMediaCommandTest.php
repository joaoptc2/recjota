<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Services\Media\PublicMediaBridge;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/** Expurgo agendado da ponte de mídia pública (Seção 7.4 / 8.2). */
class CleanupTempMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->pasta = sys_get_temp_dir().'/ponte-cmd-'.uniqid('', true);
        config([
            'agency.media_bridge.path' => $this->pasta,
            'agency.media_bridge.url' => 'https://agencia.example.com/media-tmp',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pasta);

        parent::tearDown();
    }

    private function assetPublicado(): MediaAsset
    {
        $cliente = Client::factory()->configured()->create();
        $relativo = 'clients/'.$cliente->getKey().'/midia/foto.jpg';
        Storage::disk('local')->put($relativo, (string) (new ImageManager(new Driver))->create(32, 32)->toJpeg());

        $asset = MediaAsset::factory()->create(['client_id' => $cliente->getKey(), 'local_path' => $relativo]);
        app(PublicMediaBridge::class)->publish($asset);

        return $asset->fresh();
    }

    public function test_comando_expurga_vencidas_e_so_varre_orfaos_quando_pedido(): void
    {
        $vencida = $this->assetPublicado();
        $vencida->forceFill(['public_temp_expires_at' => now()->subMinute()])->save();
        $valida = $this->assetPublicado();

        $orfa = $this->pasta.'/01J00000000000000000000RFA';
        File::ensureDirectoryExists($orfa);
        file_put_contents($orfa.'/'.str_repeat('c', 32).'.jpg', 'x');

        $this->artisan('media:cleanup-temp')
            ->expectsOutputToContain('1 cópia(s) vencida(s) removida(s).')
            ->doesntExpectOutputToContain('órfã')
            ->assertSuccessful();

        $this->assertNull($vencida->fresh()->public_temp_path);
        $this->assertFileExists($this->pasta.'/'.$valida->public_temp_path);
        $this->assertDirectoryExists($orfa);

        $this->artisan('media:cleanup-temp', ['--orfaos' => true])
            ->expectsOutputToContain('0 cópia(s) vencida(s) removida(s).')
            ->expectsOutputToContain('1 pasta(s) órfã(s) removida(s).')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($orfa);
        $this->assertFileExists($this->pasta.'/'.$valida->public_temp_path);
    }

    public function test_agendamentos_da_ponte_estao_registrados_sem_schedule_command(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        $horario = $eventos->first(fn ($e) => $e->description === 'limpar-ponte');
        $this->assertNotNull($horario, 'Agendamento limpar-ponte ausente em routes/console.php');
        $this->assertSame('0 * * * *', $horario->expression);
        $this->assertInstanceOf(CallbackEvent::class, $horario);
        $this->assertTrue($horario->withoutOverlapping);

        $diario = $eventos->first(fn ($e) => $e->description === 'varrer-ponte-orfaos');
        $this->assertNotNull($diario, 'Agendamento varrer-ponte-orfaos ausente em routes/console.php');
        $this->assertSame('0 5 * * *', $diario->expression);
        $this->assertInstanceOf(CallbackEvent::class, $diario);
        $this->assertTrue($diario->withoutOverlapping);
    }
}
