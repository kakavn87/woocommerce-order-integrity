#!/bin/bash
set -e

cd /var/www/html

apache2-foreground &
APACHE_PID=$!
trap "kill $APACHE_PID 2>/dev/null || true" EXIT

: "${WORDPRESS_DB_HOST:?WORDPRESS_DB_HOST is required}"
: "${WORDPRESS_DB_NAME:?WORDPRESS_DB_NAME is required}"
: "${WORDPRESS_DB_USER:?WORDPRESS_DB_USER is required}"
: "${WORDPRESS_DB_PASSWORD:?WORDPRESS_DB_PASSWORD is required}"

if [ ! -f wp-config.php ]; then
  wp config create --allow-root \
    --dbname="$WORDPRESS_DB_NAME" \
    --dbuser="$WORDPRESS_DB_USER" \
    --dbpass="$WORDPRESS_DB_PASSWORD" \
    --dbhost="$WORDPRESS_DB_HOST" \
    --skip-check
fi

PUBLIC_URL="${RENDER_EXTERNAL_URL:-https://oi-test-shop-clean.onrender.com}"

# Render terminates TLS before Apache. Keep WordPress aware that the public
# request is HTTPS.
if ! grep -q "Render test shop: public endpoint is HTTPS-only" wp-config.php; then
  cat >> wp-config.php <<'PHP'

// Render test shop: public endpoint is HTTPS-only.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
PHP
fi

wp config set WP_HOME "$PUBLIC_URL" --type=constant --allow-root
wp config set WP_SITEURL "$PUBLIC_URL" --type=constant --allow-root
wp config set FORCE_SSL_ADMIN true --raw --type=constant --allow-root
wp config set MYSQL_CLIENT_FLAGS MYSQLI_CLIENT_SSL --raw --type=constant --allow-root

# Keep auth keys identical across Render instances. No other custom auth
# handling is used; WordPress handles login/session cookies normally.
SALT_SEED="oi-clean-wordpress|$PUBLIC_URL"
for SALT_NAME in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
  SALT_VALUE="$(printf "%s|%s" "$SALT_SEED" "$SALT_NAME" | sha256sum | cut -d" " -f1)"
  wp config set "$SALT_NAME" "$SALT_VALUE" --type=constant --allow-root
done

mkdir -p /var/www/html/wp-content/mu-plugins
cat > /var/www/html/wp-content/mu-plugins/render-proxy.php <<'PHP'
<?php
if (!defined('RENDER_PROXY_BOOTSTRAP')) {
    define('RENDER_PROXY_BOOTSTRAP', true);
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}
PHP

echo "==> Checking WordPress database..."
wp db check --allow-root

# One-time clean reinstall: wipe the existing WordPress tables, but do NOT
# run the WordPress installer. The user will complete the normal browser
# installation himself. A marker table prevents another reset on restart.
MARKER_TABLE="$(wp config get table_prefix --allow-root)oi_clean_reinstall_marker"
if [ "${RESET_WORDPRESS:-0}" = "1" ]; then
  if ! wp db query "SELECT 1 FROM \`$MARKER_TABLE\` LIMIT 1" --allow-root >/dev/null 2>&1; then
    echo "==> RESET_WORDPRESS=1: performing one-time clean WordPress reinstall..."
    wp db reset --yes --allow-root
    wp db query "CREATE TABLE \`$MARKER_TABLE\` (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB" --allow-root
    wp db query "INSERT INTO \`$MARKER_TABLE\` (id) VALUES (1)" --allow-root
    echo "==> Database is clean. WordPress installer will be shown in the browser."
  else
    echo "==> Clean reinstall already performed; leaving database untouched."
  fi
fi

if wp core is-installed --allow-root >/dev/null 2>&1; then
  echo "==> WordPress is already installed."
  wp option update home "$PUBLIC_URL" --allow-root >/dev/null
  wp option update siteurl "$PUBLIC_URL" --allow-root >/dev/null
else
  echo "==> WordPress is NOT installed. Waiting for the normal web installer."
fi

echo "==> WordPress clean test shop ready."
wait $APACHE_PID
