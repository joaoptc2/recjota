<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Report;
use App\Models\Scopes\ClientScope;
use App\Services\Integrations\Instagram\InstagramInsights;
use App\Services\Integrations\Instagram\InstagramPublisher;
use App\Services\Integrations\SocialInsightsInterface;
use App\Services\Integrations\SocialPublisherInterface;
use App\Services\Media\CloudSourceReader;
use App\Services\Media\CompositeSourceReader;
use App\Services\Media\Contracts\MediaSourceReader;
use App\Services\Media\MediaProcessor;
use App\Services\Media\UploadSourceReader;
use App\Support\Icons;
use App\Support\Installation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Um único contexto de tenant por requisição/processo.
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);

        $this->useFileDriversBeforeInstall();

        // GD e não Imagick: é a extensão garantida no plano (R6).
        $this->app->singleton(ImageManager::class, fn () => new ImageManager(new Driver));
        $this->app->singleton(MediaProcessor::class);

        // Ponte de mídia pública (Seção 7.4): o leitor composto escolhe entre
        // upload local e nuvem (Drive/OneDrive) pela origem do asset.
        $this->app->bind(MediaSourceReader::class, fn ($app) => new CompositeSourceReader(
            $app->make(UploadSourceReader::class),
            $app->make(CloudSourceReader::class),
        ));

        // O motor de publicação (Seção 8) só conhece a interface; hoje a única
        // plataforma é o Instagram.
        $this->app->bind(SocialPublisherInterface::class, InstagramPublisher::class);
        $this->app->bind(SocialInsightsInterface::class, InstagramInsights::class);
    }

    /**
     * Antes da instalação não existe banco — e sessão, cache e fila apontam
     * para o driver `database`. O instalador web precisa de sessão (CSRF),
     * então em modo de instalação tudo cai para arquivo.
     *
     * A checagem sai de cena assim que o lock existe: em produção instalada
     * isto é um único file_exists() por requisição.
     */
    private function useFileDriversBeforeInstall(): void
    {
        if (Installation::isInstalled() || $this->app->runningUnitTests()) {
            return;
        }

        if (! Installation::isAvailable()) {
            return;
        }

        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);
    }

    public function boot(): void
    {
        // Atribuir coluna inexistente falha alto fora de produção.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // preventLazyLoading fica para a Fase 7 (caça a N+1), junto com o
        // eager loading explícito das telas. Ligar agora quebraria Policies que
        // resolvem o registro pai sob demanda.
        Model::preventLazyLoading(false);

        Password::defaults(fn () => app()->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(8));

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        // Ícones disponíveis em qualquer layout, sem @include por view.
        View::share('icons', Icons::all());

        $this->bindTenantRouteModels();
    }

    /**
     * Route model binding fora do escopo de tenant, de propósito.
     *
     * O Global Scope existe para que nenhuma listagem vaze registro de outro
     * cliente. Mas para um acesso direto por URL queremos que a negativa venha
     * da Policy — 403 explícito e auditável — e não um 404 silencioso que
     * esconde a tentativa. Toda rota que recebe um destes models chama
     * $this->authorize() logo na primeira linha.
     */
    private function bindTenantRouteModels(): void
    {
        $unscoped = fn (string $modelClass) => function (string $value) use ($modelClass): Model {
            return $modelClass::query()
                ->withoutGlobalScope(ClientScope::class)
                ->where('ulid', $value)
                ->firstOrFail();
        };

        Route::bind('client', $unscoped(Client::class));
        Route::bind('post', $unscoped(Post::class));
        Route::bind('asset', $unscoped(MediaAsset::class));
        Route::bind('connection', $unscoped(CloudConnection::class));
        Route::bind('report', $unscoped(Report::class));
    }
}
