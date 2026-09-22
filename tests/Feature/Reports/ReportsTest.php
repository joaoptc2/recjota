<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\Reports\GenerateMonthlyReportJob;
use App\Livewire\Posts\PostComposer;
use App\Livewire\Reports\MetricsDashboard;
use App\Models\Client;
use App\Models\MetricAccountDaily;
use App\Models\MetricPost;
use App\Models\Post;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\MonthlyReportReady;
use App\Services\Metrics\MetricsSummary;
use App\Services\Reports\MonthlyReportBuilder;
use App\Services\Reports\ReportRecipients;
use App\Support\Enums\ClientStatus;
use App\Support\Enums\RoleName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Painéis, PDF white-label, CSV e envio mensal (Seção 6.8). Dado ausente
 * aparece como "indisponível" em toda parte; nunca zero.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    private SocialAccount $conta;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-09-22 15:00:00', 'UTC'));

        $this->cliente = Client::factory()->configured()->create(['name' => 'Padaria Central', 'timezone' => 'America/Sao_Paulo', 'brand_colors' => ['primary' => '#B45309']]);
        $this->conta = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
    }

    /** 30 dias de série com dois buracos e três posts medidos, um deles sem alcance. */
    private function seedMetrics(): void
    {
        for ($i = 29; $i >= 1; $i--) {
            $dia = now()->subDays($i);

            if (in_array($i, [10, 11], true)) {
                continue; // dias sem coleta
            }

            MetricAccountDaily::factory()->create([
                'social_account_id' => $this->conta->getKey(),
                'date' => $dia->toDateString(),
                'followers' => 4000 + (30 - $i) * 10,
                'reach' => 100 + $i,
                'impressions' => null,
                'profile_views' => 5,
                'website_clicks' => null,
            ]);
        }

        $melhor = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'caption' => 'Post campeão do mês', 'published_at' => now()->subDays(5)]);
        MetricPost::factory()->create(['post_id' => $melhor->getKey(), 'collected_at' => now()->subDays(4)->startOfDay(), 'reach' => 1000, 'likes' => 100, 'comments' => 10, 'saves' => 10, 'shares' => 0, 'engagement_rate' => 12.0]);
        MetricPost::factory()->create(['post_id' => $melhor->getKey(), 'collected_at' => now()->subDays(1)->startOfDay(), 'reach' => 1500, 'likes' => 150, 'comments' => 15, 'saves' => 15, 'shares' => 0, 'engagement_rate' => 12.0]);

        $medio = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'caption' => 'Post mediano', 'published_at' => now()->subDays(8)]);
        MetricPost::factory()->create(['post_id' => $medio->getKey(), 'collected_at' => now()->subDay()->startOfDay(), 'reach' => 800, 'likes' => 20, 'comments' => 2, 'saves' => 2, 'shares' => 0, 'engagement_rate' => 3.0]);

        $semAlcance = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'caption' => 'Post sem alcance', 'published_at' => now()->subDays(2)]);
        MetricPost::factory()->create(['post_id' => $semAlcance->getKey(), 'collected_at' => now()->subDay()->startOfDay(), 'reach' => null, 'likes' => 5, 'comments' => 0, 'saves' => 0, 'shares' => 0, 'engagement_rate' => null]);

        Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'caption' => 'Post ainda sem coleta', 'published_at' => now()->subDay()]);
    }

    public function test_resumo_agrega_soma_so_o_que_existe_e_ordena_os_melhores_posts(): void
    {
        $this->seedMetrics();

        $resumo = app(MetricsSummary::class)->forClient($this->cliente, now()->subDays(29), now());

        $this->assertTrue($resumo->hasAnyData);
        $this->assertSame(4010, $resumo->followersStart);
        $this->assertSame(4290, $resumo->followersEnd);
        $this->assertSame(280, $resumo->followersDelta());
        $this->assertNull($resumo->impressions, 'Nenhum dia informou impressões: indisponível, não zero');
        $this->assertNull($resumo->websiteClicks);
        $this->assertSame(5 * 27, $resumo->profileViews);
        $this->assertSame(4, $resumo->postsPublished);
        $this->assertSame(3, $resumo->postsMeasured);
        $this->assertSame(7.5, $resumo->engagementRate, 'Média só entre posts com taxa: (12 + 3) / 2');
        $this->assertSame(['Post campeão do mês', 'Post mediano', 'Post sem alcance'], $resumo->topPosts->pluck('caption')->all());
        $this->assertSame(1500, $resumo->topPosts->first()->latestMetric->reach, 'Usa a coleta mais recente');
        $this->assertCount(30, $resumo->daily);
        $this->assertNull(collect($resumo->daily)->firstWhere('date', now()->subDays(10)->toDateString())['reach']);
        $this->assertCount(6, $resumo->months);
        $this->assertSame('2026-09', $resumo->months[5]['month']);
        $this->assertNull($resumo->previousReach, 'Sem coleta no período anterior: sem base de comparação');
        $this->assertNull($resumo->reachChangePercent());

        $vazio = app(MetricsSummary::class)->forClient($this->cliente, now()->subYears(2), now()->subYears(2)->addDays(6));
        $this->assertFalse($vazio->hasAnyData);
        $this->assertNull($vazio->followersEnd);
    }

    public function test_melhor_horario_so_existe_com_trinta_posts_medidos(): void
    {
        $servico = app(MetricsSummary::class);
        $this->conta->setRelation('client', $this->cliente);

        for ($i = 0; $i < 29; $i++) {
            $post = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'published_at' => now()->subDays($i)->setTime(21, 0)]);
            MetricPost::factory()->create(['post_id' => $post->getKey(), 'engagement_rate' => 8.0]);
        }

        $this->assertSame([], $servico->bestPostingHours($this->conta));

        // 30º post (às 12h UTC = 9h em Brasília) com engajamento baixo.
        $post = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'published_at' => now()->subDays(40)->setTime(12, 0)]);
        MetricPost::factory()->create(['post_id' => $post->getKey(), 'engagement_rate' => 1.0]);
        $post = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'published_at' => now()->subDays(41)->setTime(12, 0)]);
        MetricPost::factory()->create(['post_id' => $post->getKey(), 'engagement_rate' => 1.0]);

        $melhores = $servico->bestPostingHours($this->conta);

        $this->assertSame(18, $melhores[0]['hour'], '21h UTC = 18h em Brasília');
        $this->assertSame(8.0, $melhores[0]['engagement']);
        $this->assertSame(9, $melhores[1]['hour']);

        $this->actingAsUser($this->gestor);
        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])->set('socialAccountId', $this->conta->getKey());
        $componente->assertSee('Melhores horários desta conta')->assertSee('18h');
    }

    public function test_painel_da_agencia_mostra_indisponivel_e_troca_de_periodo(): void
    {
        $this->seedMetrics();
        $this->actingAsUser($this->gestor);

        $this->get(route('painel.reports', ['cliente' => $this->cliente->ulid]))
            ->assertOk()
            ->assertSee('Relatórios')
            ->assertSee('4.290')
            ->assertSee('+280 no período')
            ->assertSee('indisponível')
            ->assertSee('Post campeão do mês')
            ->assertSee('Exportar CSV')
            ->assertSee('Gerar PDF');

        Livewire::actingAs($this->gestor)
            ->test(MetricsDashboard::class, ['client' => $this->cliente])
            ->assertSet('period', '30')
            ->set('period', '7')
            ->assertSee('Post campeão do mês')
            ->assertDontSee('Post mediano')
            ->set('period', 'invalido')
            ->assertSet('period', '30');

        // Gestor de outro cliente: 403 na montagem do componente.
        $outro = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());
        Livewire::actingAs($outro)->test(MetricsDashboard::class, ['client' => $this->cliente])->assertForbidden();
    }

    public function test_portal_mostra_o_painel_enxuto_sem_exportar_nem_gerar(): void
    {
        $this->seedMetrics();
        $clienteAdmin = $this->userWithRole(RoleName::ClientAdmin, $this->cliente);

        $this->actingAsUser($clienteAdmin)
            ->get(route('portal.reports'))
            ->assertOk()
            ->assertSee('Como o perfil está indo')
            ->assertSee('4.290')
            ->assertDontSee('Exportar CSV')
            ->assertDontSee('Gerar PDF')
            ->assertDontSee('04:10 UTC');
    }

    public function test_csv_traz_as_duas_secoes_com_celulas_vazias_para_indisponivel(): void
    {
        $this->seedMetrics();

        $resposta = $this->actingAsUser($this->gestor)
            ->get(route('painel.reports.export', ['client' => $this->cliente, 'dias' => 30]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $resposta->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF\"# Métricas da conta por dia\"", $csv);
        $this->assertStringContainsString('data;conta;seguidores;alcance;impressoes;visitas_ao_perfil;cliques_no_site', $csv);
        $this->assertStringContainsString(now()->subDay()->toDateString().';'.$this->conta->handle().';4290;101;;5;', $csv);
        $this->assertStringContainsString('# Posts publicados', $csv);
        $this->assertStringContainsString('Post campeão do mês', $csv);
        $this->assertMatchesRegularExpression('/"Post sem alcance";;;5;0;0;0;\n/', $csv);

        $outro = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());
        $this->actingAsUser($outro)->get(route('painel.reports.export', ['client' => $this->cliente]))->assertForbidden();
    }

    public function test_gerar_pdf_sob_demanda_grava_o_relatorio_white_label_e_o_download_respeita_a_policy(): void
    {
        $this->seedMetrics();
        Storage::disk('local')->put('clients/'.$this->cliente->getKey().'/logo.png', (string) (new ImageManager(new Driver))->create(120, 40)->fill('#B45309')->toPng());
        $this->cliente->forceFill(['logo_path' => 'clients/'.$this->cliente->getKey().'/logo.png'])->save();

        $this->actingAsUser($this->gestor)
            ->post(route('painel.reports.generate', $this->cliente), ['mes' => '2026-09'])
            ->assertRedirect(route('painel.reports', ['cliente' => $this->cliente->ulid]))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'Setembro/2026'));

        $report = Report::withoutGlobalScopes()->sole();
        $this->assertSame('2026-09-01', $report->period_start->toDateString());
        $this->assertSame($this->gestor->getKey(), $report->generated_by);
        Storage::disk('local')->assertExists($report->path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($report->path));
        $this->assertGreaterThan(1000, $report->size_bytes);

        // Regenerar o mesmo mês substitui o arquivo em vez de acumular.
        $caminhoAntigo = $report->path;
        $this->actingAsUser($this->gestor)->post(route('painel.reports.generate', $this->cliente), ['mes' => '2026-09'])->assertRedirect();
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing($caminhoAntigo);

        // Mês futuro é recusado com explicação.
        $this->actingAsUser($this->gestor)->post(route('painel.reports.generate', $this->cliente), ['mes' => '2026-12'])->assertSessionHasErrors('mes');

        $report->refresh();
        $clienteAdmin = $this->userWithRole(RoleName::ClientAdmin, $this->cliente);
        $this->actingAsUser($clienteAdmin)
            ->get(route('reports.download', $report))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload($report->filename());

        $outro = $this->userWithRole(RoleName::ClientAdmin, Client::factory()->configured()->create());
        $this->actingAsUser($outro)->get(route('reports.download', $report))->assertForbidden();

        // Portal lista o PDF; cliente não gera.
        $this->actingAsUser($clienteAdmin)->get(route('portal.reports'))->assertOk()->assertSee('Setembro/2026')->assertSee('Baixar PDF');
        $this->actingAsUser($clienteAdmin)->post(route('painel.reports.generate', $this->cliente), ['mes' => '2026-09'])->assertStatus(302);
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
    }

    public function test_html_do_relatorio_mostra_indisponivel_em_vez_de_zero(): void
    {
        $this->seedMetrics();

        $resumo = app(MetricsSummary::class)->forClient($this->cliente, now()->startOfMonth(), now()->endOfMonth());
        $html = view('reports.monthly', [
            'client' => $this->cliente,
            'summary' => $resumo,
            'month' => now()->startOfMonth(),
            'primary' => '#B45309',
            'logo' => null,
            'agency' => 'Recjota',
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Padaria Central', $html);
        $this->assertStringContainsString('#B45309', $html);
        $this->assertStringContainsString('Post campeão do mês', $html);
        $this->assertStringContainsString('indisponível', $html);
        $this->assertStringContainsString('Setembro de 2026', $html);
    }

    public function test_reports_monthly_enfileira_um_job_por_cliente_ativo_com_conta_e_o_job_envia_o_pdf(): void
    {
        Bus::fake();
        Client::factory()->configured()->create(['name' => 'Sem conta']);
        $pausado = Client::factory()->configured()->create(['name' => 'Pausado', 'status' => ClientStatus::Paused]);
        SocialAccount::factory()->create(['client_id' => $pausado->getKey()]);

        $this->artisan('reports:monthly')
            ->expectsOutputToContain('1 relatório(s) enfileirado(s) para 08/2026')
            ->assertSuccessful();

        Bus::assertDispatched(GenerateMonthlyReportJob::class, fn (GenerateMonthlyReportJob $j) => $j->clientId === $this->cliente->getKey() && $j->month === '2026-08' && $j->send === true);
        Bus::assertDispatchedTimes(GenerateMonthlyReportJob::class, 1);

        Notification::fake();
        $this->seedMetrics();
        $clienteAdmin = $this->userWithRole(RoleName::ClientAdmin, $this->cliente);
        $viewer = $this->userWithRole(RoleName::ClientViewer, $this->cliente);
        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);

        (new GenerateMonthlyReportJob($this->cliente->getKey(), '2026-09', true))->handle(
            app(TenantContext::class),
            app(MonthlyReportBuilder::class),
            app(ReportRecipients::class),
        );

        $report = Report::withoutGlobalScopes()->sole();
        $this->assertNotNull($report->sent_at);

        Notification::assertSentTo([$clienteAdmin, $this->gestor], MonthlyReportReady::class, function (MonthlyReportReady $n, array $canais, object $destinatario) use ($report): bool {
            $mail = $n->toMail($destinatario);

            return $n->report->is($report)
                && in_array('mail', $canais, true)
                && count($mail->rawAttachments) === 1
                && $mail->rawAttachments[0]['name'] === $report->filename();
        });
        Notification::assertNotSentTo([$viewer, $criador], MonthlyReportReady::class);

        // O e-mail renderiza com o link certo para cada lado.
        $this->assertStringContainsString(route('portal.reports'), (new MonthlyReportReady($report))->toMail($clienteAdmin)->render()->toHtml());
        $this->assertStringContainsString('cliente='.$this->cliente->ulid, (new MonthlyReportReady($report))->toMail($this->gestor)->render()->toHtml());
    }
}
