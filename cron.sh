#!/bin/bash
#
# Cron mestre (Seção 8.1).
#
# No hPanel, cadastrar como tipo "Custom" — o tipo "PHP" não aceita os
# caracteres > e & desta linha (R3):
#
#   * * * * * /bin/bash /home/uXXXXXXXX/domains/seudominio.com.br/app/cron.sh
#
# Confirme o caminho do binário PHP **CLI** em hPanel > PHP Configuration; ele
# costuma diferir do PHP usado pelo servidor web.

set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

# Fallback para o php do PATH quando o caminho do plano for outro.
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="$(command -v php)"
fi

cd "$APP_DIR" || exit 1

mkdir -p storage/logs

"$PHP_BIN" artisan schedule:run >> storage/logs/cron.log 2>&1
