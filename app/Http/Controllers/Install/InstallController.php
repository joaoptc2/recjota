<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use App\Support\EnvFile;
use App\Support\Installation;
use App\Support\SystemRequirements;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Instalador web (hospedagem compartilhada sem SSH).
 *
 * Faz, pelo navegador, o que normalmente se faria por linha de comando:
 * conferir o ambiente, gravar o .env, rodar as migrations e criar o primeiro
 * usuário. Ao terminar, grava o lock e desaparece.
 *
 * Nenhuma etapa aceita comando arbitrário: o que roda aqui é fixo no código.
 */
class InstallController extends Controller
{
    public function requirements(SystemRequirements $requirements): View
    {
        $checks = $requirements->all();

        return view('install.requirements', [
            'grupos' => collect($checks)->groupBy('grupo'),
            'passou' => collect($checks)->every(fn (array $c) => $c['ok']),
        ]);
    }

    public function environmentForm(): View
    {
        $env = EnvFile::default();

        return view('install.environment', [
            'valores' => [
                'app_name' => $env->get('APP_NAME') ?: 'Recjota',
                'app_url' => $env->get('APP_URL') ?: rtrim(request()->getSchemeAndHttpHost(), '/'),
                'db_host' => $env->get('DB_HOST') ?: 'localhost',
                'db_port' => $env->get('DB_PORT') ?: '3306',
                'db_database' => $env->get('DB_DATABASE') ?: '',
                'db_username' => $env->get('DB_USERNAME') ?: '',
                'timezone' => $env->get('AGENCY_DEFAULT_TIMEZONE') ?: 'America/Sao_Paulo',
            ],
        ]);
    }

    public function environmentStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'url:https,http', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
        ], [], [
            'db_database' => 'nome do banco',
            'db_username' => 'usuário do banco',
        ]);

        if (($erro = $this->testDatabase($data)) !== null) {
            return back()->withInput($request->except('db_password', 'mail_password'))
                ->withErrors(['db_database' => $erro]);
        }

        try {
            EnvFile::default()->set([
                'APP_NAME' => $data['app_name'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => false,
                'APP_URL' => rtrim($data['app_url'], '/'),
                // O banco é SEMPRE UTC (R2); o fuso abaixo é só de exibição.
                'APP_TIMEZONE' => 'UTC',
                'AGENCY_NAME' => $data['app_name'],
                'AGENCY_DEFAULT_TIMEZONE' => $data['timezone'],

                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $data['db_host'],
                'DB_PORT' => (string) $data['db_port'],
                'DB_DATABASE' => $data['db_database'],
                'DB_USERNAME' => $data['db_username'],
                'DB_PASSWORD' => $data['db_password'] ?? '',

                'SESSION_DRIVER' => 'database',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',

                'MAIL_MAILER' => filled($data['mail_host'] ?? null) ? 'smtp' : 'log',
                'MAIL_HOST' => $data['mail_host'] ?? '',
                'MAIL_PORT' => (string) ($data['mail_port'] ?? 465),
                'MAIL_SCHEME' => ((int) ($data['mail_port'] ?? 465)) === 465 ? 'smtps' : 'smtp',
                'MAIL_USERNAME' => $data['mail_username'] ?? '',
                'MAIL_PASSWORD' => $data['mail_password'] ?? '',
                'MAIL_FROM_ADDRESS' => $data['mail_from_address'] ?? '',
                'MAIL_FROM_NAME' => $data['app_name'],

                // Caminho e URL têm de apontar para a MESMA pasta. O document root
                // real vem do servidor: no layout de produção ele não é public_path().
                'MEDIA_BRIDGE_PATH' => $this->documentRoot().'/media-tmp',
                'MEDIA_BRIDGE_URL' => rtrim($data['app_url'], '/').'/media-tmp',
            ]);
        } catch (RuntimeException $e) {
            // Mensagem acionável no formulário, nunca um 500 genérico (Seção 14).
            return back()->withInput($request->except('db_password', 'mail_password'))
                ->withErrors(['app_name' => $e->getMessage()]);
        }

        return redirect()->route('install.database')
            ->with('status', 'Conexão com o banco confirmada e configuração gravada.');
    }

    public function databaseForm(): View
    {
        return view('install.database', [
            'conectado' => $this->currentConnectionWorks(),
            'jaMigrado' => Installation::databaseIsReady(),
        ]);
    }

    public function databaseRun(Request $request): RedirectResponse
    {
        $request->validate(['demo' => ['nullable', 'boolean']]);

        if (! $this->currentConnectionWorks()) {
            return back()->withErrors([
                'conexao' => 'O sistema ainda não consegue falar com o banco. Volte um passo e confira usuário, senha e nome do banco.',
            ]);
        }

        try {
            // Nenhuma entrada do usuário chega até aqui: os comandos são fixos.
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

            /*
             * O DemoSeeder NÃO roda aqui. Ele cria usuários, e usuários fecham
             * o portão de Installation::isAvailable() — o passo seguinte
             * devolveria 404 e o instalador morreria sem criar o proprietário.
             * A escolha fica guardada e é aplicada depois do lock.
             */
            $request->session()->put('install.demo', $request->boolean('demo'));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'conexao' => 'A migração falhou: '.$e->getMessage(),
            ]);
        }

        return redirect()->route('install.administrator')
            ->with('status', 'Estrutura do banco criada e papéis configurados.');
    }

    public function administratorForm(): View|RedirectResponse
    {
        if (! Installation::databaseIsReady()) {
            return redirect()->route('install.database');
        }

        return view('install.administrator');
    }

    public function administratorStore(Request $request): RedirectResponse
    {
        if (! Installation::databaseIsReady()) {
            return redirect()->route('install.database');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:10'],
        ], [
            'password.min' => 'A senha do proprietário precisa de pelo menos 10 caracteres.',
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'type' => UserType::Agency,
                'timezone' => config('agency.default_timezone'),
                'locale' => 'pt_BR',
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(RoleName::Owner->value);

            return $user;
        });

        if ($request->session()->pull('install.demo', false)) {
            Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        }

        // A partir daqui o instalador deixa de existir.
        Installation::markInstalled();

        /*
         * Sem Auth::login() de propósito. Até este ponto a sessão vive em
         * arquivo, porque o driver `database` não existia antes das migrations;
         * o lock recém-escrito devolve o driver para o banco, e a sessão atual
         * não sobrevive ao redirect. Mandar para a tela de entrada é o caminho
         * honesto — e o usuário acabou de escolher a senha.
         */
        return redirect()->route('login')->with(
            'status',
            'Instalação concluída. Entre com o e-mail e a senha que você acabou de criar — '
            .'e cadastre o cron em seguida, porque sem ele nada é publicado.',
        );
    }

    /** @param array<string, mixed> $data */
    private function testDatabase(array $data): ?string
    {
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $data['db_host'], $data['db_port'], $data['db_database']),
                $data['db_username'],
                $data['db_password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );

            return null;
        } catch (PDOException $e) {
            return match ((int) $e->getCode()) {
                1045 => 'Usuário ou senha do banco incorretos. No hPanel, o usuário costuma começar com u......._',
                1049 => 'Banco não encontrado. Crie-o em hPanel › Bancos de Dados MySQL e use o nome completo, com o prefixo.',
                2002 => 'Não foi possível alcançar o servidor do banco. Na Hostinger o host costuma ser "localhost".',
                default => 'Falha ao conectar no banco: '.$e->getMessage(),
            };
        }
    }

    /**
     * Pasta que o servidor web realmente serve. No layout de produção ela NÃO é
     * public_path() — o projeto mora um nível acima do document root (R9).
     */
    private function documentRoot(): string
    {
        $raiz = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        return $raiz !== '' && is_dir($raiz) ? rtrim($raiz, '/') : rtrim(public_path(), '/');
    }

    private function currentConnectionWorks(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
