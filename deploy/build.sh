#!/bin/bash
#
# Monta o pacote de instalação para hospedagem compartilhada.
#
#   ./deploy/build.sh
#
# Roda NA SUA MÁQUINA (onde existem Composer e Node). O resultado é um .zip com
# tudo pronto: dependências instaladas, assets compilados, .env já com uma
# APP_KEY gerada, e os dois diretórios do layout da Hostinger montados.
#
# O servidor não precisa de Composer, de Node, nem de SSH.
#
# O diretório de trabalho não é alterado: as dependências de produção são
# instaladas dentro da cópia em dist/, não aqui.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$DIST/recjota"
VERSAO="$(date -u +%Y%m%d-%H%M)"
PACOTE="$DIST/recjota-$VERSAO.zip"

cd "$ROOT"

echo "==> Limpando build anterior"
rm -rf "$STAGE" && mkdir -p "$STAGE/app" "$STAGE/public_html"

echo "==> Compilando assets (R7 — o servidor nunca executa o Vite)"
npm ci --silent
npm run build --silent

echo "==> Copiando a aplicação"
# Só o que o Laravel precisa em runtime. Ferramenta de desenvolvimento
# (node_modules, testes, .git, dist) fica de fora.
for item in app bootstrap config database public resources routes storage \
            artisan composer.json composer.lock cron.php cron.sh; do
    cp -R "$ROOT/$item" "$STAGE/app/"
done

# storage/ vai com a estrutura de pastas, sem lixo de desenvolvimento.
rm -rf "$STAGE/app/storage/logs/"* \
       "$STAGE/app/storage/framework/cache/data/"* \
       "$STAGE/app/storage/framework/sessions/"* \
       "$STAGE/app/storage/framework/views/"* \
       "$STAGE/app/storage/app/installed.lock" \
       "$STAGE/app/database/database.sqlite"

# Caches de bootstrap nunca vão no pacote: apontariam para caminhos da sua máquina.
rm -f "$STAGE/app/bootstrap/cache/"*.php

echo "==> Instalando dependências PHP dentro do pacote (sem dev)"
# --prefer-dist evita clonar repositórios inteiros para dentro de vendor/, o
# que multiplicaria tamanho e inodes no servidor (R8).
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    --no-scripts --quiet --working-dir="$STAGE/app"

echo "==> Enxugando vendor/ (espaço e inodes são limitados — R8)"
# Nada aqui é usado em runtime: histórico do git, configuração de CI, testes e
# documentação das bibliotecas.
find "$STAGE/app/vendor" -type d \( -name '.git' -o -name '.github' -o -name 'tests' -o -name 'Tests' \
     -o -name 'test' -o -name 'doc' -o -name 'docs' -o -name 'examples' \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE/app/vendor" -type f \( -name '*.md' -o -name '.editorconfig' -o -name '.gitattributes' \
     -o -name '.gitignore' -o -name 'phpunit.xml*' -o -name '.php-cs-fixer*' \) -delete 2>/dev/null || true

# Com arquivos removidos, o autoloader é reconstruído a partir do que sobrou.
composer dump-autoload --no-dev --optimize --no-interaction --quiet --working-dir="$STAGE/app"

echo "==> Gerando .env com uma APP_KEY nova"
cp "$ROOT/.env.example" "$STAGE/app/.env"
CHAVE="base64:$(head -c 32 /dev/urandom | base64)"
# macOS e GNU sed têm sintaxes diferentes para -i; o .bak resolve os dois.
sed -i.bak "s|^APP_KEY=.*|APP_KEY=$CHAVE|" "$STAGE/app/.env" && rm -f "$STAGE/app/.env.bak"

echo "==> Montando o document root (public_html)"
cp "$ROOT/deploy/public_html-index.php" "$STAGE/public_html/index.php"
cp "$ROOT/deploy/public_html-htaccess"  "$STAGE/public_html/.htaccess"
cp "$ROOT/deploy/user.ini"              "$STAGE/public_html/.user.ini"
cp -R "$ROOT/public/build"              "$STAGE/public_html/build"
mkdir -p "$STAGE/public_html/media-tmp"
cp "$ROOT/public/media-tmp/.htaccess"   "$STAGE/public_html/media-tmp/.htaccess"

cp "$ROOT/DEPLOY.md" "$STAGE/LEIA-ME-PRIMEIRO.md"

echo "==> Conferindo o pacote"
test -f "$STAGE/app/vendor/autoload.php"       || { echo "FALHOU: vendor ausente"; exit 1; }
test -f "$STAGE/app/.env"                      || { echo "FALHOU: .env ausente"; exit 1; }
test -f "$STAGE/public_html/build/manifest.json" || { echo "FALHOU: assets ausentes"; exit 1; }
grep -q '^APP_KEY=base64:' "$STAGE/app/.env"   || { echo "FALHOU: APP_KEY não gerada"; exit 1; }

echo "==> Compactando"
cd "$DIST"
zip -qr "$PACOTE" recjota
cd "$ROOT"

echo
echo "Pacote pronto: $PACOTE"
echo "Tamanho: $(du -h "$PACOTE" | cut -f1)   Arquivos: $(unzip -l "$PACOTE" | tail -1 | awk '{print $2}')"
echo
echo "ATENCAO: o .env dentro do pacote tem a APP_KEY que criptografa os tokens"
echo "das contas conectadas. Guarde uma copia. Trocar essa chave depois torna"
echo "todos os tokens ilegiveis."
