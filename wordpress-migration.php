<?php
/**
 * Plugin Name: WordPress Migration Tool
 * Plugin URI: https://github.com/jackbmccarthy/wordpress-migration
 * Description: Export and import your entire WordPress site to another WordPress installation
 * Version: 1.0.0
 * Author: Jack McCarthy
 * Author URI: https://jackmccarthy.dev
 * License: GPL v2
 * Text Domain: wordpress-migration
 * Domain Path: /languages
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('WP_MIGRATION_VERSION', '1.0.0');
define('WP_MIGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_MIGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_MIGRATION_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 autoloader.
 *
 * @param string $class The fully-qualified class name.
 */
spl_autoload_register(function ($class) {
    $prefix = 'WP_Migration_';
    $base_dir = WP_MIGRATION_PLUGIN_DIR . 'includes/';

    // Check if the class uses our prefix.
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name.
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators, lowercase.
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    // If the file exists, require it.
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Initialize the plugin.
 *
 * @return void
 */
function wp_migration_init(): void
{
    // Load text domain for translations.
    load_plugin_textdomain(
        'wordpress-migration',
        false,
        dirname(WP_MIGRATION_PLUGIN_BASENAME) . '/languages'
    );

    // Initialize admin if in admin area.
    if (is_admin()) {
        WP_Migration_Admin::get_instance();
    }

    // Initialize REST API.
    WP_Migration_REST_API::get_instance();
}
add_action('plugins_loaded', 'wp_migration_init');

/**
 * Activate the plugin.
 *
 * @return void
 */
function wp_migration_activate(): void
{
    // Set transient for activation notice.
    set_transient('wp_migration_activated', true, 30);

    // Flush rewrite rules for REST API endpoints.
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wp_migration_activate');

/**
 * Deactivate the plugin.
 *
 * @return void
 */
function wp_migration_deactivate(): void
{
    // Flush rewrite rules.
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wp_migration_deactivate');

/**
 * Display activation notice.
 *
 * @return void
 */
function wp_migration_admin_notice(): void
{
    if (get_transient('wp_migration_activated')) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>';
        printf(
            /* translators: %s: Settings page URL */
            esc_html__('WordPress Migration Tool activated successfully! %s', 'wordpress-migration'),
            '<a href="' . esc_url(admin_url('tools.php?page=wordpress-migration')) . '">' .
            esc_html__('Go to Migration Tool', 'wordpress-migration') . '</a>'
        );
        echo '</p>';
        echo '</div>';
        delete_transient('wp_migration_activated');
    }
}
add_action('admin_notices', 'wp_migration_admin_notice');
