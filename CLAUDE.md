# Recjota — regras do projeto

Sistema Laravel 12 multi-tenant para agência de marketing, rodando em
**hospedagem compartilhada Hostinger sem SSH, sem Composer, sem Node, sem
worker, sem Redis**. Estas regras existem porque violá-las quebra produção
mesmo quando funciona em desenvolvimento.

## Ambiente (não negociável)

- **PHP 8.2 é o piso.** `composer.json` pina `config.platform.php = 8.2.0`.
  Nunca use sintaxe ou pacote que exija mais que 8.2. Extensões disponíveis:
  pdo_mysql, curl, mbstring, openssl, gd, zip, fileinfo, intl. **Não** conte
  com bcmath, imagick, ffmpeg, redis, exec/proc_open.
- **Sem processo persistente.** Fila drenada pelo Scheduler em janelas de 45s.
  Nenhuma requisição HTTP pode passar de ~10s; trabalho pesado vai para Job.
- **Scheduler usa `Schedule::call(fn () => Artisan::call(...))`, nunca
  `Schedule::command()`** — `command()` abre processo filho via proc_open,
  desabilitado na hospedagem. Todo agendamento tem `->name()` e
  `->withoutOverlapping(N)`.
- **Tudo em UTC no banco.** `APP_TIMEZONE=UTC`. Conversão só na apresentação,
  via `display_datetime()` / `App\Support\Display`. Data digitada pelo usuário
  entra com `Display::toUtc($valor, $client)`.
- **Mídia nunca é servida direto do disco.** Disco `local` tem `serve => false`;
  entrega passa por `MediaController` (checa Policy). Originais ficam fora do
  webroot. Ponte pública (`media-tmp`) é temporária e expurgada.
- **Sem symlink.** Não use `storage:link` nem `Storage::url()` do disco public.
- Nunca grave token/senha em log. Tokens de API usam cast `encrypted` e ficam
  em `$hidden`. Ao logar payloads de API, mascare tokens.

## Arquitetura

- Camadas: `Http/Controllers` + `Livewire` (apresentação, validação de entrada)
  → `Actions/` (1 classe = 1 caso de uso, recebe DTO tipado de
  `Support/DataObjects`) → `Services/` (clientes de API, processamento) →
  `Models/` + `Policies/`. **Nenhuma regra de negócio em controller ou
  componente Livewire.**
- Enums em `App\Support\Enums`. Status do post só muda via
  `Post::transitionTo()`; transição ilegal lança `InvalidStateTransition`.
- **Multi-tenancy:** todo model com `client_id` usa o trait `BelongsToClient`
  (aplica `ClientScope`). Jobs e comandos de console suspendem o escopo com
  `app(TenantContext::class)->withoutRestriction(fn () => ...)` — nunca por
  acidente. Route bindings de `client`, `post`, `asset` resolvem fora do escopo
  para que a negativa venha da Policy (403).
- **Todo model em `app/Models` precisa de Policy** em `app/Policies` com
  viewAny/view/create/update/delete (teste `PolicyCoverageTest` quebra sem).
  Policies usam o trait `TenantAware` (`allows()` checa cliente + permissão).
- Permissões: `App\Support\Enums\Permission` + matriz por papel em
  `Permission::forRole()`. Papéis: owner, admin, gestor, criador,
  client_admin, client_viewer.
- Chave pública em URL é sempre `ulid` (trait `HasUlidKey`). Nunca exponha id
  incremental.
- Colunas internas (`current_version`, `approved_version`, `ulid`,
  `email_verified_at`, tokens) ficam **fora** de `$fillable`; use
  `forceFill()`. Factories podem setá-las (rodam unguarded).
- Portal do cliente (`/portal`) nunca mostra tela técnica (token, fila, API).

## Integrações

- Instagram: **API with Instagram Login** (host `graph.instagram.com`,
  versão em `IG_API_VERSION`). Toda integração fica atrás de interface em
  `App\Services\Integrations`. Testes usam `Http::fake()` com fixtures de
  respostas reais em `tests/Fixtures/`.
- Retry de publicação é regra de negócio no post (backoff 1m/5m/15m/1h/4h,
  `next_attempt_at`, `publish_attempts`), **não** o retry da fila
  (`--tries=1`). Erro permanente (token revogado, mídia inválida) não tenta
  de novo: gera alerta acionável.
- Todo job é idempotente; chave = `post_id + current_version`
  (`Post::idempotencyKey()`).

## Qualidade

- Rode `php artisan test` antes de terminar qualquer tarefa; tudo tem de
  passar. Novas funcionalidades vêm com Feature tests (fluxo) e, quando houver
  lógica pura, Unit tests.
- Rode `./vendor/bin/pint` (com `COMPOSER_ALLOW_SUPERUSER=1` se root) antes de
  terminar. `deploy/diagnostico.php` e `bootstrap/ensure-app-key.php` estão
  excluídos.
- Migrations: string + enum PHP (não enum SQL), timestamps UTC, índices
  declarados. Compatível com SQLite (testes) e MySQL (produção).
- UI: Blade + Livewire 3 + Alpine + Tailwind 4. Mobile-first; estados vazios
  sempre dizem o próximo passo; toda mensagem de erro diz o que aconteceu e o
  que fazer. Texto em pt-BR. Nunca invente métrica: dado ausente aparece como
  "indisponível", não zero.
- Não faça commit; a integração faz. Não rode `composer update`, nem instale
  pacote que exija extensão fora da lista acima. Se precisar de pacote novo,
  `composer require` pontual, PHP 8.2-compatível, e explique no retorno.

## Comandos úteis

```bash
php artisan test                      # suíte completa (SQLite em memória)
php artisan test --filter=NomeDoTeste
COMPOSER_ALLOW_SUPERUSER=1 ./vendor/bin/pint
php artisan migrate --force           # local usa SQLite em database/database.sqlite
npm run build                         # assets vão versionados em public/build
```
