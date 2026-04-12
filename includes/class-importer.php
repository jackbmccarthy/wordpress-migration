<?php
/**
 * Importer class for WordPress Migration Tool.
 *
 * Handles importing content from exported packages.
 *
 * @package WP_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Migration_Importer
 *
 * Handles importing posts, users, taxonomy terms, media, and settings.
 */
class WP_Migration_Importer
{
    /**
     * Singleton instance.
     *
     * @var WP_Migration_Importer
     */
    private static $instance = null;

    /**
     * Import data.
     *
     * @var array
     */
    private $data = [];

    /**
     * Import options.
     *
     * @var array
     */
    private $options = [];

    /**
     * ID mapping for posts (old ID => new ID).
     *
     * @var array
     */
    private $post_id_map = [];

    /**
     * ID mapping for users (old ID => new ID).
     *
     * @var array
     */
    private $user_id_map = [];

    /**
     * ID mapping for terms (old ID => new ID).
     *
     * @var array
     */
    private $term_id_map = [];

    /**
     * Media URL to new attachment ID mapping.
     *
     * @var array
     */
    private $media_url_map = [];

    /**
     * Get singleton instance.
     *
     * @return WP_Migration_Importer
     */
    public static function get_instance(): WP_Migration_Importer
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
     * Validate import package.
     *
     * @param string $file_path Path to ZIP file.
     * @return array|WP_Error Manifest data or error.
     */
    public function validate_package(string $file_path): array|WP_Error
    {
        if (!file_exists($file_path)) {
            return new WP_Error(
                'file_not_found',
                __('Import file not found.', 'wordpress-migration')
            );
        }

        // Check file extension.
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ('zip' !== $ext) {
            return new WP_Error(
                'invalid_file_type',
                __('Please upload a valid ZIP file.', 'wordpress-migration')
            );
        }

        // Extract ZIP.
        require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
        require_once ABSPATH . '/wp-admin/includes/file.php';
        require_once ABSPATH . '/wp-admin/includes/image.php';

        $upload_dir = wp_upload_dir();
        $extract_dir = $upload_dir['basedir'] . '/wp-migration-import-' . wp_generate_uuid4();

        if (!wp_mkdir_p($extract_dir)) {
            return new WP_Error(
                'mkdir_failed',
                __('Failed to create extraction directory.', 'wordpress-migration')
            );
        }

        $archive = new PclZip($file_path);
        $result = $archive->extract(PCLZIP_OPT_PATH, $extract_dir);

        if (0 === $result) {
            $this->cleanup_dir($extract_dir);
            return new WP_Error(
                'extract_failed',
                __('Failed to extract ZIP file.', 'wordpress-migration')
            );
        }

        // Find and parse manifest.
        $manifest_path = $extract_dir . '/manifest.json';
        if (!file_exists($manifest_path)) {
            $this->cleanup_dir($extract_dir);
            return new WP_Error(
                'manifest_not_found',
                __('Manifest file not found in package.', 'wordpress-migration')
            );
        }

        $manifest_content = file_get_contents($manifest_path);
        if (false === $manifest_content) {
            $this->cleanup_dir($extract_dir);
            return new WP_Error(
                'manifest_read_failed',
                __('Failed to read manifest file.', 'wordpress-migration')
            );
        }

        $data = json_decode($manifest_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->cleanup_dir($extract_dir);
            return new WP_Error(
                'manifest_invalid_json',
                __('Manifest file contains invalid JSON.', 'wordpress-migration')
            );
        }

        $this->data = $data;
        $this->data['_extract_dir'] = $extract_dir;

        return $this->data;
    }

    /**
     * Process import with given options.
     *
     * @param array $options Import options.
     * @return array|WP_Error Import results or error.
     */
    public function process_import(array $options = []): array|WP_Error
    {
        $this->options = wp_parse_args($options, [
            'mode'            => 'merge', // 'merge' or 'replace'
            'import_users'    => true,
            'import_taxonomies' => true,
            'import_posts'    => true,
            'import_media'    => true,
            'import_settings' => true,
            'import_widgets'  => true,
            'import_menus'    => true,
            'import_plugins'  => true,
            'user_role_map'   => [], // Map old role => new role
        ]);

        $results = [
            'users'      => [],
            'taxonomies' => [],
            'posts'      => [],
            'media'      => [],
            'settings'   => [],
            'widgets'    => [],
            'menus'      => [],
            'plugins'    => [],
        ];

        // Import in order of dependencies.
        // 1. Taxonomies first (posts depend on them).
        if ($this->options['import_taxonomies'] && !empty($this->data['taxonomies'])) {
            $tax_result = $this->import_taxonomies();
            if (is_wp_error($tax_result)) {
                return $tax_result;
            }
            $results['taxonomies'] = $tax_result;
        }

        // 2. Users (posts reference authors).
        if ($this->options['import_users'] && !empty($this->data['users'])) {
            $user_result = $this->import_users();
            if (is_wp_error($user_result)) {
                return $user_result;
            }
            $results['users'] = $user_result;
        }

        // 3. Media (posts reference featured images and attachments).
        if ($this->options['import_media'] && !empty($this->data['media_manifest'])) {
            $media_result = $this->import_media();
            if (is_wp_error($media_result)) {
                return $media_result;
            }
            $results['media'] = $media_result;
        }

        // 4. Posts.
        if ($this->options['import_posts'] && !empty($this->data['posts'])) {
            $post_result = $this->import_posts();
            if (is_wp_error($post_result)) {
                return $post_result;
            }
            $results['posts'] = $post_result;
        }

        // 5. Settings (after posts for menu locations).
        if ($this->options['import_settings']) {
            $settings_result = $this->import_settings();
            if (is_wp_error($settings_result)) {
                return $settings_result;
            }
            $results['settings'] = $settings_result;
        }

        // 6. Widgets.
        if ($this->options['import_widgets'] && !empty($this->data['widgets'])) {
            $widget_result = $this->import_widgets();
            if (is_wp_error($widget_result)) {
                return $widget_result;
            }
            $results['widgets'] = $widget_result;
        }

        // 7. Menus.
        if ($this->options['import_menus'] && !empty($this->data['menus'])) {
            $menu_result = $this->import_menus();
            if (is_wp_error($menu_result)) {
                return $menu_result;
            }
            $results['menus'] = $menu_result;
        }

        // 8. Plugins.
        if ($this->options['import_plugins'] && !empty($this->data['plugins'])) {
            $plugin_result = $this->import_plugins($this->data['plugins']);
            if (is_wp_error($plugin_result)) {
                return $plugin_result;
            }
            $results['plugins'] = $plugin_result;
        }

        // Cleanup.
        if (!empty($this->data['_extract_dir'])) {
            $this->cleanup_dir($this->data['_extract_dir']);
        }

        return $results;
    }

    /**
     * Import taxonomy terms.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_taxonomies(): array|WP_Error
    {
        if (empty($this->data['taxonomies'])) {
            return [];
        }

        $results = [];
        $term_mappings = [];

        // Group terms by taxonomy for proper parent hierarchy.
        $terms_by_tax = [];
        foreach ($this->data['taxonomies'] as $term) {
            $terms_by_tax[$term['taxonomy']][] = $term;
        }

        // Sort terms by parent (parents before children).
        foreach ($terms_by_tax as $taxonomy => $terms) {
            usort($terms, function ($a, $b) {
                return ($a['parent'] ?? 0) <=> ($b['parent'] ?? 0);
            });

            foreach ($terms as $term_data) {
                $old_id = $term_data['term_id'];
                $parent = $term_data['parent'];

                // Map old parent to new parent if exists.
                $new_parent = 0;
                if ($parent > 0 && isset($term_mappings[$taxonomy][$parent])) {
                    $new_parent = $term_mappings[$taxonomy][$parent];
                }

                // Check if term already exists.
                $existing = get_term_by('slug', $term_data['slug'], $taxonomy);

                if ($existing && !is_wp_error($existing)) {
                    $new_id = $existing->term_id;
                    $term_mappings[$taxonomy][$old_id] = $new_id;
                    $this->term_id_map[$old_id] = $new_id;
                    $results[] = [
                        'old_id'      => $old_id,
                        'new_id'      => $new_id,
                        'name'        => $term_data['name'],
                        'slug'        => $term_data['slug'],
                        'taxonomy'    => $taxonomy,
                        'status'      => 'skipped',
                        'reason'      => 'already_exists',
                    ];
                    continue;
                }

                // Create the term.
                $result = wp_insert_term(
                    $term_data['name'],
                    $taxonomy,
                    [
                        'description' => $term_data['description'],
                        'slug'        => $term_data['slug'],
                        'parent'      => $new_parent,
                    ]
                );

                if (is_wp_error($result)) {
                    // Try to create with unique slug.
                    $result = wp_insert_term(
                        $term_data['name'] . '-' . wp_generate_uuid4(),
                        $taxonomy,
                        [
                            'description' => $term_data['description'],
                            'parent'      => $new_parent,
                        ]
                    );
                }

                if (!is_wp_error($result)) {
                    $new_id = $result['term_id'];
                    $term_mappings[$taxonomy][$old_id] = $new_id;
                    $this->term_id_map[$old_id] = $new_id;
                    $results[] = [
                        'old_id'   => $old_id,
                        'new_id'   => $new_id,
                        'name'     => $term_data['name'],
                        'slug'     => $term_data['slug'],
                        'taxonomy' => $taxonomy,
                        'status'   => 'imported',
                    ];
                } else {
                    $results[] = [
                        'old_id'   => $old_id,
                        'name'     => $term_data['name'],
                        'slug'     => $term_data['slug'],
                        'taxonomy' => $taxonomy,
                        'status'   => 'failed',
                        'error'    => $result->get_error_message(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Import users.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_users(): array|WP_Error
    {
        if (empty($this->data['users'])) {
            return [];
        }

        $results = [];

        foreach ($this->data['users'] as $user_data) {
            $old_id = $user_data['ID'];

            // Check if user with this email already exists.
            $existing = get_user_by('email', $user_data['user_email']);

            if ($existing) {
                $this->user_id_map[$old_id] = $existing->ID;
                $results[] = [
                    'old_id'   => $old_id,
                    'new_id'   => $existing->ID,
                    'login'    => $user_data['user_login'],
                    'email'    => $user_data['user_email'],
                    'status'   => 'skipped',
                    'reason'   => 'email_exists',
                ];
                continue;
            }

            // Check if user with same login exists.
            $existing_login = get_user_by('login', $user_data['user_login']);
            if ($existing_login) {
                $new_login = $user_data['user_login'] . '-' . wp_generate_uuid4();
            } else {
                $new_login = $user_data['user_login'];
            }

            // Map roles.
            $roles = $user_data['roles'];
            if (!empty($this->options['user_role_map'])) {
                $mapped_roles = [];
                foreach ($roles as $role) {
                    $mapped_roles[] = $this->options['user_role_map'][$role] ?? $role;
                }
                $roles = $mapped_roles;
            }

            // Create user.
            $user_id = wp_insert_user([
                'user_login'   => $new_login,
                'user_nicename' => $user_data['user_nicename'],
                'user_email'   => $user_data['user_email'],
                'user_url'     => $user_data['user_url'],
                'display_name' => $user_data['display_name'],
                'first_name'   => $user_data['first_name'],
                'last_name'    => $user_data['last_name'],
                'description'  => $user_data['description'],
                'role'         => $roles[0] ?? 'subscriber',
            ]);

            if (is_wp_error($user_id)) {
                $results[] = [
                    'old_id' => $old_id,
                    'login'  => $user_data['user_login'],
                    'status' => 'failed',
                    'error'  => $user_id->get_error_message(),
                ];
                continue;
            }

            // Set additional roles if multiple.
            if (count($roles) > 1) {
                $user = new WP_User($user_id);
                foreach (array_slice($roles, 1) as $role) {
                    $user->add_role($role);
                }
            }

            // Import user meta.
            if (!empty($user_data['meta'])) {
                foreach ($user_data['meta'] as $key => $value) {
                    $decoded = maybe_unserialize($value);
                    if (is_array($decoded)) {
                        foreach ($decoded as $single) {
                            add_user_meta($user_id, $key, $single);
                        }
                    } else {
                        add_user_meta($user_id, $key, $decoded);
                    }
                }
            }

            $this->user_id_map[$old_id] = $user_id;
            $results[] = [
                'old_id' => $old_id,
                'new_id' => $user_id,
                'login'  => $new_login,
                'email'  => $user_data['user_email'],
                'status' => 'imported',
            ];
        }

        return $results;
    }

    /**
     * Import media files.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_media(): array|WP_Error
    {
        if (empty($this->data['media_manifest'])) {
            return [];
        }

        $extract_dir = $this->data['_extract_dir'];
        $media_dir = $extract_dir . '/media';

        if (!is_dir($media_dir)) {
            return new WP_Error(
                'media_dir_missing',
                __('Media directory not found in package.', 'wordpress-migration')
            );
        }

        $results = [];

        foreach ($this->data['media_manifest'] as $media_item) {
            $old_id = $media_item['attachment_id'];
            $file_name = $media_item['file_name'];
            $source_path = $media_dir . '/' . $file_name;

            if (!file_exists($source_path)) {
                $results[] = [
                    'old_id'     => $old_id,
                    'file_name'  => $file_name,
                    'status'     => 'failed',
                    'error'      => 'File not found in package',
                ];
                continue;
            }

            // Check if attachment with this GUID already exists.
            $existing = get_posts([
                'post_type'      => 'attachment',
                'meta_query'     => [
                    [
                        'key'   => '_wp_original_guid',
                        'value' => $media_item['guid'],
                    ],
                ],
            ]);

            if (!empty($existing)) {
                $this->media_url_map[$media_item['file_url']] = $existing[0]->ID;
                $this->post_id_map[$old_id] = $existing[0]->ID;
                $results[] = [
                    'old_id'    => $old_id,
                    'new_id'    => $existing[0]->ID,
                    'file_name' => $file_name,
                    'status'    => 'skipped',
                    'reason'    => 'already_exists',
                ];
                continue;
            }

            // Upload the file.
            $upload = wp_upload_bits($file_name, null, file_get_contents($source_path));

            if (isset($upload['error']) && !empty($upload['error'])) {
                $results[] = [
                    'old_id'    => $old_id,
                    'file_name' => $file_name,
                    'status'    => 'failed',
                    'error'     => $upload['error'],
                ];
                continue;
            }

            // Determine mime type.
            $wp_filetype = wp_check_filetype($file_name, null);
            $mime_type = $media_item['mime_type'] ?? $wp_filetype['type'];

            // Create attachment.
            $attachment_id = wp_insert_attachment([
                'guid'           => $upload['url'],
                'post_mime_type' => $mime_type,
                'post_title'     => preg_replace('/\.[^.]+$/', '', $file_name),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ], $upload['file']);

            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'old_id'    => $old_id,
                    'file_name' => $file_name,
                    'status'    => 'failed',
                    'error'     => $attachment_id->get_error_message(),
                ];
                continue;
            }

            // Add original GUID for duplicate detection.
            add_post_meta($attachment_id, '_wp_original_guid', $media_item['guid']);

            // Update attachment metadata.
            $metadata = $media_item['metadata'] ?? [];
            if (!empty($metadata) && isset($metadata['sizes'])) {
                // Fix file paths in sizes.
                $new_baseurl = dirname($upload['url']);
                foreach ($metadata['sizes'] as $size => $size_data) {
                    if (isset($size_data['file'])) {
                        $metadata['sizes'][$size]['file'] = wp_basename($size_data['file']);
                    }
                }
            }
            wp_update_attachment_metadata($attachment_id, $metadata);

            // Map old ID to new ID.
            $this->post_id_map[$old_id] = $attachment_id;
            $this->media_url_map[$media_item['file_url']] = $attachment_id;

            $results[] = [
                'old_id'    => $old_id,
                'new_id'    => $attachment_id,
                'file_name' => $file_name,
                'status'    => 'imported',
            ];
        }

        return $results;
    }

    /**
     * Import posts.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_posts(): array|WP_Error
    {
        if (empty($this->data['posts'])) {
            return [];
        }

        $results = [];

        foreach ($this->data['posts'] as $post_data) {
            $old_id = $post_data['post_id'];

            // Check if post with same GUID already exists.
            $existing = get_posts([
                'meta_query' => [
                    [
                        'key'   => '_wp_original_guid',
                        'value' => $post_data['guid'],
                    ],
                ],
            ]);

            if (!empty($existing)) {
                $this->post_id_map[$old_id] = $existing[0]->ID;
                $results[] = [
                    'old_id'      => $old_id,
                    'new_id'      => $existing[0]->ID,
                    'title'       => $post_data['post_title'],
                    'status'      => 'skipped',
                    'reason'      => 'already_exists',
                ];
                continue;
            }

            // Map author.
            $new_author = $post_data['post_author'];
            if (isset($this->user_id_map[$post_data['post_author']])) {
                $new_author = $this->user_id_map[$post_data['post_author']];
            }

            // Map parent post.
            $new_parent = 0;
            if ($post_data['post_parent'] > 0 && isset($this->post_id_map[$post_data['post_parent']])) {
                $new_parent = $this->post_id_map[$post_data['post_parent']];
            }

            // Handle featured image.
            $thumbnail = null;
            if (!empty($post_data['thumbnail']) && isset($this->media_url_map[$post_data['thumbnail']])) {
                $thumbnail = $this->media_url_map[$post_data['thumbnail']];
            }

            // Prepare post data.
            $new_post_id = wp_insert_post([
                'post_type'    => $post_data['post_type'],
                'post_title'   => $post_data['post_title'],
                'post_name'    => $post_data['post_name'],
                'post_content' => $post_data['post_content'],
                'post_excerpt' => $post_data['post_excerpt'],
                'post_status'  => $post_data['post_status'],
                'post_date'    => $post_data['post_date'],
                'post_modified' => $post_data['post_modified'],
                'post_author'  => $new_author,
                'post_parent'  => $new_parent,
                'menu_order'   => $post_data['menu_order'],
                'guid'         => $post_data['guid'],
                'import_id'    => $old_id, // Try to preserve ID.
            ], true);

            if (is_wp_error($new_post_id)) {
                // If preserving ID fails, let WordPress assign a new ID.
                unset($post_data['import_id']);
                $new_post_id = wp_insert_post([
                    'post_type'    => $post_data['post_type'],
                    'post_title'   => $post_data['post_title'],
                    'post_name'    => wp_unique_post_slug($post_data['post_name']),
                    'post_content' => $post_data['post_content'],
                    'post_excerpt' => $post_data['post_excerpt'],
                    'post_status'  => $post_data['post_status'],
                    'post_date'    => $post_data['post_date'],
                    'post_modified' => $post_data['post_modified'],
                    'post_author'  => $new_author,
                    'post_parent'  => $new_parent,
                    'menu_order'   => $post_data['menu_order'],
                ], true);
            }

            if (is_wp_error($new_post_id)) {
                $results[] = [
                    'old_id' => $old_id,
                    'title'  => $post_data['post_title'],
                    'status' => 'failed',
                    'error'  => $new_post_id->get_error_message(),
                ];
                continue;
            }

            // Set featured image.
            if ($thumbnail) {
                set_post_thumbnail($new_post_id, $thumbnail);
            }

            // Add original GUID for duplicate detection.
            add_post_meta($new_post_id, '_wp_original_guid', $post_data['guid']);

            // Import post meta.
            if (!empty($post_data['meta'])) {
                foreach ($post_data['meta'] as $key => $value) {
                    // Skip internal meta.
                    if (str_starts_with($key, '_')) {
                        continue;
                    }

                    $decoded = maybe_unserialize($value);
                    if (is_array($decoded)) {
                        foreach ($decoded as $single) {
                            add_post_meta($new_post_id, $key, $single);
                        }
                    } else {
                        add_post_meta($new_post_id, $key, $decoded);
                    }
                }
            }

            // Import terms.
            if (!empty($post_data['terms'])) {
                foreach ($post_data['terms'] as $taxonomy => $terms) {
                    $term_ids = [];
                    foreach ($terms as $term) {
                        if (isset($this->term_id_map[$term['term_id']])) {
                            $term_ids[] = $this->term_id_map[$term['term_id']];
                        }
                    }
                    if (!empty($term_ids)) {
                        wp_set_object_terms($new_post_id, $term_ids, $taxonomy);
                    }
                }
            }

            $this->post_id_map[$old_id] = $new_post_id;
            $results[] = [
                'old_id'   => $old_id,
                'new_id'   => $new_post_id,
                'title'    => $post_data['post_title'],
                'type'     => $post_data['post_type'],
                'status'   => 'imported',
            ];
        }

        return $results;
    }

    /**
     * Import settings.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_settings(): array|WP_Error
    {
        $results = [];

        if (empty($this->data['settings'])) {
            return $results;
        }

        $settings = $this->data['settings'];

        // Import site options.
        $options_to_import = [
            'blogname',
            'blogdescription',
            'permalink_structure',
            'category_base',
            'tag_base',
        ];

        foreach ($options_to_import as $option) {
            if (isset($settings[$option])) {
                update_option($option, $settings[$option]);
                $results[$option] = 'updated';
            }
        }

        // Import theme mods.
        if (!empty($settings['theme_mods']) && !empty($settings['theme_name'])) {
            $current_theme = wp_get_theme();
            $import_theme = $settings['theme_name'];

            if ($current_theme->get_stylesheet() === $import_theme) {
                foreach ($settings['theme_mods'] as $key => $value) {
                    update_option('theme_mods_' . $import_theme, [$key => $value]);
                }
                $results['theme_mods'] = 'updated';
            } else {
                $results['theme_mods'] = 'skipped';
                $results['theme_mods_reason'] = 'theme_mismatch';
            }
        }

        // Import menu locations.
        if (!empty($settings['nav_menu_locations']) && !empty($this->data['menus'])) {
            $location_map = [];
            foreach ($this->data['menus'] as $menu) {
                $old_id = $menu['menu_id'];
                if (isset($this->post_id_map[$old_id])) {
                    // Find the term ID for this menu.
                    $term_id = wp_get_nav_menu_object($menu['menu_name']);
                    if ($term_id) {
                        foreach ($settings['nav_menu_locations'] as $location => $menu_id) {
                            if ($menu_id === $old_id) {
                                $location_map[$location] = $term_id->term_id;
                            }
                        }
                    }
                }
            }
            if (!empty($location_map)) {
                set_theme_mod('nav_menu_locations', $location_map);
                $results['nav_menu_locations'] = 'updated';
            }
        }

        return $results;
    }

    /**
     * Import widgets.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_widgets(): array|WP_Error
    {
        if (empty($this->data['widgets'])) {
            return [];
        }

        $results = [];

        // Import sidebars and widgets.
        if (!empty($this->data['widgets']['sidebars'])) {
            update_option('sidebars_widgets', $this->data['widgets']['sidebars']);
            $results['sidebars'] = 'updated';
        }

        if (!empty($this->data['widgets']['widgets'])) {
            foreach ($this->data['widgets']['widgets'] as $widget_id => $widget_data) {
                update_option($widget_id, $widget_data);
            }
            $results['widgets'] = 'updated';
        }

        return $results;
    }

    /**
     * Import navigation menus.
     *
     * @return array|WP_Error Results or error.
     */
    public function import_menus(): array|WP_Error
    {
        if (empty($this->data['menus'])) {
            return [];
        }

        $results = [];

        foreach ($this->data['menus'] as $menu_data) {
            $old_id = $menu_data['menu_id'];

            // Check if menu already exists.
            $existing = wp_get_nav_menu_object($menu_data['menu_name']);
            if ($existing) {
                $menu_id = $existing->term_id;
                $results[] = [
                    'old_id'   => $old_id,
                    'new_id'   => $menu_id,
                    'name'     => $menu_data['menu_name'],
                    'status'   => 'skipped',
                    'reason'   => 'already_exists',
                ];
                continue;
            }

            // Create menu.
            $menu_id = wp_create_nav_menu($menu_data);
            if (is_wp_error($menu_id)) {
                $results[] = [
                    'old_id' => $old_id,
                    'name'   => $menu_data['menu_name'],
                    'status' => 'failed',
                    'error'  => $menu_id->get_error_message(),
                ];
                continue;
            }

            // Add menu items.
            foreach ($menu_data['items'] as $item) {
                $menu_item_db_id = wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title'     => $item['menu_item_title'],
                    'menu-item-url'       => $item['menu_item_url'],
                    'menu-item-type'      => $item['menu_item_type'],
                    'menu-item-object'   => $item['menu_item_object'],
                    'menu-item-parent-id' => $this->get_new_menu_item_parent($item['menu_item_parent']),
                    'menu-item-target'    => $item['menu_item_target'],
                    'menu-item-classes'   => implode(' ', $item['menu_item_classes'] ?? []),
                    'menu-item-xfn'       => $item['menu_item_xfn'],
                    'menu-item-status'    => $item['menu_item_status'] ?? 'publish',
                ]);

                if (!is_wp_error($menu_item_db_id)) {
                    $this->post_id_map[$item['menu_item_id']] = $menu_item_db_id;
                }
            }

            $results[] = [
                'old_id'   => $old_id,
                'new_id'   => $menu_id,
                'name'     => $menu_data['menu_name'],
                'item_count' => count($menu_data['items']),
                'status'   => 'imported',
            ];
        }

        return $results;
    }

    /**
     * Get new menu item parent ID.
     *
     * @param int $old_parent Old parent ID.
     * @return int New parent ID.
     */
    private function get_new_menu_item_parent(int $old_parent): int
    {
        if ($old_parent <= 0) {
            return 0;
        }

        return $this->post_id_map[$old_parent] ?? 0;
    }

    /**
     * Import plugin settings and optionally prompt for installation.
     *
     * @param array $data Plugin data from export.
     * @param array $options Import options.
     * @return array Import results.
     */
    public function import_plugins(array $data, array $options = array()): array
    {
        $activate = isset($options['activate']) ? $options['activate'] : true;

        $results = array(
            'active_plugins' => array(),
            'plugin_settings' => array(),
            'missing_plugins' => array(),
        );

        if (isset($data['active_plugins'])) {
            // Check which plugins are already installed.
            $installed = get_plugins();

            foreach ($data['active_plugins'] as $plugin_path) {
                if (isset($installed[$plugin_path])) {
                    // Plugin is installed, just activate.
                    if ($activate && !is_plugin_active($plugin_path)) {
                        activate_plugin($plugin_path);
                    }
                    $results['active_plugins'][] = $plugin_path;
                } else {
                    // Plugin not installed.
                    $results['missing_plugins'][] = $plugin_path;
                }
            }
        }

        // Import plugin settings.
        if (isset($data['plugin_settings'])) {
            foreach ($data['plugin_settings'] as $plugin_path => $settings) {
                if (in_array($plugin_path, $results['active_plugins'], true)) {
                    foreach ($settings as $option_name => $option_value) {
                        update_option($option_name, $option_value);
                    }
                    $results['plugin_settings'][$plugin_path] = count($settings);
                }
            }
        }

        return $results;
    }

    /**
     * Cleanup directory.
     *
     * @param string $dir Directory path.
     * @return void
     */
    private function cleanup_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        WP_Filesystem();
        global $wp_filesystem;

        $wp_filesystem->delete($dir, true);
    }

    /**
     * Get ID mappings.
     *
     * @return array
     */
    public function get_id_mappings(): array
    {
        return [
            'posts' => $this->post_id_map,
            'users' => $this->user_id_map,
            'terms' => $this->term_id_map,
            'media' => $this->media_url_map,
        ];
    }
}
