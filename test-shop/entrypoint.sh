#!/bin/bash
set -e

cd /var/www/html

# WordPress uses an external persistent MySQL/MariaDB database.
# Render's local filesystem is ephemeral, so the database must not live in this container.
: "${WORDPRESS_DB_HOST:?WORDPRESS_DB_HOST is required}"
: "${WORDPRESS_DB_NAME:?WORDPRESS_DB_NAME is required}"
: "${WORDPRESS_DB_USER:?WORDPRESS_DB_USER is required}"
: "${WORDPRESS_DB_PASSWORD:?WORDPRESS_DB_PASSWORD is required}"

if [ ! -f wp-config.php ]; then
  echo "==> Creating WordPress configuration for external database..."
  wp config create     --allow-root     --dbname="$WORDPRESS_DB_NAME"     --dbuser="$WORDPRESS_DB_USER"     --dbpass="$WORDPRESS_DB_PASSWORD"     --dbhost="$WORDPRESS_DB_HOST"     --skip-check
fi

# Render terminates TLS at the proxy. This test shop is HTTPS-only.
if ! grep -q "Render test shop: public endpoint is HTTPS-only" wp-config.php; then
  cat >> wp-config.php <<'PHP'

// Render test shop: public endpoint is HTTPS-only.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
PHP
fi

PUBLIC_URL="${RENDER_EXTERNAL_URL:-https://oi-test-shop-clean.onrender.com}"

wp config set WP_HOME "$PUBLIC_URL" --type=constant --allow-root
wp config set WP_SITEURL "$PUBLIC_URL" --type=constant --allow-root
wp config set FORCE_SSL_ADMIN false --raw --type=constant --allow-root

# Enzonic requires TLS for every MySQL connection.
# WordPress exposes the mysqli client flags through MYSQL_CLIENT_FLAGS.
wp config set MYSQL_CLIENT_FLAGS MYSQLI_CLIENT_SSL --raw --type=constant --allow-root

echo "==> WordPress public URL: $PUBLIC_URL"
echo "==> External database host: $WORDPRESS_DB_HOST"
echo "==> Checking external WordPress database..."
wp db check --allow-root

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
  echo "==> WordPress is not installed. Installing into external database..."
  wp core install     --allow-root     --url="$PUBLIC_URL"     --title="Order Integrity Test Shop"     --admin_user="admin"     --admin_password="admin12346789"     --admin_email="test@example.com"     --skip-email
else
  echo "==> WordPress is already installed in external database."
  wp option update home "$PUBLIC_URL" --allow-root
  wp option update siteurl "$PUBLIC_URL" --allow-root
fi

echo "==> Verifying WordPress installation..."
wp core is-installed --allow-root
echo "==> Activating WooCommerce..."
wp plugin activate woocommerce --allow-root
echo "==> Activating Order Integrity..."
wp plugin activate order-integrity --allow-root
echo "==> WordPress test shop ready with persistent external database."

exec apache2-foreground
