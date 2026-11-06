<?php
$pageTitle = 'POS';
require 'includes/header.php';
require 'includes/shift_guard.php';

$userId = (int)$_SESSION['user_id'];

// 1. Suriin kung naka-close na ang shift ng cashier para sa kasalukuyang araw
$stmtToday = $pdo->prepare("SELECT id FROM shift_closings WHERE user_id = ? AND DATE(created_at) = CURDATE() LIMIT 1");
$stmtToday->execute([$userId]);
$isShiftClosedToday = (bool)$stmtToday->fetchColumn();

// 2. Kuhanin ang dahilan mula sa shift guard (unclosed past shift o closed today)
$blockReason = getShiftCheckoutBlockReason($pdo, $userId);

// Kung naka-close na ngayong araw at wala pang naitatakdang dahilan sa guard, maglagay ng mensahe
if ($isShiftClosedToday && !$blockReason) {
    $blockReason = 'Naka-close na ang shift mo ngayong araw. Humingi ng tulong sa Admin kung kinakailangang buksan ulit ito.';
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT * FROM products ORDER BY name")->fetchAll();
?>

<!-- Pass BUSINESS_LOGO constant to Javascript -->
<script>
    window.BUSINESS_LOGO = "<?= defined('BUSINESS_LOGO') ? htmlspecialchars(BUSINESS_LOGO) : '' ?>";
</script>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<!-- CSS para sa Windows Thermal Printing (80mm) -->
<style>
@media print {
    body * {
        visibility: hidden;
    }
    .no-print {
        display: none !important;
    }
    #receiptModal, #receiptModal * {
        visibility: visible;
    }
    #receiptModal {
        position: absolute;
        left: 0;
        top: 0;
        width: 80mm;
        margin: 0;
        padding: 0;
    }
    #receiptModal .modal-dialog {
        max-width: 80mm;
        margin: 0;
    }
    #receiptModal .modal-content {
        border: none;
        box-shadow: none;
    }
    #receiptModal .modal-body {
        padding: 0;
        font-family: monospace;
        font-size: 11px;
        color: #000;
        line-height: 1.1;
    }
    #receiptModal .modal-body p, 
    #receiptModal .modal-body div {
        margin: 0 !important;
        padding: 0 !important;
    }
    .receipt-logo-img {
        display: block !important;
        visibility: visible !important;
        max-width: 120px !important;
        height: auto !important;
        margin: 0 auto 5px auto !important;
    }
}
</style>

<?php if ($blockReason): ?>

<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-lock-fill text-danger" style="font-size:2.5rem;"></i>
        <h4 class="mt-3">Checkout Locked</h4>
        <p class="text-muted mb-4"><?= htmlspecialchars($blockReason) ?></p>
        <a href="shift_closing" class="btn btn-primary"><i class="bi bi-cash-stack"></i> Go to Shift Closing</a>
    </div>
</div>

<?php else: ?>

<div class="row g-3 no-print">
    <!-- Products grid -->
    <div class="col-lg-8">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <input type="text" id="searchBox" class="form-control" style="max-width:280px;" placeholder="Search product or scan barcode...">
            <select id="categoryFilter" class="form-select" style="max-width:200px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row g-3" id="productGrid">
            <?php foreach ($products as $p): ?>
            <div class="col-6 col-md-4 col-xl-3 product-item" data-category="<?= $p['category_id'] ?>" data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>" data-sku="<?= strtolower(htmlspecialchars($p['sku'])) ?>">
                <div class="card pos-product-card h-100" onclick='addToCart(<?= json_encode(["id"=>$p['id'], "name"=>$p['name'], "price"=>(float)$p['price'], "stock"=>999999]) ?>)'>
                    <div class="card-body text-center">
                        <?php if (!empty($p['image'])): ?>
                            <img src="<?= htmlspecialchars($p['image']) ?>" class="pos-product-img mb-2" alt="">
                        <?php else: ?>
                            <div class="mb-2 text-primary" style="font-size:1.8rem;"><i class="bi bi-basket2-fill"></i></div>
                        <?php endif; ?>
                        <div class="fw-semibold small"><?= htmlspecialchars($p['name']) ?></div>
                        <?php if (!empty($p['description'])): ?>
                            <div class="text-muted product-desc"><?= htmlspecialchars($p['description']) ?></div>
                        <?php endif; ?>
                        <div class="text-primary fw-bold">&#8369;<?= number_format($p['price'], 2) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Cart -->
    <div class="col-lg-4">
        <div class="cart-panel p-3 d-flex flex-column">
            <h5 class="mb-3"><i class="bi bi-cart3"></i> Current Order</h5>
            <div id="cartItems" style="max-height: 360px; overflow-y:auto;">
                <p class="text-muted text-center py-4" id="emptyCartMsg">No items added yet.</p>
            </div>

            <hr>
            <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Subtotal</span>
                <span id="cartSubtotal">&#8369;0.00</span>
            </div>
            <div class="d-flex justify-content-between mb-2 text-danger" id="discountRow" style="display:none !important;">
                <span>Discount (20%)</span>
                <span id="cartDiscount">-&#8369;0.00</span>
            </div>
            <div class="d-flex justify-content-between mb-3 fs-5 fw-bold">
                <span>Total</span>
                <span id="cartTotal">&#8369;0.00</span>
            </div>

            <div class="mb-2">
                <label class="form-label small">Discount</label>
                <select id="discountType" class="form-select" onchange="toggleDiscountFields()">
                    <option value="none">None</option>
                    <option value="senior">Senior Citizen (20%)</option>
                    <option value="pwd">PWD (20%)</option>
                </select>
            </div>
            <div id="discountFields" style="display:none;">
                <div class="mb-2">
                    <label class="form-label small">ID Number</label>
                    <input type="text" id="discountIdNumber" class="form-control" placeholder="Senior/PWD ID number">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Name on ID</label>
                    <input type="text" id="discountName" class="form-control" placeholder="Full name as shown on ID">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label small">Payment Method</label>
                <select id="paymentMethod" class="form-select">
                    <option value="Cash">Cash</option>
                    <option value="GCash">GCash</option>
                    <option value="GoTyme">GoTyme</option>
                    <option value="Card">Card</option>
                    <option value="Grab">Grab</option>
                    <option value="FoodPanda">FoodPanda</option>
                    <option value="Wrapnatics Account">Wrapnatics Account</option>
                </select>
            </div>

            <!-- Mandatory Kraft Bag Option Field for Shawarma Orders -->
            <div class="mb-2" id="kraftBagContainer">
                <label class="form-label small fw-semibold" id="kraftLabel">Kraft Bag Option <span class="text-danger">*</span></label>
                <select id="kraftBagOption" class="form-select">
                    <option value="" selected disabled>-- Select Kraft Bag Option --</option>
                    <option value="small">Kraft Bag (Small)</option>
                    <option value="big">Kraft Bag (Big)</option>
                    <option value="none">No Kraft Bag (Dine-in / Direct Serve)</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label small">Amount Paid (&#8369;)</label>
                <input type="number" step="0.01" id="amountPaid" class="form-control" placeholder="0.00">
            </div>
            <div class="d-flex justify-content-between mb-3">
                <span class="text-muted">Change</span>
                <span id="changeAmount" class="fw-semibold">&#8369;0.00</span>
            </div>

            <button class="btn btn-success btn-lg w-100" id="checkoutBtn" onclick="handleCheckoutValidation()">
                <i class="bi bi-check-circle"></i> Checkout
            </button>
            <button class="btn btn-outline-danger w-100 mt-2" onclick="clearCart()">
                <i class="bi bi-x-circle"></i> Clear Cart
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header no-print">
                <h5 class="modal-title">Transaction Successful <i class="bi bi-check-circle-fill text-success"></i></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="receiptBody"></div>
            <div class="modal-footer no-print">
                <button class="btn btn-outline-primary" onclick="triggerPrint()"><i class="bi bi-printer"></i> Print Receipt</button>
                <button class="btn btn-primary" data-bs-dismiss="modal" onclick="location.reload()">New Order</button>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Kraft Bag Validation Script -->
<script>
function hasShawarmaInCart() {
    if (typeof cart === 'undefined' || !Array.isArray(cart)) return false;
    return cart.some(item => {
        const name = (item.name || '').toLowerCase();
        return name.includes('shawarma');
    });
}

function handleCheckoutValidation() {
    const isShawarmaOrdered = hasShawarmaInCart();
    const bagSelect = document.getElementById('kraftBagOption');
    const selectedBag = bagSelect ? bagSelect.value : '';

    if (isShawarmaOrdered && !selectedBag) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Kraft Bag Required',
                text: 'Pumili ng Kraft Bag Option (Small, Big, o No Kraft Bag) bago mag-checkout para sa order na may Shawarma.',
                confirmButtonColor: '#0d6efd'
            });
        } else {
            alert('Pumili ng Kraft Bag Option bago mag-checkout para sa order na may Shawarma.');
        }
        return;
    }

    if (typeof checkout === 'function') {
        checkout();
    }
}
</script>

<!-- Hybrid Printing Script (Android RawBT + Windows Browser Print) -->
<script>
let lastSaleData = null;

function printCheckoutReceipts(saleData) {
    if (!saleData) return;
    lastSaleData = saleData;

    const isAndroid = /Android/i.test(navigator.userAgent);

    if (isAndroid) {
        // Android Device (RawBT Direct Bluetooth/USB Thermal Print)
        const W = 32;
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

        const divider = '-'.repeat(W);
        const starDivider = '*'.repeat(W);

        let printText = '';
        printText += line(center('*** WRAP NATICS ***'));
        printText += line(center('SHAWARMA STATION'));
        printText += line(center('ORDER RECEIPT'));
        printText += line(center('Receipt #' + (saleData.invoice_no || '')));
        printText += line(center('Guaranteed the Best.'));
        printText += line(center('Robinsons Starmills Pampanga'));
        printText += line(center(saleData.created_at || ''));
        printText += line(divider);
        printText += line(row('Cashier:' + (saleData.cashier_name || 'Cashier'), 'Pay:' + (saleData.payment_method || 'Cash')));
        printText += line(divider);
        printText += line(row('ITEM', 'QTY   COST'));
        printText += line(divider);

        if (Array.isArray(saleData.items)) {
            saleData.items.forEach(i => {
                let nameLine = i.name.length > 16 ? i.name.substring(0, 16) : i.name;
                let qtyStr = String(i.qty).padStart(4, ' ');
                let costStr = (parseFloat(i.subtotal)).toFixed(2).padStart(8, ' ');
                printText += line(row(nameLine, qtyStr + costStr));
            });
        }

        printText += line(divider);
        printText += line(row('Sub Total', 'P' + parseFloat(saleData.subtotal_amount || 0).toFixed(2)));
        if (parseFloat(saleData.discount_amount || 0) > 0) {
            printText += line(row('Discount(' + (saleData.discount_type || 'disc') + ')', '-P' + parseFloat(saleData.discount_amount).toFixed(2)));
        }
        printText += line(divider);
        printText += line(row('Order Total', 'P' + parseFloat(saleData.total_amount || 0).toFixed(2)));
        printText += line(row('Paid (' + (saleData.payment_method || 'Cash') + ')', 'P' + parseFloat(saleData.amount_paid || 0).toFixed(2)));
        printText += line(row('Change', 'P' + parseFloat(saleData.change_amount || 0).toFixed(2)));
        printText += line(starDivider);
        printText += line(center('THANK YOU!'));
        printText += line(starDivider);
        printText += line(center('Please come again!'));
        printText += line(center('Powered by JDTech Solutions'));
        printText += line('\n');

        function utf8ToBase64(str) {
            return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, function(match, p1) {
                return String.fromCharCode('0x' + p1);
            }));
        }

        let base64Data = utf8ToBase64(printText);
        window.location.href = "rawbt:base64," + base64Data;
    }
}

// Function para sa manual at Windows print button
function triggerPrint() {
    const isAndroid = /Android/i.test(navigator.userAgent);
    if (isAndroid && lastSaleData) {
        printCheckoutReceipts(lastSaleData);
    } else {
        // Windows / Desktop Browser Printing
        window.print();
    }
}

// Compatibility wrapper kung tinatawag sa pos.js ang printReceipt()
function printReceipt() {
    triggerPrint();
}
</script>

<script src="assets/js/pos.js?v=<?= filemtime('assets/js/pos.js') ?>"></script>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>