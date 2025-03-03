<?php
/**
 * MyPay API Class
 *
 * @package MyPay Payment Gateway
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_MyPay_API Class
 */
class WC_MyPay_API {
    /**
     * Maximum number of API retries
     */
    const MAX_RETRIES = 3;

    /**
     * API rate limit window in seconds
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Maximum API calls per window
     */
    const RATE_LIMIT_MAX_CALLS = 100;

    /**
     * Gateway instance
     *
     * @var WC_Gateway_MyPay
     */
    protected $gateway;

    /**
     * API endpoints
     *
     * @var array
     */
    protected $endpoints;

    /**
     * Last request data
     *
     * @var array
     */
    public $last_request;

    /**
     * Rate limiting cache
     *
     * @var array
     */
    private $api_calls = [];

    /**
     * Request validation schemas
     *
     * @var array
     */
    private $schemas = [
        'generate_order' => [
            'required' => ['Amount', 'OrderId', 'UserName', 'Password', 'MerchantId'],
            'properties' => [
                'Amount' => ['type' => 'string', 'pattern' => '^\d+\.\d{2}$'],
                'OrderId' => ['type' => 'string', 'minLength' => 6],
                'UserName' => ['type' => 'string'],
                'Password' => ['type' => 'string'],
                'MerchantId' => ['type' => 'string'],
                'ReturnUrl' => ['type' => 'string', 'format' => 'uri']
            ]
        ],
        'check_status' => [
            'oneOf' => [
                [
                    'required' => ['MerchantTransactionId'],
                    'properties' => [
                        'MerchantTransactionId' => ['type' => 'string']
                    ]
                ],
                [
                    'required' => ['GatewayTransactionId'],
                    'properties' => [
                        'GatewayTransactionId' => ['type' => 'string']
                    ]
                ]
            ]
        ]
    ];

    /**
     * Constructor
     *
     * @param WC_Gateway_MyPay $gateway
     */
    public function __construct($gateway) {
        $this->gateway = $gateway;
        
        // Set API endpoints based on test mode
        if ($gateway->test_mode) {
            $this->endpoints = array(
                'generate_order' => 'https://stagingapi1.mypay.com.np/api/use-mypay-payments',
                'check_status' => 'https://stagingapi1.mypay.com.np/api/use-mypay-payments-status'
            );
        } else {
            $this->endpoints = array(
                'generate_order' => 'https://smartdigitalnepal.com/api/use-mypay-payments',
                'check_status' => 'https://smartdigitalnepal.com/api/use-mypay-payments-status'
            );
        }
    }

    /**
     * Generate order in MyPay
     *
     * @param WC_Order $order
     * @return array|bool
     */
    public function generate_order($order) {
        // Get order number
        $order_number = (string) $order->get_order_number();
        
        // Ensure order number is at least 6 characters
        if (strlen($order_number) < 6) {
            $order_number = str_pad($order_number, 6, '0', STR_PAD_LEFT);
        }
        
        // Create return URL
        $return_url = add_query_arg(
            array(
                'wc-api' => 'wc_gateway_mypay',
                'order_id' => $order->get_id()
            ),
            home_url('/')
        );
        
        // Prepare request data
        $data = array(
            'Amount' => number_format($order->get_total(), 2, '.', ''),
            'OrderId' => $order_number,
            'UserName' => $this->gateway->username,
            'Password' => $this->gateway->password,
            'MerchantId' => $this->gateway->merchant_id,
            'ReturnUrl' => $return_url
        );
        
        $this->last_request = $data;
        
        // Send request
        return $this->send_request('generate_order', $data);
    }

    /**
     * Check transaction status
     *
     * @param string $transaction_id
     * @return array|bool
     */
    public function check_transaction_status($transaction_id) {
        // Prepare request data
        $data = array(
            'MerchantTransactionId' => $transaction_id
        );
        
        $this->last_request = $data;
        
        // Send request
        return $this->send_request('check_status', $data);
    }

    /**
     * Check transaction status by gateway transaction ID
     *
     * @param string $gateway_transaction_id
     * @return array|bool
     */
    public function check_transaction_status_by_gateway_id($gateway_transaction_id) {
        // Prepare request data
        $data = array(
            'GatewayTransactionId' => $gateway_transaction_id
        );
        
        $this->last_request = $data;
        
        // Send request
        return $this->send_request('check_status', $data);
    }

    /**
     * Send request to MyPay API with retries and validation
     *
     * @param string $endpoint_key
     * @param array $data
     * @return array|bool
     * @throws Exception
     */
    protected function send_request($endpoint_key, $data) {
        if (!isset($this->endpoints[$endpoint_key])) {
            throw new Exception("Invalid endpoint: {$endpoint_key}");
        }

        // Validate request data against schema
        if (!$this->validate_request($endpoint_key, $data)) {
            throw new Exception("Invalid request data for {$endpoint_key}");
        }

        // Check rate limit
        if (!$this->check_rate_limit()) {
            throw new Exception("API rate limit exceeded");
        }

        $url = $this->endpoints[$endpoint_key];
        $headers = [
            'Content-Type' => 'application/json',
            'API_KEY' => $this->gateway->api_key,
            'X-Request-ID' => uniqid('mypay_', true), // Idempotency key
        ];

        $args = [
            'body' => json_encode($data),
            'headers' => $headers,
            'timeout' => 60,
            'sslverify' => true // Always verify SSL
        ];

        // Store request for logging
        $this->last_request = [
            'url' => $url,
            'data' => $data,
            'headers' => $headers
        ];

        // Implement retry logic
        $attempt = 1;
        $max_attempts = self::MAX_RETRIES;
        $retry_codes = [500, 502, 503, 504];

        do {
            // Log request attempt
            $this->log_request($endpoint_key, $data, $attempt);

            // Send request
            $response = wp_remote_post($url, $args);

            // Check for connection errors
            if (is_wp_error($response)) {
                $error = $response->get_error_message();
                $this->gateway->logger->error("API Error (Attempt {$attempt}): {$error}");
                
                if ($attempt >= $max_attempts) {
                    throw new Exception("API request failed after {$max_attempts} attempts: {$error}");
                }
                
                sleep(pow(2, $attempt)); // Exponential backoff
                $attempt++;
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            // Log response
            $this->log_response($endpoint_key, $result, $status_code);

            // Handle response based on status code
            if ($status_code === 200) {
                // Validate response format
                if (!$this->validate_response($result)) {
                    throw new Exception("Invalid API response format");
                }
                return $result;
            }

            // Check if we should retry
            if (in_array($status_code, $retry_codes) && $attempt < $max_attempts) {
                sleep(pow(2, $attempt)); // Exponential backoff
                $attempt++;
                continue;
            }

            // Handle client errors
            if ($status_code >= 400) {
                $error = isset($result['Message']) ? $result['Message'] : "HTTP Error {$status_code}";
                throw new Exception("API request failed: {$error}");
            }

        } while ($attempt <= $max_attempts);

        throw new Exception("API request failed after {$max_attempts} attempts");
    }

    /**
     * Validate request data against schema
     */
    private function validate_request($endpoint_key, $data) {
        if (!isset($this->schemas[$endpoint_key])) {
            return false;
        }

        $schema = $this->schemas[$endpoint_key];

        // Check required fields
        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!isset($data[$field])) {
                    return false;
                }
            }
        }

        // Validate properties
        if (isset($schema['properties'])) {
            foreach ($data as $key => $value) {
                if (isset($schema['properties'][$key])) {
                    $prop = $schema['properties'][$key];
                    
                    // Type validation
                    if (isset($prop['type']) && gettype($value) !== $prop['type']) {
                        return false;
                    }
                    
                    // Pattern validation
                    if (isset($prop['pattern']) && !preg_match('/' . $prop['pattern'] . '/', $value)) {
                        return false;
                    }
                    
                    // Length validation
                    if (isset($prop['minLength']) && strlen($value) < $prop['minLength']) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validate API response format
     */
    private function validate_response($response) {
        if (!is_array($response)) {
            return false;
        }

        // Check for required response fields
        $required_fields = ['status', 'Message'];
        foreach ($required_fields as $field) {
            if (!isset($response[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Implement rate limiting
     */
    private function check_rate_limit() {
        $now = time();
        $window_start = $now - self::RATE_LIMIT_WINDOW;

        // Remove old entries
        $this->api_calls = array_filter($this->api_calls, function($timestamp) use ($window_start) {
            return $timestamp >= $window_start;
        });

        // Check if we're over the limit
        if (count($this->api_calls) >= self::RATE_LIMIT_MAX_CALLS) {
            return false;
        }

        // Add current request
        $this->api_calls[] = $now;
        return true;
    }

    /**
     * Log API request
     */
    private function log_request($endpoint_key, $data, $attempt) {
        $message = sprintf(
            "API Request (Attempt %d) to %s: %s",
            $attempt,
            $endpoint_key,
            json_encode($data)
        );
        $this->gateway->logger->info($message);
    }

    /**
     * Log API response
     */
    private function log_response($endpoint_key, $response, $status_code) {
        $message = sprintf(
            "API Response from %s (HTTP %d): %s",
            $endpoint_key,
            $status_code,
            json_encode($response)
        );
        $this->gateway->logger->info($message);
    }

    /**
     * Get order ID by transaction ID
     *
     * @param string $transaction_id
     * @return int|null
     */
    public function get_order_id_by_transaction_id($transaction_id) {
        $orders = wc_get_orders(array(
            'meta_key' => '_mypay_merchant_transaction_id',
            'meta_value' => $transaction_id,
            'limit' => 1,
            'type' => wc_get_order_types(),
            'return' => 'objects',
        ));

        if ($orders) {
            foreach ($orders as $order) {
                return $order->get_id();
            }
        }

        return null;
    }
}
