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

# Keep authentication salts stable across Render container replacements.
SALT_SEED="$WORDPRESS_DB_PASSWORD|$PUBLIC_URL"
for SALT_NAME in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
  SALT_VALUE="$(printf "%s|%s" "$SALT_SEED" "$SALT_NAME" | sha256sum | cut -d" " -f1)"
  wp config set "$SALT_NAME" "$SALT_VALUE" --type=constant --allow-root
done

# Use a new deterministic cookie namespace so cookies from the broken pre-fix setup
# cannot keep the browser in an authentication loop.
COOKIE_HASH="$(printf "%s|cookie-v2" "$SALT_SEED" | sha256sum | cut -c1-32)"
wp config set COOKIEHASH "$COOKIE_HASH" --type=constant --allow-root
wp config set COOKIE_DOMAIN "" --type=constant --allow-root
wp config set COOKIEPATH "/" --type=constant --allow-root
wp config set SITECOOKIEPATH "/" --type=constant --allow-root
wp config set ADMIN_COOKIE_PATH "/" --type=constant --allow-root
wp config set PLUGINS_COOKIE_PATH "/" --type=constant --allow-root

# Temporary diagnostics for the redirect that occurs before admin_init.
mkdir -p /var/www/html/wp-content/mu-plugins
cat > /var/www/html/wp-content/mu-plugins/render-auth-debug.php <<'PHP'
<?php
add_action('init', function () {
    $user = wp_get_current_user();
    $validated = wp_validate_auth_cookie();
    error_log('AUTHDBG init user_id=' . (int) $user->ID . ' validated_id=' . (int) $validated . ' logged_in=' . (is_user_logged_in() ? '1' : '0') . ' scheme=' . (is_ssl() ? 'ssl' : 'plain') . ' cookiehash=' . (defined('COOKIEHASH') ? COOKIEHASH : '(unset)') . ' cookie_names=' . implode(',', array_keys($_COOKIE)));
}, 1);

add_action('admin_init', function () {
    $user = wp_get_current_user();
    error_log('AUTHDBG admin_init user_id=' . (int) $user->ID . ' logged_in=' . (is_user_logged_in() ? '1' : '0') . ' cookie_names=' . implode(',', array_keys($_COOKIE)));
}, 1);

add_action('wp_login', function ($user_login, $user) {
    error_log('AUTHDBG wp_login user_id=' . (int) $user->ID . ' login=' . $user_login . ' auth_cookie_valid=' . (wp_validate_auth_cookie() ? '1' : '0'));
}, 10, 2);

add_action('set_auth_cookie', function ($auth_cookie, $expire, $expiration, $user_id, $scheme) {
    error_log('AUTHDBG set_auth_cookie user_id=' . (int) $user_id . ' scheme=' . $scheme . ' expire=' . (int) $expire . ' expiration=' . (int) $expiration . ' cookie_domain=' . (defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '(unset)') . ' cookie_path=' . (defined('COOKIEPATH') ? COOKIEPATH : '(unset)'));
}, 10, 5);

add_action('clear_auth_cookie', function () {
    error_log('AUTHDBG clear_auth_cookie');
});

add_action('wp_redirect', function ($location, $status) {
    error_log('AUTHDBG wp_redirect status=' . (int) $status . ' location=' . $location . ' request=' . ($_SERVER['REQUEST_URI'] ?? ''));
}, 10, 2);
PHP

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

# One-time auth migration: invalidate sessions created with the old cookie/salt setup.
if ! wp option get oi_auth_migration_v2 --allow-root >/dev/null 2>&1; then
  echo "==> Invalidating old administrator sessions for authentication migration..."
  wp user session destroy admin --all --allow-root >/dev/null 2>&1 || true
  wp option add oi_auth_migration_v2 1 --allow-root >/dev/null 2>&1 || wp option update oi_auth_migration_v2 1 --allow-root >/dev/null
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

echo "==> Repairing core administrator capabilities..."
wp cap add administrator read manage_options activate_plugins edit_posts upload_files --allow-root >/dev/null
wp user set-role admin administrator --allow-root >/dev/null

echo "==> Admin capability check:"
wp user get admin --fields=ID,user_login,roles --format=json --allow-root
echo "==> Administrator capability check:"
wp cap list administrator --format=json --allow-root
echo "==> User capability check:"
wp user list-caps admin --format=json --allow-root
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
