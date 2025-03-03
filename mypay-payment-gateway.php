<?php
/**
 * Plugin Name: MyPay Payment Gateway for WooCommerce
 * Plugin URI: https://github.com/springmusk026/mypay-payment-plugin-woocommerce
 * Description: An unofficial, open-source payment gateway plugin for WooCommerce that integrates with MyPay Checkout API. Created by NepalCloud Host.
 * Version: 1.0.0
 * Author: Basanta Sapkota
 * Author URI: https://basantasapkota026.com.np
 * Text Domain: mypay-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MYPAY_PLUGIN_FILE', __FILE__);
define('MYPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MYPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MYPAY_VERSION', '1.0.0');

/**
 * Check if WooCommerce is active
 */
function mypay_woocommerce_active_check() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', 'mypay_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display admin notice if WooCommerce is not active
 */
function mypay_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('MyPay Payment Gateway requires WooCommerce to be installed and active.', 'mypay-payment-gateway'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function mypay_payment_gateway_init() {
    // Check if WooCommerce is active
    if (!mypay_woocommerce_active_check()) {
        return;
    }

    // Load plugin text domain
    load_plugin_textdomain('mypay-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Include required files
    require_once MYPAY_PLUGIN_DIR . 'includes/class-mypay-gateway.php';
    require_once MYPAY_PLUGIN_DIR . 'includes/class-mypay-api.php';
    require_once MYPAY_PLUGIN_DIR . 'includes/class-mypay-logger.php';

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'mypay_add_gateway');
    
    // Add settings link on plugin page
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'mypay_plugin_action_links');
    
    // Register scripts and styles
    add_action('wp_enqueue_scripts', 'mypay_enqueue_scripts');
    add_action('admin_enqueue_scripts', 'mypay_admin_enqueue_scripts');
    
    // Add AJAX handlers
    add_action('wp_ajax_mypay_check_transaction_status', 'mypay_ajax_check_transaction_status');
    add_action('wp_ajax_nopriv_mypay_check_transaction_status', 'mypay_ajax_check_transaction_status');
}
add_action('plugins_loaded', 'mypay_payment_gateway_init');

/**
 * Add the MyPay Gateway to WooCommerce
 */
function mypay_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_MyPay';
    return $gateways;
}

/**
 * Add settings link on plugin page
 */
function mypay_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mypay') . '">' . __('Settings', 'mypay-payment-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Enqueue frontend scripts and styles
 */
function mypay_enqueue_scripts() {
    if (is_checkout() || is_checkout_pay_page()) {
        wp_enqueue_style('mypay-checkout-style', MYPAY_PLUGIN_URL . 'assets/css/checkout.css', array(), MYPAY_VERSION);
        wp_enqueue_script('mypay-checkout-script', MYPAY_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), MYPAY_VERSION, true);
        
        wp_localize_script('mypay-checkout-script', 'mypay_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mypay-nonce')
        ));
    }
}

/**
 * Enqueue admin scripts and styles
 */
function mypay_admin_enqueue_scripts($hook) {
    $screen = get_current_screen();
    
    if ($hook == 'woocommerce_page_wc-settings' || ($screen && $screen->post_type == 'shop_order')) {
        wp_enqueue_style('mypay-admin-style', MYPAY_PLUGIN_URL . 'assets/css/admin.css', array(), MYPAY_VERSION);
        wp_enqueue_script('mypay-admin-script', MYPAY_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), MYPAY_VERSION, true);
        
        wp_localize_script('mypay-admin-script', 'mypay_admin_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mypay-admin-nonce'),
            'checking_status' => __('Checking payment status...', 'mypay-payment-gateway'),
            'status_success' => __('Payment successful', 'mypay-payment-gateway'),
            'status_failed' => __('Payment failed', 'mypay-payment-gateway'),
            'status_cancelled' => __('Payment cancelled', 'mypay-payment-gateway'),
            'status_pending' => __('Payment pending', 'mypay-payment-gateway'),
            'status_incomplete' => __('Payment incomplete', 'mypay-payment-gateway'),
            'status_unknown' => __('Unknown status', 'mypay-payment-gateway')
        ));
    }
}

/**
 * AJAX handler for checking transaction status
 */
function mypay_ajax_check_transaction_status() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mypay-admin-nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mypay-payment-gateway')));
    }
    
    // Get order ID
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(array('message' => __('Invalid order ID', 'mypay-payment-gateway')));
    }
    
    // Get order
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'mypay-payment-gateway')));
    }
    
    // Get transaction ID
    $merchant_txn_id = get_post_meta($order_id, '_mypay_merchant_transaction_id', true);
    if (!$merchant_txn_id) {
        wp_send_json_error(array('message' => __('No transaction ID found for this order', 'mypay-payment-gateway')));
    }
    
    // Get gateway instance
    $gateways = WC_Payment_Gateways::instance();
    $gateway = $gateways->payment_gateways()['mypay'];
    
    if (!$gateway) {
        wp_send_json_error(array('message' => __('Payment gateway not found', 'mypay-payment-gateway')));
    }
    
    // Check transaction status
    $status = $gateway->api->check_transaction_status($merchant_txn_id);
    
    if (!$status) {
        wp_send_json_error(array('message' => __('Failed to check transaction status', 'mypay-payment-gateway')));
    }
    
    // Return status information
    wp_send_json_success($status);
}

/**
 * Create necessary database tables on plugin activation
 */
function mypay_activate_plugin() {
    // Create log table if needed
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mypay_transaction_logs';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        merchant_transaction_id varchar(100) NOT NULL,
        gateway_transaction_id varchar(100) DEFAULT NULL,
        amount decimal(10,2) NOT NULL,
        status varchar(20) NOT NULL,
        request_data longtext DEFAULT NULL,
        response_data longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY merchant_transaction_id (merchant_transaction_id),
        KEY gateway_transaction_id (gateway_transaction_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Add capabilities
    $admin_role = get_role('administrator');
    if ($admin_role) {
        $admin_role->add_cap('manage_mypay_transactions');
    }
    
    // Create necessary directories
    $upload_dir = wp_upload_dir();
    $mypay_log_dir = $upload_dir['basedir'] . '/mypay-logs';
    
    if (!file_exists($mypay_log_dir)) {
        wp_mkdir_p($mypay_log_dir);
    }
    
    // Create .htaccess file to protect logs
    $htaccess_file = $mypay_log_dir . '/.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "# Deny access to all files
<FilesMatch \".*\">
    Order Allow,Deny
    Deny from all
</FilesMatch>";
        
        file_put_contents($htaccess_file, $htaccess_content);
    }
    
    // Create index.php file to prevent directory listing
    $index_file = $mypay_log_dir . '/index.php';
    if (!file_exists($index_file)) {
        file_put_contents($index_file, '<?php // Silence is golden');
    }
}
register_activation_hook(__FILE__, 'mypay_activate_plugin');

/**
 * Clean up on plugin deactivation
 */
function mypay_deactivate_plugin() {
    // Remove scheduled events if any
    wp_clear_scheduled_hook('mypay_daily_cleanup');
}
register_deactivation_hook(__FILE__, 'mypay_deactivate_plugin');
