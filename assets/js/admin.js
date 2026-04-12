/**
 * WordPress Migration Tool - Admin JavaScript
 *
 * @package WP_Migration
 */

(function ($) {
    'use strict';

    var WPMigrationAdmin = {
        currentZipPath: null,
        currentFileName: null,

        init: function () {
            this.bindEvents();
            this.initDropzone();
        },

        bindEvents: function () {
            // Export form.
            $('#wp-migration-export-form').on('submit', this.handleExport.bind(this));
            $('#wp-migration-download-btn').on('click', this.handleDownload.bind(this));

            // Import form.
            $('#wp-migration-import-form').on('submit', this.handleImport.bind(this));
            $('#wp-migration-file-input').on('change', this.handleFileSelect.bind(this));

            // Settings.
            $('#wp-migration-copy-key').on('click', this.copyApiKey.bind(this));
            $('#wp-migration-regenerate-key').on('click', this.regenerateApiKey.bind(this));
        },

        initDropzone: function () {
            var dropzone = $('#wp-migration-dropzone');

            if (dropzone.length === 0) return;

            // Prevent default drag events.
            $(document).on('dragover drop', function (e) {
                e.preventDefault();
                return false;
            });

            dropzone.on('dragover dragleave', function (e) {
                e.preventDefault();
                $(this).toggleClass('dragover', 'dragover' === e.type);
            });

            dropzone.on('drop', function (e) {
                e.preventDefault();
                $(this).removeClass('dragover');

                var files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    $('#wp-migration-file-input')[0].files = files;
                    WPMigrationAdmin.handleFileSelect({ target: $('#wp-migration-file-input')[0] });
                }
            });
        },

        handleExport: function (e) {
            e.preventDefault();

            var form = $(e.target);
            var btn = $('#wp-migration-export-btn');
            var progress = $('#wp-migration-export-progress');
            var result = $('#wp-migration-export-result');
            var error = $('#wp-migration-export-error');

            // Hide previous states.
            result.hide();
            error.hide();
            progress.show();
            btn.prop('disabled', true).text(WPMigration.strings.exporting);

            // Build options object.
            var options = {};
            form.find('input[type="checkbox"]').each(function () {
                options[$(this).val()] = $(this).prop('checked');
            });

            $.ajax({
                url: WPMigration.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_migration_export',
                    nonce: WPMigration.nonce,
                    ...options
                },
                success: function (response) {
                    if (response.success) {
                        WPMigrationAdmin.currentZipPath = response.data.zip_path;
                        WPMigrationAdmin.currentFileName = response.data.file_name;

                        // Show stats.
                        var statsHtml = '<div class="wp-migration-stats">';
                        statsHtml += '<p><strong>' + WPMigration.strings.complete + '</strong></p>';
                        if (response.data.stats) {
                            statsHtml += '<p>' + response.data.stats.posts + ' posts</p>';
                            statsHtml += '<p>' + response.data.stats.users + ' users</p>';
                            statsHtml += '<p>' + response.data.stats.taxonomies + ' terms</p>';
                            statsHtml += '<p>' + response.data.stats.media + ' media files</p>';
                        }
                        statsHtml += '</div>';

                        progress.hide();
                        result.find('.wp-migration-stats').html(statsHtml);
                        result.show();
                    } else {
                        WPMigrationAdmin.showError(error, response.data.message);
                    }
                },
                error: function (xhr, status, errorThrown) {
                    WPMigrationAdmin.showError(error, errorThrown || 'Unknown error');
                },
                complete: function () {
                    btn.prop('disabled', false).text('<?php esc_html_e('Create Export Package', 'wordpress-migration'); ?>');
                }
            });
        },

        handleDownload: function (e) {
            e.preventDefault();

            if (!WPMigrationAdmin.currentZipPath) return;

            // Use the download AJAX handler for security.
            var downloadUrl = WPMigration.ajaxurl.replace('admin-ajax.php', 'admin.php') +
                '?action=wp_migration_download&nonce=' + WPMigration.nonce +
                '&file=' + encodeURIComponent(WPMigrationAdmin.currentZipPath) +
                '&name=' + encodeURIComponent(WPMigrationAdmin.currentFileName);

            window.location.href = downloadUrl;
        },

        handleImport: function (e) {
            e.preventDefault();

            var form = $(e.target);
            var btn = $('#wp-migration-import-btn');
            var progress = $('#wp-migration-import-progress');
            var result = $('#wp-migration-import-result');
            var error = $('#wp-migration-import-error');
            var fileInput = $('#wp-migration-file-input')[0];

            if (!fileInput.files || fileInput.files.length === 0) {
                alert('<?php esc_html_e('Please select a file to import.', 'wordpress-migration'); ?>');
                return;
            }

            // Confirm import.
            if (!confirm(WPMigration.strings.import_warning)) {
                return;
            }

            // Hide previous states.
            result.hide();
            error.hide();
            progress.show();
            btn.prop('disabled', true).text(WPMigration.strings.importing);

            // Build form data.
            var formData = new FormData(form[0]);
            formData.append('action', 'wp_migration_import');
            formData.append('nonce', WPMigration.nonce);

            $.ajax({
                url: WPMigration.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    if (response.success) {
                        progress.hide();

                        // Show results.
                        var resultsHtml = '<div class="wp-migration-import-stats">';
                        resultsHtml += '<h3>' + WPMigration.strings.complete + '</h3>';

                        if (response.data.results) {
                            var results = response.data.results;

                            if (results.users && results.users.length > 0) {
                                resultsHtml += WPMigrationAdmin.formatStatGroup('Users', results.users);
                            }
                            if (results.taxonomies && results.taxonomies.length > 0) {
                                resultsHtml += WPMigrationAdmin.formatStatGroup('Taxonomies', results.taxonomies);
                            }
                            if (results.posts && results.posts.length > 0) {
                                resultsHtml += WPMigrationAdmin.formatStatGroup('Posts', results.posts);
                            }
                            if (results.media && results.media.length > 0) {
                                resultsHtml += WPMigrationAdmin.formatStatGroup('Media', results.media);
                            }
                        }

                        resultsHtml += '</div>';

                        result.find('.wp-migration-import-stats').html(resultsHtml);
                        result.show();
                    } else {
                        WPMigrationAdmin.showError(error, response.data.message);
                    }
                },
                error: function (xhr, status, errorThrown) {
                    WPMigrationAdmin.showError(error, errorThrown || 'Unknown error');
                },
                complete: function () {
                    btn.prop('disabled', false).text('<?php esc_html_e('Start Import', 'wordpress-migration'); ?>');
                }
            });
        },

        handleFileSelect: function (e) {
            var fileInput = e.target;
            var filenameDisplay = $('.wp-migration-filename');
            var importBtn = $('#wp-migration-import-btn');

            if (fileInput.files && fileInput.files.length > 0) {
                var file = fileInput.files[0];

                // Check file type.
                if (!file.name.endsWith('.zip')) {
                    alert('<?php esc_html_e('Please select a ZIP file.', 'wordpress-migration'); ?>');
                    fileInput.value = '';
                    filenameDisplay.text('');
                    importBtn.prop('disabled', true);
                    return;
                }

                filenameDisplay.text(file.name);
                importBtn.prop('disabled', false);
            } else {
                filenameDisplay.text('');
                importBtn.prop('disabled', true);
            }
        },

        formatStatGroup: function (title, items) {
            var html = '<div class="stat-group">';
            html += '<h4>' + title + '</h4>';
            html += '<table>';
            html += '<tr><th>Item</th><th>Status</th></tr>';

            var displayItems = items.slice(0, 10);
            displayItems.forEach(function (item) {
                var name = item.name || item.title || item.file_name || item.login || item.file || 'Item';
                var status = item.status || 'complete';
                var statusClass = 'success' === status ? '✓' : ('skipped' === status ? '↷' : '✗');
                html += '<tr><td>' + name + '</td><td>' + statusClass + ' ' + status + '</td></tr>';
            });

            if (items.length > 10) {
                html += '<tr><td colspan="2">... and ' + (items.length - 10) + ' more</td></tr>';
            }

            html += '</table>';
            html += '</div>';
            return html;
        },

        showError: function (errorEl, message) {
            errorEl.find('.error-message').text(message);
            errorEl.show();
        },

        copyApiKey: function () {
            var keyDisplay = $('#wp-migration-api-key-display');
            if (keyDisplay.length === 0) return;

            navigator.clipboard.writeText(keyDisplay.text()).then(function () {
                var btn = $('#wp-migration-copy-key');
                var originalText = btn.text();
                btn.text('<?php esc_html_e('Copied!', 'wordpress-migration'); ?>');
                setTimeout(function () {
                    btn.text(originalText);
                }, 2000);
            });
        },

        regenerateApiKey: function () {
            if (!confirm('<?php esc_html_e('Are you sure you want to regenerate the API key? Any existing integrations will stop working.', 'wordpress-migration'); ?>')) {
                return;
            }

            $.ajax({
                url: WPMigration.ajaxurl.replace('admin-ajax.php', 'admin.php'),
                type: 'POST',
                data: {
                    action: 'wp_migration_regenerate_key',
                    nonce: WPMigration.nonce
                },
                success: function (response) {
                    if (response.success && response.data.api_key) {
                        $('#wp-migration-api-key-display').text(response.data.api_key);
                        $('#wp-migration-copy-key').show();
                    }
                }
            });
        }
    };

    // Initialize on document ready.
    $(document).ready(function () {
        WPMigrationAdmin.init();
    });

})(jQuery);
