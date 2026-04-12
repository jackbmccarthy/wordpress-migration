<?php
/**
 * Admin class for WordPress Migration Tool.
 *
 * Handles the admin interface.
 *
 * @package WP_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Migration_Admin
 *
 * Handles admin interface for the migration tool.
 */
class WP_Migration_Admin
{
    /**
     * Singleton instance.
     *
     * @var WP_Migration_Admin
     */
    private static $instance = null;

    /**
     * Admin page slug.
     *
     * @var string
     */
    private $page_slug = 'wordpress-migration';

    /**
     * Get singleton instance.
     *
     * @return WP_Migration_Admin
     */
    public static function get_instance(): WP_Migration_Admin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wp_migration_export', [$this, 'ajax_export']);
        add_action('wp_ajax_wp_migration_import', [$this, 'ajax_import']);
        add_action('wp_ajax_wp_migration_cancel', [$this, 'ajax_cancel']);
        add_action('wp_ajax_wp_migration_get_progress', [$this, 'ajax_get_progress']);
        add_action('wp_ajax_wp_migration_download', [$this, 'ajax_download']);
    }

    /**
     * Add admin menu item.
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        add_management_page(
            __('WordPress Migration', 'wordpress-migration'),
            __('Migration Tool', 'wordpress-migration'),
            'manage_options',
            $this->page_slug,
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page.
     * @return void
     */
    public function enqueue_assets(string $hook): void
    {
        // Only load on our plugin page.
        if ('tools_page_' . $this->page_slug !== $hook) {
            return;
        }

        // CSS.
        wp_enqueue_style(
            'wp-migration-admin',
            WP_MIGRATION_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_MIGRATION_VERSION
        );

        // JS.
        wp_enqueue_script(
            'wp-migration-admin',
            WP_MIGRATION_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WP_MIGRATION_VERSION,
            true
        );

        // Localize script.
        wp_localize_script('wp-migration-admin', 'WPMigration', [
            'ajaxurl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('wp_migration_nonce'),
            'strings'   => [
                'exporting'      => __('Exporting...', 'wordpress-migration'),
                'importing'      => __('Importing...', 'wordpress-migration'),
                'complete'       => __('Complete!', 'wordpress-migration'),
                'error'           => __('Error', 'wordpress-migration'),
                'download_ready'  => __('Download Ready', 'wordpress-migration'),
                'cancel_confirm'  => __('Are you sure you want to cancel?', 'wordpress-migration'),
                'import_warning'  => __('Warning: This will import content into your site. Continue?', 'wordpress-migration'),
            ],
        ]);
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public function render_admin_page(): void
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'export';

        require_once WP_MIGRATION_PLUGIN_DIR . 'admin/partials/admin-display.php';
    }

    /**
     * AJAX export handler.
     *
     * @return void
     */
    public function ajax_export(): void
    {
        check_ajax_referer('wp_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wordpress-migration')]);
        }

        $options = [];
        $option_keys = [
            'include_posts',
            'include_pages',
            'include_cpts',
            'include_taxonomies',
            'include_users',
            'include_media',
            'include_settings',
            'include_widgets',
            'include_menus',
        ];

        foreach ($option_keys as $key) {
            $options[$key] = isset($_POST[$key]) && 'true' === $_POST[$key];
        }

        // Run export.
        $exporter = WP_Migration_Exporter::get_instance();
        $result = $exporter->generate_export_package($options);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'    => __('Export complete!', 'wordpress-migration'),
            'zip_path'   => $result['zip_path'],
            'zip_url'    => str_replace(WP_MIGRATION_PLUGIN_DIR, WP_MIGRATION_PLUGIN_URL, $result['zip_path']),
            'file_name'  => wp_basename($result['zip_path']),
            'stats'      => [
                'posts'      => count($result['manifest']['posts'] ?? []),
                'users'      => count($result['manifest']['users'] ?? []),
                'taxonomies' => count($result['manifest']['taxonomies'] ?? []),
                'media'      => count($result['manifest']['media_manifest'] ?? []),
            ],
        ]);
    }

    /**
     * AJAX import handler.
     *
     * @return void
     */
    public function ajax_import(): void
    {
        check_ajax_referer('wp_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wordpress-migration')]);
        }

        // Check for uploaded file.
        if (empty($_FILES['import_file'])) {
            wp_send_json_error(['message' => __('No file uploaded.', 'wordpress-migration')]);
        }

        $file = $_FILES['import_file'];

        // Validate file.
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Upload failed.', 'wordpress-migration')]);
        }

        // Check file type.
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ('zip' !== $ext) {
            wp_send_json_error(['message' => __('Please upload a ZIP file.', 'wordpress-migration')]);
        }

        // Move to temp location.
        $upload_dir = wp_upload_dir();
        $temp_path = $upload_dir['basedir'] . '/wp-migration-import-' . wp_generate_uuid4() . '.zip';

        if (!move_uploaded_file($file['tmp_name'], $temp_path)) {
            wp_send_json_error(['message' => __('Failed to save uploaded file.', 'wordpress-migration')]);
        }

        // Get options.
        $options = [];
        $option_keys = [
            'import_users',
            'import_taxonomies',
            'import_posts',
            'import_media',
            'import_settings',
            'import_widgets',
            'import_menus',
        ];

        foreach ($option_keys as $key) {
            $options[$key] = isset($_POST[$key]) && 'true' === $_POST[$key];
        }

        $options['mode'] = isset($_POST['import_mode']) ? sanitize_text_field($_POST['import_mode']) : 'merge';

        // Process import.
        $importer = WP_Migration_Importer::get_instance();

        // Validate package first.
        $validation = $importer->validate_package($temp_path);
        if (is_wp_error($validation)) {
            @unlink($temp_path);
            wp_send_json_error(['message' => $validation->get_error_message()]);
        }

        // Process import.
        $result = $importer->process_import($options);

        // Cleanup temp file.
        @unlink($temp_path);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Import complete!', 'wordpress-migration'),
            'results' => $result,
        ]);
    }

    /**
     * AJAX cancel handler.
     *
     * @return void
     */
    public function ajax_cancel(): void
    {
        check_ajax_referer('wp_migration_nonce', 'nonce');

        // Cleanup any temp files.
        $upload_dir = wp_upload_dir();
        $temp_files = glob($upload_dir['basedir'] . '/wp-migration-*.zip');

        foreach ($temp_files as $file) {
            @unlink($file);
        }

        $temp_dirs = glob($upload_dir['basedir'] . '/wp-migration-*', GLOB_ONLYDIR);
        foreach ($temp_dirs as $dir) {
            $this->delete_dir($dir);
        }

        wp_send_json_success(['message' => __('Cancelled.', 'wordpress-migration')]);
    }

    /**
     * AJAX get progress handler.
     *
     * @return void
     */
    public function ajax_get_progress(): void
    {
        check_ajax_referer('wp_migration_nonce', 'nonce');

        // Get progress from transient.
        $progress = get_transient('wp_migration_progress');

        if (false === $progress) {
            wp_send_json_success(['progress' => 0, 'message' => '']);
        }

        wp_send_json_success($progress);
    }

    /**
     * AJAX download handler.
     *
     * @return void
     */
    public function ajax_download(): void
    {
        check_ajax_referer('wp_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'wordpress-migration'));
        }

        $file_path = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';

        if (empty($file_path) || !file_exists($file_path)) {
            wp_die(__('File not found.', 'wordpress-migration'));
        }

        // Security: ensure file is in uploads directory.
        $upload_dir = wp_upload_dir();
        $real_path = realpath($file_path);

        if (false === $real_path || strpos($real_path, $upload_dir['basedir']) !== 0) {
            wp_die(__('Invalid file path.', 'wordpress-migration'));
        }

        $file_name = isset($_GET['name']) ? sanitize_file_name($_GET['name']) : wp_basename($file_path);

        // Set headers for download.
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));

        // Clean buffers.
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Output file.
        readfile($file_path);
        exit;
    }

    /**
     * Delete directory recursively.
     *
     * @param string $dir Directory path.
     * @return bool
     */
    private function delete_dir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_dir($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Get page slug.
     *
     * @return string
     */
    public function get_page_slug(): string
    {
        return $this->page_slug;
    }
}
