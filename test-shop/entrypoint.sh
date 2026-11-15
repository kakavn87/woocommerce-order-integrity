#!/bin/bash
set -e

mkdir -p /run/mysqld
chown -R mysql:mysql /run/mysqld /var/lib/mysql

if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql >/dev/null
fi

mariadbd-safe --datadir=/var/lib/mysql --skip-networking=0 --bind-address=127.0.0.1 &
for i in {1..60}; do
  if mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; then
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "ERROR: MariaDB did not become ready."
    exit 1
  fi
  sleep 1
done

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$MYSQL_DATABASE\`;
CREATE USER IF NOT EXISTS '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASSWORD';
GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'localhost';
ALTER USER '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASSWORD';
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';
FLUSH PRIVILEGES;
SQL

cd /var/www/html

if [ ! -f wp-config.php ]; then
  echo "==> Creating WordPress configuration..."
  wp config create \
    --allow-root \
    --dbname="$MYSQL_DATABASE" \
    --dbuser="$MYSQL_USER" \
    --dbpass="$MYSQL_PASSWORD" \
    --dbhost="127.0.0.1:3306" \
    --skip-check
fi

# Render terminates TLS at the proxy. Tell WordPress that the original request was HTTPS.
if ! grep -q "HTTP_X_FORWARDED_PROTO" wp-config.php; then
  cat >> wp-config.php <<'PHP'

// Render reverse-proxy HTTPS handling.
// Render terminates TLS before forwarding the request to Apache.
// Accept comma-separated forwarded protocol values as well.
if (
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos(strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']), 'https') !== false)
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}
PHP
fi

wp config set WP_HOME "https://oi-test-shop-v5fg.onrender.com" --type=constant --allow-root
wp config set WP_SITEURL "https://oi-test-shop-v5fg.onrender.com" --type=constant --allow-root
# The public Render URL is already HTTPS. Do not force a second admin SSL redirect,
# which can create a redirect loop behind the Render reverse proxy.
wp config set FORCE_SSL_ADMIN false --raw --type=constant --allow-root

echo "==> Checking WordPress database..."
wp db check --allow-root

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  echo "==> WordPress is not installed. Installing automatically..."
  wp core install \
    --allow-root \
    --url="https://oi-test-shop-v5fg.onrender.com" \
    --title="Order Integrity Test Shop" \
    --admin_user="admin" \
    --admin_password="admin12346789" \
    --admin_email="test@example.com" \
    --skip-email
else
  echo "==> WordPress is already installed."
fi

echo "==> Verifying WordPress installation..."
wp core is-installed --allow-root

echo "==> Activating WooCommerce..."
wp plugin activate woocommerce --allow-root

echo "==> Activating Order Integrity..."
wp plugin activate order-integrity --allow-root

echo "==> WordPress test shop ready."

exec apache2-foreground
