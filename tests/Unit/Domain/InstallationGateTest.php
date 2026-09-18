<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Support\Installation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O portão do instalador decide quem pode reescrever as credenciais do banco.
 * Sem RefreshDatabase de propósito: estes casos trocam a conexão padrão, o que
 * é incompatível com a transação de teste.
 */
class InstallationGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Installation::fake(false);
    }

    public function test_banco_inalcancavel_com_sistema_configurado_tranca_o_instalador(): void
    {
        config([
            'database.default' => 'quebrado',
            'database.connections.quebrado' => [
                'driver' => 'sqlite',
                'database' => '/caminho/que/nao/existe/banco.sqlite',
            ],
        ]);
        DB::purge('quebrado');

        $this->assertTrue(Installation::databaseIsConfigured());

        // Falha FECHADA: aberto aqui, qualquer visitante reescreveria o .env
        // durante uma queda do MySQL.
        $this->assertFalse(Installation::isAvailable());
    }

    public function test_sem_banco_configurado_o_instalador_continua_aberto(): void
    {
        config([
            'database.default' => 'vazio',
            'database.connections.vazio' => ['driver' => 'sqlite', 'database' => ''],
        ]);
        DB::purge('vazio');

        $this->assertFalse(Installation::databaseIsConfigured());
        $this->assertTrue(Installation::isAvailable());
    }

    public function test_placeholder_do_pacote_nao_conta_como_banco_configurado(): void
    {
        config([
            'database.default' => 'exemplo',
            'database.connections.exemplo' => [
                'driver' => 'mysql',
                'database' => 'uXXXXXXXX_recjota',
            ],
        ]);

        $this->assertFalse(Installation::databaseIsConfigured());
    }

    public function test_lock_presente_fecha_o_portao_sem_consultar_o_banco(): void
    {
        Installation::fake(true);

        $this->assertFalse(Installation::isAvailable());
    }
}
