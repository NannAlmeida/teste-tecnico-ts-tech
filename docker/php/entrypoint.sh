#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] instalando dependencias"
    composer install --no-interaction --prefer-dist
fi

echo "[entrypoint] aguardando o MySQL em ${DB_HOST}"
until mysqladmin ping -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" --silent; do
    sleep 2
done

echo "[entrypoint] aplicando migrations"
php spark migrate

# O DatabaseSeeder limpa antes de popular, entao reiniciar o container devolve o
# ambiente ao mesmo conjunto de dados. Util para avaliacao, destrutivo para uso
# real: SEED_ON_START=false desliga.
if [ "${SEED_ON_START:-true}" = "true" ]; then
    echo "[entrypoint] populando dados de demonstracao"
    php spark db:seed DatabaseSeeder
fi

echo "[entrypoint] pronto em http://localhost:8080"

exec "$@"
