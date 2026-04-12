<?php
/**
 * Admin display partial for WordPress Migration Tool.
 *
 * @package WP_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

$admin = WP_Migration_Admin::get_instance();
$page_slug = $admin->get_page_slug();
?>
<div class="wrap wp-migration-wrap">
    <h1>
        <?php echo esc_html__('WordPress Migration Tool', 'wordpress-migration'); ?>
    </h1>

    <h2 class="nav-tab-wrapper">
        <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=export"
           class="nav-tab <?php echo 'export' === $active_tab ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Export', 'wordpress-migration'); ?>
        </a>
        <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=import"
           class="nav-tab <?php echo 'import' === $active_tab ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Import', 'wordpress-migration'); ?>
        </a>
        <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=settings"
           class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Settings', 'wordpress-migration'); ?>
        </a>
        <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=help"
           class="nav-tab <?php echo 'help' === $active_tab ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html__('Help', 'wordpress-migration'); ?>
        </a>
    </h2>

    <div class="wp-migration-content">
        <?php
        switch ($active_tab) {
            case 'export':
                $this->render_export_tab();
                break;
            case 'import':
                $this->render_import_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
            case 'help':
                $this->render_help_tab();
                break;
        }
        ?>
    </div>
</div>

<?php
/**
 * Render export tab.
 *
 * @return void
 */
function render_export_tab(): void
{
    ?>
    <div class="wp-migration-export-tab">
        <div class="wp-migration-intro">
            <p>
                <?php esc_html_e('Export your entire WordPress site including posts, pages, custom post types, users, media, taxonomy terms, settings, widgets, and menus.', 'wordpress-migration'); ?>
            </p>
        </div>

        <form id="wp-migration-export-form" class="wp-migration-form">
            <div class="wp-migration-options">
                <h3><?php esc_html_e('Select What to Export', 'wordpress-migration'); ?></h3>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_posts" value="true" checked />
                    <span><?php esc_html_e('Posts', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_pages" value="true" checked />
                    <span><?php esc_html_e('Pages', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_cpts" value="true" checked />
                    <span><?php esc_html_e('Custom Post Types', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_taxonomies" value="true" checked />
                    <span><?php esc_html_e('Taxonomies (Categories, Tags, Custom Taxonomies)', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_users" value="true" checked />
                    <span><?php esc_html_e('Users (without passwords)', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_media" value="true" checked />
                    <span><?php esc_html_e('Media Library (images, files)', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_settings" value="true" checked />
                    <span><?php esc_html_e('Site Settings', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_widgets" value="true" checked />
                    <span><?php esc_html_e('Widgets', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="include_menus" value="true" checked />
                    <span><?php esc_html_e('Navigation Menus', 'wordpress-migration'); ?></span>
                </label>
            </div>

            <div class="wp-migration-actions">
                <button type="submit" class="button button-primary button-hero" id="wp-migration-export-btn">
                    <?php esc_html_e('Create Export Package', 'wordpress-migration'); ?>
                </button>
            </div>
        </form>

        <div id="wp-migration-export-progress" class="wp-migration-progress" style="display: none;">
            <div class="wp-migration-progress-bar">
                <div class="wp-migration-progress-fill"></div>
            </div>
            <p class="wp-migration-progress-text"></p>
        </div>

        <div id="wp-migration-export-result" class="wp-migration-result" style="display: none;">
            <div class="wp-migration-result-success">
                <h3><?php esc_html_e('Export Complete!', 'wordpress-migration'); ?></h3>
                <p><?php esc_html_e('Your export package is ready for download.', 'wordpress-migration'); ?></p>
                <div class="wp-migration-stats"></div>
                <a href="#" class="button button-primary" id="wp-migration-download-btn">
                    <?php esc_html_e('Download Export File', 'wordpress-migration'); ?>
                </a>
            </div>
        </div>

        <div id="wp-migration-export-error" class="wp-migration-error" style="display: none;">
            <h3><?php esc_html_e('Export Failed', 'wordpress-migration'); ?></h3>
            <p class="error-message"></p>
            <button type="button" class="button" onclick="location.reload()">
                <?php esc_html_e('Try Again', 'wordpress-migration'); ?>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render import tab.
 *
 * @return void
 */
function render_import_tab(): void
{
    ?>
    <div class="wp-migration-import-tab">
        <div class="wp-migration-intro">
            <p>
                <?php esc_html_e('Import content from a previously exported WordPress Migration package. You can choose to merge with existing content or replace it.', 'wordpress-migration'); ?>
            </p>
        </div>

        <form id="wp-migration-import-form" class="wp-migration-form" enctype="multipart/form-data">
            <div class="wp-migration-upload-zone" id="wp-migration-dropzone">
                <div class="wp-migration-upload-content">
                    <span class="dashicons dashicons-upload"></span>
                    <p>
                        <?php esc_html_e('Drag and drop your export ZIP file here', 'wordpress-migration'); ?>
                        <br/>
                        <span class="wp-migration-or"><?php esc_html_e('or', 'wordpress-migration'); ?></span>
                    </p>
                    <input type="file" name="import_file" id="wp-migration-file-input" accept=".zip" />
                    <label for="wp-migration-file-input" class="button">
                        <?php esc_html_e('Select ZIP File', 'wordpress-migration'); ?>
                    </label>
                    <p class="wp-migration-filename"></p>
                </div>
            </div>

            <div class="wp-migration-options">
                <h3><?php esc_html_e('Import Options', 'wordpress-migration'); ?></h3>

                <div class="wp-migration-mode-select">
                    <label>
                        <input type="radio" name="import_mode" value="merge" checked />
                        <strong><?php esc_html_e('Merge', 'wordpress-migration'); ?></strong>
                        <span><?php esc_html_e('Add imported content alongside existing content', 'wordpress-migration'); ?></span>
                    </label>
                    <label>
                        <input type="radio" name="import_mode" value="replace" />
                        <strong><?php esc_html_e('Replace', 'wordpress-migration'); ?></strong>
                        <span><?php esc_html_e('Overwrite existing content with imported content', 'wordpress-migration'); ?></span>
                    </label>
                </div>

                <h4><?php esc_html_e('What to Import', 'wordpress-migration'); ?></h4>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_users" value="true" checked />
                    <span><?php esc_html_e('Users', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_taxonomies" value="true" checked />
                    <span><?php esc_html_e('Taxonomies', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_posts" value="true" checked />
                    <span><?php esc_html_e('Posts, Pages, and Custom Post Types', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_media" value="true" checked />
                    <span><?php esc_html_e('Media Library', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_settings" value="true" checked />
                    <span><?php esc_html_e('Site Settings', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_widgets" value="true" checked />
                    <span><?php esc_html_e('Widgets', 'wordpress-migration'); ?></span>
                </label>

                <label class="wp-migration-checkbox">
                    <input type="checkbox" name="import_menus" value="true" checked />
                    <span><?php esc_html_e('Navigation Menus', 'wordpress-migration'); ?></span>
                </label>
            </div>

            <div class="wp-migration-actions">
                <button type="submit" class="button button-primary button-hero" id="wp-migration-import-btn" disabled>
                    <?php esc_html_e('Start Import', 'wordpress-migration'); ?>
                </button>
            </div>
        </form>

        <div id="wp-migration-import-progress" class="wp-migration-progress" style="display: none;">
            <div class="wp-migration-progress-bar">
                <div class="wp-migration-progress-fill"></div>
            </div>
            <p class="wp-migration-progress-text"></p>
        </div>

        <div id="wp-migration-import-result" class="wp-migration-result" style="display: none;">
            <div class="wp-migration-result-success">
                <h3><?php esc_html_e('Import Complete!', 'wordpress-migration'); ?></h3>
                <div class="wp-migration-import-stats"></div>
                <p>
                    <a href="<?php echo esc_url(admin_url()); ?>">
                        <?php esc_html_e('Return to Dashboard', 'wordpress-migration'); ?>
                    </a>
                </p>
            </div>
        </div>

        <div id="wp-migration-import-error" class="wp-migration-error" style="display: none;">
            <h3><?php esc_html_e('Import Failed', 'wordpress-migration'); ?></h3>
            <p class="error-message"></p>
            <button type="button" class="button" onclick="location.reload()">
                <?php esc_html_e('Try Again', 'wordpress-migration'); ?>
            </button>
        </div>
    </div>
    <?php
}

/**
 * Render settings tab.
 *
 * @return void
 */
function render_settings_tab(): void
{
    $api_key = WP_Migration_REST_API::get_api_key();
    ?>
    <div class="wp-migration-settings-tab">
        <form id="wp-migration-settings-form" class="wp-migration-form">
            <h3><?php esc_html_e('Remote Migration API', 'wordpress-migration'); ?></h3>

            <p>
                <?php esc_html_e('Use the API key below to connect to this site from a remote location for push/pull migrations.', 'wordpress-migration'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('API Key Status', 'wordpress-migration'); ?></th>
                    <td>
                        <?php if ($api_key): ?>
                            <span class="wp-migration-status-active"><?php esc_html_e('Active', 'wordpress-migration'); ?></span>
                        <?php else: ?>
                            <span class="wp-migration-status-inactive"><?php esc_html_e('Not Set', 'wordpress-migration'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('API Key', 'wordpress-migration'); ?></th>
                    <td>
                        <?php if ($api_key): ?>
                            <code id="wp-migration-api-key-display"><?php echo esc_html($api_key); ?></code>
                            <button type="button" class="button" id="wp-migration-copy-key">
                                <?php esc_html_e('Copy', 'wordpress-migration'); ?>
                            </button>
                        <?php else: ?>
                            <em><?php esc_html_e('No API key generated yet.', 'wordpress-migration'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Actions', 'wordpress-migration'); ?></th>
                    <td>
                        <button type="button" class="button" id="wp-migration-regenerate-key">
                            <?php esc_html_e('Regenerate API Key', 'wordpress-migration'); ?>
                        </button>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('API Usage', 'wordpress-migration'); ?></h3>

            <p><?php esc_html_e('Remote site URL:', 'wordpress-migration'); ?></p>
            <code><?php echo esc_html(rest_url('wp-migration/v1/')); ?></code>

            <p><?php esc_html_e('Authentication:', 'wordpress-migration'); ?></p>
            <code>Authorization: Bearer YOUR_API_KEY</code>

            <h4><?php esc_html_e('Available Endpoints', 'wordpress-migration'); ?></h4>
            <ul>
                <li><code>GET /status</code> - Check remote site status</li>
                <li><code>GET /export</code> - Get export package</li>
                <li><code>POST /import</code> - Push import to remote</li>
                <li><code>POST /pull</code> - Pull import from remote</li>
            </ul>
        </form>
    </div>
    <?php
}

/**
 * Render help tab.
 *
 * @return void
 */
function render_help_tab(): void
{
    ?>
    <div class="wp-migration-help-tab">
        <h3><?php esc_html_e('Frequently Asked Questions', 'wordpress-migration'); ?></h3>

        <div class="wp-migration-faq">
            <h4><?php esc_html_e('What does this plugin export?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('The Migration Tool can export all of your WordPress content including:', 'wordpress-migration'); ?>
            </p>
            <ul>
                <li><?php esc_html_e('Posts, pages, and custom post types', 'wordpress-migration'); ?></li>
                <li><?php esc_html_e('Categories, tags, and custom taxonomy terms', 'wordpress-migration'); ?></li>
                <li><?php esc_html_e('Users (passwords are not exported for security)', 'wordpress-migration'); ?></li>
                <li><?php esc_html_e('Media library files (images, documents, etc.)', 'wordpress-migration'); ?></li>
                <li><?php esc_html_e('Site settings (title, description, permalinks, etc.)', 'wordpress-migration'); ?></li>
                <li><?php esc_html_e('Widgets and widget configurations', 'wordpress-migration'); ?></li>
                <li><?php esc_html_e('Navigation menus and menu locations', 'wordpress-migration'); ?></li>
            </ul>

            <h4><?php esc_html_e('How do I import to a new site?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('On the Import tab, upload the ZIP file you downloaded from your export. Choose whether to merge or replace content, select what to import, and click Start Import.', 'wordpress-migration'); ?>
            </p>

            <h4><?php esc_html_e('Will this overwrite my existing content?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('By default, the import merges content. Duplicate detection prevents creating multiple copies of the same post or media. Choose "Replace" mode if you want to overwrite existing content.', 'wordpress-migration'); ?>
            </p>

            <h4><?php esc_html_e('What about my theme and plugins?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('Theme settings (theme mods) are exported but only applied if you\'re using the same theme on the destination site. Plugin settings are not exported - you\'ll need to reinstall and reconfigure plugins on the new site.', 'wordpress-migration'); ?>
            </p>

            <h4><?php esc_html_e('Can I do remote migrations?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('Yes! Go to Settings to generate an API key, then use the REST API endpoints to push to or pull from a remote WordPress site.', 'wordpress-migration'); ?>
            </p>

            <h4><?php esc_html_e('Why aren\'t passwords exported?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('For security reasons, user passwords are never exported. After importing, users will need to use the password reset feature to set their passwords.', 'wordpress-migration'); ?>
            </p>

            <h4><?php esc_html_e('Large sites are timing out. What can I do?', 'wordpress-migration'); ?></h4>
            <p>
                <?php esc_html_e('For very large sites, consider using the REST API to do incremental migrations, or increase your PHP memory limit and execution time before running an export/import.', 'wordpress-migration'); ?>
            </p>
        </div>

        <h3><?php esc_html_e('Support', 'wordpress-migration'); ?></h3>
        <p>
            <?php
            printf(
                /* translators: %s: Plugin URI */
                esc_html__('For issues or feature requests, please visit the %s.', 'wordpress-migration'),
                '<a href="https://github.com/jackbmccarthy/wordpress-migration">' . esc_html__('GitHub repository', 'wordpress-migration') . '</a>'
            );
            ?>
        </p>
    </div>
    <?php
}
?>
