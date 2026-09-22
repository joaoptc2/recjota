<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\RoleName;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\Feature\Integrations\InstagramFixtures;

/**
 * Cenário padrão do motor de publicação: cliente configurado, gestor, autor,
 * conta do Instagram com token conhecido e ponte de mídia numa pasta
 * temporária. Todas as respostas HTTP vêm das fixtures da Graph API.
 */
trait PublishingSetup
{
    use InstagramFixtures;

    protected const IG_USER_ID = '17841405793187218';

    protected const TOKEN = 'IGAAtokenDaContaParaPublicar0000000000000000';

    protected const CONTAINER_ID = '17889455560051444';

    protected const MEDIA_ID = '17895695668004550';

    protected string $ponte;

    protected Client $cliente;

    protected User $gestor;

    protected User $autor;

    protected SocialAccount $conta;

    protected function setUpPublishing(): void
    {
        $this->seedRoles();
        $this->configureInstagram();

        Storage::fake('local');

        $this->ponte = sys_get_temp_dir().'/ponte-'.uniqid('', true);

        config([
            'agency.media_bridge.path' => $this->ponte,
            'agency.media_bridge.url' => 'https://agencia.example.com/media-tmp',
            'agency.media_bridge.ttl_hours' => 6,
        ]);

        $this->cliente = Client::factory()->configured()->create(['timezone' => 'America/Sao_Paulo']);
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
        $this->autor = $this->userWithRole(RoleName::Criador, $this->cliente);

        $this->conta = SocialAccount::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'external_id' => self::IG_USER_ID,
            'access_token' => self::TOKEN,
        ]);
    }

    protected function tearDownPublishing(): void
    {
        File::deleteDirectory($this->ponte);
    }

    /** Post pronto para sair: aprovado na versão atual, agendado para o passado. */
    protected function postPronto(array $atributos = [], int $midias = 1, PostType $tipo = PostType::FeedImage): Post
    {
        $post = Post::factory()->create(array_merge([
            'client_id' => $this->cliente->getKey(),
            'social_account_id' => $this->conta->getKey(),
            'created_by' => $this->autor->getKey(),
            'type' => $tipo,
            'caption' => 'Legenda de teste #recjota',
            'first_comment' => '#hashtags #no #comentario',
            'scheduled_at' => now()->subMinute(),
            'status' => PostStatus::Scheduled,
            'approval_status' => ApprovalStatus::Approved,
            'current_version' => 1,
            'approved_version' => 1,
        ], $atributos));

        for ($i = 0; $i < $midias; $i++) {
            PostMedia::create([
                'post_id' => $post->getKey(),
                'media_asset_id' => $this->imagem("foto-{$i}.jpg")->getKey(),
                'position' => $i,
            ]);
        }

        return $post->fresh();
    }

    protected function imagem(string $nome = 'foto.jpg'): MediaAsset
    {
        $relativo = 'clients/'.$this->cliente->getKey().'/midia/'.$nome;
        Storage::disk('local')->put($relativo, (string) (new ImageManager(new Driver))->create(64, 80)->fill('#4f46e5')->toJpeg());

        return MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'filename' => $nome,
            'local_path' => $relativo,
        ]);
    }

    protected function urlMedia(): string
    {
        return 'graph.instagram.com/v23.0/'.self::IG_USER_ID.'/media';
    }

    protected function urlPublish(): string
    {
        return 'graph.instagram.com/v23.0/'.self::IG_USER_ID.'/media_publish';
    }

    protected function urlQuota(): string
    {
        return 'graph.instagram.com/v23.0/'.self::IG_USER_ID.'/content_publishing_limit*';
    }

    protected function urlContainer(): string
    {
        return 'graph.instagram.com/v23.0/'.self::CONTAINER_ID.'?*';
    }

    protected function urlPermalink(): string
    {
        return 'graph.instagram.com/v23.0/'.self::MEDIA_ID.'?*';
    }

    protected function urlComments(): string
    {
        return 'graph.instagram.com/v23.0/'.self::MEDIA_ID.'/comments';
    }

    /** Fake do caminho feliz inteiro: cota → container → FINISHED → publish → permalink → comentário. */
    protected function fakeHappyPublishing(string $statusFixture = 'container_status_finished'): void
    {
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
            $this->urlContainer() => $this->fixtureResponse($statusFixture),
            $this->urlPublish() => $this->fixtureResponse('media_publish'),
            $this->urlPermalink() => $this->fixtureResponse('permalink'),
            $this->urlComments() => $this->fixtureResponse('comment'),
        ]);
    }

    /** Quantas requisições bateram numa URL (sem query string). */
    protected function chamadasPara(string $sufixo): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with((string) strtok($r->url(), '?'), $sufixo))->count();
    }
}
