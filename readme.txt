=== WordPress Migration Tool ===
Contributors: jackbmccarthy
Tags: migration, export, import, backup, wordpress
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export and import your entire WordPress site to another WordPress installation.

== Description ==

WordPress Migration Tool allows you to export and import all of your WordPress content including posts, pages, custom post types, users, media, taxonomy terms, settings, widgets, and menus.

**Features:**

* Export all post types (posts, pages, CPTs)
* Export all taxonomy terms (categories, tags, custom taxonomies)
* Export all users (without passwords for security)
* Export all media library files as ZIP
* Export site settings, widgets, and navigation menus
* Export active plugin list and plugin settings
* Import with merge or replace options
* Duplicate detection prevents creating multiple copies
* Remote migration via REST API
* User role mapping on import

**Remote Migration:**

Generate an API key in Settings to enable push/pull migrations between WordPress sites.

== Installation ==

1. Upload the `wordpress-migration` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > Migration Tool to start

== Frequently Asked Questions ==

= Does this export passwords? =

No, for security reasons, user passwords are never exported. After importing, users will need to use the password reset feature.

= Will this overwrite my existing content? =

By default, import merges content with existing content. Duplicate detection prevents creating multiple copies of the same post or media. Choose "Replace" mode if you want to overwrite.

= What about my theme and plugins? =

Theme settings (theme mods) are exported but only applied if using the same theme. Active plugins and their settings are exported - plugin files must be reinstalled on the target site but settings will be imported automatically.

= How do I do a remote migration? =

Go to Settings to generate an API key, then use the REST API endpoints to push to or pull from a remote WordPress site.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release
