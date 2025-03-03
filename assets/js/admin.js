/**
 * MyPay Admin JavaScript
 * Handles transaction management and status checking in admin panel
 */
; (($) => {
    // Transaction status cache
    const statusCache = new Map();

    // Debounce helper
    const debounce = (func, wait) => {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };

    // Error handler
    const handleError = (error, $element) => {
        console.error('MyPay Error:', error);
        const message = error.responseJSON?.message || error.statusText || 'Unknown error occurred';
        const $errorDiv = $('<div/>')
            .addClass('mypay-error notice notice-error')
            .text(message);
        $element.html($errorDiv);
    };

    // Format currency
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'NPR'
        }).format(amount);
    };

    // Format date
    const formatDate = (dateStr) => {
        return new Intl.DateTimeFormat('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(dateStr));
    };

    // Status text helper
    const getStatusText = (status, withClass = true) => {
        const statusMap = {
            1: { text: mypay_admin_params.status_success, class: 'success' },
            2: { text: mypay_admin_params.status_failed, class: 'failed' },
            3: { text: mypay_admin_params.status_cancelled, class: 'cancelled' },
            4: { text: mypay_admin_params.status_pending, class: 'pending' },
            5: { text: mypay_admin_params.status_incomplete, class: 'incomplete' }
        };

        const status_info = statusMap[Number.parseInt(status)] ||
            { text: mypay_admin_params.status_unknown, class: 'unknown' };

        return withClass ?
            `<span class="mypay-status mypay-status-${status_info.class}">${status_info.text}</span>` :
            status_info.text;
    };

    // Check single payment status
    const checkPaymentStatus = async ($button) => {
        const $result = $button.closest('.mypay-payment-info').find('.mypay-status-result');
        const orderId = $button.data('order-id');

        // Return cached result if available and fresh
        if (statusCache.has(orderId)) {
            const cached = statusCache.get(orderId);
            if (Date.now() - cached.timestamp < 30000) { // 30 seconds cache
                updateStatusDisplay($result, cached.data);
                return;
            }
        }

        try {
            $result.html(`<p>${mypay_admin_params.checking_status}</p>`);

            const response = await $.ajax({
                url: mypay_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'mypay_check_transaction_status',
                    order_id: orderId,
                    nonce: mypay_admin_params.nonce
                }
            });

            if (response.success) {
                // Cache the result
                statusCache.set(orderId, {
                    timestamp: Date.now(),
                    data: response.data
                });

                updateStatusDisplay($result, response.data);

                // Reload only if status changed
                if (response.data.status_changed) {
                    setTimeout(() => window.location.reload(), 2000);
                }
            } else {
                throw new Error(response.data.message);
            }
        } catch (error) {
            handleError(error, $result);
        }
    };

    // Update status display
    const updateStatusDisplay = ($element, data) => {
        const { Status, Remarks, Amount, TransactionDate } = data;

        let html = `<p>${getStatusText(Status)}</p>`;

        if (Amount) {
            html += `<p>Amount: ${formatCurrency(Amount)}</p>`;
        }

        if (TransactionDate) {
            html += `<p>Last Updated: ${formatDate(TransactionDate)}</p>`;
        }

        if (Remarks) {
            html += `<p>Remarks: ${Remarks}</p>`;
        }

        $element.html(html);
    };

    // Batch status checker
    const checkAllStatuses = debounce(async () => {
        const $buttons = $('.check-mypay-status');
        const $notification = $('<div/>').addClass('notice notice-info').text('Checking all transactions...');

        $('#wpbody-content').prepend($notification);

        try {
            await Promise.all(
                $buttons.map(function () {
                    return checkPaymentStatus($(this));
                })
            );

            $notification
                .removeClass('notice-info')
                .addClass('notice-success')
                .text('All transaction statuses updated');

            setTimeout(() => $notification.fadeOut(), 3000);
        } catch (error) {
            $notification
                .removeClass('notice-info')
                .addClass('notice-error')
                .text('Error updating some transactions');
        }
    }, 1000);

    // Initialize admin features
    const initAdmin = () => {
        // Single status check
        $(document).on('click', '.check-mypay-status', function (e) {
            e.preventDefault();
            checkPaymentStatus($(this));
        });

        // Batch status check button
        const $actionDiv = $('<div/>').addClass('mypay-batch-actions');
        const $batchButton = $('<button/>')
            .addClass('button')
            .text('Check All Transactions')
            .on('click', checkAllStatuses);

        $actionDiv.append($batchButton);

        $('.wp-header-end').after($actionDiv);

        // Export transactions button
        const $exportButton = $('<button/>')
            .addClass('button')
            .text('Export Transactions')
            .on('click', exportTransactions);

        $actionDiv.append($exportButton);

        // Auto-refresh for pending transactions
        if ($('.mypay-status-pending, .mypay-status-processing').length) {
            setInterval(checkAllStatuses, 300000); // Every 5 minutes
        }
    };

    // Export transactions
    const exportTransactions = async () => {
        try {
            const response = await $.ajax({
                url: mypay_admin_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'mypay_export_transactions',
                    nonce: mypay_admin_params.nonce
                }
            });

            if (response.success) {
                const blob = new Blob([response.data], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = 'mypay-transactions.csv';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
            } else {
                throw new Error(response.data.message);
            }
        } catch (error) {
            handleError(error, $('#wpbody-content'));
        }
    };

    // Initialize on document ready
    $(document).ready(initAdmin);
})(jQuery)
