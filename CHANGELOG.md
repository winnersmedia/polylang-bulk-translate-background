# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2025-06-26

### Added
- Initial release of Polylang Bulk Translate Background plugin
- Background processing for Polylang bulk translations to prevent timeouts
- Real-time progress tracking with AJAX updates
- Queue management system with configurable batch processing
- Support for all post types through dynamic screen detection
- Professional admin interface with settings page
- WP-CLI commands for server environments with disabled WP-Cron
- Manual processing button for immediate queue processing
- Comprehensive error handling and retry mechanisms
- Automatic cleanup of completed queue items
- Internationalization support (i18n ready)
- Security features with proper nonce verification

### Features
- **Queue System**: Database-backed translation queue with status tracking
- **Background Processing**: WordPress cron and WP-CLI command support
- **Progress Tracking**: Real-time AJAX updates showing translation progress
- **Error Handling**: Detailed error logging and graceful failure recovery
- **Multi-Post Type**: Automatic support for any WordPress post type
- **Batch Processing**: Configurable batch sizes (1-50 items)
- **Cleanup Tools**: Manual and automatic cleanup of completed items
- **Admin Interface**: Professional settings page under Settings → Bulk Translate BG

### WP-CLI Commands
- `wp pbtb process` - Process pending translations
- `wp pbtb status` - Show queue status and statistics  
- `wp pbtb cleanup` - Clean up completed queue items

### Solved Issues
- Polylang bulk translation timeouts with 50+ posts
- Server memory exhaustion during large translation operations
- PHP max execution time exceeded errors
- Cloudflare timeouts on bulk translation requests
- WordPress admin freezing during bulk operations
- Failed bulk translations with no error feedback

### Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- Polylang or Polylang Pro plugin active
- MySQL/MariaDB database

### Notes
- Tested successfully on one WordPress installation
- Solved major timeout issues in production environment
- Limited testing - use with caution on production sites
- Backup recommended before bulk translation operations