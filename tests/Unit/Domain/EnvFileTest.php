<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Support\EnvFile;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * O instalador grava credenciais direto no .env. Uma senha deformada aqui é
 * especialmente cruel: a conexão é testada com o valor certo e o arquivo fica
 * com outro, então o erro só aparece na requisição seguinte.
 */
class EnvFileTest extends TestCase
{
    private string $caminho;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caminho = sys_get_temp_dir().'/env-teste-'.bin2hex(random_bytes(6));
        file_put_contents($this->caminho, "APP_NAME=Recjota\nDB_PASSWORD=\n# um comentário\nOUTRO=1\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->caminho);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valoresDificeis(): array
    {
        return [
            'cifrão e barra invertida' => ['S3nh4$2024\\perigo'],
            'aspas simples' => ["ele disse: don't"],
            'aspas duplas' => ['diz "olá" aqui'],
            'parece interpolação' => ['abc${NAO_INTERPOLE}def'],
            'cerquilha e espaço' => ['senha #1 com espaço'],
            'só letras e números' => ['senhasimples123'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('valoresDificeis')]
    public function test_valor_volta_exatamente_como_entrou(string $valor): void
    {
        $env = new EnvFile($this->caminho);
        $env->set(['DB_PASSWORD' => $valor]);

        $this->assertSame($valor, $env->get('DB_PASSWORD'));
    }

    #[DataProvider('valoresDificeis')]
    public function test_o_phpdotenv_le_o_mesmo_valor(string $valor): void
    {
        (new EnvFile($this->caminho))->set(['DB_PASSWORD' => $valor]);

        $lido = Dotenv::parse((string) file_get_contents($this->caminho));

        $this->assertSame($valor, $lido['DB_PASSWORD'] ?? null);
    }

    public function test_comentarios_e_chaves_vizinhas_sobrevivem(): void
    {
        (new EnvFile($this->caminho))->set(['DB_PASSWORD' => 'x']);

        $conteudo = (string) file_get_contents($this->caminho);

        $this->assertStringContainsString('# um comentário', $conteudo);
        $this->assertStringContainsString('OUTRO=1', $conteudo);
        $this->assertStringContainsString('APP_NAME=Recjota', $conteudo);
    }

    public function test_chave_inexistente_e_acrescentada_ao_final(): void
    {
        $env = new EnvFile($this->caminho);
        $env->set(['MEDIA_BRIDGE_PATH' => '/home/u1/domains/x/public_html/media-tmp']);

        $this->assertSame('/home/u1/domains/x/public_html/media-tmp', $env->get('MEDIA_BRIDGE_PATH'));
    }

    public function test_cada_chave_e_gravada_uma_vez_so(): void
    {
        $env = new EnvFile($this->caminho);
        $env->set(['DB_PASSWORD' => 'primeira']);
        $env->set(['DB_PASSWORD' => 'segunda']);

        $this->assertSame(1, substr_count((string) file_get_contents($this->caminho), 'DB_PASSWORD='));
        $this->assertSame('segunda', $env->get('DB_PASSWORD'));
    }

    public function test_arquivo_sem_permissao_de_escrita_explica_o_que_fazer(): void
    {
        chmod($this->caminho, 0o444);

        if (is_writable($this->caminho)) {
            // root ignora o bit de escrita; o caso não é verificável aqui.
            $this->markTestSkipped('Processo com privilégio suficiente para ignorar a permissão.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/permissão para 644/');

        (new EnvFile($this->caminho))->set(['DB_PASSWORD' => 'x']);
    }
}
