#!/bin/bash
set -e

cd /var/www/html

# Start Apache immediately so Render detects the web port while WordPress initializes.
apache2-foreground &
APACHE_PID=$!
trap "kill $APACHE_PID 2>/dev/null || true" EXIT

: "${WORDPRESS_DB_HOST:?WORDPRESS_DB_HOST is required}"
: "${WORDPRESS_DB_NAME:?WORDPRESS_DB_NAME is required}"
: "${WORDPRESS_DB_USER:?WORDPRESS_DB_USER is required}"
: "${WORDPRESS_DB_PASSWORD:?WORDPRESS_DB_PASSWORD is required}"

if [ ! -f wp-config.php ]; then
  echo "==> Creating WordPress configuration for external database..."
  wp config create --allow-root --dbname="$WORDPRESS_DB_NAME" --dbuser="$WORDPRESS_DB_USER" --dbpass="$WORDPRESS_DB_PASSWORD" --dbhost="$WORDPRESS_DB_HOST" --skip-check
fi

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
wp config set MYSQL_CLIENT_FLAGS MYSQLI_CLIENT_SSL --raw --type=constant --allow-root

echo "==> WordPress public URL: $PUBLIC_URL"
echo "==> Checking external WordPress database..."
wp db check --allow-root

if [ "${RESET_WORDPRESS:-0}" = "1" ]; then
  echo "==> RESET_WORDPRESS=1: resetting WordPress database..."
  wp db reset --yes --allow-root
  echo "==> Installing fresh WordPress..."
  wp core install --allow-root --url="$PUBLIC_URL" --title="Order Integrity Test Shop" --admin_user="admin" --admin_password="admin12346789" --admin_email="test@example.com" --skip-email
else
  if ! wp core is-installed --allow-root >/dev/null 2>&1; then
    echo "==> WordPress is not installed. Installing into external database..."
    wp core install --allow-root --url="$PUBLIC_URL" --title="Order Integrity Test Shop" --admin_user="admin" --admin_password="admin12346789" --admin_email="test@example.com" --skip-email
  else
    echo "==> WordPress is already installed in external database."
    wp option update home "$PUBLIC_URL" --allow-root
    wp option update siteurl "$PUBLIC_URL" --allow-root
  fi
fi

echo "==> Verifying WordPress installation..."
wp core is-installed --allow-root

echo "==> Ensuring administrator role..."
if wp user get admin --field=ID --allow-root >/dev/null 2>&1; then
  wp user set-role admin administrator --allow-root
else
  echo "ERROR: WordPress admin user does not exist."
  exit 1
fi

echo "==> Admin capability check:"
wp user get admin --fields=ID,user_login,roles --format=json --allow-root
echo

echo "==> Clearing stale WooCommerce installation lock before loading WooCommerce..."
wp option delete wc_installing --skip-plugins=woocommerce --allow-root >/dev/null 2>&1 || true

echo "==> Checking WooCommerce..."
if wp plugin is-active woocommerce --skip-plugins=woocommerce --allow-root >/dev/null 2>&1; then
  echo "WooCommerce is already active."
else
  wp plugin activate woocommerce --skip-plugins=woocommerce --allow-root
fi

echo "==> Clearing WooCommerce installation lock after activation..."
wp option delete wc_installing --skip-plugins=woocommerce --allow-root >/dev/null 2>&1 || true

echo "==> Activating Order Integrity..."
wp plugin activate order-integrity --skip-plugins=woocommerce --allow-root

echo "==> WordPress test shop ready with persistent external database."

wait $APACHE_PID
