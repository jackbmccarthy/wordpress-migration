<?php
/**
 * REST API class for WordPress Migration Tool.
 *
 * Provides REST API endpoints for remote migration.
 *
 * @package WP_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Migration_REST_API
 *
 * Handles REST API endpoints for push/pull migration.
 */
class WP_Migration_REST_API
{
    /**
     * Singleton instance.
     *
     * @var WP_Migration_REST_API
     */
    private static $instance = null;

    /**
     * Namespace for REST routes.
     *
     * @var string
     */
    private $namespace = 'wp-migration/v1';

    /**
     * Option name for API key.
     *
     * @var string
     */
    private const API_KEY_OPTION = 'wp_migration_api_key';

    /**
     * Get singleton instance.
     *
     * @return WP_Migration_REST_API
     */
    public static function get_instance(): WP_Migration_REST_API
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
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        // Register API key authentication.
        add_filter('rest_authentication_errors', [$this, 'check_api_key'], 20);

        // Status check endpoint (no auth required for connectivity test).
        register_rest_route($this->namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_status'],
            'permission_callback' => '__return_true',
        ]);

        // Get export endpoint (push migration - remote pulls from us).
        register_rest_route($this->namespace, '/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_export'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'include' => [
                    'description' => 'Comma-separated list of items to include.',
                    'type'        => 'string',
                    'default'     => 'posts,taxonomies,users,media,settings,widgets,menus',
                ],
            ],
        ]);

        // Media file endpoint.
        register_rest_route($this->namespace, '/media/(?P<file>.+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_media_file'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Import endpoint (push migration - we push to remote).
        register_rest_route($this->namespace, '/import', [
            'methods'             => 'POST',
            'callback'            => [$this, 'post_import'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Pull migration endpoint (we pull from remote).
        register_rest_route($this->namespace, '/pull', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pull_migration'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'remote_url' => [
                    'description' => 'Remote site URL.',
                    'type'        => 'string',
                    'required'    => true,
                ],
            ],
        ]);

        // Generate/regenerate API key.
        register_rest_route($this->namespace, '/api-key', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_api_key'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'generate_api_key'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);
    }

    /**
     * Check API key authentication.
     *
     * @param WP_Error|null $wp_error WP_Error object.
     * @return WP_Error|null
     */
    public function check_api_key(?WP_Error $wp_error)
    {
        // Skip auth for status endpoint.
        $route = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (str_contains($route, '/wp-migration/v1/status')) {
            return $wp_error;
        }

        // Skip auth if not our namespace.
        if (!str_contains($route, $this->namespace)) {
            return $wp_error;
        }

        // Check for API key.
        $api_key = $this->get_api_key_from_request();

        if (empty($api_key)) {
            return new WP_Error(
                'rest_no_api_key',
                __('API key is required.', 'wordpress-migration'),
                ['status' => 401]
            );
        }

        $stored_key = get_option(self::API_KEY_OPTION);

        if (empty($stored_key) || !hash_equals($stored_key, $api_key)) {
            return new WP_Error(
                'rest_invalid_api_key',
                __('Invalid API key.', 'wordpress-migration'),
                ['status' => 401]
            );
        }

        return $wp_error;
    }

    /**
     * Get API key from request.
     *
     * @return string|null
     */
    private function get_api_key_from_request(): ?string
    {
        // Check Authorization header.
        $headers = isset($_SERVER['HTTP_AUTHORIZATION']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION'])) : '';

        if (!empty($headers) && str_starts_with($headers, 'Bearer ')) {
            return trim(substr($headers, 7));
        }

        // Check X-API-KEY header.
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_API_KEY']));
        }

        // Check query parameter.
        if (!empty($_GET['api_key'])) {
            return sanitize_text_field($_GET['api_key']);
        }

        return null;
    }

    /**
     * Check permission callback.
     *
     * @return bool|WP_Error
     */
    public function check_permission(): bool|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to perform this action.', 'wordpress-migration'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Get status endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'status'     => 'ok',
            'version'    => WP_MIGRATION_VERSION,
            'site_url'   => home_url(),
            'site_name'  => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'timestamp'  => current_time('c'),
        ], 200);
    }

    /**
     * Get export endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_export(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $include = $request->get_param('include');
        $includes = array_map('trim', explode(',', $include));

        $options = [];
        $option_map = [
            'posts'      => 'include_posts',
            'taxonomies' => 'include_taxonomies',
            'users'      => 'include_users',
            'media'      => 'include_media',
            'settings'  => 'include_settings',
            'widgets'    => 'include_widgets',
            'menus'      => 'include_menus',
        ];

        foreach ($option_map as $key => $option) {
            $options[$option] = in_array($key, $includes, true);
        }

        // Generate export.
        $exporter = WP_Migration_Exporter::get_instance();
        $result = $exporter->generate_export_package($options);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $result['manifest'],
            'zip_url' => str_replace(WP_MIGRATION_PLUGIN_DIR, WP_MIGRATION_PLUGIN_URL, $result['zip_path']),
        ], 200);
    }

    /**
     * Get media file endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_media_file(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $file = $request->get_param('file');
        $file = sanitize_text_field($file);

        // Sanitize path.
        $file = str_replace('..', '', $file);
        $file = str_replace('/', '', $file);

        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/wp-migration-temp-' . $file;

        if (!file_exists($file_path)) {
            return new WP_Error(
                'file_not_found',
                __('Media file not found.', 'wordpress-migration'),
                ['status' => 404]
            );
        }

        $mime_type = wp_check_filetype($file_path);
        $mime = $mime_type['type'] ?: 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file_path));
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');

        readfile($file_path);
        exit;
    }

    /**
     * Post import endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function post_import(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $request->get_json_params();

        if (empty($body) || !isset($body['data'])) {
            return new WP_Error(
                'invalid_request',
                __('Invalid import data.', 'wordpress-migration'),
                ['status' => 400]
            );
        }

        $importer = WP_Migration_Importer::get_instance();

        // Process import.
        $options = wp_parse_args($body['options'] ?? [], [
            'mode'              => 'merge',
            'import_users'      => true,
            'import_taxonomies' => true,
            'import_posts'     => true,
            'import_media'     => true,
            'import_settings'  => true,
            'import_widgets'   => true,
            'import_menus'     => true,
            'user_role_map'    => [],
        ]);

        $result = $importer->process_import($options);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success' => true,
            'results' => $result,
        ], 200);
    }

    /**
     * Pull migration from remote endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function pull_migration(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $remote_url = $request->get_param('remote_url');
        $remote_url = trailingslashit($remote_url);

        // Get API key from request or settings.
        $api_key = $this->get_api_key_from_request();

        // First, get status from remote.
        $response = wp_remote_get($remote_url . 'wp-json/wp-migration/v1/status', [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if (200 !== $status_code) {
            return new WP_Error(
                'remote_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Remote server returned error: %d', 'wordpress-migration'),
                    $status_code
                ),
                ['status' => $status_code]
            );
        }

        // Pull export from remote.
        $headers = [];
        if ($api_key) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $export_response = wp_remote_get(
            $remote_url . 'wp-json/wp-migration/v1/export',
            [
                'timeout' => 300,
                'headers' => $headers,
            ]
        );

        if (is_wp_error($export_response)) {
            return $export_response;
        }

        $export_code = wp_remote_retrieve_response_code($export_response);
        if (200 !== $export_code) {
            return new WP_Error(
                'export_failed',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Failed to get export from remote: %d', 'wordpress-migration'),
                    $export_code
                ),
                ['status' => $export_code]
            );
        }

        $export_data = json_decode(wp_remote_retrieve_body($export_response), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'invalid_export_data',
                __('Received invalid export data from remote.', 'wordpress-migration')
            );
        }

        // Download ZIP if available.
        $zip_path = null;
        if (!empty($export_data['zip_url'])) {
            $zip_response = wp_remote_get($export_data['zip_url'], [
                'timeout' => 600,
            ]);

            if (!is_wp_error($zip_response) && 200 === wp_remote_retrieve_response_code($zip_response)) {
                $upload_dir = wp_upload_dir();
                $zip_name = 'pulled-migration-' . date('Y-m-d-His') . '.zip';
                $zip_path = $upload_dir['basedir'] . '/' . $zip_name;

                $body = wp_remote_retrieve_body($zip_response);
                file_put_contents($zip_path, $body);
            }
        }

        // Import the data.
        $importer = WP_Migration_Importer::get_instance();

        // Set the data directly for import.
        $importer->set_data($export_data['data']);

        $options = $request->get_param('options') ?: [];
        $result = $importer->process_import($options);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success'  => true,
            'results'   => $result,
            'zip_path'  => $zip_path,
        ], 200);
    }

    /**
     * Get API key.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_api_key(WP_REST_Request $request): WP_REST_Response
    {
        $key = get_option(self::API_KEY_OPTION);

        return new WP_REST_Response([
            'has_key' => !empty($key),
        ], 200);
    }

    /**
     * Generate new API key.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function generate_api_key(WP_REST_Request $request): WP_REST_Response
    {
        $new_key = wp_generate_password(32, false);

        update_option(self::API_KEY_OPTION, $new_key);

        return new WP_REST_Response([
            'success' => true,
            'api_key' => $new_key,
        ], 200);
    }

    /**
     * Get stored API key.
     *
     * @return string|null
     */
    public static function get_api_key(): ?string
    {
        return get_option(self::API_KEY_OPTION);
    }
}
