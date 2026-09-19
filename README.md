# Recjota — gestão de conteúdo e aprovações para agência

Sistema web multi-tenant para uma agência de marketing digital gerenciar
múltiplos perfis de clientes e, no mesmo produto, dar ao cliente uma via curta
para **aprovar conteúdo**.

O princípio condutor é esse: o gargalo de uma agência não é publicar, é
conseguir aprovação. Cada decisão de produto aqui existe para reduzir atrito no
ciclo `criar → revisar → aprovar → publicar`.

## Estado atual: Fase 1 concluída

| Fase | Escopo | Situação |
|------|--------|----------|
| 1 | Fundação: schema, autenticação, papéis, multi-tenancy, layout base | ✅ entregue |
| 2 | Conteúdo e calendário (composer, biblioteca de mídia, 4 visões) | ✅ entregue |
| 3 | Aprovação (versionamento, links mágicos, portal, notificações) | ✅ entregue |
| 4 | Integração Instagram (OAuth, ponte de mídia, motor de publicação) | pendente |
| 5 | Google Drive e OneDrive | pendente |
| 6 | Métricas e relatórios | pendente |
| 7 | Refino, performance, acessibilidade e documentação final | pendente |

## Como isto chega na hospedagem

O servidor **não roda Composer, não roda Node e não precisa de SSH**. O deploy é
cópia de arquivos mais quatro telas no navegador:

1. Você baixa o **pacote de instalação** na página de Releases — um `.zip` de
   ~20 MB com as dependências instaladas, os assets compilados e uma `APP_KEY`
   já gerada, remontado a cada alteração no código. (`./deploy/build.sh` gera o
   mesmo pacote localmente.) **O botão "Code › Download ZIP" não serve**: ele
   entrega o código-fonte sem as bibliotecas.
2. Você envia as duas pastas do pacote por FTP ou pelo Gerenciador de Arquivos:
   `app/` fica **ao lado** da pasta que o domínio publica e o **conteúdo** de
   `document-root/` vai **dentro** dela. O nome dessa pasta (`public_html` na
   Hostinger) não está escrito em lugar nenhum do código — o `index.php`
   localiza a aplicação sozinho.
3. Abre o site: o **instalador web** confere o ambiente, testa a conexão com o
   banco, cria as tabelas e o primeiro usuário — e depois disso desaparece.
4. Cadastra **um** cron job. `cron.php` para o tipo "PHP" do hPanel, `cron.sh`
   para o tipo "Custom". Sem ele nada é publicado.

Depois da instalação, `/manutencao` substitui o terminal: migrar o banco, limpar
e reconstruir caches, processar a fila. Lista fechada de comandos, nenhum deles
digitável.

Se algo der errado no envio, `deploy/diagnostico.php` é um arquivo avulso que
você copia para a pasta publicada: ele diz onde os arquivos estão, quem
consegue lê-los e o que falta, atrás de uma chave que só quem tem acesso à
pasta consegue ler. Passo a passo completo em [DEPLOY.md](DEPLOY.md).

## Stack

PHP 8.2+ · Laravel 12 · MySQL 8 / MariaDB 10.11+ · Livewire 3 + Alpine ·
Tailwind CSS 4 · Blade · spatie/laravel-permission · spatie/laravel-activitylog ·
intervention/image · Guzzle.

Cache, fila e sessão no driver `database`. Sem Redis, sem Docker, sem Node em
runtime, sem worker persistente — o alvo é hospedagem compartilhada Hostinger.

## Ambiente local

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Para desenvolvimento local o SQLite basta; em produção é MySQL.
touch database/database.sqlite
# ajuste .env: DB_CONNECTION=sqlite  (e comente as variáveis DB_* restantes)

php artisan migrate --seed
npm run build      # ou npm run dev enquanto estiver mexendo em CSS/JS
php artisan serve
```

### Usuários da base demo

Senha de todos: `senha-demo-2026`

| E-mail | Papel | Enxerga |
|--------|-------|---------|
| `owner@agencia.test` | owner | toda a agência |
| `gestor@agencia.test` | gestor | Acme Café e Bonsai Studio |
| `criador@agencia.test` | criador | Acme Café e Bonsai Studio |
| `aprovador@acme.test` | client_admin | somente Acme Café |
| `leitor@bonsai.test` | client_viewer | somente Bonsai Studio |

Usuários da agência caem em `/painel`; usuários de cliente caem em `/portal`.

## Testes

```bash
php artisan test
```

O teste de isolamento multi-tenant (`tests/Feature/Tenancy/TenantIsolationTest.php`)
é obrigatório em CI: ele prova que um usuário do cliente A recebe **403** ao
pedir um recurso do cliente B por ID direto na URL.

## Decisões estruturais que valem para todas as fases

**Nada depende de terminal no servidor.** Instalação, migração e manutenção têm
caminho pelo navegador. O instalador some depois do primeiro usuário; o console
de manutenção só roda comandos de um catálogo fechado
(`App\Support\MaintenanceCommands`) e exige papel de proprietário.

**O agendador roda em processo.** `routes/console.php` usa `Schedule::call()` em
vez de `Schedule::command()`, porque `command()` abre processo filho via
`proc_open` — função que parte das hospedagens compartilhadas desabilita.

**Nenhum caminho de servidor fixo no código.** O front controller do document
root procura a aplicação nas pastas vizinhas e, se não achar, explica o que
houve em vez de estourar um erro fatal. Nada depende de a pasta publicada se
chamar `public_html`.

**Mídia nunca é servida direto do disco.** O arquivo vive fora do webroot e a
entrega passa por `MediaController`, que consulta a Policy antes de abrir o
arquivo. O disco `local` tem `serve => false` de propósito: a rota embutida do
Laravel entrega qualquer arquivo sem checar autorização.

**Sem symlink de storage.** O disco `local` do Laravel serve os arquivos por
rota própria, o que dispensa `php artisan storage:link` — impossível de rodar
sem SSH quando a função `symlink()` está desabilitada.

**Fuso horário.** `APP_TIMEZONE=UTC`. Toda coluna `datetime` é UTC. A conversão
para o fuso do cliente acontece só na exibição, via `display_datetime()` /
`App\Support\Display`. Nenhuma data de agendamento é gravada em horário local.

**Multi-tenancy.** Um banco só. Todo registro de cliente carrega `client_id`, e
o trait `BelongsToClient` aplica um Global Scope que filtra por cliente
acessível. Nenhuma query de domínio depende de o desenvolvedor lembrar de
filtrar. Jobs e comandos suspendem o escopo explicitamente com
`tenant()->withoutRestriction(...)`.

**403 em vez de 404.** O route model binding de `Client` e `Post` resolve
**fora** do escopo de tenant, de propósito: assim a negativa vem da Policy, como
403 explícito e auditável, em vez de um 404 silencioso. O Global Scope continua
protegendo toda listagem.

**Máquina de estados.** `App\Support\Enums\PostStatus` concentra as transições
legais do post. Qualquer transição ilegal lança `InvalidStateTransition`.
Ninguém escreve `status` direto: usa-se `Post::transitionTo()`.

**Sem worker.** Um único cron chama `schedule:run`; a fila é drenada em janelas
de 50 s com `--stop-when-empty`. Ver `routes/console.php` e `cron.sh`.

## Providências fora do código (começam no dia 1)

Levam semanas e são o caminho crítico do projeto:

1. Criar o app no [Meta for Developers](https://developers.facebook.com/) e
   adicionar o produto **Instagram** (API with Instagram Login, host
   `graph.instagram.com` — não exige Página do Facebook).
2. Publicar **política de privacidade** e **termos de uso** no domínio (exigidos
   na submissão).
3. Converter as contas Instagram dos clientes para **Professional** (Business ou
   Creator). Stories por API só funcionam em conta Business.
4. **Submeter o App Review** para `instagram_business_content_publish` assim que
   houver fluxo demonstrável. Este é o item de maior prazo de todo o projeto:
   conte com 2 a 6 semanas e possivelmente mais de uma rodada. Enquanto o app
   estiver em desenvolvimento, só contas com papel de testador funcionam.
5. Criar projeto no Google Cloud, ativar **Drive API** e **Picker API**,
   configurar a tela de consentimento com o escopo `drive.file` (não-sensível —
   escopos amplos disparam verificação anual paga).
6. Registrar o app no **Microsoft Entra ID** (multi-tenant, plataforma Web) com
   verificação de publisher. Escopos: `Files.Read`, `Files.ReadWrite`,
   `offline_access` e `Sites.Read.All` — sem o último o File Picker v8 não
   funciona.
7. Configurar **SPF, DKIM e DMARC** no domínio.

## Deploy

Ver [DEPLOY.md](DEPLOY.md).
