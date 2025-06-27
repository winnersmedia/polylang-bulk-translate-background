# Must-Use Plugin: Queue Watchdog

This directory contains a must-use plugin that ensures the translation queue never gets stuck.

## Installation

Copy `pbtb-queue-watchdog.php` to your WordPress `wp-content/mu-plugins/` directory.

```bash
cp mu-plugin/pbtb-queue-watchdog.php /path/to/wordpress/wp-content/mu-plugins/
```

## What It Does

- Monitors the translation queue for stalled items
- Automatically restarts processing when needed
- Resets items stuck in "processing" status
- Works with your existing WordPress cron setup

## Requirements

- No additional cron jobs needed
- Works with standard wp-cron.php triggers
- Compatible with server-side cron setups

The watchdog runs automatically whenever WordPress cron is triggered, ensuring your bulk translations always complete successfully.