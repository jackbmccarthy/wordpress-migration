<?php
/**
 * Exporter class for WordPress Migration Tool.
 *
 * Handles exporting all site content to a portable format.
 *
 * @package WP_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Migration_Exporter
 *
 * Handles exporting posts, users, taxonomy terms, media, and settings.
 */
class WP_Migration_Exporter
{
    /**
     * Singleton instance.
     *
     * @var WP_Migration_Exporter
     */
    private static $instance = null;

    /**
     * Export data.
     *
     * @var array
     */
    private $data = [];

    /**
     * Export options.
     *
     * @var array
     */
    private $options = [];

    /**
     * Temporary directory for export.
     *
     * @var string
     */
    private $temp_dir = '';

    /**
     * Get singleton instance.
     *
     * @return WP_Migration_Exporter
     */
    public static function get_instance(): WP_Migration_Exporter
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
        // Private constructor for singleton.
    }

    /**
     * Generate export package.
     *
     * @param array $options Export options.
     * @return array|WP_Error Export data or error.
     */
    public function generate_export_package(array $options = [])
    {
        $this->options = wp_parse_args($options, [
            'include_posts'       => true,
            'include_pages'      => true,
            'include_cpts'       => true,
            'include_taxonomies' => true,
            'include_users'      => true,
            'include_media'      => true,
            'include_settings'   => true,
            'include_widgets'    => true,
            'include_menus'      => true,
            'include_plugins'    => true,
        ]);

        $this->data = [
            'exported_at'    => current_time('c'),
            'wp_version'     => get_bloginfo('version'),
            'site_url'       => home_url(),
            'site_name'      => get_bloginfo('name'),
            'options'        => $this->options,
            'posts'          => [],
            'taxonomies'     => [],
            'users'          => [],
            'media_manifest' => [],
            'settings'       => [],
            'widgets'        => [],
            'menus'          => [],
            'plugins'       => [],
        ];

        // Create temp directory.
        $upload_dir = wp_upload_dir();
        $this->temp_dir = $upload_dir['basedir'] . '/wp-migration-temp-' . wp_generate_uuid4();

        if (!wp_mkdir_p($this->temp_dir)) {
            return new WP_Error(
                'mkdir_failed',
                __('Failed to create temporary directory.', 'wordpress-migration')
            );
        }

        // Export in order of dependencies.
        if ($this->options['include_taxonomies']) {
            $this->export_taxonomies();
        }

        if ($this->options['include_users']) {
            $this->export_users();
        }

        if ($this->options['include_posts'] || $this->options['include_pages'] || $this->options['include_cpts']) {
            $this->export_posts();
        }

        if ($this->options['include_media']) {
            $this->export_media();
        }

        if ($this->options['include_settings'] || $this->options['include_widgets'] || $this->options['include_menus']) {
            $this->export_settings();
        }

        if ($this->options['include_plugins']) {
            $this->export_plugins();
        }

        // Save manifest JSON.
        $manifest_path = $this->temp_dir . '/manifest.json';
        $json_result = file_put_contents(
            $manifest_path,
            wp_json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if (false === $json_result) {
            $this->cleanup();
            return new WP_Error(
                'manifest_write_failed',
                __('Failed to write manifest file.', 'wordpress-migration')
            );
        }

        // Create ZIP file.
        $zip_result = $this->create_zip();

        if (is_wp_error($zip_result)) {
            $this->cleanup();
            return $zip_result;
        }

        return [
            'zip_path' => $zip_result,
            'manifest' => $this->data,
            'temp_dir' => $this->temp_dir,
        ];
    }

    /**
     * Export all post types.
     *
     * @return void
     */
    public function export_posts(): void
    {
        $post_types = ['post', 'page'];

        if ($this->options['include_cpts']) {
            $cpts = get_post_types([
                'public'   => true,
                '_builtin' => false,
            ]);
            $post_types = array_merge($post_types, $cpts);
        }

        foreach ($post_types as $post_type) {
            $this->export_posts_by_type($post_type);
        }
    }

    /**
     * Export posts by type.
     *
     * @param string $post_type Post type.
     * @return void
     */
    private function export_posts_by_type(string $post_type): void
    {
        $posts = get_posts([
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        foreach ($posts as $post) {
            $post_data = $this->prepare_post_data($post);
            $this->data['posts'][] = $post_data;
        }
    }

    /**
     * Prepare post data for export.
     *
     * @param WP_Post $post Post object.
     * @return array Post data.
     */
    private function prepare_post_data(WP_Post $post): array
    {
        // Get post meta.
        $meta = get_post_meta($post->ID);

        // Filter out internal meta keys.
        $filtered_meta = [];
        foreach ($meta as $key => $values) {
            if (!str_starts_with($key, '_')) {
                $filtered_meta[$key] = maybe_serialize($values);
            }
        }

        // Get featured image.
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $thumbnail_url = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null;

        // Get post terms.
        $taxonomies = get_object_taxonomies($post->post_type);
        $terms = [];
        foreach ($taxonomies as $taxonomy) {
            $post_terms = get_the_terms($post->ID, $taxonomy);
            if ($post_terms && !is_wp_error($post_terms)) {
                $terms[$taxonomy] = [];
                foreach ($post_terms as $term) {
                    $terms[$taxonomy][] = [
                        'term_id'     => $term->term_id,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'taxonomy'    => $term->taxonomy,
                        'description' => $term->description,
                        'parent'      => $term->parent,
                    ];
                }
            }
        }

        return [
            'post_type'    => $post->post_type,
            'post_id'      => $post->ID,
            'post_title'   => $post->post_title,
            'post_name'    => $post->post_name,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => $post->post_status,
            'post_date'    => $post->post_date,
            'post_modified' => $post->post_modified,
            'post_author'  => $post->post_author,
            'post_parent'  => $post->post_parent,
            'menu_order'   => $post->menu_order,
            'guid'         => $post->guid,
            'meta'         => $filtered_meta,
            'thumbnail'    => $thumbnail_url,
            'terms'        => $terms,
        ];
    }

    /**
     * Export all taxonomy terms.
     *
     * @return void
     */
    public function export_taxonomies(): void
    {
        $taxonomies = get_taxonomies(['show_cloud' => true], 'objects');

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy->name,
                'hide_empty' => false,
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $this->data['taxonomies'][] = [
                    'term_id'     => $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'taxonomy'    => $term->taxonomy,
                    'description' => $term->description,
                    'parent'      => $term->parent,
                    'count'       => $term->count,
                ];
            }
        }
    }

    /**
     * Export all users.
     *
     * @return void
     */
    public function export_users(): void
    {
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'author', 'contributor', 'subscriber'],
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ]);

        foreach ($users as $user) {
            $this->data['users'][] = [
                'ID'              => $user->ID,
                'user_login'      => $user->user_login,
                'user_nicename'   => $user->user_nicename,
                'user_email'      => $user->user_email,
                'user_url'        => $user->user_url,
                'display_name'    => $user->display_name,
                'roles'           => $user->roles,
                'first_name'      => $user->first_name,
                'last_name'       => $user->last_name,
                'description'     => $user->description,
                'user_registered' => $user->user_registered,
                'meta'            => $this->get_user_meta($user->ID),
            ];
        }
    }

    /**
     * Get user meta for export (excluding sensitive fields).
     *
     * @param int $user_id User ID.
     * @return array User meta.
     */
    private function get_user_meta(int $user_id): array
    {
        $exclude_keys = [
            'password',
            'session_tokens',
            'wp_capabilities',
            'wp_user_level',
            'default_password_nag',
        ];

        $meta = get_user_meta($user_id);
        $filtered = [];

        foreach ($meta as $key => $values) {
            if (!in_array($key, $exclude_keys, true)) {
                $filtered[$key] = maybe_serialize($values);
            }
        }

        return $filtered;
    }

    /**
     * Export media files.
     *
     * @return void
     */
    public function export_media(): void
    {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ]);

        $media_dir = $this->temp_dir . '/media';
        wp_mkdir_p($media_dir);

        foreach ($attachments as $attachment) {
            $file_path = get_attached_file($attachment->ID);

            if (!file_exists($file_path)) {
                continue;
            }

            $file_url = wp_get_attachment_url($attachment->ID);
            $file_name = wp_basename($file_path);

            // Copy file to media folder.
            $dest_path = $media_dir . '/' . $file_name;
            copy($file_path, $dest_path);

            // Get metadata.
            $metadata = wp_get_attachment_metadata($attachment->ID);

            $this->data['media_manifest'][] = [
                'attachment_id' => $attachment->ID,
                'post_id'       => $attachment->post_parent,
                'file_name'     => $file_name,
                'file_url'      => $file_url,
                'mime_type'     => $attachment->post_mime_type,
                'metadata'      => $metadata,
                'guid'          => $attachment->guid,
            ];
        }
    }

    /**
     * Export settings.
     *
     * @return void
     */
    public function export_settings(): void
    {
        // Export site options.
        $options_to_export = [
            'blogname',
            'blogdescription',
            'permalink_structure',
            'category_base',
            'tag_base',
            ' uploads',
        ];

        foreach ($options_to_export as $option) {
            $value = get_option(ltrim($option, ' '));
            if (false !== $value) {
                $this->data['settings'][ltrim($option, ' ')] = $value;
            }
        }

        // Export theme mods.
        $current_theme = wp_get_theme();
        $theme_mods = get_option('theme_mods_' . $current_theme->get_stylesheet(), []);
        if (!empty($theme_mods)) {
            $this->data['settings']['theme_mods'] = $theme_mods;
            $this->data['settings']['theme_name'] = $current_theme->get_stylesheet();
        }

        // Export active plugins.
        $active_plugins = get_option('active_plugins', []);
        $this->data['settings']['active_plugins'] = $active_plugins;

        // Export site icon and other settings.
        $this->data['settings']['site_icon'] = get_option('site_icon');
        $this->data['settings']['avatar_default'] = get_option('avatar_default');
        $this->data['settings']['comment_registration'] = get_option('comment_registration');
        $this->data['settings']['default_comment_status'] = get_option('default_comment_status');

        if ($this->options['include_widgets']) {
            $this->export_widgets();
        }

        if ($this->options['include_menus']) {
            $this->export_menus();
        }
    }

    /**
     * Export widgets.
     *
     * @return void
     */
    private function export_widgets(): void
    {
        global $wp_registered_widgets, $wp_registered_widget_controls, $wp_registered_sidebars;

        // Get widget options.
        $widget_options = get_option('widget_whitelisted');
        if (empty($widget_options)) {
            $widget_options = get_option('option_tree', []);
        }

        // Get sidebars widget positions.
        $sidebars_widgets = get_option('sidebars_widgets', []);

        $this->data['widgets'] = [
            'sidebars'    => $sidebars_widgets,
            'widgets'     => $widget_options,
        ];
    }

    /**
     * Export navigation menus.
     *
     * @return void
     */
    private function export_menus(): void
    {
        // Get all navigation menus.
        $menus = wp_get_nav_menus();

        foreach ($menus as $menu) {
            $menu_items = wp_get_nav_menu_items($menu->term_id);

            $menu_data = [
                'menu_id'   => $menu->term_id,
                'menu_name' => $menu->name,
                'menu_slug' => $menu->slug,
                'items'     => [],
            ];

            foreach ($menu_items as $item) {
                $menu_data['items'][] = [
                    'menu_item_id'       => $item->ID,
                    'post_id'            => $item->object_id,
                    'menu_item_parent'   => $item->menu_item_parent,
                    'menu_item_title'    => $item->title,
                    'menu_item_url'      => $item->url,
                    'menu_item_type'     => $item->type,
                    'menu_item_object'   => $item->object,
                    'menu_item_target'   => $item->target,
                    'menu_item_attr_title' => $item->attr_title,
                    'menu_item_classes'  => $item->classes,
                    'menu_item_xfn'      => $item->xfn,
                    'menu_item_status'   => $item->post_status,
                ];
            }

            $this->data['menus'][] = $menu_data;
        }

        // Get menu locations.
        $this->data['settings']['nav_menu_locations'] = get_nav_menu_locations();
    }

    /**
     * Export all installed plugins and their settings.
     *
     * @return void
     */
    public function export_plugins(): void
    {
        // Get all installed plugins.
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());
        $network_active = (is_multisite()) ? get_site_option('active_sitewide_plugins', array()) : array();

        // For each active plugin, try to export its settings.
        $plugin_settings = array();
        foreach ($active_plugins as $plugin_path) {
            $settings = $this->get_plugin_settings($plugin_path);
            if (!empty($settings)) {
                $plugin_settings[$plugin_path] = $settings;
            }
        }

        $this->data['plugins'] = array(
            'all_plugins' => $all_plugins,
            'active_plugins' => $active_plugins,
            'network_active' => array_keys($network_active),
            'plugin_settings' => $plugin_settings,
            'exported_at' => current_time('mysql'),
        );
    }

    /**
     * Get settings for a specific plugin by examining common option names.
     *
     * @param string $plugin_path Plugin path.
     * @return array Plugin settings.
     */
    private function get_plugin_settings(string $plugin_path): array
    {
        $plugin_slug = dirname($plugin_path);
        $settings = array();

        // Common option name patterns for plugins.
        $option_patterns = array(
            $plugin_slug,                                     // plugin name
            str_replace('-', '_', $plugin_slug),            // hyphen to underscore
            str_replace('/', '_', $plugin_slug),             // slash to underscore
        );

        // Get all options.
        global $wpdb;
        $options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE autoload = 'yes'");

        foreach ($options as $option) {
            foreach ($option_patterns as $pattern) {
                if (stripos($option->option_name, $pattern) !== false) {
                    // Found a related option.
                    $settings[$option->option_name] = maybe_unserialize($option->option_value);
                }
            }
        }

        return $settings;
    }

    /**
     * Create ZIP archive of export.
     *
     * @return string|WP_Error Path to ZIP file or error.
     */
    private function create_zip()
    {
        require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';

        $upload_dir = wp_upload_dir();
        $zip_name = 'wordpress-migration-' . date('Y-m-d-His') . '.zip';
        $zip_path = $upload_dir['basedir'] . '/' . $zip_name;

        // Remove existing ZIP if exists.
        if (file_exists($zip_path)) {
            wp_delete_file($zip_path);
        }

        $archive = new PclZip($zip_path);

        // Add all files from temp directory.
        $result = $archive->create(
            $this->temp_dir,
            PCLZIP_OPT_REMOVE_PATH,
            $this->temp_dir
        );

        if (0 === $result) {
            return new WP_Error(
                'zip_failed',
                __('Failed to create ZIP archive.', 'wordpress-migration') . ' ' . $archive->errorInfo(true)
            );
        }

        return $zip_path;
    }

    /**
     * Clean up temporary files.
     *
     * @return void
     */
    public function cleanup(): void
    {
        if (!empty($this->temp_dir) && is_dir($this->temp_dir)) {
            WP_Filesystem();
            global $wp_filesystem;

            $wp_filesystem->delete($this->temp_dir, true);
        }
    }

    /**
     * Get export data.
     *
     * @return array
     */
    public function get_data(): array
    {
        return $this->data;
    }
}
