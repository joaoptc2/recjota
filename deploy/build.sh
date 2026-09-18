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
rm -rf "$STAGE" && mkdir -p "$STAGE/app" "$STAGE/document-root"

echo "==> Compilando assets (R7 — o servidor nunca executa o Vite)"
npm ci --silent
npm run build --silent

echo "==> Copiando a aplicação"
# Só o que o Laravel precisa em runtime. Ferramenta de desenvolvimento
# (node_modules, testes, .git, dist) fica de fora.
for item in app bootstrap config database public resources routes storage \
            artisan composer.json composer.lock cron.php cron.sh .htaccess; do
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

echo "==> Montando o document root"
# A pasta se chama "document-root" e não "public_html" porque o nome real varia
# por hospedagem e por domínio. O que vale é o CONTEÚDO dela.
cp "$ROOT/deploy/document-root-index.php" "$STAGE/document-root/index.php"
cp "$ROOT/deploy/document-root-htaccess"  "$STAGE/document-root/.htaccess"
cp "$ROOT/deploy/user.ini"                "$STAGE/document-root/.user.ini"
cp -R "$ROOT/public/build"                "$STAGE/document-root/build"
mkdir -p "$STAGE/document-root/media-tmp"
cp "$ROOT/public/media-tmp/.htaccess"     "$STAGE/document-root/media-tmp/.htaccess"

echo "==> Escrevendo as instruções dentro de cada pasta"
cat > "$STAGE/app/LEIA-ME.txt" <<'TXT'
ESTA PASTA VAI FORA DA ÁREA PÚBLICA.

Envie a pasta "app" inteira para o MESMO nível da pasta que o seu domínio
publica — ao lado dela, nunca dentro.

Na Hostinger, com o domínio seudominio.com.br, fica assim:

  /home/uXXXXXXXX/domains/seudominio.com.br/
  ├── app/          <-- esta pasta, aqui
  └── public_html/  <-- a pasta que o domínio publica

Aqui dentro estão o .env, o vendor e o storage. Se esta pasta acabar dentro da
área pública, essas coisas ficam acessíveis pela internet.
TXT

cat > "$STAGE/document-root/LEIA-ME.txt" <<'TXT'
O CONTEÚDO DESTA PASTA VAI PARA A ÁREA PÚBLICA DO SEU DOMÍNIO.

Atenção: o CONTEÚDO, não a pasta. Depois do envio, o index.php precisa estar
solto na pasta publicada — não dentro de uma subpasta chamada "document-root".

Como descobrir qual é a pasta publicada:
  hPanel > Sites > Gerenciador de Arquivos. A pasta que abre por padrão, com o
  nome do seu domínio, é ela. Costuma se chamar "public_html".
  Se ela não existir, crie-a com esse nome.

Resultado esperado:

  /home/uXXXXXXXX/domains/seudominio.com.br/public_html/
  ├── index.php
  ├── .htaccess
  ├── .user.ini
  ├── build/
  └── media-tmp/

Os arquivos que começam com ponto (.htaccess, .user.ini) são ocultos: ligue a
exibição de arquivos ocultos no seu cliente de FTP, senão eles não sobem.
TXT

cp "$ROOT/DEPLOY.md" "$STAGE/LEIA-ME-PRIMEIRO.md"

# Fica na raiz do pacote, e não no document root, porque é ferramenta de
# emergência: só entra no ar quando alguém decide copiá-la para lá.
cp "$ROOT/deploy/diagnostico.php" "$STAGE/diagnostico.php"

echo "==> Conferindo o pacote"
test -f "$STAGE/app/vendor/autoload.php"       || { echo "FALHOU: vendor ausente"; exit 1; }
test -f "$STAGE/app/.env"                      || { echo "FALHOU: .env ausente"; exit 1; }
test -f "$STAGE/document-root/build/manifest.json" || { echo "FALHOU: assets ausentes"; exit 1; }
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
