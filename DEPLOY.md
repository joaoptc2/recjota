# Deploy na Hostinger (hospedagem compartilhada)

Este documento cresce a cada fase. O que está aqui já é suficiente para colocar
a Fase 1 no ar e validar o cron.

## 1. Antes de subir qualquer coisa

Confira no hPanel e anote:

- [ ] **Versão do PHP web** — hPanel › PHP Configuration. Precisa ser 8.2+.
- [ ] **Versão do PHP CLI** — é frequentemente diferente da web. Descubra o
      caminho do binário (`/opt/alt/php83/usr/bin/php`, por exemplo) e confirme
      com `<caminho> -v`. O `cron.sh` usa esse caminho, não o `php` do PATH.
- [ ] **`memory_limit` e `max_execution_time`** do plano — hPanel › PHP Info.
      Se `memory_limit` for menor que 256M, ajuste as expectativas de upload.
- [ ] **Extensões** — precisam existir: `pdo_mysql`, `curl`, `mbstring`,
      `openssl`, `gd`, `zip`, `fileinfo`, `intl`.
- [ ] **Limite de cron jobs do plano** — o sistema foi desenhado para funcionar
      com **um único** cron (R4), mas confirme.
- [ ] **SSL ativo** no domínio, com redirecionamento forçado.

## 2. Estrutura de diretórios (R9)

O document root da Hostinger é `public_html`, e o Laravel espera servir de
`public/`. A solução, sem mexer em configuração de servidor, é manter o projeto
**fora** do document root:

```
/home/uXXXXXXXX/domains/seudominio.com.br/
├── app/                  ← este repositório (Laravel completo)
│   ├── app/ bootstrap/ config/ database/ resources/ routes/
│   ├── storage/ vendor/ .env cron.sh artisan
│   └── public/
└── public_html/          ← DOCUMENT ROOT
    ├── index.php         ← cópia de deploy/public_html-index.php
    ├── .htaccess         ← cópia de deploy/public_html-htaccess
    ├── .user.ini         ← cópia de deploy/user.ini
    ├── build/            ← cópia de app/public/build (assets compilados)
    ├── media-tmp/        ← ponte de mídia pública, com .htaccess restritivo
    └── storage/          ← symlink para ../app/storage/app/public
```

Nunca deixe `.env`, `storage/` ou `vendor/` acessíveis via HTTP.

## 3. Build local (R7 — o servidor não roda Node)

Na sua máquina, antes de enviar:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build          # gera public/build, que vai versionado
```

## 4. Envio

Com SSH disponível (preferível):

```bash
cd /home/uXXXXXXXX/domains/seudominio.com.br/app
git pull
composer install --no-dev --optimize-autoloader
```

Sem SSH: envie por SFTP o conteúdo do projeto para `app/`, incluindo `vendor/` e
`public/build/`.

## 5. Configuração

```bash
cd /home/uXXXXXXXX/domains/seudominio.com.br/app

cp .env.example .env     # preencha DB_*, MAIL_*, APP_URL
php artisan key:generate --force

php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Permissões: `storage/` e `bootstrap/cache/` graváveis (755, ou 775 conforme o
usuário do PHP).

Link de storage — se `symlink()` estiver desabilitado, crie manualmente:

```bash
php artisan storage:link || ln -s ../app/storage/app/public ../public_html/storage
```

Copie os arquivos de `deploy/` para o document root:

```bash
cp deploy/public_html-index.php   ../public_html/index.php
cp deploy/public_html-htaccess    ../public_html/.htaccess
cp deploy/user.ini                ../public_html/.user.ini
cp -r public/build                ../public_html/build
mkdir -p ../public_html/media-tmp
cp public/media-tmp/.htaccess     ../public_html/media-tmp/.htaccess
```

## 6. Cron (Seção 8.1)

No hPanel › Cron Jobs, crie **um** job do tipo **Custom** (o tipo "PHP" não
aceita `>` nem `&`, R3), rodando a cada minuto:

```
* * * * * /bin/bash /home/uXXXXXXXX/domains/seudominio.com.br/app/cron.sh
```

Se o seu plano limitar a frequência, `*/5 * * * *` também funciona — as janelas
de publicação apenas ficam menos precisas.

O cron do hPanel roda em **UTC** (R2). É por isso que o banco inteiro é UTC.

### Validar o cron

1. Espere dois minutos e verifique `storage/logs/cron.log`.
2. Abra `https://seudominio.com.br/health`. O campo
   `checks.cron_heartbeat.ok` precisa ser `true`. Enquanto o cron não rodar, o
   endpoint responde **503** — de propósito.

## 7. Checklist de produção

- [ ] `/health` responde `200` com `status: ok`.
- [ ] SSL válido e HTTP redirecionando para HTTPS.
- [ ] `APP_DEBUG=false` e `APP_ENV=production`.
- [ ] `.env` inacessível via HTTP (`https://seudominio.com.br/.env` → 403/404).
- [ ] Envio de e-mail funcionando (teste com a recuperação de senha).
- [ ] SPF, DKIM e DMARC configurados no DNS.
- [ ] Login funcionando para um usuário da agência e um do cliente.
- [ ] Teste ponta a ponta de publicação em conta sandbox — **a partir da Fase 4**.

## 8. Backup

A hospedagem compartilhada não garante backup próprio. A partir da Fase 7 há
rotina automática; até lá, faça o dump manualmente pelo hPanel › Backups ou via
phpMyAdmin, e guarde fora do servidor. Retenção recomendada: 14 dias.
