# WordPress Migration Tool

Export and import your entire WordPress site to another WordPress installation.

## Quick Install

### Zip for Upload via WordPress Admin

```bash
cd wordpress-migration
zip -r wordpress-migration.zip .
```

Then upload via: Plugins > Add New > Upload Plugin > Install Now > Activate

### Alternative: FTP/SSH Manual Install

```bash
# On your local machine, create zip
zip -r wordpress-migration.zip wordpress-migration/

# On your server via SSH
unzip wordpress-migration.zip -d /wp-content/plugins/
```

### Alternative: Git Clone (on server)

```bash
cd /wp-content/plugins/
git clone https://github.com/jackbmccarthy/wordpress-migration.git
```

## Features

- Export all post types (posts, pages, CPTs)
- Export all taxonomy terms (categories, tags, custom taxonomies)
- Export all users (without passwords)
- Export all media library files as ZIP
- Export site settings, widgets, and navigation menus
- Export active plugin list and plugin settings
- Import with merge or replace options
- Duplicate detection prevents creating multiple copies
- Remote migration via REST API
- User role mapping on import

## Usage

1. Activate the plugin
2. Go to **Tools > Migration Tool**
3. Select what to export
4. Click **Download Export**
5. On target site, upload the export file via **Import** tab

## License

GPL v2