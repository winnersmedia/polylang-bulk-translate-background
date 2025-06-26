# Polylang Bulk Translate Background

**Fix Polylang bulk translation timeouts** with background processing and real-time progress tracking.

## 🚨 Problem Solved

Are you experiencing these issues with **Polylang bulk translations**?

- ✅ **Bulk translate timeouts** when translating 50+ posts
- ✅ **Server timeouts** during large translation operations  
- ✅ **Cloudflare timeouts** on bulk translation requests
- ✅ **Memory limit exceeded** errors during bulk operations
- ✅ **PHP max execution time** exceeded errors
- ✅ **White screen of death** during bulk translations
- ✅ **Failed bulk translations** with no error message
- ✅ **WordPress admin freezing** during translation operations

**This plugin solves all these problems** by moving bulk translations to the background.

## 🔥 Key Features

### ⚡ Background Processing
- **No more timeouts** - translations process in the background
- **Queue-based system** with configurable batch sizes
- **Real-time progress tracking** with AJAX updates
- **Automatic retry** for failed translations

### 🎯 Works with All Post Types
- **Dynamic post type detection** - works with any post type automatically
- **Custom post types** supported out of the box
- **WooCommerce products**, Pages, Posts, Custom Content Types
- **No configuration needed** - just install and use

### 🛠️ Multiple Processing Options
- **WordPress Cron** for standard setups
- **WP-CLI commands** for servers with disabled WP-Cron
- **Manual processing button** for immediate processing
- **System cron support** for high-performance servers

### 📊 Professional Admin Interface
- **Settings page** with configurable options
- **Queue status monitoring** with detailed statistics
- **Pending items viewer** showing what's being processed
- **Progress tracking page** with real-time updates
- **Manual cleanup tools** for queue management

## 🚀 Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/polylang-bulk-translate-background/`
3. Activate the plugin through the WordPress admin
4. Go to **Settings → Bulk Translate BG** to configure

## ⚙️ Configuration

### Basic Settings
- **Batch Size**: Number of translations to process per batch (1-50)
- **Batch Delay**: Delay between batches to prevent server overload (1-60 seconds)
- **Cleanup Days**: Automatically remove completed items after X days (1-365)

### For Servers with Disabled WP-Cron

If your server has `DISABLE_WP_CRON` set to `true`, use these **WP-CLI commands**:

```bash
# Process translation queue (add to system cron)
wp pbtb process --batch-size=20

# Check queue status
wp pbtb status

# Clean up completed items
wp pbtb cleanup --days=30
```

**System Cron Example** (process every 5 minutes):
```bash
*/5 * * * * /usr/local/bin/wp pbtb process --path=/var/www/html --allow-root --batch-size=20 >/dev/null 2>&1
```

## 🎯 How It Works

1. **Intercepts Polylang bulk actions** using WordPress hooks
2. **Queues translations** in the database instead of processing immediately
3. **Processes in background** using WordPress cron or WP-CLI
4. **Shows real-time progress** with AJAX updates
5. **Handles errors gracefully** with retry mechanisms

## 📋 Requirements

- **WordPress 5.0+**
- **PHP 7.4+**
- **Polylang or Polylang Pro** plugin activated
- **MySQL/MariaDB** database

## 🔧 WP-CLI Commands

Perfect for **high-performance servers** and **system cron** setups:

```bash
# Process pending translations
wp pbtb process

# Process with custom batch size
wp pbtb process --batch-size=50

# Process with delay between batches
wp pbtb process --delay=3

# Limit number of items to process
wp pbtb process --limit=100

# Check queue status
wp pbtb status

# Clean up completed items
wp pbtb cleanup

# Clean up items older than 7 days
wp pbtb cleanup --days=7
```

## 📈 Performance Benefits

### Before (Standard Polylang)
- ❌ Timeouts with 50+ posts
- ❌ Server memory exhaustion
- ❌ PHP execution time limits
- ❌ Failed translations with no feedback

### After (Background Processing)
- ✅ **No timeouts** - unlimited post quantities
- ✅ **Memory efficient** - processes in small batches
- ✅ **Progress tracking** - see exactly what's happening
- ✅ **Error handling** - clear error messages and retry logic

## 🔍 SEO Keywords Covered

This plugin solves these commonly searched problems:

- Polylang bulk translate timeout
- WordPress translation timeout fix
- Polylang bulk translation not working
- WordPress bulk translate memory limit
- Polylang timeout error solution
- WordPress translation queue plugin
- Background translation processing
- Polylang bulk operation timeout
- WordPress translation batch processing
- Polylang performance optimization

## ⚠️ Important Notes

- **Limited Testing**: This plugin has been tested on one WordPress installation where it worked excellently
- **Production Use**: While it solved major timeout issues successfully, use caution on production sites
- **Backup First**: Always backup your database before bulk translation operations
- **Test Environment**: Consider testing on a staging site first

## 🐛 Known Limitations

- Only tested on **one WordPress site** (worked perfectly there)
- **New plugin** - limited real-world testing
- Requires **Polylang plugin** to be active
- **Background processing** means translations aren't instant

## 📞 Support & Issues

- **GitHub Issues**: Report bugs and request features
- **WordPress.org Support**: (Coming soon)
- **Documentation**: This README and inline code comments

## 🤝 Contributing

This plugin solved a real problem and is shared to help others facing the same issues. Contributions welcome:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## 📄 License

GPL v2 or later - same as WordPress

## 🔗 Related Plugins

- **Polylang** - The multilingual plugin this extends
- **Polylang Pro** - Advanced Polylang features
- **WPML** - Alternative multilingual plugin

---

**Fix your Polylang bulk translation timeouts today!** This plugin transforms frustrating timeout errors into smooth, monitored background processing.