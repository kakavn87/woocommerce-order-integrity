<?php
// Render test-shop bootstrap configuration.
if (getenv('WORDPRESS_DB_HOST')) {
    define('WP_DEBUG', true);
    define('WP_DEBUG_LOG', true);
    define('WP_DEBUG_DISPLAY', false);
}
