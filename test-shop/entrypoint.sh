#!/bin/bash
set -e

mkdir -p /run/mysqld
chown -R mysql:mysql /run/mysqld /var/lib/mysql

if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql >/dev/null
fi

mysqld_safe --datadir=/var/lib/mysql --skip-networking=0 --bind-address=127.0.0.1 &
for i in {1..60}; do
  if mysqladmin ping -h 127.0.0.1 --silent; then
    break
  fi
  sleep 1
done

mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$MYSQL_DATABASE\`;
CREATE USER IF NOT EXISTS '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASSWORD';
GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'localhost';
ALTER USER '$MYSQL_USER'@'localhost' IDENTIFIED BY '$MYSQL_PASSWORD';
GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'localhost';
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';
FLUSH PRIVILEGES;
SQL

export WORDPRESS_DB_HOST=127.0.0.1:3306
export WORDPRESS_DB_NAME=$MYSQL_DATABASE
export WORDPRESS_DB_USER=$MYSQL_USER
export WORDPRESS_DB_PASSWORD=$MYSQL_PASSWORD
export WORDPRESS_CONFIG_EXTRA="if (!defined('WP_DEBUG')) { define('WP_DEBUG', true); } if (!defined('WP_DEBUG_LOG')) { define('WP_DEBUG_LOG', true); } if (!defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', false); }"

/usr/local/bin/docker-entrypoint.sh true

echo "==> Checking WordPress database..."
wp db check --allow-root --path=/var/www/html

if ! wp core is-installed --allow-root --path=/var/www/html >/dev/null 2>&1; then
  echo "==> WordPress is not installed. Installing automatically..."
  wp core install \
    --allow-root \
    --path=/var/www/html \
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
wp core is-installed --allow-root --path=/var/www/html

echo "==> Activating WooCommerce..."
wp plugin activate woocommerce --allow-root --path=/var/www/html

echo "==> Activating Order Integrity..."
wp plugin activate order-integrity --allow-root --path=/var/www/html

echo "==> WordPress test shop ready."

exec apache2-foreground
