let cart = []; // { id, name, price, qty, stock }
let lastSaleId = null;

function renderCart() {
    const cartItemsEl = document.getElementById('cartItems');

    if (cart.length === 0) {
        cartItemsEl.innerHTML = '<p class="text-muted text-center py-4" id="emptyCartMsg">No items added yet.</p>';
    } else {
        cartItemsEl.innerHTML = cart.map((item, idx) => `
            <div class="cart-item d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold small">${escapeHtml(item.name)}</div>
                    <div class="text-muted small">&#8369;${item.price.toFixed(2)} x ${item.qty}</div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button class="btn btn-sm btn-outline-secondary qty-btn" onclick="changeQty(${idx}, -1)">-</button>
                    <span class="mx-1">${item.qty}</span>
                    <button class="btn btn-sm btn-outline-secondary qty-btn" onclick="changeQty(${idx}, 1)">+</button>
                    <button class="btn btn-sm btn-outline-danger qty-btn ms-1" onclick="removeItem(${idx})"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        `).join('');
    }

    updateTotals();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.innerText = str || '';
    return div.innerHTML;
}

function addToCart(product) {
    const existing = cart.find(i => i.id === product.id);
    if (existing) {
        if (existing.qty < product.stock) {
            existing.qty += 1;
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Stock Limit Reached',
                text: 'You have reached the available stock for this product.',
                confirmColor: '#0d6efd'
            });
        }
    } else {
        cart.push({ id: product.id, name: product.name, price: product.price, qty: 1, stock: product.stock, sku: product.sku || '' });
    }
    renderCart();
}

function changeQty(idx, delta) {
    const item = cart[idx];
    if (!item) return;
    const newQty = item.qty + delta;
    if (newQty < 1) {
        removeItem(idx);
        return;
    }
    if (newQty > item.stock) {
        Swal.fire({
            icon: 'warning',
            title: 'Stock Limit Reached',
            text: 'You have reached the available stock for this product.',
            confirmColor: '#0d6efd'
        });
        return;
    }
    item.qty = newQty;
    renderCart();
}

function removeItem(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;

    Swal.fire({
        title: 'Clear Cart?',
        text: 'Are you sure you want to clear the entire cart?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, clear it!'
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            renderCart();
        }
    });
}

function toggleDiscountFields() {
    const type = document.getElementById('discountType').value;
    const fields = document.getElementById('discountFields');
    if (fields) {
        fields.style.display = (type === 'none') ? 'none' : 'block';
    }
    updateTotals();
}

function getDiscountRate() {
    const type = document.getElementById('discountType').value;
    return (type === 'senior' || type === 'pwd') ? 0.20 : 0;
}

function updateTotals() {
    const subtotal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const rate = getDiscountRate();
    const discountAmount = subtotal * rate;
    const total = subtotal - discountAmount;

    document.getElementById('cartSubtotal').innerText = '\u20B1' + subtotal.toFixed(2);
    document.getElementById('cartTotal').innerText = '\u20B1' + total.toFixed(2);

    const discountRow = document.getElementById('discountRow');
    if (discountRow) {
        if (rate > 0) {
            discountRow.style.setProperty('display', 'flex', 'important');
            document.getElementById('cartDiscount').innerText = '-\u20B1' + discountAmount.toFixed(2);
        } else {
            discountRow.style.setProperty('display', 'none', 'important');
        }
    }

    calculateChange();
}

function calculateChange() {
    const subtotal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const rate = getDiscountRate();
    const total = subtotal - (subtotal * rate);
    const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
    const change = paid - total;
    document.getElementById('changeAmount').innerText = '\u20B1' + (change > 0 ? change.toFixed(2) : '0.00');
}

document.getElementById('amountPaid').addEventListener('input', calculateChange);

// Search / filter
function filterProducts() {
    const q = document.getElementById('searchBox').value.toLowerCase();
    const cat = document.getElementById('categoryFilter').value;
    document.querySelectorAll('.product-item').forEach(el => {
        const matchesSearch = el.dataset.name.includes(q) || el.dataset.sku.includes(q);
        const matchesCategory = !cat || el.dataset.category === cat;
        el.style.display = (matchesSearch && matchesCategory) ? '' : 'none';
    });
}
document.getElementById('searchBox').addEventListener('input', filterProducts);
document.getElementById('categoryFilter').addEventListener('change', filterProducts);

// Checkout
function checkout() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Empty Cart',
            text: 'The cart is empty. Please add items before checking out.',
            confirmColor: '#0d6efd'
        });
        return;
    }

    // Clean & aggregate current cart items to ensure strict payload structure
    const sanitizedCart = [];
    cart.forEach(item => {
        if (item.id && item.qty > 0) {
            sanitizedCart.push({
                id: parseInt(item.id, 10),
                name: item.name,
                price: parseFloat(item.price),
                qty: parseInt(item.qty, 10),
                sku: item.sku || ''
            });
        }
    });

    if (sanitizedCart.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Cart',
            text: 'Cart contains invalid item quantities.',
            confirmColor: '#0d6efd'
        });
        return;
    }

    const subtotal = sanitizedCart.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const discountType = document.getElementById('discountType').value;
    const discountIdNumberEl = document.getElementById('discountIdNumber');
    const discountNameEl = document.getElementById('discountName');
    const discountIdNumber = discountIdNumberEl ? discountIdNumberEl.value.trim() : '';
    const discountName = discountNameEl ? discountNameEl.value.trim() : '';
    const rate = getDiscountRate();
    const total = subtotal - (subtotal * rate);
    const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
    const paymentMethod = document.getElementById('paymentMethod').value;
    
    // KUHANIN ANG NAPILING KRAFT BAG OPTION MULA SA DROPDOWN
    const kraftBagOptionEl = document.getElementById('kraftBagOption');
    const kraftBagOption = kraftBagOptionEl ? kraftBagOptionEl.value : '';

    if (discountType !== 'none' && (discountIdNumber === '' || discountName === '')) {
        Swal.fire({
            icon: 'warning',
            title: 'Discount Details Required',
            text: 'Please enter the ID Number and Name for the Senior/PWD discount.',
            confirmColor: '#0d6efd'
        });
        return;
    }

    if (paid < total) {
        Swal.fire({
            icon: 'error',
            title: 'Insufficient Payment',
            text: 'Amount paid is less than the total amount.',
            confirmColor: '#0d6efd'
        });
        return;
    }

    const btn = document.getElementById('checkoutBtn');
    btn.disabled = true;
    btn.innerText = 'Processing...';

    const cartSnapshot = sanitizedCart.map(i => ({ ...i }));

    fetch('process_sale', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cart: sanitizedCart,
            paymentMethod,
            amountPaid: paid,
            discountType,
            discountIdNumber,
            discountName,
            kraftBagOption // ISINAMA ANG KRAFT BAG OPTION SA PAYLOAD
        })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Checkout';
        if (data.success) {
            showReceipt(data, cartSnapshot);

            // AUTOMATIC PRINTING FOR BOTH ANDROID & WINDOWS
            const saleDataForPrint = {
                invoice_no: data.invoice_no,
                created_at: new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) + ' ' + new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }),
                cashier_name: data.cashier_name || 'Cashier_1',
                payment_method: data.payment_method,
                subtotal_amount: data.subtotal,
                discount_type: data.discount_type,
                discount_amount: data.discount_amount,
                total_amount: data.total,
                amount_paid: data.amount_paid,
                change_amount: data.change,
                items: cartSnapshot.map(i => ({
                    name: i.name,
                    qty: i.qty,
                    subtotal: i.price * i.qty
                }))
            };

            const isAndroid = /Android/i.test(navigator.userAgent);
            if (isAndroid) {
                if (typeof printCheckoutReceipts === 'function') {
                    printCheckoutReceipts(saleDataForPrint);
                }
            } else {
                // Windows Automatic Print Trigger
                setTimeout(() => {
                    window.print();
                }, 500);
            }
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Transaction Error',
                text: data.message || 'Unable to process the transaction.',
                confirmColor: '#0d6efd'
            });
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Checkout';
        Swal.fire({
            icon: 'error',
            title: 'Connection Error',
            text: err.toString(),
            confirmColor: '#0d6efd'
        });
    });
}

function showReceipt(data, items) {
    lastSaleId = data.sale_id;

    const now = new Date();
    const dateFormatted = now.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) + 
                          ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

    const receiptBody = document.getElementById('receiptBody');
    receiptBody.dataset.receiptData = JSON.stringify({
        invoiceNo: data.invoice_no,
        dateFormatted: dateFormatted,
        cashier: data.cashier_name || 'Cashier_1',
        paymentMethod: data.payment_method,
        items: items,
        subtotal: data.subtotal,
        discountType: data.discount_type,
        discountAmount: data.discount_amount,
        total: data.total,
        amountPaid: data.amount_paid,
        change: data.change
    });

    const itemsHtml = items.map(i => `
        <tr>
            <td style="padding: 2px 0;">
                <div>${escapeHtml(i.name)}</div>
                ${i.sku ? `<div style="font-size: 0.85em;">\${escapeHtml(i.sku)}</div>` : ''}
            </td>
            <td class="text-center" style="padding: 2px 0;">${i.qty}</td>
            <td class="text-end" style="padding: 2px 0;">${(i.price * i.qty).toFixed(2)}</td>
        </tr>
    `).join('');

    const discountHtml = data.discount_amount > 0
        ? `<div class="d-flex justify-content-between"><span>Discount (${escapeHtml(data.discount_type)})</span><span>-${data.discount_amount.toFixed(2)}</span></div>`
        : '';

    // Tingnan kung may BUSINESS_LOGO variable/element sa pahina
    const logoUrl = typeof window.BUSINESS_LOGO !== 'undefined' ? window.BUSINESS_LOGO : (document.getElementById('businessLogoPath')?.value || '');
    const logoHtml = logoUrl ? `<div class="text-center mb-2"><img src="${logoUrl}" alt="Logo" style="max-width: 120px; height: auto; object-fit: contain;"></div>` : '';

    receiptBody.innerHTML = `
        <div class="text-center font-monospace" style="font-size: 13px; line-height: 1.3;">
            ${logoHtml}
            <div class="mb-2">
                <small>ORDER</small>
                <div class="fw-bold fs-5">Receipt #${data.invoice_no}</div>
            </div>
            <div class="mb-2">
                <div>Guaranteed the Best.</div>
                <div>Robinsons Starmills Pampanga</div>
                <div>${dateFormatted}</div>
            </div>
            <div class="text-start my-2">
                ----------------------------<br>
                Cashier &nbsp;: ${escapeHtml(data.cashier_name || 'Cashier_1')}<br>
                Payment : ${escapeHtml(data.payment_method)}<br>
                ----------------------------
            </div>
            <table class="w-100 text-start font-monospace" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th style="font-weight: normal;">ITEM</th>
                        <th class="text-center" style="font-weight: normal;">QTY</th>
                        <th class="text-end" style="font-weight: normal;">COST</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>
            <div class="text-start my-2">
                ----------------------------
            </div>
            <div class="text-start font-monospace">
                <div class="d-flex justify-content-between"><span>Sub Total</span><span>${data.subtotal.toFixed(2)}</span></div>
                ${discountHtml}
                <div class="text-start my-1">----------------------------</div>
                <div class="d-flex justify-content-between fw-bold fs-6"><span>Order Total</span><span>&#8369;${data.total.toFixed(2)}</span></div>
                <div class="d-flex justify-content-between"><span>Paid (${data.payment_method})</span><span>${data.amount_paid.toFixed(2)}</span></div>
                <div class="d-flex justify-content-between"><span>Change</span><span>${data.change.toFixed(2)}</span></div>
            </div>
            <div class="mt-3">
                ****************************<br>
                <strong class="fs-6">THANK YOU!</strong><br>
                ****************************<br>
                Please come again!<br>
                <small style="font-size: 10px;">Powered by JDTech Solutions</small>
            </div>
        </div>
    `;

    const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
    modal.show();

    cart = [];
    renderCart();
    document.getElementById('amountPaid').value = '';
    document.getElementById('discountType').value = 'none';
    if (document.getElementById('discountIdNumber')) document.getElementById('discountIdNumber').value = '';
    if (document.getElementById('discountName')) document.getElementById('discountName').value = '';
    toggleDiscountFields();
}

// HYBRID PRINTING (Android RawBT + Windows Browser Print)
function printReceipt(type) {
    const isAndroid = /Android/i.test(navigator.userAgent);

    if (!isAndroid) {
        // Windows Desktop / Browser Print
        window.print();
        return;
    }

    // Android RawBT Text Print
    const receiptBody = document.getElementById('receiptBody');
    if (!receiptBody || !receiptBody.dataset.receiptData) {
        Swal.fire({
            icon: 'info',
            title: 'No Receipt',
            text: 'No receipt data to print.',
            confirmColor: '#0d6efd'
        });
        return;
    }

    const d = JSON.parse(receiptBody.dataset.receiptData);

    const W = 28;
    const line = (str = '') => str + '\n';
    const center = (str) => {
        let pad = Math.floor((W - str.length) / 2);
        return ' '.repeat(pad > 0 ? pad : 0) + str;
    };
    const row = (left, right) => {
        let space = W - left.length - right.length;
        if (space < 1) space = 1;
        return left + ' '.repeat(space) + right;
    };

    let p = '';

    p += line(center('============================'));
    p += line(center('*** WRAP NATICS ***'));
    p += line(center('SHAWARMA STATION'));
    p += line(center('============================'));
    p += line();
    p += line(center('ORDER'));
    p += line(center('Receipt #' + d.invoiceNo));
    p += line();
    p += line(center('Guaranteed the Best.'));
    p += line(center('Robinsons Starmills Pampanga'));
    p += line(center(d.dateFormatted));
    p += line('----------------------------');

    p += line('Cashier : ' + d.cashier);
    p += line('Payment : ' + d.paymentMethod);
    p += line('----------------------------');

    p += line('ITEM            QTY    COST');
    p += line('----------------------------');

    d.items.forEach(i => {
        let nameLine = i.name;
        if (nameLine.length > 15) nameLine = nameLine.substring(0, 15);
        let qtyStr = String(i.qty).padStart(3, ' ');
        let costStr = (i.price * i.qty).toFixed(2).padStart(8, ' ');
        
        p += line(row(nameLine, qtyStr + costStr));
        if (i.sku) {
            p += line(i.sku);
        }
    });

    p += line('----------------------------');

    p += line(row('Sub Total', d.subtotal.toFixed(2)));
    if (d.discountAmount > 0) {
        p += line(row(`Discount (${d.discountType})`, '-' + d.discountAmount.toFixed(2)));
    }
    p += line('----------------------------');
    p += line(row('Order Total', 'P' + d.total.toFixed(2)));
    p += line(row(`Paid (${d.paymentMethod})`, d.amountPaid.toFixed(2)));
    p += line(row('Change', d.change.toFixed(2)));
    p += line();

    p += line('****************************');
    p += line(center('THANK YOU!'));
    p += line('****************************');
    p += line(center('Please come again!'));
    p += line(center('Powered by JDTech Solutions'));
    p += line('\n\n');

    let base64Data = btoa(encodeURIComponent(p).replace(/%([0-9A-F]{2})/g, (match, p1) => String.fromCharCode('0x' + p1)));
    window.location.href = "rawbt:base64," + base64Data;
}