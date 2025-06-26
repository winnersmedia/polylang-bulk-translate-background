<?php
/**
 * Plugin Name: Polylang Bulk Translate Background
 * Plugin URI: https://github.com/your-username/polylang-bulk-translate-background
 * Description: Prevents timeouts during Polylang bulk translations by processing them in the background with real-time progress tracking.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: polylang-bulk-translate-background
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PBTB_VERSION', '1.0.0');
define('PBTB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PBTB_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Only load if Polylang is active
add_action('plugins_loaded', 'pbtb_init');
function pbtb_init() {
    // Check for Polylang
    if (!function_exists('pll_languages_list')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('Polylang Bulk Translate Background requires Polylang or Polylang Pro to be active.', 'polylang-bulk-translate-background') . 
                 '</p></div>';
        });
        return;
    }
    
    new PBTB_Bulk_Translate_Background();
}

class PBTB_Bulk_Translate_Background {
    
    private $queue_table;
    private $plugin_name = 'polylang-bulk-translate-background';
    
    public function __construct() {
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'pbtb_translation_queue';
        
        // Load text domain
        add_action('init', array($this, 'load_textdomain'));
        
        // Core functionality
        add_action('init', array($this, 'create_tables'));
        add_action('admin_init', array($this, 'init_hooks'));
        add_action('pbtb_process_translation_queue', array($this, 'process_queue_item'), 10, 3);
        add_action('wp_ajax_pbtb_get_progress', array($this, 'ajax_get_progress'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Immediate hook registration for all post types
        add_action('current_screen', array($this, 'setup_screen_hooks'));
        
        // Admin interface
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_links'));
        
        // Cleanup
        add_action('wp_scheduled_delete', array($this, 'cleanup_old_queue_items'));
        
        // WP-CLI command
        if (defined('WP_CLI') && WP_CLI) {
            add_action('init', array($this, 'register_cli_command'));
        }
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'polylang-bulk-translate-background',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Create the translation queue table
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->queue_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            post_id int(11) NOT NULL,
            target_language varchar(10) NOT NULL,
            translation_type varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            batch_id varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            error_message text NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY batch_id (batch_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update version option
        update_option('pbtb_db_version', PBTB_VERSION);
    }
    
    /**
     * Initialize admin hooks
     */
    public function init_hooks() {
        // Hook registration is now handled in setup_screen_hooks
    }
    
    /**
     * Setup hooks based on current screen - supports all post types
     */
    public function setup_screen_hooks($screen) {
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        
        // Add bulk action hook for this specific screen
        add_filter("handle_bulk_actions-{$screen->id}", array($this, 'intercept_bulk_action'), 5, 3);
    }
    
    /**
     * Intercept Polylang bulk translate actions
     */
    public function intercept_bulk_action($redirect_to, $doaction, $post_ids) {
        // Only intercept pll_translate actions
        if ($doaction !== 'pll_translate') {
            return $redirect_to;
        }
        
        // Check if we have post IDs and translation data
        if (empty($post_ids) || !isset($_REQUEST['pll-translate-lang']) || !isset($_REQUEST['translate'])) {
            return $redirect_to;
        }
        
        // Enhanced nonce verification
        if (!$this->verify_nonce()) {
            wp_die(
                esc_html__('Security check failed. Please try again.', 'polylang-bulk-translate-background'),
                esc_html__('Security Error', 'polylang-bulk-translate-background'),
                array('response' => 403)
            );
        }
        
        // Get translation parameters
        $target_languages = $_REQUEST['pll-translate-lang'];
        $translation_type = $_REQUEST['translate'];
        
        // Generate batch ID
        $batch_id = 'batch_' . time() . '_' . wp_generate_password(8, false);
        
        // Queue translations
        $queued_count = $this->queue_translations($post_ids, $target_languages, $translation_type, $batch_id);
        
        if ($queued_count > 0) {
            // Schedule background processing
            $this->schedule_batch_processing($batch_id);
            
            // Redirect to progress page
            $progress_url = add_query_arg(array(
                'page' => 'pbtb-progress',
                'batch_id' => $batch_id,
                'total' => $queued_count
            ), admin_url('admin.php'));
            
            wp_redirect($progress_url);
            exit;
        }
        
        // If nothing was queued, let Polylang handle it normally
        return $redirect_to;
    }
    
    /**
     * Enhanced nonce verification
     */
    private function verify_nonce() {
        // Try Polylang's nonce first
        if (isset($_REQUEST['_pll_translate_nonce'])) {
            if (wp_verify_nonce($_REQUEST['_pll_translate_nonce'], 'pll_translate')) {
                return true;
            }
        }
        
        // Try standard WordPress bulk action nonce
        if (isset($_REQUEST['_wpnonce'])) {
            $screen = get_current_screen();
            $nonce_action = 'bulk-' . ($screen ? $screen->post_type : 'posts');
            if (wp_verify_nonce($_REQUEST['_wpnonce'], $nonce_action)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Queue translations for background processing
     */
    private function queue_translations($post_ids, $target_languages, $translation_type, $batch_id) {
        global $wpdb;
        
        $queued_count = 0;
        
        foreach ($post_ids as $post_id) {
            foreach ($target_languages as $target_lang) {
                // Skip if translation already exists (optional check)
                if (function_exists('pll_get_post') && pll_get_post($post_id, $target_lang)) {
                    continue;
                }
                
                $result = $wpdb->insert(
                    $this->queue_table,
                    array(
                        'post_id' => intval($post_id),
                        'target_language' => sanitize_text_field($target_lang),
                        'translation_type' => sanitize_text_field($translation_type),
                        'batch_id' => $batch_id,
                        'status' => 'pending'
                    ),
                    array('%d', '%s', '%s', '%s', '%s')
                );
                
                if ($result) {
                    $queued_count++;
                }
            }
        }
        
        return $queued_count;
    }
    
    /**
     * Schedule background processing using WordPress cron
     */
    private function schedule_batch_processing($batch_id) {
        wp_schedule_single_event(time(), 'pbtb_process_translation_queue', array($batch_id, 0, 10));
    }
    
    /**
     * Process queue items in batches
     */
    public function process_queue_item($batch_id, $offset, $limit) {
        global $wpdb;
        
        // Get pending items from this batch
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table} 
             WHERE batch_id = %s AND status = 'pending' 
             ORDER BY id ASC LIMIT %d OFFSET %d",
            $batch_id, $limit, $offset
        ));
        
        if (empty($items)) {
            return; // Batch complete
        }
        
        foreach ($items as $item) {
            $this->process_single_translation($item);
        }
        
        // Schedule next batch with delay to prevent overwhelming server
        $next_offset = $offset + $limit;
        wp_schedule_single_event(time() + 3, 'pbtb_process_translation_queue', array($batch_id, $next_offset, $limit));
    }
    
    /**
     * Process a single translation item
     */
    private function process_single_translation($item) {
        global $wpdb;
        
        try {
            // Mark as processing
            $wpdb->update(
                $this->queue_table,
                array('status' => 'processing'),
                array('id' => $item->id),
                array('%s'),
                array('%d')
            );
            
            // Get the original post
            $post = get_post($item->post_id);
            if (!$post) {
                throw new Exception('Post not found: ' . $item->post_id);
            }
            
            // Use Polylang's translation functions
            $translation_id = null;
            
            switch ($item->translation_type) {
                case 'pll_copy_post':
                    $translation_id = $this->copy_post_translation($post, $item->target_language);
                    break;
                case 'pll_sync_post':
                    $translation_id = $this->sync_post_translation($post, $item->target_language);
                    break;
                default:
                    throw new Exception('Unknown translation type: ' . $item->translation_type);
            }
            
            if ($translation_id) {
                // Mark as completed
                $wpdb->update(
                    $this->queue_table,
                    array(
                        'status' => 'completed',
                        'processed_at' => current_time('mysql')
                    ),
                    array('id' => $item->id),
                    array('%s', '%s'),
                    array('%d')
                );
            } else {
                throw new Exception('Translation failed - no ID returned');
            }
            
        } catch (Exception $e) {
            // Mark as failed
            $wpdb->update(
                $this->queue_table,
                array(
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $item->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }
    }
    
    /**
     * Copy post translation
     */
    private function copy_post_translation($post, $target_language) {
        if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
            return false;
        }
        
        $translation_data = array(
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => 'draft',
            'post_type' => $post->post_type,
            'post_author' => $post->post_author,
        );
        
        $translation_id = wp_insert_post($translation_data);
        
        if ($translation_id && !is_wp_error($translation_id)) {
            // Set language for the new post
            pll_set_post_language($translation_id, $target_language);
            
            // Create translation relationship
            $translations = function_exists('pll_get_post_translations') 
                ? pll_get_post_translations($post->ID) 
                : array();
            $translations[$target_language] = $translation_id;
            pll_save_post_translations($translations);
            
            return $translation_id;
        }
        
        return false;
    }
    
    /**
     * Sync post translation
     */
    private function sync_post_translation($post, $target_language) {
        if (!function_exists('pll_get_post')) {
            return false;
        }
        
        $translation_id = pll_get_post($post->ID, $target_language);
        
        if ($translation_id) {
            $translation_data = array(
                'ID' => $translation_id,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status' => $post->post_status,
            );
            
            $result = wp_update_post($translation_data);
            return $result ? $translation_id : false;
        } else {
            return $this->copy_post_translation($post, $target_language);
        }
    }
    
    /**
     * Add admin pages
     */
    public function add_admin_pages() {
        // Progress page (accessible to users who can edit posts)
        add_submenu_page(
            null, // Hidden from menu
            __('Bulk Translation Progress', 'polylang-bulk-translate-background'),
            __('Translation Progress', 'polylang-bulk-translate-background'),
            'edit_posts',
            'pbtb-progress',
            array($this, 'progress_page')
        );
        
        // Settings page (accessible to admins)
        add_submenu_page(
            'options-general.php',
            __('Polylang Bulk Translate Settings', 'polylang-bulk-translate-background'),
            __('Bulk Translate BG', 'polylang-bulk-translate-background'),
            'manage_options',
            'pbtb-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Add plugin action links
     */
    public function add_plugin_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=pbtb-settings') . '">' . 
                        __('Settings', 'polylang-bulk-translate-background') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('pbtb_settings');
            
            update_option('pbtb_batch_size', intval($_POST['batch_size']));
            update_option('pbtb_batch_delay', intval($_POST['batch_delay']));
            update_option('pbtb_cleanup_days', intval($_POST['cleanup_days']));
            
            echo '<div class="notice notice-success"><p>' . 
                 esc_html__('Settings saved successfully.', 'polylang-bulk-translate-background') . 
                 '</p></div>';
        }
        
        $batch_size = get_option('pbtb_batch_size', 10);
        $batch_delay = get_option('pbtb_batch_delay', 3);
        $cleanup_days = get_option('pbtb_cleanup_days', 30);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('pbtb_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="batch_size"><?php esc_html_e('Batch Size', 'polylang-bulk-translate-background'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="batch_size" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50" />
                            <p class="description"><?php esc_html_e('Number of translations to process per batch (1-50).', 'polylang-bulk-translate-background'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="batch_delay"><?php esc_html_e('Batch Delay (seconds)', 'polylang-bulk-translate-background'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="batch_delay" name="batch_delay" value="<?php echo esc_attr($batch_delay); ?>" min="1" max="60" />
                            <p class="description"><?php esc_html_e('Delay between batches to prevent server overload (1-60 seconds).', 'polylang-bulk-translate-background'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cleanup_days"><?php esc_html_e('Cleanup After (days)', 'polylang-bulk-translate-background'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="cleanup_days" name="cleanup_days" value="<?php echo esc_attr($cleanup_days); ?>" min="1" max="365" />
                            <p class="description"><?php esc_html_e('Remove completed queue items after this many days (1-365).', 'polylang-bulk-translate-background'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <h2><?php esc_html_e('Queue Status', 'polylang-bulk-translate-background'); ?></h2>
            <?php $this->display_queue_stats(); ?>
            
            <h2><?php esc_html_e('Pending Items', 'polylang-bulk-translate-background'); ?></h2>
            <?php $this->display_pending_items(); ?>
            
            <h2><?php esc_html_e('Manual Processing', 'polylang-bulk-translate-background'); ?></h2>
            <p><?php esc_html_e('Process pending translations manually (useful when WP-Cron is disabled):', 'polylang-bulk-translate-background'); ?></p>
            <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=pbtb-settings&action=process'), 'pbtb_process'); ?>" 
               class="button button-primary">
                <?php esc_html_e('Process Queue Now', 'polylang-bulk-translate-background'); ?>
            </a>
            
            <h2><?php esc_html_e('Manual Cleanup', 'polylang-bulk-translate-background'); ?></h2>
            <p><?php esc_html_e('Remove all completed and failed queue items:', 'polylang-bulk-translate-background'); ?></p>
            <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=pbtb-settings&action=cleanup'), 'pbtb_cleanup'); ?>" 
               class="button button-secondary" 
               onclick="return confirm('<?php esc_attr_e('Are you sure you want to clean up all completed queue items?', 'polylang-bulk-translate-background'); ?>')">
                <?php esc_html_e('Clean Up Queue', 'polylang-bulk-translate-background'); ?>
            </a>
        </div>
        <?php
        
        // Handle manual processing action
        if (isset($_GET['action']) && $_GET['action'] === 'process') {
            check_admin_referer('pbtb_process');
            $processed = $this->manual_process_queue();
            if ($processed > 0) {
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(esc_html__('Processed %d translation items.', 'polylang-bulk-translate-background'), $processed) . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-info"><p>' . 
                     esc_html__('No pending items to process.', 'polylang-bulk-translate-background') . 
                     '</p></div>';
            }
        }
        
        // Handle cleanup action
        if (isset($_GET['action']) && $_GET['action'] === 'cleanup') {
            check_admin_referer('pbtb_cleanup');
            $this->cleanup_queue();
            echo '<div class="notice notice-success"><p>' . 
                 esc_html__('Queue cleanup completed.', 'polylang-bulk-translate-background') . 
                 '</p></div>';
        }
    }
    
    /**
     * Display queue statistics
     */
    private function display_queue_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$this->queue_table} 
             GROUP BY status",
            ARRAY_A
        );
        
        if (empty($stats)) {
            echo '<p>' . esc_html__('No queue items found.', 'polylang-bulk-translate-background') . '</p>';
            return;
        }
        
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('Status', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Count', 'polylang-bulk-translate-background') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($stats as $stat) {
            echo '<tr>';
            echo '<td>' . esc_html(ucfirst($stat['status'])) . '</td>';
            echo '<td>' . esc_html($stat['count']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Display pending items
     */
    private function display_pending_items() {
        global $wpdb;
        
        $pending_items = $wpdb->get_results(
            "SELECT q.*, p.post_title, p.post_type 
             FROM {$this->queue_table} q 
             LEFT JOIN {$wpdb->posts} p ON q.post_id = p.ID 
             WHERE q.status = 'pending' 
             ORDER BY q.created_at DESC 
             LIMIT 50"
        );
        
        if (empty($pending_items)) {
            echo '<p>' . esc_html__('No pending items found.', 'polylang-bulk-translate-background') . '</p>';
            return;
        }
        
        echo '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Post Title', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Post Type', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Target Language', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Translation Type', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Batch ID', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Created', 'polylang-bulk-translate-background') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($pending_items as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item->post_title ?: sprintf(__('Post #%d', 'polylang-bulk-translate-background'), $item->post_id)) . '</td>';
            echo '<td>' . esc_html($item->post_type ?: 'N/A') . '</td>';
            echo '<td>' . esc_html($item->target_language) . '</td>';
            echo '<td>' . esc_html($item->translation_type) . '</td>';
            echo '<td><code>' . esc_html($item->batch_id) . '</code></td>';
            echo '<td>' . esc_html(mysql2date('Y-m-d H:i:s', $item->created_at)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        
        if (count($pending_items) >= 50) {
            echo '<p><em>' . esc_html__('Showing latest 50 pending items. Total pending items may be higher.', 'polylang-bulk-translate-background') . '</em></p>';
        }
    }
    
    /**
     * Progress page HTML
     */
    public function progress_page() {
        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field($_GET['batch_id']) : '';
        $total = isset($_GET['total']) ? intval($_GET['total']) : 0;
        
        if (empty($batch_id)) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Translation Progress', 'polylang-bulk-translate-background') . '</h1>';
            echo '<p>' . esc_html__('No batch ID provided.', 'polylang-bulk-translate-background') . '</p>';
            echo '</div>';
            return;
        }
        
        // Get current stats
        global $wpdb;
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing
             FROM {$this->queue_table} 
             WHERE batch_id = %s",
            $batch_id
        ), ARRAY_A);
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Translation Progress', 'polylang-bulk-translate-background'); ?></h1>
            
            <div id="pbtb-progress-container" style="max-width: 800px;">
                <div class="card" style="padding: 20px; margin: 20px 0;">
                    <h2><?php esc_html_e('Translation Status', 'polylang-bulk-translate-background'); ?></h2>
                    
                    <div class="progress-info" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0;">
                        <div>
                            <strong><?php esc_html_e('Total Items:', 'polylang-bulk-translate-background'); ?></strong>
                            <span id="pbtb-total" style="display: block; font-size: 24px; color: #0073aa;"><?php echo esc_html($stats['total'] ?? $total); ?></span>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Completed:', 'polylang-bulk-translate-background'); ?></strong>
                            <span id="pbtb-completed" style="display: block; font-size: 24px; color: #00a32a;"><?php echo esc_html($stats['completed'] ?? 0); ?></span>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Failed:', 'polylang-bulk-translate-background'); ?></strong>
                            <span id="pbtb-failed" style="display: block; font-size: 24px; color: #d63638;"><?php echo esc_html($stats['failed'] ?? 0); ?></span>
                        </div>
                        <div>
                            <strong><?php esc_html_e('Remaining:', 'polylang-bulk-translate-background'); ?></strong>
                            <span id="pbtb-remaining" style="display: block; font-size: 24px; color: #f56e28;"><?php echo esc_html($stats['pending'] ?? $total); ?></span>
                        </div>
                    </div>
                    
                    <div class="progress-bar-container" style="width: 100%; background-color: #f0f0f0; border-radius: 8px; margin: 20px 0; overflow: hidden;">
                        <div id="pbtb-progress-bar" style="width: <?php echo esc_attr(($stats && $stats['total'] > 0) ? round(($stats['completed'] + $stats['failed']) / $stats['total'] * 100) : 0); ?>%; height: 30px; background: linear-gradient(90deg, #00a32a 0%, #0073aa 100%); transition: width 0.5s ease;"></div>
                    </div>
                    
                    <div id="pbtb-status" style="font-size: 16px; font-weight: 500; text-align: center; padding: 10px;">
                        <?php 
                        if ($stats && $stats['pending'] > 0) {
                            esc_html_e('Processing translations...', 'polylang-bulk-translate-background');
                        } else {
                            esc_html_e('Processing complete!', 'polylang-bulk-translate-background');
                        }
                        ?>
                    </div>
                </div>
                
                <div style="text-align: center; margin: 20px 0;">
                    <button id="pbtb-refresh" class="button button-secondary" style="margin-right: 10px;">
                        <?php esc_html_e('Refresh Progress', 'polylang-bulk-translate-background'); ?>
                    </button>
                    <a href="<?php echo admin_url('edit.php'); ?>" class="button button-primary">
                        <?php esc_html_e('Back to Posts', 'polylang-bulk-translate-background'); ?>
                    </a>
                </div>
                
                <?php $this->display_queue_items($batch_id); ?>
            </div>
        </div>
        
        <script>
        let batchId = '<?php echo esc_js($batch_id); ?>';
        let progressTimer;
        
        function updateProgress() {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'pbtb_get_progress',
                    batch_id: batchId,
                    _ajax_nonce: '<?php echo wp_create_nonce('pbtb_progress'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        let data = response.data;
                        
                        jQuery('#pbtb-total').text(data.total);
                        jQuery('#pbtb-completed').text(data.completed);
                        jQuery('#pbtb-failed').text(data.failed);
                        jQuery('#pbtb-remaining').text(data.pending);
                        
                        let percentage = data.total > 0 ? Math.round((parseInt(data.completed) + parseInt(data.failed)) / data.total * 100) : 0;
                        jQuery('#pbtb-progress-bar').css('width', percentage + '%');
                        
                        if (data.pending > 0) {
                            jQuery('#pbtb-status').text('<?php esc_html_e('Processing translations...', 'polylang-bulk-translate-background'); ?> ' + percentage + '% <?php esc_html_e('complete', 'polylang-bulk-translate-background'); ?>');
                        } else {
                            jQuery('#pbtb-status').text('<?php esc_html_e('Processing complete!', 'polylang-bulk-translate-background'); ?> ' + data.completed + ' <?php esc_html_e('successful', 'polylang-bulk-translate-background'); ?>, ' + data.failed + ' <?php esc_html_e('failed', 'polylang-bulk-translate-background'); ?>.');
                            clearInterval(progressTimer);
                        }
                    }
                },
                error: function() {
                    jQuery('#pbtb-status').text('<?php esc_html_e('Error checking progress. Please refresh manually.', 'polylang-bulk-translate-background'); ?>');
                }
            });
        }
        
        jQuery(document).ready(function() {
            updateProgress();
            progressTimer = setInterval(updateProgress, 3000);
            
            jQuery('#pbtb-refresh').click(function() {
                updateProgress();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Display queue items for a batch
     */
    private function display_queue_items($batch_id) {
        global $wpdb;
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, p.post_title, p.post_type 
             FROM {$this->queue_table} q 
             LEFT JOIN {$wpdb->posts} p ON q.post_id = p.ID 
             WHERE q.batch_id = %s 
             ORDER BY q.status DESC, q.id ASC",
            $batch_id
        ));
        
        if (empty($items)) {
            return;
        }
        
        echo '<div class="card" style="padding: 20px; margin: 20px 0;">';
        echo '<h2>' . esc_html__('Translation Queue Items', 'polylang-bulk-translate-background') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Post Title', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Post Type', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Target Language', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Status', 'polylang-bulk-translate-background') . '</th>';
        echo '<th>' . esc_html__('Error', 'polylang-bulk-translate-background') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($items as $item) {
            $status_class = '';
            switch ($item->status) {
                case 'completed': $status_class = 'style="color: #00a32a; font-weight: bold;"'; break;
                case 'failed': $status_class = 'style="color: #d63638; font-weight: bold;"'; break;
                case 'processing': $status_class = 'style="color: #0073aa; font-weight: bold;"'; break;
                default: $status_class = 'style="color: #f56e28;"'; break;
            }
            
            echo '<tr>';
            echo '<td>' . esc_html($item->post_title ?: sprintf(__('Post #%d', 'polylang-bulk-translate-background'), $item->post_id)) . '</td>';
            echo '<td>' . esc_html($item->post_type) . '</td>';
            echo '<td>' . esc_html($item->target_language) . '</td>';
            echo '<td ' . $status_class . '>' . esc_html(ucfirst($item->status)) . '</td>';
            echo '<td>' . esc_html($item->error_message ?: '-') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
    
    /**
     * AJAX handler for progress updates
     */
    public function ajax_get_progress() {
        check_ajax_referer('pbtb_progress');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Permission denied');
        }
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error('No batch ID provided');
        }
        
        global $wpdb;
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing
             FROM {$this->queue_table} 
             WHERE batch_id = %s",
            $batch_id
        ), ARRAY_A);
        
        wp_send_json_success($stats);
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'pbtb-') !== false) {
            wp_enqueue_script('jquery');
        }
    }
    
    /**
     * Cleanup old queue items
     */
    public function cleanup_old_queue_items() {
        $cleanup_days = get_option('pbtb_cleanup_days', 30);
        $this->cleanup_queue($cleanup_days);
    }
    
    /**
     * Manual processing of queue items
     */
    public function manual_process_queue() {
        global $wpdb;
        
        $batch_size = get_option('pbtb_batch_size', 10);
        $processed = 0;
        
        // Get pending items from all batches
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table} 
             WHERE status = 'pending' 
             ORDER BY created_at ASC 
             LIMIT %d",
            $batch_size
        ));
        
        if (empty($items)) {
            return 0;
        }
        
        foreach ($items as $item) {
            $this->process_single_translation($item);
            $processed++;
        }
        
        return $processed;
    }
    
    /**
     * Manual cleanup of queue items
     */
    private function cleanup_queue($days = null) {
        global $wpdb;
        
        if ($days === null) {
            // Clean all completed and failed items
            $wpdb->query(
                "DELETE FROM {$this->queue_table} 
                 WHERE status IN ('completed', 'failed')"
            );
        } else {
            // Clean items older than specified days
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->queue_table} 
                 WHERE status IN ('completed', 'failed') 
                 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        }
    }
    
    /**
     * Register WP-CLI command
     */
    public function register_cli_command() {
        WP_CLI::add_command('pbtb process', array($this, 'cli_process_queue'));
        WP_CLI::add_command('pbtb status', array($this, 'cli_queue_status'));
        WP_CLI::add_command('pbtb cleanup', array($this, 'cli_cleanup_queue'));
    }
    
    /**
     * WP-CLI command to process queue
     */
    public function cli_process_queue($args, $assoc_args) {
        $batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : get_option('pbtb_batch_size', 10);
        $processed = 0;
        
        global $wpdb;
        
        do {
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->queue_table} 
                 WHERE status = 'pending' 
                 ORDER BY created_at ASC 
                 LIMIT %d",
                $batch_size
            ));
            
            if (empty($items)) {
                break;
            }
            
            foreach ($items as $item) {
                $this->process_single_translation($item);
                $processed++;
                
                if ($processed % 10 === 0) {
                    WP_CLI::log("Processed {$processed} items...");
                }
            }
            
            // Small delay to prevent overwhelming the server
            if (isset($assoc_args['delay'])) {
                sleep(intval($assoc_args['delay']));
            }
            
        } while (!empty($items) && (!isset($assoc_args['limit']) || $processed < intval($assoc_args['limit'])));
        
        WP_CLI::success("Processed {$processed} translation items.");
    }
    
    /**
     * WP-CLI command to show queue status
     */
    public function cli_queue_status() {
        global $wpdb;
        
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$this->queue_table} 
             GROUP BY status",
            ARRAY_A
        );
        
        if (empty($stats)) {
            WP_CLI::log('No queue items found.');
            return;
        }
        
        WP_CLI::log('Translation Queue Status:');
        WP_CLI::log('------------------------');
        
        foreach ($stats as $stat) {
            WP_CLI::log(sprintf('%-12s: %d', ucfirst($stat['status']), $stat['count']));
        }
    }
    
    /**
     * WP-CLI command to cleanup queue
     */
    public function cli_cleanup_queue($args, $assoc_args) {
        $days = isset($assoc_args['days']) ? intval($assoc_args['days']) : null;
        
        global $wpdb;
        
        if ($days === null) {
            $result = $wpdb->query(
                "DELETE FROM {$this->queue_table} 
                 WHERE status IN ('completed', 'failed')"
            );
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->queue_table} 
                 WHERE status IN ('completed', 'failed') 
                 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
        }
        
        WP_CLI::success("Cleaned up {$result} queue items.");
    }
}
?>