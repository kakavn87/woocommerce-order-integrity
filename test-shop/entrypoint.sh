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
