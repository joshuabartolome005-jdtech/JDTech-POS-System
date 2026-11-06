<?php
// Prevent any HTML warnings/errors or whitespace from ruining the JSON response
ob_start();

// Set PHP Timezone to Philippine Standard Time
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'config/database.php';
    require_once 'config/auth.php';
    require_once 'includes/audit.php';
    require_once 'includes/shift_guard.php';

    // Set MySQL Connection Timezone to +08:00 (PHT)
    if (isset($pdo)) {
        $pdo->exec("SET time_zone = '+08:00';");
    }

    // Verify authentication for AJAX/API request
    if (!isset($_SESSION['user_id'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    $blockReason = getShiftCheckoutBlockReason($pdo, (int)$_SESSION['user_id']);
    if ($blockReason) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $blockReason]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $rawCart = $input['cart'] ?? [];
    $paymentMethod = $input['paymentMethod'] ?? 'Cash';
    $amountPaid = (float)($input['amountPaid'] ?? 0);
    $kraftBagOption = strtolower(trim($input['kraftBagOption'] ?? 'none'));

    $discountType = $input['discountType'] ?? 'none';
    if (!in_array($discountType, ['none', 'senior', 'pwd'], true)) {
        $discountType = 'none';
    }
    $discountIdNumber = trim($input['discountIdNumber'] ?? '');
    $discountName = trim($input['discountName'] ?? '');

    if (empty($rawCart) || !is_array($rawCart)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'The cart is empty.']);
        exit;
    }

    if ($discountType !== 'none' && ($discountIdNumber === '' || $discountName === '')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'ID Number and Name are required for the Senior/PWD discount.']);
        exit;
    }

    // Sanitize and filter cart payload to ensure clean unique items
    $cart = [];
    foreach ($rawCart as $cItem) {
        $pId = (int)($cItem['id'] ?? 0);
        $pQty = (int)($cItem['qty'] ?? 0);
        if ($pId > 0 && $pQty > 0) {
            if (isset($cart[$pId])) {
                $cart[$pId]['qty'] += $pQty;
            } else {
                $cart[$pId] = [
                    'id' => $pId,
                    'qty' => $pQty,
                    'name' => $cItem['name'] ?? ''
                ];
            }
        }
    }

    if (empty($cart)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No valid items in cart.']);
        exit;
    }

    $pdo->beginTransaction();

    // Compute subtotal & validate stock
    $subtotal = 0;
    $productData = [];
    foreach ($cart as $item) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
        $stmt->execute([$item['id']]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new Exception("Product not found: " . ($item['name'] !== '' ? $item['name'] : "ID #" . $item['id']));
        }
        if ($product['stock_qty'] < $item['qty']) {
            throw new Exception("Insufficient stock for: " . $product['name']);
        }

        $itemSubtotal = $product['price'] * $item['qty'];
        $subtotal += $itemSubtotal;
        $productData[] = [
            'product' => $product,
            'qty' => (int)$item['qty'],
            'subtotal' => $itemSubtotal
        ];
    }

    $discountRate = ($discountType === 'senior' || $discountType === 'pwd') ? 0.20 : 0;
    $discountAmount = round($subtotal * $discountRate, 2);
    $total = $subtotal - $discountAmount;

    if ($amountPaid < $total) {
        throw new Exception("Amount paid is insufficient.");
    }

    $change = $amountPaid - $total;
    $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    // Insert sale header (kasama na ang kraft_bag_option)
    $stmt = $pdo->prepare("INSERT INTO sales
        (invoice_no, user_id, total_amount, subtotal_amount, discount_type, discount_id_number, discount_name, discount_amount, amount_paid, change_amount, payment_method, kraft_bag_option)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $invoiceNo,
        $_SESSION['user_id'],
        $total,
        $subtotal,
        $discountType,
        $discountType !== 'none' ? $discountIdNumber : null,
        $discountType !== 'none' ? $discountName : null,
        $discountAmount,
        $amountPaid,
        $change,
        $paymentMethod,
        $kraftBagOption
    ]);
    $saleId = $pdo->lastInsertId();

    // Insert sale items & deduct product stock
    foreach ($productData as $pd) {
        $p = $pd['product'];
        $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, price, qty, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$saleId, $p['id'], $p['name'], $p['price'], $pd['qty'], $pd['subtotal']]);

        $stmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
        $stmt->execute([$pd['qty'], $p['id']]);
    }

    // Deduct raw ingredient stock based on each product's recipe
    foreach ($productData as $pd) {
        $p = $pd['product'];
        $qty = $pd['qty'];

        $stmt = $pdo->prepare("SELECT ingredient_id, quantity FROM recipe_items WHERE product_id = ?");
        $stmt->execute([$p['id']]);
        foreach ($stmt->fetchAll() as $r) {
            $consumeQty = $r['quantity'] * $qty;
            $upd = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty - ? WHERE id = ?");
            $upd->execute([$consumeQty, $r['ingredient_id']]);
        }
    }

    // EKSKLUSIBONG DEDUCTION NG KRAFT BAGS BATAY SA NAPILI SA POS DROPDOWN
    if ($kraftBagOption === 'small') {
        $upd = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty - 1 WHERE id = 10 OR name LIKE '%Kraft Bag (Small)%'");
        $upd->execute();
    } elseif ($kraftBagOption === 'big') {
        $upd = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty - 1 WHERE id = 11 OR name LIKE '%Kraft Bag (Big)%'");
        $upd->execute();
    }
    // Pag 'none' ang kraftBagOption, walang mababawas na bag.

    $pdo->commit();

    // Audit Log
    $itemsSummary = implode(', ', array_map(
        fn($pd) => $pd['qty'] . 'x ' . $pd['product']['name'],
        $productData
    ));
    if (function_exists('log_audit')) {
        log_audit($pdo, $_SESSION['user_id'], 'Sale Checkout',
            "Invoice $invoiceNo - Items: $itemsSummary - Total: " . number_format($total, 2) . " ($paymentMethod)");
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'sale_id' => $saleId,
        'invoice_no' => $invoiceNo,
        'subtotal' => $subtotal,
        'discount_type' => $discountType,
        'discount_amount' => $discountAmount,
        'total' => $total,
        'amount_paid' => $amountPaid,
        'change' => $change,
        'payment_method' => $paymentMethod,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}