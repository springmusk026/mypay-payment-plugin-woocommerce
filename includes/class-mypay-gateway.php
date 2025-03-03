<?php
/**
 * MyPay Payment Gateway Class
 *
 * @package MyPay Payment Gateway
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Gateway_MyPay Class
 */
class WC_Gateway_MyPay extends WC_Payment_Gateway {

    /**
     * API instance
     *
     * @var WC_MyPay_API
     */
    public $api;

    /**
     * Logger instance
     *
     * @var WC_MyPay_Logger
     */
    public $logger;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Setup general properties
        $this->id                 = 'mypay';
        $this->icon               = apply_filters('woocommerce_mypay_icon', MYPAY_PLUGIN_URL . 'assets/images/mypay-logo.png');
        $this->has_fields         = false;
        $this->method_title       = __('MyPay', 'mypay-payment-gateway');
        $this->method_description = __('Accept payments via MyPay Checkout API.', 'mypay-payment-gateway');
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title              = $this->get_option('title');
        $this->description        = $this->get_option('description');
        $this->api_key            = $this->get_option('api_key');
        $this->merchant_id        = $this->get_option('merchant_id');
        $this->username           = $this->get_option('username');
        $this->password           = $this->get_option('password');
        $this->test_mode          = 'yes' === $this->get_option('test_mode');
        $this->debug              = 'yes' === $this->get_option('debug');
        $this->order_status       = $this->get_option('order_status', 'wc-processing');

        // Initialize API and Logger
        $this->api = new WC_MyPay_API($this);
        $this->logger = new WC_MyPay_Logger($this->debug);

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_gateway_mypay', array($this, 'handle_webhook'));
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 3);
        
        // Add custom meta box to order page
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'mypay-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable MyPay Payment', 'mypay-payment-gateway'),
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'mypay-payment-gateway'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'mypay-payment-gateway'),
                'default'     => __('MyPay Payment', 'mypay-payment-gateway'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'mypay-payment-gateway'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'mypay-payment-gateway'),
                'default'     => __('Pay securely using MyPay Checkout API.', 'mypay-payment-gateway'),
                'desc_tip'    => true,
            ),
            'api_settings' => array(
                'title'       => __('API Settings', 'mypay-payment-gateway'),
                'type'        => 'title',
                'description' => __('Enter your MyPay API credentials below.', 'mypay-payment-gateway'),
            ),
            'test_mode' => array(
                'title'       => __('Test Mode', 'mypay-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable Test Mode', 'mypay-payment-gateway'),
                'default'     => 'yes',
                'description' => __('Place the payment gateway in test mode using test API credentials.', 'mypay-payment-gateway'),
            ),
            'api_key' => array(
                'title'       => __('API Key', 'mypay-payment-gateway'),
                'type'        => 'password',
                'description' => __('Enter your MyPay API Key.', 'mypay-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'merchant_id' => array(
                'title'       => __('Merchant ID', 'mypay-payment-gateway'),
                'type'        => 'text',
                'description' => __('Enter your MyPay Merchant ID.', 'mypay-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'username' => array(
                'title'       => __('API Username', 'mypay-payment-gateway'),
                'type'        => 'text',
                'description' => __('Enter your MyPay API Username.', 'mypay-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'password' => array(
                'title'       => __('API Password', 'mypay-payment-gateway'),
                'type'        => 'password',
                'description' => __('Enter your MyPay API Password.', 'mypay-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'advanced_settings' => array(
                'title'       => __('Advanced Settings', 'mypay-payment-gateway'),
                'type'        => 'title',
                'description' => '',
            ),
            'order_status' => array(
                'title'       => __('Order Status After Payment', 'mypay-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose the order status after successful payment.', 'mypay-payment-gateway'),
                'default'     => 'wc-processing',
                'options'     => wc_get_order_statuses(),
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'mypay-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'mypay-payment-gateway'),
                'default'     => 'no',
                'description' => __('Log MyPay API interactions inside the WooCommerce logs directory.', 'mypay-payment-gateway'),
            ),
        );
    }

    /**
     * Add meta boxes to the order edit screen
     */
    public function add_meta_boxes() {
        global $post;
        
        if (!$post) {
            return;
        }
        
        $order = wc_get_order($post->ID);
        
        if (!$order) {
            return;
        }
        
        // Only add meta box if this order uses the MyPay payment method
        if ($order->get_payment_method() === $this->id) {
            add_meta_box(
                'mypay-payment-info',
                __('MyPay Payment Information', 'mypay-payment-gateway'),
                array($this, 'output_meta_box'),
                'shop_order',
                'side',
                'high'
            );
        }
    }

    /**
     * Output the meta box
     */
    public function output_meta_box($post) {
        $order = wc_get_order($post->ID);
        
        $merchant_txn_id = $order->get_meta('_mypay_merchant_transaction_id', true);
        $gateway_txn_id = $order->get_meta('_mypay_gateway_transaction_id', true);
        $payment_status = $order->get_meta('_mypay_payment_status', true);
        $payment_date = $order->get_meta('_mypay_payment_date', true);
        
        echo '<div class="mypay-payment-info">';
        
        if ($merchant_txn_id) {
            echo '<p><strong>' . __('Merchant Transaction ID:', 'mypay-payment-gateway') . '</strong><br>' . esc_html($merchant_txn_id) . '</p>';
        }
        
        if ($gateway_txn_id) {
            echo '<p><strong>' . __('Gateway Transaction ID:', 'mypay-payment-gateway') . '</strong><br>' . esc_html($gateway_txn_id) . '</p>';
        }
        
        if ($payment_status) {
            $status_label = $this->get_status_label($payment_status);
            $status_class = $this->get_status_class($payment_status);
            
            echo '<p><strong>' . __('Payment Status:', 'mypay-payment-gateway') . '</strong><br>';
            echo '<span class="mypay-status mypay-status-' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span></p>';
        }
        
        if ($payment_date) {
            echo '<p><strong>' . __('Payment Date:', 'mypay-payment-gateway') . '</strong><br>' . esc_html($payment_date) . '</p>';
        }
        
        echo '<p><a href="#" class="button check-mypay-status" data-order-id="' . esc_attr($post->ID) . '">' . __('Check Payment Status', 'mypay-payment-gateway') . '</a></p>';
        echo '<div class="mypay-status-result"></div>';
        
        echo '</div>';
    }

    /**
     * Get status label
     */
    private function get_status_label($status) {
        $statuses = array(
            '1' => __('Success', 'mypay-payment-gateway'),
            '2' => __('Failed', 'mypay-payment-gateway'),
            '3' => __('Cancelled', 'mypay-payment-gateway'),
            '4' => __('Pending', 'mypay-payment-gateway'),
            '5' => __('Incomplete', 'mypay-payment-gateway'),
        );
        
        return isset($statuses[$status]) ? $statuses[$status] : __('Unknown', 'mypay-payment-gateway');
    }

    /**
     * Get status CSS class
     */
    private function get_status_class($status) {
        $classes = array(
            '1' => 'success',
            '2' => 'failed',
            '3' => 'cancelled',
            '4' => 'pending',
            '5' => 'incomplete',
        );
        
        return isset($classes[$status]) ? $classes[$status] : 'unknown';
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        $this->logger->info('Processing payment for order #' . $order_id);
        
        try {
            // Generate order in MyPay
            $response = $this->api->generate_order($order);
            
            if (!$response || !isset($response['status']) || $response['status'] !== true) {
                $error_message = isset($response['Message']) ? $response['Message'] : __('Unknown error occurred while processing payment', 'mypay-payment-gateway');
                throw new Exception($error_message);
            }
            
            // Save transaction data
            $this->save_transaction_data($order, $response);
            
            // Mark as pending payment
            $order->update_status('pending', __('Awaiting MyPay payment', 'mypay-payment-gateway'));
            
            // Reduce stock levels
            wc_reduce_stock_levels($order_id);
            
            // Remove cart
            WC()->cart->empty_cart();
            
            $this->logger->info('Payment process successful for order #' . $order_id . '. Redirecting to: ' . $response['RedirectURL']);
            
            // Return thankyou redirect
            return array(
                'result'   => 'success',
                'redirect' => $response['RedirectURL']
            );
            
        } catch (Exception $e) {
            $this->logger->error('Payment processing failed for order #' . $order_id . ': ' . $e->getMessage());
            
            wc_add_notice(__('Payment error: ', 'mypay-payment-gateway') . $e->getMessage(), 'error');
            
            return array(
                'result'   => 'fail',
                'redirect' => ''
            );
        }
    }

    /**
     * Save transaction data to order
     */
    private function save_transaction_data($order, $response) {
        $order_id = $order->get_id();
        
        // Save merchant transaction ID
        if (isset($response['MerchantTransactionId'])) {
            $order->update_meta_data('_mypay_merchant_transaction_id', $response['MerchantTransactionId']);
			$order->save();
        }
        
        // Save transaction data to database
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mypay_transaction_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'merchant_transaction_id' => $response['MerchantTransactionId'],
                'amount' => $order->get_total(),
                'status' => '5', // Incomplete
                'request_data' => json_encode($this->api->last_request),
                'response_data' => json_encode($response),
                'created_at' => current_time('mysql'),
            ),
            array(
                '%d',
                '%s',
                '%f',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );
    }

    /**
     * Handle webhook from MyPay
     */
    public function handle_webhook() {
        $this->logger->info('Webhook received: ' . json_encode($_GET));
        
        // Get query parameters
        $gateway_txn_id = isset($_GET['GatewayTransactionId']) ? sanitize_text_field($_GET['GatewayTransactionId']) : '';
        $merchant_txn_id = isset($_GET['MerchantTransactionId']) ? sanitize_text_field($_GET['MerchantTransactionId']) : '';
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $txn_id = isset($_GET['TrnxId']) ? sanitize_text_field($_GET['TrnxId']) : '';
        
        // Validate required parameters
        if (empty($merchant_txn_id) && empty($gateway_txn_id)) {
            $this->logger->error('Webhook missing transaction IDs');
            wp_die(__('Invalid request', 'mypay-payment-gateway'), '', array('response' => 400));
        }
        
        // Find order by transaction ID if order_id not provided
        if (!$order_id && !empty($merchant_txn_id)) {
            $order_id = $this->get_order_id_by_transaction_id($merchant_txn_id);
        }
        
        if (!$order_id) {
            $this->logger->error('Could not find order for transaction: ' . $merchant_txn_id);
            wp_die(__('Order not found', 'mypay-payment-gateway'), '', array('response' => 404));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->logger->error('Invalid order ID: ' . $order_id);
            wp_die(__('Invalid order', 'mypay-payment-gateway'), '', array('response' => 404));
        }
        
        // Save gateway transaction ID if available
        if (!empty($gateway_txn_id)) {
            $order->update_meta_data('_mypay_gateway_transaction_id', $gateway_txn_id);
			$order->save();
        }
        
        // Check transaction status
        $status_response = $this->api->check_transaction_status($merchant_txn_id);
        
        if (!$status_response) {
            $this->logger->error('Failed to check transaction status for order #' . $order_id);
            wp_redirect($this->get_return_url($order));
            exit;
        }
        
        $this->logger->info('Transaction status for order #' . $order_id . ': ' . json_encode($status_response));
        
        // Process based on transaction status
        if (isset($status_response['Status'])) {
            $status_code = (int) $status_response['Status'];
            $remarks = isset($status_response['Remarks']) ? $status_response['Remarks'] : '';
            
            // Save status to order
            $order->update_meta_data('_mypay_payment_status', $status_code);
            $order->update_meta_data('_mypay_payment_date', current_time('mysql'));
			$order->save();
            
            // Update transaction log
            $this->update_transaction_log($order_id, $merchant_txn_id, $gateway_txn_id, $status_code, $status_response);
            
            switch ($status_code) {
                case 1: // Success
                    $this->process_successful_payment($order, $merchant_txn_id, $remarks);
                    break;
                    
                case 2: // Failed
                    $order->update_status('failed', sprintf(__('MyPay payment failed: %s', 'mypay-payment-gateway'), $remarks));
                    $this->logger->error('Payment failed for order #' . $order_id . ': ' . $remarks);
                    break;
                    
                case 3: // Cancelled
                    $order->update_status('cancelled', sprintf(__('MyPay payment cancelled: %s', 'mypay-payment-gateway'), $remarks));
                    $this->logger->info('Payment cancelled for order #' . $order_id . ': ' . $remarks);
                    break;
                    
                case 4: // Pending
                    $order->update_status('on-hold', sprintf(__('MyPay payment pending: %s', 'mypay-payment-gateway'), $remarks));
                    $this->logger->info('Payment pending for order #' . $order_id . ': ' . $remarks);
                    break;
                    
                case 5: // Incomplete
                    $order->update_status('pending', sprintf(__('MyPay payment incomplete: %s', 'mypay-payment-gateway'), $remarks));
                    $this->logger->info('Payment incomplete for order #' . $order_id . ': ' . $remarks);
                    break;
                    
                default:
                    $order->update_status('on-hold', sprintf(__('MyPay payment status unknown (%s): %s', 'mypay-payment-gateway'), $status_code, $remarks));
                    $this->logger->warning('Unknown payment status for order #' . $order_id . ': ' . $status_code);
                    break;
            }
        }
        
        // Redirect to thank you page
        wp_redirect($this->get_return_url($order));
        exit;
    }

    /**
     * Process successful payment
     */
    private function process_successful_payment($order, $transaction_id, $remarks = '') {
        // Complete payment
        $order->payment_complete($transaction_id);
        
        // Add order note
        $order->add_order_note(
            sprintf(__('MyPay payment successful. Transaction ID: %s', 'mypay-payment-gateway'), $transaction_id)
        );
        
        // Update order status if configured
        if ($this->order_status !== 'wc-processing' && $this->order_status !== 'wc-completed') {
            $order->update_status(str_replace('wc-', '', $this->order_status));
        }
        
        $this->logger->info('Payment completed for order #' . $order->get_id());
    }

    /**
     * Update transaction log
     */
    private function update_transaction_log($order_id, $merchant_txn_id, $gateway_txn_id, $status, $response) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mypay_transaction_logs';
        
        // Check if transaction exists
        $transaction = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM $table_name WHERE merchant_transaction_id = %s",
                $merchant_txn_id
            )
        );
        
        if ($transaction) {
            // Update existing transaction
            $wpdb->update(
                $table_name,
                array(
                    'gateway_transaction_id' => $gateway_txn_id,
                    'status' => $status,
                    'response_data' => json_encode($response),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $transaction->id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            // Insert new transaction
            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'merchant_transaction_id' => $merchant_txn_id,
                    'gateway_transaction_id' => $gateway_txn_id,
                    'amount' => 0, // We don't know the amount at this point
                    'status' => $status,
                    'response_data' => json_encode($response),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Get order ID by transaction ID
     */
    private function get_order_id_by_transaction_id($transaction_id) {
        global $wpdb;
        
        // First try to get from post meta
        $orders = wc_get_orders(array(
            'meta_key' => '_mypay_merchant_transaction_id',
            'meta_value' => $transaction_id,
            'limit' => 1,
        ));
        
        if (!empty($orders)) {
            return $orders[0]->get_id();
        }
        
        // Then try to get from transaction logs
        $table_name = $wpdb->prefix . 'mypay_transaction_logs';
        
        $order_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT order_id FROM $table_name WHERE merchant_transaction_id = %s LIMIT 1",
                $transaction_id
            )
        );
        
        return $order_id;
    }

    /**
     * Handle order status changes
     */
    public function order_status_changed($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        
        // Only process MyPay orders
        if ($order->get_payment_method() !== $this->id) {
            return;
        }
        
        // Log status change
        $this->logger->info('Order #' . $order_id . ' status changed from ' . $old_status . ' to ' . $new_status);
    }

    /**
     * Process refund
     *
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     * @return bool
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        // MyPay doesn't support refunds via API yet
        return false;
    }
}

