#!/usr/bin/env bash
# Ejecutar en Ubuntu con sudo desde una copia del repositorio.
# Uso: sudo bash deploy/install-node.sh FrontEnd 10.0.0.10 web,balancer
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Se requiere sudo.' >&2; exit 1; }
NODE=${1:?Nombre de instancia}; PRIVATE_IP=${2:?IP privada}; ROLES=${3:?web,balancer/backend/database}
[[ $NODE =~ ^[A-Za-z0-9_-]+$ && $PRIVATE_IP =~ ^10\.0\.0\.[0-9]+$ ]] || exit 2
[[ -f config.local.php ]] || { echo 'Falta config.local.php privado.' >&2; exit 2; }
export DEBIAN_FRONTEND=noninteractive
if [[ ${CLOUD1_SKIP_PACKAGES:-0} != 1 ]]; then
    apt-get update -qq
    apt-get -o DPkg::Lock::Timeout=180 install -y -qq nginx php-fpm php-curl php-mysql php-mbstring
    if [[ ,$ROLES, == *,database,* ]]; then apt-get -o DPkg::Lock::Timeout=180 install -y -qq mysql-server; fi
fi
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo 'Se requiere PHP 8.1 o superior.' >&2; exit 2; }

# Copia de seguridad de la configuración y del código anterior, sin borrar datos.
BACKUP=/var/backups/cloud1/$(date +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP"
[[ ! -d /var/www/cloud1 ]] || cp -a /var/www/cloud1 "$BACKUP/app"
cp -a /etc/nginx "$BACKUP/nginx"
cp -a /etc/php "$BACKUP/php"
install -d -o root -g www-data -m 0755 /var/www/cloud1 /run/cloud1
tar --exclude=.git --exclude=.local --exclude=.env --exclude=config.local.php --exclude='*.key' --exclude='*.pem' -cf - . | tar -xf - -C /var/www/cloud1
chown -R root:www-data /var/www/cloud1
chmod -R g=rX,o=rX /var/www/cloud1
install -o root -g www-data -m 0640 config.local.php /var/www/cloud1/config.local.php

cat > /etc/php/"$PHP_VERSION"/fpm/pool.d/cloud1.conf <<'POOL'
[cloud1]
user = www-data
group = www-data
listen = /run/cloud1/php-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
clear_env = yes
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
POOL
if [[ ,$ROLES, == *,backend,* ]]; then
    # La API tiene procesos propios para evitar que las webs esperen al mismo pool.
    sed -e 's/\[cloud1\]/[cloud1-api]/' -e 's#php-fpm.sock#php-api.sock#' \
        /etc/php/"$PHP_VERSION"/fpm/pool.d/cloud1.conf > /etc/php/"$PHP_VERSION"/fpm/pool.d/cloud1-api.conf
fi
echo 'd /run/cloud1 0755 root root -' > /etc/tmpfiles.d/cloud1.conf
render() { sed -e "s/__PRIVATE_IP__/$PRIVATE_IP/g" -e 's/__BALANCER_IP__/10.0.0.10/g' "$1" > "$2"; }
render deploy/nginx/web.conf.template /etc/nginx/conf.d/cloud1-web.conf
# Las webs internas solo escuchan en su IP privada:8080.
if [[ -L /etc/nginx/sites-enabled/default ]]; then rm /etc/nginx/sites-enabled/default; fi
if [[ ,$ROLES, == *,backend,* ]]; then render deploy/nginx/api.conf.template /etc/nginx/conf.d/cloud1-api.conf; fi
if [[ ,$ROLES, == *,balancer,* ]]; then
    render deploy/nginx/balancer.conf.template /etc/nginx/conf.d/cloud1-balancer.conf
    # El sitio default de nginx también escucha en 80 como default_server.
    if [[ -L /etc/nginx/sites-enabled/default ]]; then rm /etc/nginx/sites-enabled/default; fi
    if systemctl is-active --quiet apache2; then
        echo 'Apache está usando el puerto 80. Detén Apache en esta VM antes de activar el balanceador.' >&2
        exit 2
    fi
fi
nginx -t
if command -v ufw >/dev/null && ufw status | head -n 1 | grep -q '^Status: active'; then
    ufw allow from 10.0.0.10 to "$PRIVATE_IP" port 8080 proto tcp
    if [[ ,$ROLES, == *,balancer,* ]]; then ufw allow 80/tcp; fi
    if [[ ,$ROLES, == *,backend,* ]]; then
        for SOURCE in 10.0.0.10 10.0.0.11 10.0.0.12; do ufw allow from "$SOURCE" to "$PRIVATE_IP" port 8081 proto tcp; done
    fi
    if [[ ,$ROLES, == *,database,* ]]; then ufw allow from 10.0.0.11 to "$PRIVATE_IP" port 3306 proto tcp; fi
fi
systemctl enable --now "php$PHP_VERSION-fpm" nginx
systemctl restart "php$PHP_VERSION-fpm"
systemctl reload nginx
echo "Listo: $NODE ($ROLES). Respaldo: $BACKUP"
