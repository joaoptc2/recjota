<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PublishLog;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Enums\RoleName;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use ZipArchive;

/**
 * Fase 7: cabeçalhos de segurança, backup em PHP puro, retenção de logs,
 * direitos LGPD (exportar/excluir) e páginas de erro que dizem o que fazer.
 */
class Phase7HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->owner = $this->userWithRole(RoleName::Owner);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backups'));
        File::deleteDirectory(storage_path('app/exports'));

        parent::tearDown();
    }

    public function test_toda_resposta_web_traz_csp_e_os_demais_cabecalhos_de_seguranca(): void
    {
        $resposta = $this->get(route('login'))->assertOk();

        $resposta->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = (string) $resposta->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString('frame-src', $csp);
        $this->assertStringContainsString('https://docs.google.com', $csp, 'O Google Picker abre em iframe');
        $this->assertStringContainsString('form-action', $csp);
        $this->assertStringContainsString('https://login.microsoftonline.com', $csp);
        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp, 'Em HTTP local o upgrade quebraria o site');
        $this->assertStringContainsString('upgrade-insecure-requests', SecurityHeaders::policy(true));
        $this->assertTrue($resposta->headers->has('Permissions-Policy'));
    }

    public function test_backup_gera_dump_gzip_restauravel_e_aplica_a_retencao(): void
    {
        $cliente = Client::factory()->configured()->create(['name' => "Padaria O'Brien"]);
        Post::factory()->count(3)->create(['client_id' => $cliente->getKey(), 'caption' => 'Legenda com "aspas" e \'apóstrofo\'']);

        File::ensureDirectoryExists(storage_path('app/backups'));
        $velho = storage_path('app/backups/banco-2026-01-01-000000.sql.gz');
        file_put_contents($velho, 'x');
        touch($velho, now()->subDays(20)->timestamp);

        $this->artisan('backup:database')
            ->expectsOutputToContain('Backup gravado: banco-')
            ->expectsOutputToContain('1 backup(s) antigo(s) removido(s).')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($velho);

        $arquivos = collect(File::files(storage_path('app/backups')))->filter(fn ($f) => str_ends_with($f->getFilename(), '.sql.gz'));
        $this->assertCount(1, $arquivos);

        $sql = (string) gzdecode((string) file_get_contents($arquivos->first()->getPathname()));

        $this->assertStringContainsString('CREATE TABLE "posts"', $sql);
        $this->assertStringContainsString('INSERT INTO "clients"', $sql);
        $this->assertStringContainsString("Padaria O''Brien", $sql, 'Apóstrofo escapado');
        $this->assertStringContainsString('Legenda com "aspas"', $sql);
        $this->assertStringContainsString('INSERT INTO "users"', $sql);

        // Restaura numa base limpa e confere que os dados voltam.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec($sql);
        $this->assertSame(3, (int) $pdo->query('select count(*) from posts')->fetchColumn());
        $this->assertSame("Padaria O'Brien", (string) $pdo->query('select name from clients')->fetchColumn());
    }

    public function test_download_do_backup_so_para_quem_administra_e_so_com_nome_valido(): void
    {
        File::ensureDirectoryExists(storage_path('app/backups'));
        file_put_contents(storage_path('app/backups/banco-2026-09-22-023000.sql.gz'), gzencode('-- ok'));

        $this->actingAsUser($this->owner)
            ->get(route('painel.settings'))
            ->assertOk()
            ->assertSee('Backups do banco')
            ->assertSee('banco-2026-09-22-023000.sql.gz');

        $this->actingAsUser($this->owner)
            ->get(route('painel.settings.backup', 'banco-2026-09-22-023000.sql.gz'))
            ->assertOk()
            ->assertDownload('banco-2026-09-22-023000.sql.gz');

        $this->actingAsUser($this->owner)->get('/painel/configuracoes/backups/..%2F..%2F.env')->assertNotFound();
        $this->actingAsUser($this->owner)->get(route('painel.settings.backup', 'banco-2026-09-22-999999.sql.gz'))->assertNotFound();

        $gestor = $this->userWithRole(RoleName::Gestor, Client::factory()->create());
        $this->actingAsUser($gestor)->get(route('painel.settings.backup', 'banco-2026-09-22-023000.sql.gz'))->assertForbidden();
    }

    public function test_agendamentos_de_backup_e_retencao_estao_registrados_e_log_de_publicacao_e_podado(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        foreach (['backup-banco' => '30 2 * * *', 'limpar-auditoria' => '0 2 2 * *', 'limpar-logs-publicacao' => '50 2 * * *'] as $nome => $cron) {
            $evento = $eventos->first(fn ($e) => $e->description === $nome);

            $this->assertNotNull($evento, "Agendamento {$nome} ausente");
            $this->assertInstanceOf(CallbackEvent::class, $evento);
            $this->assertSame($cron, $evento->expression);
        }

        $post = Post::factory()->create();
        $velho = PublishLog::factory()->create(['post_id' => $post->getKey()]);
        PublishLog::query()->whereKey($velho->getKey())->update(['created_at' => now()->subDays(100)]);
        $novo = PublishLog::factory()->create(['post_id' => $post->getKey()]);

        $this->artisan('model:prune', ['--model' => [PublishLog::class]])->assertSuccessful();

        $this->assertNull(PublishLog::find($velho->getKey()));
        $this->assertNotNull(PublishLog::find($novo->getKey()));
    }

    public function test_exportacao_lgpd_entrega_zip_com_json_sem_tokens(): void
    {
        Storage::fake('local');
        $cliente = Client::factory()->configured()->create(['name' => 'Padaria Central']);
        $conta = SocialAccount::factory()->create(['client_id' => $cliente->getKey(), 'access_token' => 'IGAAtokenQueNaoPodeSair000000000000000000']);
        $post = Post::factory()->create(['client_id' => $cliente->getKey(), 'social_account_id' => $conta->getKey(), 'caption' => 'Post exportado']);
        $asset = MediaAsset::factory()->create(['client_id' => $cliente->getKey()]);
        Storage::disk('local')->put('thumb.webp', 'miniatura');
        $asset->forceFill(['local_thumb_path' => 'thumb.webp'])->save();
        $aprovador = $this->userWithRole(RoleName::ClientAdmin, $cliente);
        $gestor = $this->userWithRole(RoleName::Gestor, $cliente);

        $resposta = $this->actingAsUser($gestor)
            ->post(route('painel.clients.export', $cliente))
            ->assertOk()
            ->assertHeader('Content-Disposition');

        $zipPath = $resposta->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $posts = json_decode((string) $zip->getFromName('posts.json'), true);
        $contas = json_decode((string) $zip->getFromName('contas_sociais.json'), true);
        $pessoas = json_decode((string) $zip->getFromName('pessoas.json'), true);

        $this->assertSame('Post exportado', $posts[0]['caption']);
        $this->assertArrayNotHasKey('access_token', $contas[0]);
        $this->assertStringNotContainsString('IGAAtoken', (string) $zip->getFromName('contas_sociais.json'));
        $this->assertSame($aprovador->email, collect($pessoas)->firstWhere('papel_no_cliente', 'client_admin')['email']);
        $this->assertNotFalse($zip->locateName('miniaturas/'.$asset->ulid.'.webp'));
        $this->assertNotFalse($zip->locateName('LEIA-ME.txt'));
        $zip->close();

        $this->assertNotNull(Activity::query()->where('event', 'export')->where('subject_id', $cliente->getKey())->first());

        // Gestor de outro cliente não exporta; criador não exporta.
        $outro = $this->userWithRole(RoleName::Gestor, Client::factory()->create());
        $this->actingAsUser($outro)->post(route('painel.clients.export', $cliente))->assertForbidden();
        $criador = $this->userWithRole(RoleName::Criador, $cliente);
        $this->actingAsUser($criador)->post(route('painel.clients.export', $cliente))->assertForbidden();
    }

    public function test_exclusao_lgpd_exige_owner_e_o_nome_digitado_e_apaga_tudo(): void
    {
        Storage::fake('local');
        $cliente = Client::factory()->configured()->create(['name' => 'Padaria Central']);
        $conta = SocialAccount::factory()->create(['client_id' => $cliente->getKey()]);
        $post = Post::factory()->create(['client_id' => $cliente->getKey(), 'social_account_id' => $conta->getKey()]);
        Storage::disk('local')->put('clients/'.$cliente->getKey().'/midia/foto.jpg', 'x');
        $asset = MediaAsset::factory()->create(['client_id' => $cliente->getKey(), 'local_path' => 'clients/'.$cliente->getKey().'/midia/foto.jpg']);
        Storage::disk('local')->put('clients/'.$cliente->getKey().'/relatorios/r.pdf', '%PDF');
        Report::factory()->create(['client_id' => $cliente->getKey(), 'path' => 'clients/'.$cliente->getKey().'/relatorios/r.pdf']);

        $soDaqui = $this->userWithRole(RoleName::ClientAdmin, $cliente);
        $outroCliente = Client::factory()->configured()->create();
        $compartilhado = $this->userWithRole(RoleName::ClientViewer, $cliente);
        $compartilhado->clients()->attach($outroCliente->getKey(), ['role' => 'client_viewer', 'is_primary_contact' => false]);
        $gestor = $this->userWithRole(RoleName::Gestor, $cliente);
        $admin = $this->userWithRole(RoleName::Admin);

        // Admin não tem clients.delete; gestor também não.
        $this->actingAsUser($admin)->delete(route('painel.clients.destroy', $cliente), ['confirmacao' => 'Padaria Central'])->assertForbidden();
        $this->actingAsUser($gestor)->delete(route('painel.clients.destroy', $cliente), ['confirmacao' => 'Padaria Central'])->assertForbidden();

        // Nome errado: nada acontece.
        $this->actingAsUser($this->owner)
            ->from(route('painel.clients.show', $cliente))
            ->delete(route('painel.clients.destroy', $cliente), ['confirmacao' => 'Padaria'])
            ->assertRedirect(route('painel.clients.show', $cliente))
            ->assertSessionHasErrors('confirmacao');
        $this->assertNotNull(Client::withoutGlobalScopes()->find($cliente->getKey()));

        $this->actingAsUser($this->owner)
            ->delete(route('painel.clients.destroy', $cliente), ['confirmacao' => 'Padaria Central'])
            ->assertRedirect(route('painel.clients.index'))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'excluído definitivamente') && str_contains($s, '1 usuário(s)'));

        $this->assertNull(Client::withoutGlobalScopes()->withTrashed()->find($cliente->getKey()));
        $this->assertNull(Post::withoutGlobalScopes()->withTrashed()->find($post->getKey()));
        $this->assertNull(SocialAccount::withoutGlobalScopes()->withTrashed()->find($conta->getKey()));
        $this->assertNull(MediaAsset::withoutGlobalScopes()->withTrashed()->find($asset->getKey()));
        $this->assertSame(0, Report::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing('clients/'.$cliente->getKey().'/midia/foto.jpg');
        Storage::disk('local')->assertMissing('clients/'.$cliente->getKey().'/relatorios/r.pdf');

        $this->assertNull(User::find($soDaqui->getKey()), 'Usuário do portal só deste cliente é removido');
        $this->assertNotNull(User::find($compartilhado->getKey()), 'Usuário com outro cliente continua');
        $this->assertNotNull(User::find($gestor->getKey()), 'Equipe da agência continua');

        $registro = Activity::query()->where('event', 'erase')->sole();
        $this->assertSame('Padaria Central', $registro->properties['nome']);
        $this->assertSame($this->owner->getKey(), $registro->causer_id);
    }

    public function test_paginas_de_erro_em_portugues_dizem_o_que_fazer(): void
    {
        $this->get('/pagina-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertSee('O que fazer');

        $cliente = Client::factory()->configured()->create();
        $outro = Client::factory()->configured()->create();
        $gestor = $this->userWithRole(RoleName::Gestor, $cliente);

        $this->actingAsUser($gestor)
            ->get(route('painel.clients.show', $outro))
            ->assertForbidden()
            ->assertSee('Você não tem acesso a isto')
            ->assertDontSee('This action is unauthorized');
    }
}
