<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Livewire\Integrations\IntegrationHealth;
use App\Models\Client;
use App\Models\Post;
use App\Models\PublishingQuota;
use App\Models\PublishLog;
use App\Models\SocialAccount;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Saúde das integrações (Seção 6.12): só a agência com integrations.manage. */
class IntegrationHealthPageTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->cliente = Client::factory()->configured()->create();
    }

    public function test_owner_abre_a_pagina_e_ve_as_contas_com_semaforo_cota_e_validade(): void
    {
        $conta = SocialAccount::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'username' => 'loja_da_maria',
            'token_expires_at' => now()->addDays(40),
        ]);
        PublishingQuota::factory()->create(['social_account_id' => $conta->getKey(), 'used_count' => 7, 'quota_total' => 50]);

        $expirada = SocialAccount::factory()->expired()->create([
            'client_id' => $this->cliente->getKey(),
            'username' => 'conta_expirada',
            'last_error' => 'O Instagram não reconhece mais o acesso. Reconectar.',
        ]);

        $this->actingAsUser($this->userWithRole(RoleName::Owner));

        $resposta = $this->get(route('painel.integrations'))->assertOk();

        $resposta->assertSee('Integrações')
            ->assertSee('@loja_da_maria')
            ->assertSee($this->cliente->name)
            ->assertSee('7/50')
            ->assertSee('40 dia(s)')
            ->assertSee('@conta_expirada')
            ->assertSee('Reconectar')
            ->assertSee('O Instagram não reconhece mais o acesso.')
            ->assertSee(route('painel.integrations.instagram.connect', ['client' => $this->cliente, 'conta' => $expirada->ulid]), escape: false)
            ->assertSee('bg-emerald-500')
            ->assertSee('bg-amber-500');

        // Link "Configurações" e "Integrações" deixaram de ser "em breve".
        $resposta->assertSee('href="'.route('painel.settings').'"', escape: false);
    }

    public function test_token_com_menos_de_7_dias_ganha_destaque(): void
    {
        SocialAccount::factory()->create(['client_id' => $this->cliente->getKey(), 'token_expires_at' => now()->addDays(3)]);

        $this->actingAsUser($this->userWithRole(RoleName::Owner));

        $this->get(route('painel.integrations'))
            ->assertOk()
            ->assertSee('3 dia(s)')
            ->assertSee('text-amber-600');
    }

    public function test_criador_e_cliente_recebem_403(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Criador, $this->cliente))
            ->get(route('painel.integrations'))
            ->assertForbidden();

        $this->actingAsUser($this->userWithRole(RoleName::ClientAdmin, $this->cliente))
            ->get(route('painel.integrations'))
            ->assertForbidden();
    }

    public function test_gestor_so_ve_contas_dos_proprios_clientes(): void
    {
        SocialAccount::factory()->create(['client_id' => $this->cliente->getKey(), 'username' => 'minha_conta']);
        SocialAccount::factory()->create(['client_id' => Client::factory()->create()->getKey(), 'username' => 'conta_alheia']);

        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $this->cliente));

        $this->get(route('painel.integrations'))
            ->assertOk()
            ->assertSee('@minha_conta')
            ->assertDontSee('@conta_alheia');
    }

    public function test_estado_vazio_explica_como_conectar(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Owner));

        $this->get(route('painel.integrations'))
            ->assertOk()
            ->assertSee('Nenhuma conta conectada')
            ->assertSee('Conectar Instagram');
    }

    public function test_historico_mostra_no_maximo_20_publish_logs_da_conta(): void
    {
        $conta = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);
        $post = Post::factory()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $conta->getKey()]);
        PublishLog::factory()->count(25)->create(['post_id' => $post->getKey()]);

        $outraConta = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);
        $outroPost = Post::factory()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $outraConta->getKey()]);
        PublishLog::factory()->create(['post_id' => $outroPost->getKey(), 'succeeded' => false, 'error' => 'Erro só da outra conta']);

        $this->actingAsUser($this->userWithRole(RoleName::Owner));

        $componente = Livewire::test(IntegrationHealth::class)
            ->call('toggleLogs', $conta->getKey())
            ->assertSet('logsDe', $conta->getKey())
            ->assertDontSee('Erro só da outra conta');

        $this->assertCount(20, $componente->instance()->logs());

        $componente->call('toggleLogs', $conta->getKey())->assertSet('logsDe', null);
    }

    public function test_gestor_nao_abre_historico_de_conta_alheia(): void
    {
        $alheia = SocialAccount::factory()->create(['client_id' => Client::factory()->create()->getKey()]);

        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $this->cliente));

        Livewire::test(IntegrationHealth::class)
            ->call('toggleLogs', $alheia->getKey())
            ->assertForbidden();
    }
}
