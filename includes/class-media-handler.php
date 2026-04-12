<?php
/**
 * Media Handler class for WordPress Migration Tool.
 *
 * Handles downloading and processing media files.
 *
 * @package WP_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WP_Migration_Media_Handler
 *
 * Handles media file operations during migration.
 */
class WP_Migration_Media_Handler
{
    /**
     * Singleton instance.
     *
     * @var WP_Migration_Media_Handler
     */
    private static $instance = null;

    /**
     * Temporary directory for media processing.
     *
     * @var string
     */
    private $temp_dir = '';

    /**
     * Get singleton instance.
     *
     * @return WP_Migration_Media_Handler
     */
    public static function get_instance(): WP_Migration_Media_Handler
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
     * Create temporary directory.
     *
     * @return string|WP_Error Directory path or error.
     */
    public function create_temp_dir(): string|WP_Error
    {
        $upload_dir = wp_upload_dir();
        $this->temp_dir = $upload_dir['basedir'] . '/wp-migration-media-' . wp_generate_uuid4();

        if (!wp_mkdir_p($this->temp_dir)) {
            return new WP_Error(
                'mkdir_failed',
                __('Failed to create temporary directory for media.', 'wordpress-migration')
            );
        }

        // Add .htaccess protection.
        file_put_contents($this->temp_dir . '/.htaccess', 'deny from all');
        file_put_contents($this->temp_dir . '/index.php', '<?php // Silence is golden');

        return $this->temp_dir;
    }

    /**
     * Download a media file from URL.
     *
     * @param string $url  Media URL.
     * @param int    $post_id Post ID to attach to.
     * @return int|WP_Error Attachment ID or error.
     */
    public function download_and_import(string $url, int $post_id = 0): int|WP_Error
    {
        // Check if already downloaded.
        $existing = get_posts([
            'post_type'      => 'attachment',
            'meta_query'     => [
                [
                    'key'   => '_wp_original_url',
                    'value' => $url,
                ],
            ],
        ]);

        if (!empty($existing)) {
            return $existing[0]->ID;
        }

        // Download the file.
        $response = wp_remote_get($url, [
            'timeout'   => 300,
            'redirection' => 10,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            return new WP_Error(
                'download_failed',
                sprintf(
                    /* translators: %d: HTTP response code */
                    __('Failed to download media. HTTP code: %d', 'wordpress-migration'),
                    $response_code
                )
            );
        }

        $body = wp_remote_retrieve_body($response);

        // Determine filename.
        $filename = $this->get_filename_from_url($url);
        if (empty($filename)) {
            $filename = 'media-' . wp_generate_uuid4() . '.tmp';
        }

        // Save to temp directory.
        $temp_path = $this->temp_dir . '/' . $filename;
        $result = file_put_contents($temp_path, $body);

        if (false === $result) {
            return new WP_Error(
                'write_failed',
                __('Failed to write media file to disk.', 'wordpress-migration')
            );
        }

        // Upload to WordPress.
        $upload = wp_upload_bits($filename, null, $body);

        if (isset($upload['error']) && !empty($upload['error'])) {
            return new WP_Error(
                'upload_failed',
                $upload['error']
            );
        }

        // Determine mime type.
        $wp_filetype = wp_check_filetype($filename, null);
        $mime_type = $wp_filetype['type'];

        if (empty($mime_type)) {
            $mime_type = 'application/octet-stream';
        }

        // Create attachment.
        $attachment_id = wp_insert_attachment([
            'guid'           => $upload['url'],
            'post_mime_type' => $mime_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $post_id,
        ], $upload['file']);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Add original URL for duplicate detection.
        add_post_meta($attachment_id, '_wp_original_url', $url);

        // Generate and update attachment metadata.
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        return $attachment_id;
    }

    /**
     * Get filename from URL.
     *
     * @param string $url URL.
     * @return string Filename.
     */
    private function get_filename_from_url(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return '';
        }

        $filename = wp_basename($path);

        // Check for valid filename characters.
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            return '';
        }

        return $filename;
    }

    /**
     * Process a batch of media files.
     *
     * @param array $media_items Array of media items to process.
     * @param callable|null $progress_callback Callback for progress updates.
     * @return array Results.
     */
    public function process_batch(array $media_items, ?callable $progress_callback = null): array
    {
        $results = [];
        $total = count($media_items);
        $processed = 0;

        foreach ($media_items as $item) {
            $attachment_id = $this->download_and_import(
                $item['url'],
                $item['post_id'] ?? 0
            );

            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'url'    => $item['url'],
                    'status' => 'failed',
                    'error'  => $attachment_id->get_error_message(),
                ];
            } else {
                $results[] = [
                    'url'           => $item['url'],
                    'attachment_id' => $attachment_id,
                    'status'        => 'success',
                ];
            }

            $processed++;

            if ($progress_callback) {
                call_user_func($progress_callback, $processed, $total);
            }
        }

        return $results;
    }

    /**
     * Create media archive from attachments.
     *
     * @param array $attachment_ids Attachment IDs to include.
     * @return string|WP_Error Path to archive or error.
     */
    public function create_media_archive(array $attachment_ids): string|WP_Error
    {
        require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';

        $upload_dir = wp_upload_dir();
        $temp_dir = $this->temp_dir ?: $this->create_temp_dir();

        if (is_wp_error($temp_dir)) {
            return $temp_dir;
        }

        $media_dir = $temp_dir . '/media';
        wp_mkdir_p($media_dir);

        $copied = 0;
        foreach ($attachment_ids as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (file_exists($file_path)) {
                copy($file_path, $media_dir . '/' . wp_basename($file_path));
                $copied++;
            }
        }

        $archive_name = 'media-' . date('Y-m-d-His') . '.zip';
        $archive_path = $upload_dir['basedir'] . '/' . $archive_name;

        if (file_exists($archive_path)) {
            wp_delete_file($archive_path);
        }

        $archive = new PclZip($archive_path);
        $result = $archive->create(
            $media_dir,
            PCLZIP_OPT_REMOVE_PATH,
            $media_dir
        );

        if (0 === $result) {
            return new WP_Error(
                'archive_failed',
                __('Failed to create media archive.', 'wordpress-migration')
            );
        }

        return $archive_path;
    }

    /**
     * Extract media archive.
     *
     * @param string $zip_path Path to ZIP file.
     * @return string|WP_Error Extract directory or error.
     */
    public function extract_media_archive(string $zip_path): string|WP_Error
    {
        require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';

        if (!file_exists($zip_path)) {
            return new WP_Error(
                'file_not_found',
                __('Media archive not found.', 'wordpress-migration')
            );
        }

        $upload_dir = wp_upload_dir();
        $extract_dir = $upload_dir['basedir'] . '/wp-migration-extract-' . wp_generate_uuid4();

        if (!wp_mkdir_p($extract_dir)) {
            return new WP_Error(
                'mkdir_failed',
                __('Failed to create extraction directory.', 'wordpress-migration')
            );
        }

        $archive = new PclZip($zip_path);
        $result = $archive->extract(PCLZIP_OPT_PATH, $extract_dir);

        if (0 === $result) {
            $this->cleanup_dir($extract_dir);
            return new WP_Error(
                'extract_failed',
                __('Failed to extract media archive.', 'wordpress-migration')
            );
        }

        return $extract_dir;
    }

    /**
     * Import media from extracted directory.
     *
     * @param string $extract_dir Extract directory.
     * @param int    $post_id     Post ID to attach to.
     * @return array Results.
     */
    public function import_from_directory(string $extract_dir, int $post_id = 0): array
    {
        $results = [];
        $files = glob($extract_dir . '/*');

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $filename = wp_basename($file);
            $wp_filetype = wp_check_filetype($filename, null);
            $mime_type = $wp_filetype['type'];

            if (empty($mime_type)) {
                $mime_type = 'application/octet-stream';
            }

            // Upload the file.
            $upload = wp_upload_bits($filename, null, file_get_contents($file));

            if (isset($upload['error']) && !empty($upload['error'])) {
                $results[] = [
                    'file'   => $filename,
                    'status' => 'failed',
                    'error'  => $upload['error'],
                ];
                continue;
            }

            // Create attachment.
            $attachment_id = wp_insert_attachment([
                'guid'           => $upload['url'],
                'post_mime_type' => $mime_type,
                'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $post_id,
            ], $upload['file']);

            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'file'   => $filename,
                    'status' => 'failed',
                    'error'  => $attachment_id->get_error_message(),
                ];
                continue;
            }

            // Generate metadata.
            $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $metadata);

            $results[] = [
                'file'           => $filename,
                'attachment_id'  => $attachment_id,
                'url'            => $upload['url'],
                'status'         => 'imported',
            ];
        }

        return $results;
    }

    /**
     * Get all attachment URLs for a site.
     *
     * @return array Attachment data.
     */
    public function get_all_attachments(): array
    {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ]);

        $data = [];
        foreach ($attachments as $attachment) {
            $data[] = [
                'id'       => $attachment->ID,
                'url'      => wp_get_attachment_url($attachment->ID),
                'filename' => wp_basename(get_attached_file($attachment->ID)),
                'mime_type' => $attachment->post_mime_type,
                'parent'   => $attachment->post_parent,
            ];
        }

        return $data;
    }

    /**
     * Cleanup temporary directory.
     *
     * @param string|null $dir Directory path (uses temp_dir if null).
     * @return void
     */
    public function cleanup(?string $dir = null): void
    {
        $dir = $dir ?: $this->temp_dir;
        if (!empty($dir) && is_dir($dir)) {
            $this->cleanup_dir($dir);
        }
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
     * Get temp directory.
     *
     * @return string
     */
    public function get_temp_dir(): string
    {
        return $this->temp_dir;
    }
}
