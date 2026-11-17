/**
 * Payment Frontend JavaScript
 * 
 * Handles payment method selection and processing in the frontend
 * 
 * @package MenuPopup
 */

(function($) {
    'use strict';
    
    /**
     * Payment Frontend Class
     */
    class PaymentFrontend {
        
        /**
         * Constructor
         */
        constructor() {
            this.paymentManager = null;
            this.selectedMethod = null;
            this.isProcessing = false;
            
            this.init();
        }
        
        /**
         * Initialize
         */
        init() {
            this.bindEvents();
            
            // Hide hardcoded payment options initially - they will be shown based on enabled gateways
            $('.payment-method-option').hide();
            
            // Load payment methods
            this.loadPaymentMethods();
        }
        
        /**
         * Bind events
         */
        bindEvents() {
            // Payment method selection
            $(document).on('change', '.payment-method input[name="payment_method"]', (e) => {
                this.selectMethod($(e.target).val());
            });
            
            // Place order button
            $(document).on('click', '#place-order-btn, .place-order-btn', (e) => {
                e.preventDefault();
                this.processOrder();
            });
            
            // COD agreement checkbox
            $(document).on('change', 'input[name="cod_agreement"]', (e) => {
                this.validateAgreement();
            });
            
            // Bank transfer agreement checkbox
            $(document).on('change', 'input[name="bank_transfer_agreement"]', (e) => {
                this.validateAgreement();
            });
            
            // Listen for cart updates
            $(document).on('menu_popup_cart_updated', () => {
                this.loadPaymentMethods();
            });
        }
        
        /**
         * Load enabled payment methods
         */
        loadPaymentMethods() {
            if (!window.menuPopupPayment) {
                console.error('menuPopupPayment configuration not found');
                return;
            }
            
            $.ajax({
                url: window.menuPopupPayment.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_payment_methods',
                    nonce: window.menuPopupPayment.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.renderPaymentMethods(response.data.methods);
                    } else {
                        this.showError(response.data.message || 'Failed to load payment methods');
                    }
                },
                error: () => {
                    this.showError('Failed to connect to server');
                }
            });
        }
        
        /**
         * Render payment methods
         * 
         * @param array methods
         */
        renderPaymentMethods(methods) {
            // Try multiple container selectors to find where to render
            let container = $('.payment-methods-container');
            
            // If not found, use the payment methods section in modal
            if (!container.length) {
                container = $('#payment-methods-section');
            }
            
            if (!container.length) {
                // No container found - payment methods are handled by menu-popup-frontend.js
                // Just update visibility of existing options
                this.updatePaymentOptionsVisibility(methods);
                return;
            }
            
            if (!methods || methods.length === 0) {
                container.html('<p class="no-payment-methods">' + 
                    window.menuPopupPayment.noMethodsAvailable + 
                    '</p>');
                return;
            }
            
            // Hide the hardcoded payment options and show dynamically loaded ones
            const existingOptions = container.find('.payment-method-option, .payment-method');
            if (existingOptions.length > 0) {
                // Update existing options visibility based on enabled gateways
                this.updatePaymentOptionsVisibility(methods);
                container.show();
            } else {
                // Render new payment method HTML
                let html = '';
                
                methods.forEach((method) => {
                    html += this.getPaymentMethodHTML(method);
                });
                
                container.html(html);
            }
            
            // Trigger event
            $(document).trigger('payment_methods_loaded', [methods]);
        }
        
        /**
         * Update payment options visibility based on enabled gateways
         * 
         * @param array methods
         */
        updatePaymentOptionsVisibility(methods) {
            const enabledIds = methods.map(m => m.id);
            
            // Hide all payment method options first
            $('.payment-method-option').hide();
            
            // Show only enabled payment methods
            enabledIds.forEach(id => {
                const option = $(`.payment-method-option input[value="${id}"]`).closest('.payment-method-option');
                if (option.length) {
                    option.show();
                } else {
                    // Also check with different selector
                    $(`#payment-methods-section .payment-method-option`).each(function() {
                        const input = $(this).find('input');
                        if (input.val() === id) {
                            $(this).show();
                        }
                    });
                }
            });
            
            // Select first available option
            const firstVisible = $('.payment-method-option:visible:first input');
            if (firstVisible.length) {
                firstVisible.prop('checked', true).trigger('change');
            }
        }
        
        /**
         * Get payment method HTML
         * 
         * @param object method
         * @return string
         */
        getPaymentMethodHTML(method) {
            const icons = {
                'cod': '💵',
                'bank_transfer': '🏦',
                'fawry': '💳'
            };
            
            const labels = {
                'cod': window.menuPopupPayment.labels.cod || 'Cash on Delivery',
                'bank_transfer': window.menuPopupPayment.labels.bank_transfer || 'Bank Transfer',
                'fawry': window.menuPopupPayment.labels.fawry || 'Pay with Fawry'
            };
            
            const descriptions = {
                'cod': window.menuPopupPayment.descriptions.cod || 'Pay in cash when your order is delivered',
                'bank_transfer': window.menuPopupPayment.descriptions.bank_transfer || 'Transfer via bank account',
                'fawry': window.menuPopupPayment.descriptions.fawry || 'Pay securely online'
            };
            
            return `
                <div class="payment-method" data-gateway="${method.id}">
                    <label class="payment-method-label">
                        <input type="radio" 
                               name="payment_method" 
                               value="${method.id}"
                               ${method.id === 'cod' ? 'checked' : ''}>
                        <span class="payment-icon">${icons[method.id] || '💰'}</span>
                        <span class="payment-details">
                            <span class="payment-name">${labels[method.id] || method.name}</span>
                            <span class="payment-description">${descriptions[method.id] || method.description}</span>
                        </span>
                    </label>
                    <div class="payment-method-content" style="display: none;">
                        ${method.is_online ? this.getOnlinePaymentContent(method) : ''}
                    </div>
                </div>
            `;
        }
        
        /**
         * Get online payment content
         * 
         * @param object method
         * @return string
         */
        getOnlinePaymentContent(method) {
            return `<p class="online-payment-notice">${window.menuPopupPayment.redirectNotice || 'You will be redirected to complete payment'}</p>`;
        }
        
        /**
         * Select payment method
         * 
         * @param string method
         */
        selectMethod(method) {
            this.selectedMethod = method;
            
            // Hide all payment contents
            $('.payment-method-content').hide();
            
            // Show selected payment content
            const selectedContent = $(`.payment-method[data-gateway="${method}"] .payment-method-content`);
            if (selectedContent.length) {
                selectedContent.show();
            }
            
            // Update UI
            $('.payment-method').removeClass('selected');
            $(`.payment-method[data-gateway="${method}"]`).addClass('selected');
            
            // Trigger event
            $(document).trigger('payment_method_selected', [method]);
            
            // Update place order button state
            this.updatePlaceOrderButton();
        }
        
        /**
         * Validate agreement checkboxes
         */
        validateAgreement() {
            this.updatePlaceOrderButton();
        }
        
        /**
         * Update place order button state
         */
        updatePlaceOrderButton() {
            const btn = $('#place-order-btn, .place-order-btn');
            
            if (!this.selectedMethod) {
                btn.prop('disabled', true);
                return;
            }
            
            // Check agreement for COD and Bank Transfer
            if (this.selectedMethod === 'cod') {
                const agreed = $('input[name="cod_agreement"]').is(':checked');
                btn.prop('disabled', !agreed);
            } else if (this.selectedMethod === 'bank_transfer') {
                const agreed = $('input[name="bank_transfer_agreement"]').is(':checked');
                btn.prop('disabled', !agreed);
            } else {
                btn.prop('disabled', false);
            }
        }
        
        /**
         * Process order
         */
        processOrder() {
            if (this.isProcessing) {
                return;
            }
            
            if (!this.selectedMethod) {
                this.showError(window.menuPopupPayment.selectMethodMessage || 'Please select a payment method');
                return;
            }
            
            // Validate agreement
            if (this.selectedMethod === 'cod') {
                if (!$('input[name="cod_agreement"]').is(':checked')) {
                    this.showError(window.menuPopupPayment.codAgreementRequired || 'Please agree to pay in cash on delivery');
                    return;
                }
            }
            
            if (this.selectedMethod === 'bank_transfer') {
                if (!$('input[name="bank_transfer_agreement"]').is(':checked')) {
                    this.showError(window.menuPopupPayment.bankTransferAgreementRequired || 'Please confirm you have made the transfer');
                    return;
                }
            }
            
            // Get order data
            const orderData = this.getOrderData();
            
            if (!orderData) {
                this.showError('Order data not found');
                return;
            }
            
            // Add payment method to order data
            orderData.payment_method = this.selectedMethod;
            
            // Show loading
            this.setLoading(true);
            
            // Process payment
            $.ajax({
                url: window.menuPopupPayment.ajax_url,
                type: 'POST',
                data: {
                    action: 'process_payment',
                    nonce: window.menuPopupPayment.nonce,
                    payment_method: this.selectedMethod,
                    order_data: orderData
                },
                success: (response) => {
                    this.setLoading(false);
                    
                    if (response.success) {
                        this.handlePaymentSuccess(response.data);
                    } else {
                        this.handlePaymentError(response.data);
                    }
                },
                error: (xhr, status, error) => {
                    this.setLoading(false);
                    this.showError(window.menuPopupPayment.processingError || 'Payment processing failed: ' + error);
                }
            });
        }
        
        /**
         * Get order data from the form
         * 
         * @return object|null
         */
        getOrderData() {
            // Try to get order data from various sources
            const cartForm = $('#menu-popup-cart-form, .menu-popup-cart-form');
            
            if (cartForm.length) {
                const formData = {};
                
                // Get basic order info
                formData.order_id = cartForm.find('input[name="order_id"]').val() || 0;
                formData.total = cartForm.find('.cart-total-amount, .order-total').text().replace(/[^0-9.]/g, '') || 0;
                formData.currency = cartForm.find('input[name="currency"]').val() || 'EGP';
                
                // Get customer info
                formData.customer_name = cartForm.find('input[name="customer_name"], #customer-name').val() || '';
                formData.customer_phone = cartForm.find('input[name="customer_phone"], #customer-phone').val() || '';
                formData.customer_email = cartForm.find('input[name="customer_email"], #customer-email').val() || '';
                formData.address = cartForm.find('textarea[name="address"], #delivery-address').val() || '';
                
                // Get order items
                formData.items = this.getOrderItems();
                
                return formData;
            }
            
            // Fallback: try to get from window object
            if (window.menuPopupOrderData) {
                return window.menuPopupOrderData;
            }
            
            return null;
        }
        
        /**
         * Get order items from cart
         * 
         * @return array
         */
        getOrderItems() {
            const items = [];
            
            $('.menu-popup-cart-item, .cart-item').each((index, element) => {
                const $item = $(element);
                
                items.push({
                    id: $item.data('item-id') || 0,
                    name: $item.find('.item-name, .item-title').text() || '',
                    price: parseFloat($item.find('.item-price, .price').text().replace(/[^0-9.]/g, '')) || 0,
                    quantity: parseInt($item.find('.item-quantity, .quantity').text()) || 1
                });
            });
            
            return items;
        }
        
        /**
         * Handle payment success
         * 
         * @param object data
         */
        handlePaymentSuccess(data) {
            // Check if redirect is needed (but not for test mode)
            if (data.payment_url && !data.payment_url.includes('test') && !data.payment_url.startsWith('#')) {
                // Redirect to payment page
                window.location.href = data.payment_url;
                return;
            }
            
            // Test mode or no redirect - show success message
            this.showSuccess(data.message || 'Payment processed successfully');
            
            // Trigger event
            $(document).trigger('payment_success', [data]);
            
            // Redirect to confirmation page if available
            if (data.confirmation_url) {
                setTimeout(() => {
                    window.location.href = data.confirmation_url;
                }, 2000);
            } else if (data.order_id) {
                // Reload page to show updated status
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        }
        
        /**
         * Handle payment error
         * 
         * @param object data
         */
        handlePaymentError(data) {
            const message = data.message || 'Payment failed';
            this.showError(message);
            
            // Trigger event
            $(document).trigger('payment_error', [data]);
        }
        
        /**
         * Show success message
         * 
         * @param string message
         */
        showSuccess(message) {
            this.clearMessages();
            
            const html = `
                <div class="payment-message payment-success">
                    <span class="icon">✓</span>
                    <span class="message">${message}</span>
                </div>
            `;
            
            this.insertMessage(html);
        }
        
        /**
         * Show error message
         * 
         * @param string message
         */
        showError(message) {
            this.clearMessages();
            
            const html = `
                <div class="payment-message payment-error">
                    <span class="icon">✕</span>
                    <span class="message">${message}</span>
                </div>
            `;
            
            this.insertMessage(html);
        }
        
        /**
         * Insert message into DOM
         * 
         * @param string html
         */
        insertMessage(html) {
            const container = $('.checkout-form, #checkout-form, .payment-messages');
            
            if (container.length) {
                container.prepend(html);
            } else {
                // Insert after the form
                $('form[name="checkout-form"], form.checkout-form, form#order-form').first().prepend(html);
            }
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                $('.payment-message').fadeOut(() => {
                    $(this).remove();
                });
            }, 5000);
        }
        
        /**
         * Clear all messages
         */
        clearMessages() {
            $('.payment-message').remove();
        }
        
        /**
         * Set loading state
         * 
         * @param bool isLoading
         */
        setLoading(isLoading) {
            this.isProcessing = isLoading;
            
            const btn = $('#place-order-btn, .place-order-btn');
            const overlay = $('.payment-overlay, .checkout-overlay');
            
            if (isLoading) {
                btn.prop('disabled', true).addClass('loading');
                overlay.show();
            } else {
                btn.prop('disabled', false).removeClass('loading');
                overlay.hide();
            }
        }
    }
    
    // Initialize on DOM ready
    $(document).ready(() => {
        window.menuPopupPaymentFrontend = new PaymentFrontend();
    });
    
})(jQuery);
