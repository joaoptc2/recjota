<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Comment;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Task;
use App\Models\User;
use App\Support\Enums\ClientStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\RoleName;
use App\Support\Enums\TaskStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Agência demo com 2 clientes e 5 usuários de papéis diferentes, para que a
 * Fase 1 possa ser conferida à mão e o teste de isolamento tenha dados reais.
 *
 * Senha de todos os usuários: senha-demo-2026
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'senha-demo-2026';

    public function run(): void
    {
        // Seeder roda fora de uma requisição autenticada: sem escopo de tenant.
        app(TenantContext::class)->withoutRestriction(function (): void {
            $owner = $this->user('Ana Owner', 'owner@agencia.test', RoleName::Owner);
            $gestor = $this->user('Gabriel Gestor', 'gestor@agencia.test', RoleName::Gestor);
            $criador = $this->user('Carla Criadora', 'criador@agencia.test', RoleName::Criador);

            $acme = $this->client('Acme Café', $owner, ['approval_required' => true, 'approval_deadline_hours' => 24]);
            $bonsai = $this->client('Bonsai Studio', $owner, ['approval_required' => true, 'auto_publish_on_approval' => true]);

            // Gestor e criadora atendem os dois clientes; owner enxerga tudo por papel.
            foreach ([$acme, $bonsai] as $client) {
                $this->attach($gestor, $client, RoleName::Gestor);
                $this->attach($criador, $client, RoleName::Criador);
            }

            // Um aprovador em cada cliente — é isto que o teste de isolamento usa.
            $acmeAdmin = $this->user('Marcos da Acme', 'aprovador@acme.test', RoleName::ClientAdmin);
            $this->attach($acmeAdmin, $acme, RoleName::ClientAdmin, primary: true);

            $bonsaiViewer = $this->user('Júlia do Bonsai', 'leitor@bonsai.test', RoleName::ClientViewer);
            $this->attach($bonsaiViewer, $bonsai, RoleName::ClientViewer, primary: true);

            foreach ([$acme, $bonsai] as $client) {
                $this->content($client, $gestor, $criador);
            }
        });
    }

    private function user(string $name, string $email, RoleName $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'type' => $role->userType(),
                'timezone' => config('agency.default_timezone'),
                'locale' => 'pt_BR',
                'is_active' => true,
            ],
        );

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function client(string $name, User $creator, array $settings = []): Client
    {
        $client = Client::firstOrCreate(
            ['name' => $name],
            [
                'legal_name' => $name.' LTDA',
                'brand_colors' => ['primary' => $name === 'Acme Café' ? '#B45309' : '#0F766E'],
                'timezone' => config('agency.default_timezone'),
                'contract_start' => now()->subMonths(6),
                'status' => ClientStatus::Active,
                'created_by' => $creator->getKey(),
            ],
        );

        ClientSetting::updateOrCreate(
            ['client_id' => $client->getKey()],
            array_merge([
                'approval_required' => true,
                'internal_review_required' => false,
                'approval_deadline_hours' => config('agency.approval.default_deadline_hours'),
                'auto_publish_on_approval' => false,
                'min_approvals' => 1,
            ], $settings),
        );

        return $client;
    }

    private function attach(User $user, Client $client, RoleName $role, bool $primary = false): void
    {
        $user->clients()->syncWithoutDetaching([
            $client->getKey() => ['role' => $role->value, 'is_primary_contact' => $primary],
        ]);

        $user->forgetAccessibleClients();
    }

    private function content(Client $client, User $gestor, User $criador): void
    {
        if ($client->posts()->exists()) {
            return;
        }

        $account = SocialAccount::factory()->create([
            'client_id' => $client->getKey(),
            'display_name' => $client->name,
            'username' => str($client->name)->slug('')->lower()->toString(),
        ]);

        $campaign = Campaign::factory()->create([
            'client_id' => $client->getKey(),
            'name' => 'Lançamento '.now()->translatedFormat('F'),
            'created_by' => $gestor->getKey(),
        ]);

        $base = [
            'client_id' => $client->getKey(),
            'social_account_id' => $account->getKey(),
            'campaign_id' => $campaign->getKey(),
            'created_by' => $criador->getKey(),
        ];

        Post::factory()->awaitingClient()->create($base + [
            'type' => PostType::Carousel,
            'caption' => 'Chegou a nova linha. Deslize para ver tudo. #novidade #'.str($client->name)->slug(''),
            'scheduled_at' => now()->addDays(2)->setTime(21, 0),
        ]);

        Post::factory()->approved()->create($base + [
            'type' => PostType::Reel,
            'caption' => 'Bastidores de quem faz acontecer. #bastidores',
            'scheduled_at' => now()->addDays(5)->setTime(22, 30),
        ]);

        Post::factory()->published()->create($base + [
            'type' => PostType::FeedImage,
            'caption' => 'Obrigado por mais um mês incrível com vocês. #gratidao',
        ]);

        $failed = Post::factory()->failed()->create($base + [
            'type' => PostType::FeedImage,
            'caption' => 'Post que não subiu — serve para conferir o painel de erros.',
            'scheduled_at' => now()->subDay(),
        ]);

        Comment::factory()->forPost($failed)->internal()->create([
            'user_id' => $gestor->getKey(),
            'body' => 'Comentário interno: reconectar a conta antes de tentar de novo.',
        ]);

        Comment::factory()->forPost($failed)->create([
            'user_id' => $gestor->getKey(),
            'body' => 'Estamos reagendando esta publicação, sem impacto no calendário.',
        ]);

        Task::factory()->create([
            'client_id' => $client->getKey(),
            'title' => 'Fechar pauta da próxima quinzena',
            'assignee_id' => $criador->getKey(),
            'created_by' => $gestor->getKey(),
            'due_at' => now()->addDays(3),
            'status' => TaskStatus::Doing,
        ]);

        Task::factory()->create([
            'client_id' => $client->getKey(),
            'title' => 'Coletar depoimentos em vídeo',
            'assignee_id' => $gestor->getKey(),
            'created_by' => $gestor->getKey(),
            'due_at' => now()->subDays(2),
            'status' => TaskStatus::Todo,
        ]);
    }
}
