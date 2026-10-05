#!/bin/sh
# Entrypoint do container `api` (nginx + php-fpm). Só ele roda migrate/seed: os workers
# esperam este container ficar "healthy" (docker-compose depends_on).
set -e
cd /app

if [ ! -f .env ]; then
  echo "ERRO: api/.env não existe. Rode 'make setup' (copia .env.example)." >&2
  exit 1
fi
if ! grep -qE '^APP_KEY=base64:.+' .env; then
  echo "ERRO: APP_KEY vazio em api/.env. Use a chave de dev do .env.example." >&2
  exit 1
fi

# Diretórios de runtime (B2) e permissões: php-fpm, workers e scheduler rodam como www-data.
for d in storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/framework/testing storage/fonts storage/logs bootstrap/cache; do
  mkdir -p "$d"
done
rm -f bootstrap/cache/*.php          # caches gerados fora do container (B9)
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

as_app() { su-exec www-data "$@"; }

as_app php artisan package:discover --ansi

# B8: imagens de evento (como root: public/ pertence ao usuário do host no bind mount)
rm -f public/storage
ln -sfn ../storage/app/public public/storage

as_app php artisan migrate --force

as_app php artisan migrate --force
[ "${RUN_SEEDERS:-true}" = "true" ] && as_app php artisan db:seed --force

php-fpm -D
exec nginx -g "daemon off;"
