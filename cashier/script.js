class CashierDashboard {
    constructor() {
        if (window.dashboard) {
            console.warn('CashierDashboard instance already exists');
            return window.dashboard;
        }
        
        this.currentSection = 'dashboard';
        this.cart = [];
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.init();
    }

    init() {
        this.bindEvents();
        this.setupKeyboardShortcuts();
        this.startAutoRefresh();
        this.focusBarcodeInput();

        // initial refresh
        this.refreshDashboardStats();
        this.refreshPendingOrdersData();
        this.refreshCompletedOrdersData();

        // auto refresh
        setInterval(() => {
            this.refreshDashboardStats();
            this.refreshPendingOrdersData();
            this.refreshCompletedOrdersData();
        }, 30000);
    }

    initConfirmPaymentButtons() {
        document.addEventListener('click', async (e) => {
            // Only handle .confirm-payment-btn, NOT .mark-collected here!
            const confirmBtn = e.target.closest('.confirm-payment-btn');
            if (!confirmBtn) return;
            e.preventDefault();

            const origHtml = confirmBtn.innerHTML;
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';

            try {
                const orderId = confirmBtn.dataset.orderId;
                const csrfToken = confirmBtn.dataset.csrfToken || this.csrfToken;

                const formData = new FormData();
                formData.append('ajax', 'mark_collected');
                formData.append('order_id', orderId);
                formData.append('csrf_token', csrfToken);

                const response = await fetch('orders_manager.php', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.status === 'success') {
                    this.showToast(data.message, 'success');

                    // remove row
                    const orderRow = confirmBtn.closest('tr');
                    if (orderRow) orderRow.remove();

                    // if backend returned stats, update badges
                    if (data.stats) {
                        const pendingCount = document.getElementById('pending-count');
                        const completedCount = document.getElementById('completed-count');
                        const totalPendingAmount = document.getElementById('total-pending-amount');
                        const totalCompletedAmount = document.getElementById('total-completed-amount');

                        if (pendingCount && typeof data.stats.pending_count !== 'undefined') {
                            pendingCount.textContent = data.stats.pending_count;
                        }
                        if (completedCount && typeof data.stats.completed_count !== 'undefined') {
                            completedCount.textContent = data.stats.completed_count;
                        }
                        if (totalPendingAmount && typeof data.stats.total_pending !== 'undefined') {
                            totalPendingAmount.textContent = `MWK${parseFloat(data.stats.total_pending).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                        }
                        if (totalCompletedAmount && typeof data.stats.total_completed !== 'undefined') {
                            totalCompletedAmount.textContent = `MWK${parseFloat(data.stats.total_completed).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                        }
                    }

                    this.refreshPendingOrdersData();
                    this.refreshCompletedOrdersData();
                    this.refreshStats();
                } else {
                    this.showToast(data.message || 'Failed to mark as collected', 'error');
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = origHtml;
                }
            } catch (error) {
                console.error('Error marking as collected:', error);
                this.showToast('Error marking as collected', 'error');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = origHtml;
            }
        });
    }

    bindEvents() {
        // Navigation events
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (item.getAttribute('href')?.startsWith('#')) {
                    e.preventDefault();
                    const section = item.getAttribute('href').substring(1);
                    this.showSection(section);
                }
            });
        });

        // Barcode input events
        const barcodeInput = document.getElementById('barcode-input');
        if (barcodeInput) {
            barcodeInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.addToCart();
                }
            });

            barcodeInput.addEventListener('input', (e) => {
                this.debounce(() => this.previewProduct(e.target.value), 300);
            });
        }

        // Cart action buttons
        const addToCartBtn = document.getElementById('add-to-cart-btn');
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', () => this.addToCart());
        }

        const clearCartBtn = document.getElementById('clear-cart-btn');
        if (clearCartBtn) {
            clearCartBtn.addEventListener('click', () => this.clearCart());
        }

        const processPaymentBtn = document.getElementById('process-payment-btn');
        if (processPaymentBtn) {
            processPaymentBtn.addEventListener('click', () => this.processPayment());
        }

        // Payment method selection
        const paymentMethodSelect = document.getElementById('payment-method');
        if (paymentMethodSelect) {
            paymentMethodSelect.addEventListener('change', (e) => {
                const processBtn = document.getElementById('process-payment-btn');
                if (processBtn) {
                    processBtn.disabled = !e.target.value || this.getCartItemCount() === 0;
                }
            });
        }

        // Cart item controls
        document.getElementById('cart-items-list')?.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-item')) {
                const index = parseInt(e.target.closest('.remove-item').dataset.index);
                this.removeCartItem(index);
            } else if (e.target.classList.contains('increase-qty')) {
                const index = parseInt(e.target.closest('.increase-qty').dataset.index);
                this.updateQuantity(index, 1);
            } else if (e.target.classList.contains('decrease-qty')) {
                const index = parseInt(e.target.closest('.decrease-qty').dataset.index);
                this.updateQuantity(index, -1);
            }
        });

        // Sales report form submission
        const salesReportForm = document.getElementById('sales-report-form');
        if (salesReportForm) {
            salesReportForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                await this.loadSalesReport(startDate, endDate);
            });
        }

        // Download CSV button
        const downloadCsvBtn = document.getElementById('download-csv-btn');
        if (downloadCsvBtn) {
            downloadCsvBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                window.location.href = `?ajax=sales_report_data&start_date=${startDate}&end_date=${endDate}&download=1`;
            });
        }

        // Confirm payment forms for pending orders
        document.getElementById('pending-orders-table')?.addEventListener('submit', async (e) => {
            if (e.target.classList.contains('confirm-payment-form')) {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData(form);
                formData.append('ajax', true);

                try {
                    const response = await fetch('pending_orders.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();

                    if (data.status === 'success') {
                        this.showToast(data.message, 'success');
                        const orderRow = form.closest('tr');
                        if (orderRow) {
                            orderRow.remove();
                            const pendingCount = document.getElementById('pending-count');
                            if (pendingCount) {
                                pendingCount.textContent = parseInt(pendingCount.textContent) - 1;
                            }
                            const totalPending = document.getElementById('total-pending-amount');
                            if (totalPending) {
                                let currentTotal = parseFloat(totalPending.textContent.replace(/[^0-9.-]+/g, '')) || 0;
                                currentTotal -= data.order_total;
                                totalPending.textContent = currentTotal.toLocaleString('en-US', {minimumFractionDigits: 2});
                            }
                        }
                        this.refreshStats();
                        // Trigger refresh of completed orders
                        if (this.currentSection === 'completed-orders') {
                            this.loadCompletedOrders();
                        }
                    } else {
                        this.showToast(data.message, 'error');
                    }
                } catch (error) {
                    console.error('Error confirming payment:', error);
                    this.showToast('An error occurred.', 'error');
                }
            }
        });

        document.addEventListener('click', e => {
            const btn = e.target.closest('.mark-collected');
            if (btn) {
                e.preventDefault();
                const orderId = btn.dataset.orderId;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';
                this.markOrderAsCollected(orderId).finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-box"></i> Mark as Collected';
                });
            }
        });

        // Modal logic
        document.body.addEventListener('click', async function(e) {
            const btn = e.target.closest('.view-order-details');
            if (btn) {
                e.preventDefault();
                const orderId = btn.getAttribute('data-order-id');
                const modal = document.getElementById('order-details-modal');
                const modalBody = document.getElementById('order-details-body');
                modalBody.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
                modal.style.display = 'flex';

                // Fetch order details via AJAX
                try {
                    const resp = await fetch(`orders_manager.php?action=order_details&order_id=${orderId}`);
                    const data = await resp.json();
                    if (data.status === 'success') {
                        let html = `<h2>Order #${data.order.order_id}</h2>`;
                        html += `<p><strong>Customer:</strong> ${data.order.customer}</p>`;
                        html += `<p><strong>Amount:</strong> MWK${parseFloat(data.order.total).toLocaleString()}</p>`;
                        html += `<p><strong>Payment Method:</strong> ${data.order.payment_method}</p>`;
                        html += `<p><strong>Status:</strong> ${data.order.status}</p>`;
                        html += `<p><strong>Collected:</strong> ${data.order.collected}</p>`;
                        html += `<p><strong>Created At:</strong> ${data.order.created_at}</p>`;
                        html += `<p><strong>Updated At:</strong> ${data.order.updated_at}</p>`;
                        if (data.order.items && data.order.items.length) {
                            html += `<h3>Items</h3><ul>`;
                            data.order.items.forEach(item => {
                                html += `<li>${item.name} x${item.quantity} @ MWK${parseFloat(item.price).toLocaleString()}</li>`;
                            });
                            html += `</ul>`;
                        }
                        modalBody.innerHTML = html;
                    } else {
                        modalBody.innerHTML = `<div style="color:red;">${data.message || 'Failed to load order details.'}</div>`;
                    }
                } catch (err) {
                    modalBody.innerHTML = `<div style="color:red;">Error loading order details.</div>`;
                }
            }
            // Close modal
            if (e.target.id === 'close-order-modal') {
                document.getElementById('order-details-modal').style.display = 'none';
            }
        });

        // Optional: Close modal on outside click
        document.getElementById('order-details-modal').addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + N: New Sale
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                this.showSection('new-sale');
            }

            // Ctrl/Cmd + R: Refresh Stats
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                this.refreshStats();
            }

            // Escape: Back to dashboard
            if (e.key === 'Escape' && this.currentSection !== 'dashboard') {
                this.showSection('dashboard');
            }
        });
    }

    showSection(sectionName) {
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });

        const targetSection = document.getElementById(`${sectionName}-section`);
        if (targetSection) {
            targetSection.classList.add('active');
            this.currentSection = sectionName;
        }

        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
        });

        const activeNavItem = document.querySelector(`.nav-item[href="#${sectionName}"]`) || 
                             document.querySelector(`.nav-item[onclick*="${sectionName}"]`);
        if (activeNavItem) {
            activeNavItem.classList.add('active');
        }

        if (sectionName === 'new-sale') {
            this.focusBarcodeInput();
        } else if (sectionName === 'pending-orders') {
            this.loadPendingOrders();
        } else if (sectionName === 'completed-orders') {
            this.loadCompletedOrders();
        } else if (sectionName === 'sales-report') {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            this.loadSalesReport(startDate, endDate);
        }
    }

    focusBarcodeInput() {
        setTimeout(() => {
            const barcodeInput = document.getElementById('barcode-input');
            if (barcodeInput && this.currentSection === 'new-sale') {
                barcodeInput.focus();
            }
        }, 100);
    }

    async previewProduct(barcode) {
        if (!barcode || barcode.length < 3) {
            this.hideProductPreview();
            return;
        }

        try {
            const response = await fetch(`?ajax=product_details&barcode=${encodeURIComponent(barcode)}`);
            const data = await response.json();

            if (data.status === 'success') {
                this.showProductPreview(data.data);
            } else {
                this.hideProductPreview();
            }
        } catch (error) {
            console.error('Error fetching product details:', error);
            this.hideProductPreview();
        }
    }

    showProductPreview(product) {
        const preview = document.getElementById('product-preview');
        const nameEl = document.getElementById('product-name');
        const priceEl = document.getElementById('product-price');
        const categoryEl = document.getElementById('product-category');

        if (preview && nameEl && priceEl && categoryEl) {
            nameEl.textContent = product.name;
            priceEl.textContent = `MWK${parseFloat(product.price).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
            categoryEl.textContent = product.category;
            preview.style.display = 'block';
        }
    }

    hideProductPreview() {
        const preview = document.getElementById('product-preview');
        if (preview) {
            preview.style.display = 'none';
        }
    }

    async addToCart() {
        const barcodeInput = document.getElementById('barcode-input');
        const barcode = barcodeInput?.value.trim();

        if (!barcode) {
            this.showToast('Please enter a barcode', 'warning');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('ajax', 'add_to_cart');
            formData.append('csrf_token', this.csrfToken);
            formData.append('barcode', barcode);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.status === 'success') {
                this.showToast(data.message, 'success');
                this.updateCartDisplay();
                this.updateCartTotal(data.cart_total);
                this.updateCartBadge(data.cart_count);
                barcodeInput.value = '';
                this.hideProductPreview();
                this.focusBarcodeInput();
            } else {
                this.showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Error adding to cart:', error);
            this.showToast('Error adding product to cart', 'error');
        }
    }

    async removeCartItem(index) {
        try {
            const formData = new FormData();
            formData.append('ajax', 'cart_operation');
            formData.append('operation', 'remove_item');
            formData.append('item_index', index);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.status === 'success') {
                this.showToast(data.message, 'success');
                this.updateCartDisplay();
                this.updateCartTotal(data.cart_total);
                this.updateCartBadge(data.cart_count);
            } else {
                this.showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Error removing cart item:', error);
            this.showToast('Error removing item from cart', 'error');
        }
    }

    async updateQuantity(index, change) {
        const cartItem = document.querySelector(`.cart-item[data-index="${index}"]`);
        const quantityEl = cartItem?.querySelector('.quantity');
        const currentQty = parseInt(quantityEl?.textContent || '0');
        const newQty = currentQty + change;

        if (newQty < 1) {
            this.removeCartItem(index);
            return;
        }

        try {
            const formData = new FormData();
            formData.append('ajax', 'cart_operation');
            formData.append('operation', 'update_quantity');
            formData.append('item_index', index);
            formData.append('quantity', newQty);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.status === 'success') {
                this.updateCartDisplay();
                this.updateCartTotal(data.cart_total);
                this.updateCartBadge(data.cart_count);
            } else {
                this.showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Error updating quantity:', error);
            this.showToast('Error updating quantity', 'error');
        }
    }

    async processPayment() {
        const paymentMethod = document.getElementById('payment-method').value;
        if (!paymentMethod || this.getCartItemCount() === 0) {
            this.showToast('Please select a payment method and add items to the cart', 'warning');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('ajax', 'process_payment');
            formData.append('csrf_token', this.csrfToken);
            formData.append('payment_method', paymentMethod);

            const response = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.status === 'success') {
                this.showToast(data.message, 'success');
                this.clearCart();
                this.updateCartTotal(0);
                this.updateCartBadge(0);
                this.showSection('dashboard');
                this.refreshPendingOrdersData();
                this.refreshCompletedOrdersData();
            } else if (data.status === 'pending' && paymentMethod === 'Mobile Transfer') {
                // Initiate PayChangu payment
                const email = window.sessionUserEmail || 'zechaliahjmbuwa99@gmail.com';
                const firstName = window.sessionUserFirstName || 'CASHIER';
                const lastName = window.sessionUserLastName || 'A';
                makePayment({
                    public_key: "PUB-2Gv1hPxqu1KqhbH3VJ3886NlQxEZeXji",
                    tx_ref: data.tx_ref,
                    amount: parseFloat(data.total.replace(/,/g, '')),
                    currency: "MWK",
                    callback_url: "http://localhost/EdahhPos/cashier/callback.php",
                    return_url: "http://localhost/EdahhPos/cashier/return.php",
                    email: email,
                    first_name: firstName,
                    last_name: lastName,
                    title: "Auntie Eddah POS Payment",
                    description: "Payment for your order at Auntie Eddah POS",
                    uuid: (window.sessionUUID || 'uuid')
                });
            } else {
                this.showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Error processing payment:', error);
            this.showToast('Error processing payment', 'error');
        }
    }

    async loadPendingOrders() {
        try {
            const response = await fetch('orders_manager.php?action=pending');
            const data = await response.json();

            // keep existing rendering logic but use data.data as the orders array
            if (data.status === 'success') {
                // call the same rendering code you already have, replace any previous fetch usage
                // For brevity here assume your existing code uses 'data.data' — keep it
                // ...existing pending orders rendering code...
            } else {
                this.showToast(data.message || 'Failed to load pending orders', 'error');
            }
        } catch (error) {
            console.error('Error loading pending orders:', error);
            this.showToast('Error loading pending orders: ' + error.message, 'error');
        }
    }

    async refreshDashboardStats() {
        try {
            const resp = await fetch('dashboard.php?ajax=stats');
            const data = await resp.json();
            if (data.status === 'success' && data.stats) {
                const s = data.stats;
                const el = id => document.getElementById(id);
                if (el('orders-today')) el('orders-today').textContent = s.orders_today;
                if (el('total-orders')) el('total-orders').textContent = s.total_orders;
                if (el('pending-count')) el('pending-count').textContent = s.pending_payments;
                if (el('completed-count')) el('completed-count').textContent = s.completed;
                if (el('transactions-count')) el('transactions-count').textContent = s.successful_transactions;
                if (el('total-sales')) el('total-sales').textContent = 'MWK' + Number(s.total_sales).toLocaleString();
                if (el('revenue-today')) el('revenue-today').textContent = 'MWK' + Number(s.revenue_today).toLocaleString();
            }
        } catch (err) {
            console.error('Failed to refresh dashboard stats', err);
        }
    }

    async refreshPendingOrdersData() {
        try {
            const resp = await fetch('dashboard.php?ajax=pending_orders_data');
            const data = await resp.json();
            if (data.status === 'success') {
                this.renderPendingOrdersTable(data.data || []);
            }
        } catch (err) {
            console.error('Failed to refresh pending orders', err);
        }
    }

    async refreshCompletedOrdersData() {
        try {
            const resp = await fetch('dashboard.php?ajax=completed_orders_data');
            const data = await resp.json();
            if (data.status === 'success') {
                this.renderCompletedOrdersTable(data.data || []);
            }
        } catch (err) {
            console.error('Failed to refresh completed orders', err);
        }
    }

    renderPendingOrdersTable(orders) {
        const tbody = document.querySelector('#pending-orders-table tbody');
        const badge = document.getElementById('pending-count');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!orders.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">No pending orders</td></tr>';
            if (badge) badge.textContent = '0';
            return;
        }
        orders.forEach(o => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>#${o.order_id}</td>
                <td>MWK ${Number(o.total_amount || o.total).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                <td>${o.payment_method}</td>
                <td>${o.created_at}</td>
                <td>
                    <button class="btn btn-sm btn-primary mark-collected" data-id="${o.order_id}">Mark Collected</button>
                    <a href="order_details.php?id=${o.order_id}" class="btn btn-sm btn-info">View</a>
                </td>
            `;
            tbody.appendChild(tr);
        });
        if (badge) badge.textContent = orders.length;
        // bind mark collected
        tbody.querySelectorAll('.mark-collected').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const id = btn.getAttribute('data-id');
                await this.markOrderCollected(id);
            });
        });
    }

    renderCompletedOrdersTable(orders) {
        const tbody = document.querySelector('#completed-orders-table tbody');
        const badge = document.getElementById('completed-count');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!orders.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center">No completed orders</td></tr>';
            if (badge) badge.textContent = '0';
            return;
        }
        orders.forEach(o => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>#${o.order_id}</td>
                <td>MWK ${Number(o.total_amount || o.total).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                <td>${o.payment_method}</td>
                <td>${o.created_at}</td>
                <td>
                    <a href="order_details.php?id=${o.order_id}" class="btn btn-sm btn-info">View</a>
                    <button class="btn btn-sm btn-secondary print-order" data-id="${o.order_id}">Print</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        if (badge) badge.textContent = orders.length;
    }

    async markOrderCollected(orderId) {
        try {
            const form = new FormData();
            form.append('ajax', 'mark_collected');
            form.append('order_id', orderId);
            form.append('csrf_token', window.csrfToken || '');

            const resp = await fetch('dashboard.php', { method: 'POST', body: form });
            const data = await resp.json();
            if (data.status === 'success') {
                this.showToast(data.message || 'Order marked collected', 'success');
                await this.refreshPendingOrdersData();
                await this.refreshCompletedOrdersData();
                await this.refreshDashboardStats();
            } else {
                this.showToast(data.message || 'Failed to mark collected', 'error');
            }
        } catch (err) {
            console.error('Error marking collected', err);
            this.showToast('Error marking order collected', 'error');
        }
    }

    async loadCompletedOrders() {
        try {
            const response = await fetch('orders_manager.php?action=completed');
            const data = await response.json();
            if (data.status === 'success') {
                // ...existing completed orders rendering code...
            } else {
                this.showToast(data.message || 'Failed to load completed orders', 'error');
            }
        } catch (error) {
            console.error('Error loading completed orders:', error);
            this.showToast('Error loading completed orders: ' + error.message, 'error');
        }
    }

    async loadSalesReport(startDate, endDate) {
        try {
            const response = await fetch(`?ajax=sales_report_data&start_date=${startDate}&end_date=${endDate}`);
            const data = await response.json();

            const salesReportContent = document.getElementById('sales-report-content');
            if (salesReportContent) {
                if (data.status === 'success' && data.data.length > 0) {
                    let tableHtml = `
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Orders</th>
                                        <th>Total Sales (MWK)</th>
                                        <th>Payment Method</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    let totalSales = 0;
                    data.data.forEach(row => {
                        totalSales += parseFloat(row.total_sales);
                        tableHtml += `
                            <tr>
                                <td>${row.sale_date}</td>
                                <td>${row.orders_count}</td>
                                <td>${parseFloat(row.total_sales).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                <td>${this.capitalizeFirstLetter(row.payment_method.replace('_', ' '))}</td>
                            </tr>
                        `;
                    });
                    tableHtml += `
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 20px; text-align: center;">
                            <p><strong>Total Sales: MWK${totalSales.toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></p>
                        </div>
                    `;
                    salesReportContent.innerHTML = tableHtml;
                } else {
                    salesReportContent.innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <i class="fas fa-inbox" style="font-size: 4rem; color: var(--secondary-color); margin-bottom: 20px;"></i>
                            <h3>No Sales Data</h3>
                            <p>No sales recorded for the selected period.</p>
                        </div>
                    `;
                }
            }
        } catch (error) {
            console.error('Error loading sales report:', error);
            this.showToast('Error loading sales report', 'error');
        }
    }

    startAutoRefresh() {
        setInterval(() => {
            this.refreshStats();
            if (this.currentSection === 'pending-orders') {
                this.loadPendingOrders();
            }
            if (this.currentSection === 'completed-orders') {
                this.loadCompletedOrders();
            }
        }, 30000);

        setInterval(() => {
            dashboard.refreshPendingOrdersData();
            dashboard.refreshCompletedOrdersData();
        }, 30000);
    }

    showToast(message, type = 'info') {
        const notificationContainer = document.getElementById('notification-container');
        if (!notificationContainer) return;

        const toast = document.createElement('div');
        toast.className = `notification ${type} animate__animated animate__fadeInDown`;
        toast.innerHTML = `<i class="fas fa-info-circle"></i> ${message}`;
        notificationContainer.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('animate__fadeOutUp');
            toast.addEventListener('animationend', () => toast.remove());
        }, 5000);
    }

    debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    updateCartDisplay() {
        // Placeholder for cart display update
    }

    updateCartTotal(total) {
        const totalEl = document.getElementById('cart-total');
        if (totalEl) {
            totalEl.textContent = `MWK${total.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
        }
    }

    updateCartBadge(count) {
        const badgeEl = document.getElementById('cart-count');
        if (badgeEl) {
            badgeEl.textContent = count;
        }
    }

    clearCart() {
        this.cart = [];
        this.updateCartDisplay();
        this.updateCartTotal(0);
        this.updateCartBadge(0);
    }

    getCartItemCount() {
        return this.cart.length;
    }

    async refreshStats() {
        try {
            const resp = await fetch('orders_manager.php?action=stats');
            const json = await resp.json();
            if (json.status === 'success' && json.data) {
                const s = json.data;
                const pendingCount = document.getElementById('pending-count');
                const completedCount = document.getElementById('completed-count');
                const totalPendingAmount = document.getElementById('total-pending-amount');
                const totalCompletedAmount = document.getElementById('total-completed-amount');

                if (pendingCount && typeof s.pending_count !== 'undefined') pendingCount.textContent = s.pending_count;
                if (completedCount && typeof s.completed_count !== 'undefined') completedCount.textContent = s.completed_count;
                if (totalPendingAmount && typeof s.total_pending !== 'undefined') totalPendingAmount.textContent = `MWK${parseFloat(s.total_pending).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                if (totalCompletedAmount && typeof s.total_completed !== 'undefined') totalCompletedAmount.textContent = `MWK${parseFloat(s.total_completed).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
            }
        } catch (err) {
            console.error('Error refreshing stats:', err);
        }
    }

    async markOrderAsCollected(orderId) {
        try {
            const formData = new FormData();
            formData.append('ajax', 'mark_collected');
            formData.append('order_id', orderId);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

            // FIX: Use the correct endpoint for marking as collected
            const response = await fetch('orders_manager.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                this.showToast(data.message, 'success');
                this.loadCompletedOrders();
                this.refreshPendingOrdersData();
                this.refreshStats();
            } else {
                this.showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Error marking order as collected:', error);
            this.showToast('Error marking order as collected', 'error');
        }
    }
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.dashboard = new CashierDashboard();
});

function makePayment(payment) {
    PaychanguCheckout({
        "public_key": payment.public_key,
        "tx_ref": payment.tx_ref,
        "amount": payment.amount,
        "currency": "MWK",
        "callback_url": payment.callback_url,
        "return_url": payment.return_url,
        "customer": {
            "email": payment.email,
            "first_name": payment.first_name,
            "last_name": payment.last_name
        },
        "customization": {
            "title": payment.title,
            "description": payment.description
        },
        "meta": {
            "uuid": payment.uuid,
            "response": "Response"
        }
    });
}