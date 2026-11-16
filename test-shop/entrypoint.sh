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

# Render terminates TLS at the proxy. This disposable test shop is HTTPS-only.
cat >> wp-config.php <<'PHP'

// Render test shop: public endpoint is HTTPS-only.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
PHP

# Use Render's actual public URL instead of hard-coding a service name.
PUBLIC_URL="${RENDER_EXTERNAL_URL:-https://oi-test-shop-clean.onrender.com}"

wp config set WP_HOME "$PUBLIC_URL" --type=constant --allow-root
wp config set WP_SITEURL "$PUBLIC_URL" --type=constant --allow-root
wp config set FORCE_SSL_ADMIN false --raw --type=constant --allow-root

echo "==> WordPress public URL: $PUBLIC_URL"
echo "==> Checking WordPress database..."
wp db check --allow-root

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  echo "==> WordPress is not installed. Installing automatically..."
  wp core install \
    --allow-root \
    --url="$PUBLIC_URL" \
    --title="Order Integrity Test Shop" \
    --admin_user="admin" \
    --admin_password="admin12346789" \
    --admin_email="test@example.com" \
    --skip-email
else
  echo "==> WordPress is already installed."
  wp option update home "$PUBLIC_URL" --allow-root
  wp option update siteurl "$PUBLIC_URL" --allow-root
fi

echo "==> Verifying WordPress installation..."
wp core is-installed --allow-root

# Ensure the disposable test account is a real administrator.
# This is idempotent and also repairs an existing test database.
wp user session destroy admin --all --allow-root || true
wp user set-role admin administrator --allow-root
wp user meta update admin wp_user_level 10 --allow-root
wp eval 'if (!user_can(get_user_by("login", "admin"), "manage_options")) { fwrite(STDERR, "ERROR: admin lacks manage_options\\n"); exit(1); }' --allow-root

# WooCommerce needs a writable log directory on every fresh container.
mkdir -p /var/www/html/wp-content/uploads/wc-logs
chown -R www-data:www-data /var/www/html/wp-content/uploads

echo "==> Activating WooCommerce..."
wp plugin activate woocommerce --allow-root
echo "==> Activating Order Integrity..."
wp plugin activate order-integrity --allow-root
echo "==> WordPress test shop ready."

exec apache2-foreground
