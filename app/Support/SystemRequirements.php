<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Verificação de ambiente do instalador web (R6).
 *
 * Em hospedagem compartilhada não dá para ajustar php.ini nem instalar
 * extensão: o que existe, existe. Então o instalador diz claramente o que
 * falta e onde resolver, em vez de falhar no meio da migração.
 */
final class SystemRequirements
{
    public const MIN_PHP = '8.2.0';

    /** Extensões usadas pelo sistema — todas padrão no hPanel. */
    public const EXTENSIONS = [
        'pdo_mysql' => 'Banco de dados MySQL',
        'curl' => 'Chamadas às APIs da Meta, Google e Microsoft',
        'mbstring' => 'Contagem de caracteres da legenda em UTF-8',
        'openssl' => 'Criptografia dos tokens em repouso',
        'gd' => 'Miniaturas e validação de proporção da mídia',
        'zip' => 'Exportações e relatórios',
        'fileinfo' => 'Validação do MIME real do upload',
        'intl' => 'Datas e números em pt-BR',
    ];

    /**
     * @return array<int, array{grupo: string, item: string, ok: bool, detalhe: string, comoResolver: ?string}>
     */
    public function all(): array
    {
        return [...$this->php(), ...$this->extensions(), ...$this->filesystem(), ...$this->application()];
    }

    public function passes(): bool
    {
        foreach ($this->all() as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    private function php(): array
    {
        return [[
            'grupo' => 'PHP',
            'item' => 'Versão '.self::MIN_PHP.' ou superior',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'detalhe' => 'Detectado: PHP '.PHP_VERSION,
            'comoResolver' => 'hPanel › Avançado › Configuração PHP › selecione 8.2 ou superior.',
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function extensions(): array
    {
        $checks = [];

        foreach (self::EXTENSIONS as $extension => $paraQue) {
            $checks[] = [
                'grupo' => 'Extensões PHP',
                'item' => $extension,
                'ok' => extension_loaded($extension),
                'detalhe' => $paraQue,
                'comoResolver' => 'hPanel › Avançado › Configuração PHP › aba Extensões PHP.',
            ];
        }

        return $checks;
    }

    /** @return array<int, array<string, mixed>> */
    private function filesystem(): array
    {
        $paths = [
            storage_path() => 'storage/',
            storage_path('framework') => 'storage/framework/',
            storage_path('framework/cache') => 'storage/framework/cache/',
            storage_path('framework/sessions') => 'storage/framework/sessions/',
            storage_path('framework/views') => 'storage/framework/views/',
            storage_path('logs') => 'storage/logs/',
            base_path('bootstrap/cache') => 'bootstrap/cache/',
        ];

        $checks = [];

        foreach ($paths as $path => $label) {
            if (! is_dir($path)) {
                @mkdir($path, 0o775, true);
            }

            $checks[] = [
                'grupo' => 'Permissões de escrita',
                'item' => $label,
                'ok' => is_dir($path) && is_writable($path),
                'detalhe' => is_dir($path) ? 'Encontrado' : 'Pasta ausente',
                'comoResolver' => 'Gerenciador de Arquivos › clique com o botão direito na pasta › Permissões › 755 (ou 775).',
            ];
        }

        $env = EnvFile::default();

        $checks[] = [
            'grupo' => 'Permissões de escrita',
            'item' => '.env',
            'ok' => $env->exists() && $env->isWritable(),
            'detalhe' => $env->exists() ? 'Encontrado' : 'Arquivo ausente na raiz do projeto',
            'comoResolver' => 'O arquivo vem pronto no pacote de instalação. Se faltar, copie .env.example para .env e dê permissão 644.',
        ];

        return $checks;
    }

    /** @return array<int, array<string, mixed>> */
    private function application(): array
    {
        $key = (string) config('app.key');

        return [
            [
                'grupo' => 'Aplicação',
                'item' => 'Chave de criptografia (APP_KEY)',
                'ok' => $key !== '',
                'detalhe' => $key !== '' ? 'Definida' : 'Ausente — os tokens não podem ser criptografados sem ela',
                'comoResolver' => 'A chave vem gerada no pacote de instalação. Se faltar, gere uma nova na tela seguinte.',
            ],
            [
                'grupo' => 'Aplicação',
                'item' => 'Dependências (vendor/)',
                'ok' => is_file(base_path('vendor/autoload.php')),
                'detalhe' => 'As bibliotecas já vêm no pacote — o servidor não precisa do Composer',
                'comoResolver' => 'Reenvie o pacote de instalação completo, sem excluir a pasta vendor.',
            ],
            [
                'grupo' => 'Aplicação',
                'item' => 'Assets compilados (public/build)',
                'ok' => is_file(public_path('build/manifest.json')),
                'detalhe' => 'CSS e JavaScript já vêm compilados — o servidor não precisa do Node',
                'comoResolver' => 'Reenvie o pacote de instalação completo, sem excluir a pasta build.',
            ],
        ];
    }
}
