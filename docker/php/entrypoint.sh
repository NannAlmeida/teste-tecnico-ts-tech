#!/bin/sh
set -e

cd /var/www/html

# O php-fpm roda como www-data, e o bind mount chega com o dono do host.
mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar
chmod -R 777 writable

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] instalando dependencias"
    composer install --no-interaction --prefer-dist
fi

# A checagem usa o proprio mysqli em vez do cliente de linha de comando: o
# compose ja espera o healthcheck do banco, entao isto e so rede de seguranca e
# nao justifica carregar o pacote mysql-client na imagem.
echo "[entrypoint] aguardando o MySQL em ${DB_HOST}"
until php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASS")) ? 0 : 1);'; do
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
