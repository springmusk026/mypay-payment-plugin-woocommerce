<?php
/**
 * MyPay Logger Class
 *
 * @package MyPay Payment Gateway
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_MyPay_Logger Class
 */
class WC_MyPay_Logger
{
    /**
     * Maximum log file size in MB
     */
    const MAX_LOG_SIZE = 10;

    /**
     * Maximum number of days to keep logs
     */
    const MAX_LOG_AGE = 30;

    /**
     * Debug mode
     *
     * @var bool
     */
    protected $debug;

    /**
     * Log file handle
     *
     * @var resource
     */
    protected $log_handle;

    /**
     * Current log file
     *
     * @var string
     */
    protected $current_log_file;

    /**
     * Log levels
     *
     * @var array
     */
    protected $log_levels = [
        'ERROR' => 0,
        'WARNING' => 1,
        'INFO' => 2,
        'DEBUG' => 3,
        'TRACE' => 4
    ];

    /**
     * Constructor
     *
     * @param bool $debug
     */
    public function __construct($debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * Log info message
     *
     * @param string $message
     */
    public function info($message)
    {
        $this->log('INFO', $message);
    }

    /**
     * Log error message
     *
     * @param string $message
     */
    public function error($message)
    {
        $this->log('ERROR', $message);
    }

    /**
     * Log warning message
     *
     * @param string $message
     */
    public function warning($message)
    {
        $this->log('WARNING', $message);
    }

    /**
     * Log message
     *
     * @param string $level
     * @param string $message
     */
    protected function log($level, $message, $context = [])
    {
        // Always log errors, otherwise only log if debug is enabled
        if (!$this->debug && $level !== 'ERROR') {
            return;
        }

        // Add trace data for errors
        if ($level === 'ERROR') {
            $context['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        }

        // Add request data if available
        if (!empty($_SERVER)) {
            $context['request'] = [
                'url' => !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
                'method' => !empty($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
                'ip' => !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''
            ];
        }

        // Use WC_Logger if available
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $log_context = array_merge(['source' => 'mypay-payment-gateway'], $context);

            switch ($level) {
                case 'ERROR':
                    $logger->error($this->format_message($message, $context), $log_context);
                    break;
                case 'WARNING':
                    $logger->warning($this->format_message($message, $context), $log_context);
                    break;
                case 'DEBUG':
                    $logger->debug($this->format_message($message, $context), $log_context);
                    break;
                default:
                    $logger->info($this->format_message($message, $context), $log_context);
                    break;
            }

            return;
        }

        // Fallback to custom logging
        $this->custom_log($level, $message, $context);
    }

    /**
     * Custom log implementation
     *
     * @param string $level
     * @param string $message
     */
    /**
     * Format log message with context
     */
    protected function format_message($message, $context = [])
    {
        // If message is not a string, convert to JSON
        if (!is_string($message)) {
            $message = json_encode($message);
        }

        // If context is not empty, add it to the message
        if (!empty($context)) {
            $context_json = json_encode($context, JSON_PRETTY_PRINT);
            $message .= "\nContext: " . $context_json;
        }

        return $message;
    }

    /**
     * Custom log implementation with rotation and cleanup
     */
    protected function custom_log($level, $message, $context = [])
    {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mypay-logs';

        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);

            // Create .htaccess file to protect logs
            $htaccess_file = $log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                $htaccess_content = "# Deny access to all files
<FilesMatch \".*\">
    Order Allow,Deny
    Deny from all
</FilesMatch>";

                file_put_contents($htaccess_file, $htaccess_content);
            }

            // Create index.php file to prevent directory listing
            $index_file = $log_dir . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, '<?php // Silence is golden');
            }
        }

        // Clean old logs
        $this->cleanup_logs($log_dir);

        // Generate log file path
        $log_file = $log_dir . '/mypay-' . date('Y-m-d') . '.log';

        // Check if we need to rotate the log
        if ($this->should_rotate_log($log_file)) {
            $this->rotate_log($log_file);
        }

        // Open new log file if needed
        if (!$this->log_handle || $this->current_log_file !== $log_file) {
            if ($this->log_handle) {
                fclose($this->log_handle);
            }
            $this->log_handle = fopen($log_file, 'a');
            $this->current_log_file = $log_file;
        }

        // Write log entry
        if ($this->log_handle) {
            $timestamp = date('Y-m-d H:i:s');
            $formatted_message = $this->format_message($message, $context);
            fwrite($this->log_handle, "[$timestamp] [$level] $formatted_message" . PHP_EOL);
        }
    }

    /**
     * Check if log file should be rotated
     */
    protected function should_rotate_log($log_file)
    {
        if (!file_exists($log_file)) {
            return false;
        }

        $size_mb = filesize($log_file) / 1024 / 1024;
        return $size_mb >= self::MAX_LOG_SIZE;
    }

    /**
     * Rotate log file
     */
    protected function rotate_log($log_file)
    {
        if (!file_exists($log_file)) {
            return;
        }

        $info = pathinfo($log_file);
        $rotated_file = $info['dirname'] . '/' . $info['filename'] . '-' . date('Y-m-d-H-i-s') . '.log';
        rename($log_file, $rotated_file);

        // Compress rotated log
        if (function_exists('gzopen')) {
            $gz_file = $rotated_file . '.gz';
            $gz = gzopen($gz_file, 'w9');
            gzwrite($gz, file_get_contents($rotated_file));
            gzclose($gz);
            unlink($rotated_file);
        }
    }

    /**
     * Clean old log files
     */
    protected function cleanup_logs($log_dir)
    {
        if (!is_dir($log_dir)) {
            return;
        }

        $now = time();
        $max_age = self::MAX_LOG_AGE * 24 * 60 * 60;

        foreach (new DirectoryIterator($log_dir) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            // Skip .htaccess and index.php
            if (in_array($file->getFilename(), array('.htaccess', 'index.php'))) {
                continue;
            }

            // Remove old files
            if (($now - $file->getCTime()) > $max_age) {
                unlink($file->getPathname());
            }
        }
    }

    /**
     * Debug log with stack trace
     */
    public function debug($message, $context = [])
    {
        if ($this->debug) {
            $context['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $this->log('DEBUG', $message, $context);
        }
    }

    /**
     * Trace log for detailed debugging
     */
    public function trace($message, $context = [])
    {
        if ($this->debug) {
            $this->log('TRACE', $message, $context);
        }
    }

    /**
     * Close log file handle
     */
    public function __destruct()
    {
        if ($this->log_handle) {
            fclose($this->log_handle);
        }
    }
}
