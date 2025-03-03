# MyPay Payment Gateway for WooCommerce

<p align="center">
  <a href="https://nepalcloud.com.np">
    <img src="assets/images/nepalcloud.png" alt="NepalCloud Host Logo" height="80">
  </a>
  &nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://namence.com">
    <img src="assets/images/Namence.png" alt="Namence Logo" height="80">
  </a>
</p>

<p align="center">
A collaborative project by <strong>NepalCloud Host</strong> and <strong>Namence</strong>
</p>

[![Current Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/springmusk026/mypay-payment-plugin-woocommerce/releases)
[![WP Version](https://img.shields.io/badge/wordpress-%3E%3D%205.6-green.svg)](https://wordpress.org/)
[![WC Version](https://img.shields.io/badge/woocommerce-%3E%3D%205.0-purple.svg)](https://woocommerce.com/)

An unofficial, open-source WooCommerce payment gateway plugin for MyPay integration. This plugin allows WooCommerce store owners to accept payments through MyPay payment gateway.

⚠️ **Disclaimer**: This is an unofficial plugin developed by NepalCloud Host and is not officially associated with MyPay. While we strive to maintain security and functionality, please use this plugin at your own discretion.

## Version History

### Version 1.0.0 (Initial Release)
Core Features:
- Integrated MyPay payment gateway with WooCommerce checkout system
- Added support for both live and test environments
- Implemented secure transaction handling with nonce verification
- Added comprehensive logging system for debugging

Technical Implementations:
- Custom database table 'wp_mypay_transaction_logs' for transaction tracking
- AJAX-based real-time transaction status checking system
- Secure logging system with protected directory structure
- Full WordPress coding standards compliance
- Built with PHP 7.3+ compatibility

Security Features Added:
- SSL requirement verification
- API credential encryption
- Protected log storage with .htaccess rules
- Input sanitization and data validation
- Nonce-based AJAX security

WooCommerce Integration:
- Integration with WooCommerce payment gateway system
- MyPay API integration for payment processing
- Comprehensive transaction logging system
- Custom database table for transaction management
- Secure log storage with .htaccess protection
- AJAX-based transaction status checking
- Admin interface for payment settings
- Test mode support for development
- HPOS (High-Performance Order Storage) compatibility
- WooCommerce Blocks compatibility

## Features

- Seamless integration with WooCommerce
- Support for MyPay Checkout API
- Transaction logging and monitoring
- Admin interface for payment configuration
- Real-time payment status updates
- Secure payment processing
- HPOS (High-Performance Order Storage) compatibility
- WooCommerce Blocks compatibility

## Requirements

- WordPress 5.6 or higher
- WooCommerce 5.0 or higher
- PHP 7.3 or higher
- SSL Certificate (for secure transactions)

## Installation

1. Download the latest version from the [GitHub repository](https://github.com/springmusk026/mypay-payment-plugin-woocommerce)
2. Upload the plugin files to the `/wp-content/plugins/mypay-payment-gateway` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure the plugin in WooCommerce → Settings → Payments

## Configuration

1. Navigate to WooCommerce → Settings → Payments
2. Click on "MyPay"
3. Configure the following settings:
   - Enable/Disable: Turn the payment method on/off
   - Title: The payment method name shown to customers
   - Description: Payment method description shown at checkout
   - API Credentials: Enter your MyPay API credentials
   - Test Mode: Enable/disable test mode for development

## Security Features

- SSL requirement check
- Nonce verification for AJAX requests
- Protected transaction logs
- Secure storage of API credentials
- Input sanitization and validation
- Database table with proper indexing
- Protected log directory with .htaccess

## Logging and Debugging

The plugin includes comprehensive logging functionality:
- Transaction logs are stored in a dedicated database table
- Debug logs are stored in the `wp-content/uploads/mypay-logs` directory
- Log access is restricted through .htaccess rules
- Logs include request/response data and transaction status updates

## Database Structure

The plugin creates a custom table for transaction logging:

```sql
wp_mypay_transaction_logs
- id (bigint)
- order_id (bigint)
- merchant_transaction_id (varchar)
- gateway_transaction_id (varchar)
- amount (decimal)
- status (varchar)
- request_data (longtext)
- response_data (longtext)
- created_at (datetime)
- updated_at (datetime)
```

## Contributing

We welcome contributions! Please feel free to submit issues and pull requests.

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-new-feature`
3. Commit your changes: `git commit -am 'Add some feature'`
4. Push to the branch: `git push origin feature/my-new-feature`
5. Submit a pull request

## Known Issues and Limitations

- This is an unofficial integration and may contain bugs or security issues
- Not all MyPay features may be supported
- Limited testing has been performed
- API responses may change without notice
- No official support from MyPay

## License

This project is licensed under the GPL-2.0+ License - see the [LICENSE](http://www.gnu.org/licenses/gpl-2.0.txt) file for details.

## Authors

- **Basanta Sapkota** - *Initial work* - [springmusk026](https://github.com/springmusk026)
- Website: [basantasapkota026.com.np](https://basantasapkota026.com.np)

## Organization

- **NepalCloud Host** - [Website](https://nepalcloud.com.np) | [GitHub](https://github.com/NepalCloudHost)

## Support

For bugs and feature requests, please use the GitHub issues page. As this is an unofficial plugin, there is no official support channel.

## Acknowledgments

- Thanks to the WooCommerce team for their excellent documentation
- Thanks to the WordPress community for plugin development guidelines
- Special thanks to all contributors and testers
