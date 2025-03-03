/**
 * MyPay Checkout JavaScript
 * Handles payment form validation and status checking
 */
; (($) => {
    // Payment form validation
    const validatePaymentForm = () => {
        const $form = $('form.checkout');
        let isValid = true;

        // Clear previous errors
        $('.mypay-error').remove();

        // Basic amount validation
        const amount = $('input[name="payment_amount"]').val();
        if (amount && !(/^\d+(\.\d{2})?$/).test(amount)) {
            showError('Invalid payment amount format');
            isValid = false;
        }

        return isValid;
    };

    // Show error message
    const showError = (message) => {
        const $errorDiv = $('<div/>')
            .addClass('mypay-error woocommerce-error')
            .text(message);

        $('#mypay-payment-errors').html($errorDiv);
        $('html, body').animate({
            scrollTop: $('#mypay-payment-errors').offset().top - 100
        }, 1000);
    };

    // Payment status checker
    const checkPaymentStatus = (orderId) => {
        const statusChecker = setInterval(() => {
            $.ajax({
                url: mypay_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'mypay_check_transaction_status',
                    order_id: orderId,
                    nonce: mypay_params.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const status = response.data.status;
                        updatePaymentStatus(status);

                        // Stop checking if payment is complete or failed
                        if (['success', 'failed', 'cancelled'].includes(status)) {
                            clearInterval(statusChecker);
                            handleFinalStatus(status, response.data);
                        }
                    }
                },
                error: (xhr, textStatus, error) => {
                    console.error('Status check failed:', error);
                    showError('Failed to check payment status. Please refresh the page.');
                    clearInterval(statusChecker);
                }
            });
        }, 5000); // Check every 5 seconds

        // Stop checking after 10 minutes
        setTimeout(() => {
            clearInterval(statusChecker);
        }, 600000);
    };

    // Update payment status display
    const updatePaymentStatus = (status) => {
        const $statusDiv = $('.mypay-payment-status');
        $statusDiv
            .removeClass('pending processing completed failed')
            .addClass(status)
            .text(getStatusText(status));
    };

    // Get human-readable status text
    const getStatusText = (status) => {
        const statusTexts = {
            'pending': 'Payment Pending',
            'processing': 'Processing Payment',
            'completed': 'Payment Completed',
            'failed': 'Payment Failed',
            'cancelled': 'Payment Cancelled'
        };
        return statusTexts[status] || 'Unknown Status';
    };

    // Handle final payment status
    const handleFinalStatus = (status, data) => {
        switch (status) {
            case 'success':
                window.location.href = data.redirect_url;
                break;
            case 'failed':
                showError(data.message || 'Payment failed. Please try again.');
                break;
            case 'cancelled':
                showError('Payment was cancelled. Please try again.');
                break;
        }
    };

    // Initialize checkout
    $(document).ready(() => {
        // Add payment form validation
        $('form.checkout').on('checkout_place_order_mypay', () => {
            return validatePaymentForm();
        });

        // Start status checker if order ID is available
        const orderId = $('#mypay-order-id').val();
        if (orderId) {
            checkPaymentStatus(orderId);
        }

        // Handle payment method change
        $('form.checkout').on('change', 'input[name="payment_method"]', function () {
            const selectedMethod = $(this).val();
            if (selectedMethod === 'mypay') {
                $('.mypay-fields').show();
            } else {
                $('.mypay-fields').hide();
            }
        });
    });

    // Add error handling for network issues
    $(window).on('online', () => {
        $('.mypay-network-error').remove();
    }).on('offline', () => {
        showError('Internet connection lost. Please check your connection and try again.');
    });
})(jQuery);
