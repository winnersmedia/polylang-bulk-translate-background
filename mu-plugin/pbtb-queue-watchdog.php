<?php
/**
 * Polylang Bulk Translate Background - Queue Watchdog
 * 
 * This must-use plugin ensures the translation queue is always processed
 * whenever WordPress cron runs, preventing stalled processing.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Hook into init to ensure the bulk translate plugin is loaded
add_action('init', function() {
    // Only proceed if the bulk translate plugin is active
    if (!class_exists('Polylang_Bulk_Translate_Background')) {
        return;
    }
    
    // Schedule our watchdog if not already scheduled
    if (!wp_next_scheduled('pbtb_queue_watchdog')) {
        wp_schedule_event(time(), 'pbtb_watchdog_interval', 'pbtb_queue_watchdog');
    }
    
    // Also run watchdog check immediately on every page load if items are pending
    global $wpdb;
    $queue_table = $wpdb->prefix . 'pbtb_translation_queue';
    if ($wpdb->get_var("SHOW TABLES LIKE '$queue_table'") == $queue_table) {
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'");
        if ($pending > 0) {
            do_action('pbtb_queue_watchdog');
        }
    }
});

// Register custom cron interval (every 5 minutes)
add_filter('cron_schedules', function($schedules) {
    $schedules['pbtb_watchdog_interval'] = array(
        'interval' => 300, // 5 minutes
        'display'  => __('Every 5 Minutes (PBTB Watchdog)')
    );
    return $schedules;
});

// Watchdog function to check and process pending items
add_action('pbtb_queue_watchdog', function() {
    // Check if plugin is active
    if (!class_exists('Polylang_Bulk_Translate_Background')) {
        return;
    }
    
    global $wpdb;
    
    // Get the queue table name
    $queue_table = $wpdb->prefix . 'pbtb_translation_queue';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$queue_table'") != $queue_table) {
        return;
    }
    
    // Check for pending items that aren't being processed
    $pending_count = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$queue_table} 
        WHERE status = 'pending'
    ");
    
    if ($pending_count > 0) {
        // Log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PBTB Watchdog: Found ' . $pending_count . ' pending items, triggering processing...');
        }
        
        // Get distinct batch IDs that need processing
        $stalled_batches = $wpdb->get_col("
            SELECT DISTINCT batch_id 
            FROM {$queue_table} 
            WHERE status = 'pending' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY created_at ASC 
            LIMIT 5
        ");
        
        // Schedule processing for each stalled batch
        foreach ($stalled_batches as $batch_id) {
            // Check if this batch is already scheduled
            if (!wp_next_scheduled('pbtb_process_translation_queue', array($batch_id, 0, 50))) {
                wp_schedule_single_event(time() + 10, 'pbtb_process_translation_queue', array($batch_id, 0, 50));
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('PBTB Watchdog: Scheduled processing for batch ' . $batch_id);
                }
            }
        }
    }
    
    // Also check for items stuck in 'processing' status
    $stuck_processing = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$queue_table} 
        WHERE status = 'processing' 
        AND processed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    
    if ($stuck_processing > 0) {
        // Reset stuck items back to pending
        $wpdb->query("
            UPDATE {$queue_table} 
            SET status = 'pending', 
                processed_at = NULL,
                error_message = CONCAT(IFNULL(error_message, ''), ' [Reset by watchdog]')
            WHERE status = 'processing' 
            AND processed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ");
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PBTB Watchdog: Reset ' . $stuck_processing . ' stuck processing items');
        }
    }
});

// Alternative approach: Hook directly into wp_cron execution
add_action('wp_loaded', function() {
    // Only run during cron requests
    if (!defined('DOING_CRON') || !DOING_CRON) {
        return;
    }
    
    // Check if plugin is active
    if (!class_exists('Polylang_Bulk_Translate_Background')) {
        return;
    }
    
    // Run our watchdog check
    do_action('pbtb_queue_watchdog');
});

// Hook into every cron execution to ensure pending items are processed
add_action('wp_cron', function() {
    // Check if plugin is active
    if (!class_exists('Polylang_Bulk_Translate_Background')) {
        return;
    }
    
    global $wpdb;
    $queue_table = $wpdb->prefix . 'pbtb_translation_queue';
    
    // Direct check and process
    if ($wpdb->get_var("SHOW TABLES LIKE '$queue_table'") == $queue_table) {
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'");
        if ($pending > 0) {
            // Force immediate processing
            do_action('pbtb_queue_watchdog');
            
            // Also try to directly trigger the processing
            if (function_exists('wp_pbtb_process_queue')) {
                wp_pbtb_process_queue();
            }
        }
    }
});

// Clean up on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('pbtb_queue_watchdog');
});