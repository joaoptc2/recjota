<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Client;
use App\Models\Post;
use App\Models\Scopes\ClientScope;
use App\Support\Icons;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Um único contexto de tenant por requisição/processo.
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
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
    }
}
