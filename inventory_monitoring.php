<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'includes/audit.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    header('Location: login.php');
    exit;
}

$isAdmin = ($_SESSION['role'] === 'admin');
$pageTitle = 'Inventory Monitoring';

// MAX BACKDATE LIMIT: 90 DAYS (3 MONTHS)
$maxBackdateDays = 90;
$minAllowedDate = date('Y-m-d', strtotime("-$maxBackdateDays days"));

function computeIngredientSold(PDO $pdo, int $ingredientId, string $ingredientName, string $dateFrom, string $dateTo): float
{
    if ($dateFrom > $dateTo) {
        return 0;
    }

    // EXCLUSIVE DEDUCTION LOGIC FOR KRAFT BAGS (SMALL = ID 10, BIG = ID 11)
    if ($ingredientId === 10 || $ingredientId === 11 || stristr($ingredientName, 'Kraft Bag') !== false) {
        
        // Target specifically product_ids 4, 5, 6, 7, 8 (Shawarma items)
        $stmt = $pdo->prepare("
            SELECT s.id, COALESCE(SUM(si.qty), 0) as shawarma_qty
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE LOWER(s.status) = 'completed' 
              AND DATE(s.created_at) BETWEEN ? AND ?
              AND si.product_id IN (4, 5, 6, 7, 8)
            GROUP BY s.id
        ");
        $stmt->execute([$dateFrom, $dateTo]);

        $smallBagsUsed = 0;
        $bigBagsUsed = 0;

        foreach ($stmt->fetchAll() as $r) {
            $qty = (int)$r['shawarma_qty'];

            if ($qty === 1) {
                // Pag 1 Shawarma order -> Small Bag LANG
                $smallBagsUsed++;
            } elseif ($qty >= 2) {
                // Pag 2 o higit pang Shawarma orders -> Big Bag LANG
                $bigBagsUsed++;
            }
        }

        // Return EKSKLUSIBO batay sa kung anong Bag ID ang pino-process
        if ($ingredientId === 10 || stristr($ingredientName, 'Small') !== false) {
            return (float)$smallBagsUsed;
        } 
        if ($ingredientId === 11 || stristr($ingredientName, 'Big') !== false) {
            return (float)$bigBagsUsed;
        }

        return 0;
    }

    // STANDARD INGREDIENT DEDUCTION QUERY VIA RECIPE_ITEMS FOR OTHER INGREDIENTS
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ri.quantity * si.qty), 0)
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN recipe_items ri ON ri.product_id = si.product_id AND ri.ingredient_id = ?
        WHERE LOWER(s.status) = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$ingredientId, $dateFrom, $dateTo]);
    return (float)$stmt->fetchColumn();
}

function sumAdjustments(PDO $pdo, int $ingredientId, string $type, string $dateFrom, string $dateTo): int
{
    if ($dateFrom > $dateTo) {
        return 0;
    }
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM ingredient_adjustments WHERE ingredient_id = ? AND adjustment_type = ? AND DATE(created_at) BETWEEN ? AND ? AND (reason IS NULL OR reason != 'Direct Opening Edit')");
    $stmt->execute([$ingredientId, $type, $dateFrom, $dateTo]);
    return (int)$stmt->fetchColumn();
}

$message = $_SESSION['flash_message'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_error']);

// DIRECT OPENING STOCK EDIT / UPDATE (ADMIN ONLY) WITH POST-REDIRECT-GET
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_opening_stock'])) {
    if (!$isAdmin) {
        $_SESSION['flash_error'] = 'Unauthorized action. Cashiers have view-only access.';
    } else {
        $ingredientId = (int)($_POST['edit_ingredient_id'] ?? 0);
        $newOpening = (int)($_POST['new_opening'] ?? 0);
        $oldOpening = (int)($_POST['old_opening'] ?? 0);
        $entryDate = trim($_POST['edit_entry_date'] ?? date('Y-m-d'));

        if ($ingredientId <= 0 || $newOpening < 0) {
            $_SESSION['flash_error'] = 'Invalid opening stock value entered.';
        } else {
            $diff = $newOpening - $oldOpening;
            if ($diff !== 0) {
                $nameStmt = $pdo->prepare("SELECT name FROM ingredients WHERE id = ?");
                $nameStmt->execute([$ingredientId]);
                $ingName = $nameStmt->fetchColumn();

                // Direct adjustment to stock_qty
                $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty " . ($diff > 0 ? '+' : '-') . " ? WHERE id = ?");
                $stmt->execute([abs($diff), $ingredientId]);

                $timestamp = $entryDate . ' ' . date('H:i:s');
                $stmt = $pdo->prepare("INSERT INTO ingredient_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ingredientId, $_SESSION['user_id'], ($diff > 0 ? 'in' : 'out'), abs($diff), 'Direct Opening Edit', "Opening overridden from $oldOpening to $newOpening", $timestamp]);

                log_audit($pdo, $_SESSION['user_id'], 'Opening Stock Updated', "Adjusted {$ingName} opening on {$entryDate} from {$oldOpening} to {$newOpening}");

                $_SESSION['flash_message'] = "Opening stock for {$ingName} updated successfully to {$newOpening}.";
            }
        }
    }
    header("Location: inventory_monitoring?date=" . urlencode($_POST['edit_entry_date'] ?? date('Y-m-d')));
    exit;
}

// DIRECT RECEIVED STOCK OVERRIDE / EDIT (ADMIN ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_received_stock'])) {
    if (!$isAdmin) {
        $_SESSION['flash_error'] = 'Unauthorized action. Cashiers have view-only access.';
    } else {
        $ingredientId = (int)($_POST['edit_ingredient_id'] ?? 0);
        $newReceived = (int)($_POST['new_received'] ?? 0);
        $oldReceived = (int)($_POST['old_received'] ?? 0);
        $entryDate = trim($_POST['edit_entry_date'] ?? date('Y-m-d'));

        if ($ingredientId <= 0 || $newReceived < 0) {
            $_SESSION['flash_error'] = 'Invalid received stock value entered.';
        } else {
            $diff = $newReceived - $oldReceived;
            if ($diff !== 0) {
                $nameStmt = $pdo->prepare("SELECT name FROM ingredients WHERE id = ?");
                $nameStmt->execute([$ingredientId]);
                $ingName = $nameStmt->fetchColumn();

                // Adjust physical stock base sa diff ng received override
                $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty " . ($diff > 0 ? '+' : '-') . " ? WHERE id = ?");
                $stmt->execute([abs($diff), $ingredientId]);

                $timestamp = $entryDate . ' ' . date('H:i:s');
                $stmt = $pdo->prepare("INSERT INTO ingredient_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ingredientId, $_SESSION['user_id'], ($diff > 0 ? 'in' : 'out'), abs($diff), 'Direct Received Edit', "Received overridden from $oldReceived to $newReceived", $timestamp]);

                // I-update o i-insert sa ingredient_receipts para sumabay ang record sa petsa
                $chk = $pdo->prepare("SELECT id FROM ingredient_receipts WHERE ingredient_id = ? AND DATE(created_at) = ?");
                $chk->execute([$ingredientId, $entryDate]);
                if ($chk->fetch()) {
                    $updRec = $pdo->prepare("UPDATE ingredient_receipts SET quantity = ? WHERE ingredient_id = ? AND DATE(created_at) = ?");
                    $updRec->execute([$newReceived, $ingredientId, $entryDate]);
                } else if ($newReceived > 0) {
                    $insRec = $pdo->prepare("INSERT INTO ingredient_receipts (ingredient_id, user_id, quantity, notes, created_at) VALUES (?, ?, ?, ?, ?)");
                    $insRec->execute([$ingredientId, $_SESSION['user_id'], $newReceived, 'Direct Received Edit Override', $timestamp]);
                }

                log_audit($pdo, $_SESSION['user_id'], 'Received Stock Updated', "Adjusted {$ingName} received on {$entryDate} from {$oldReceived} to {$newReceived}");
                $_SESSION['flash_message'] = "Received stock for {$ingName} updated successfully to {$newReceived}.";
            }
        }
    }
    header("Location: inventory_monitoring?date=" . urlencode($_POST['edit_entry_date'] ?? date('Y-m-d')));
    exit;
}

// DIRECT SOLD STOCK OVERRIDE / EDIT (ADMIN ONLY)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sold_stock'])) {
    if (!$isAdmin) {
        $_SESSION['flash_error'] = 'Unauthorized action. Cashiers have view-only access.';
    } else {
        $ingredientId = (int)($_POST['edit_ingredient_id'] ?? 0);
        $newSold = (int)($_POST['new_sold'] ?? 0);
        $oldSold = (int)($_POST['old_sold'] ?? 0);
        $entryDate = trim($_POST['edit_entry_date'] ?? date('Y-m-d'));

        if ($ingredientId <= 0 || $newSold < 0) {
            $_SESSION['flash_error'] = 'Invalid sold stock value entered.';
        } else {
            $diff = $newSold - $oldSold; // Kung tumaas ang sold, ibig sabihin bawas sa stock (-); kung bumaba, dagdag sa stock (+)
            if ($diff !== 0) {
                $nameStmt = $pdo->prepare("SELECT name FROM ingredients WHERE id = ?");
                $nameStmt->execute([$ingredientId]);
                $ingName = $nameStmt->fetchColumn();

                // Baliktad ang epekto sa physical stock kapag sold ang in-edit ($diff > 0 means mas marami ang nabenta, kaya -)
                $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty " . ($diff > 0 ? '-' : '+') . " ? WHERE id = ?");
                $stmt->execute([abs($diff), $ingredientId]);

                $timestamp = $entryDate . ' ' . date('H:i:s');
                // Kung nagbenta/nabawasan, adjustment type ay 'out' kapag $diff > 0, 'in' kapag $diff < 0
                $adjType = ($diff > 0) ? 'out' : 'in';
                $stmt = $pdo->prepare("INSERT INTO ingredient_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ingredientId, $_SESSION['user_id'], $adjType, abs($diff), 'Direct Sold Edit', "Sold overridden from $oldSold to $newSold", $timestamp]);

                log_audit($pdo, $_SESSION['user_id'], 'Sold Stock Updated', "Adjusted {$ingName} sold on {$entryDate} from {$oldSold} to {$newSold}");
                $_SESSION['flash_message'] = "Sold stock for {$ingName} updated successfully to {$newSold}.";
            }
        }
    }
    header("Location: inventory_monitoring?date=" . urlencode($_POST['edit_entry_date'] ?? date('Y-m-d')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_stock'])) {
    if (!$isAdmin) {
        $_SESSION['flash_error'] = 'Unauthorized action. Cashiers have view-only access.';
    } else {
        $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);
        $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if ($ingredientId <= 0 || $qty <= 0) {
            $_SESSION['flash_error'] = 'Please select an ingredient and enter a valid quantity.';
        } elseif ($entryDate < $minAllowedDate || $entryDate > date('Y-m-d')) {
            $_SESSION['flash_error'] = "Entry date must be between $minAllowedDate and today.";
        } else {
            $timestamp = $entryDate . ' ' . date('H:i:s');
            
            $stmt = $pdo->prepare("INSERT INTO ingredient_receipts (ingredient_id, user_id, quantity, notes, created_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$ingredientId, $_SESSION['user_id'], $qty, $notes ?: null, $timestamp]);

            $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty + ? WHERE id = ?");
            $stmt->execute([$qty, $ingredientId]);

            $nameStmt = $pdo->prepare("SELECT name FROM ingredients WHERE id = ?");
            $nameStmt->execute([$ingredientId]);
            $ingName = $nameStmt->fetchColumn();

            log_audit($pdo, $_SESSION['user_id'], 'Ingredient Received', "+$qty {$ingName} on $entryDate" . ($notes ? " ($notes)" : ''));

            $_SESSION['flash_message'] = 'Stock received and logged successfully for date: ' . $entryDate;
        }
    }
    header("Location: inventory_monitoring?date=" . urlencode($_POST['entry_date'] ?? date('Y-m-d')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_stock'])) {
    if (!$isAdmin) {
        $_SESSION['flash_error'] = 'Unauthorized action. Cashiers have view-only access.';
    } else {
        $ingredientId = (int)($_POST['adj_ingredient_id'] ?? 0);
        $type = ($_POST['adj_type'] ?? '') === 'out' ? 'out' : 'in';
        $qty = (int)($_POST['adj_quantity'] ?? 0);
        $entryDate = trim($_POST['adj_entry_date'] ?? date('Y-m-d'));
        $reason = trim($_POST['adj_reason'] ?? '');
        $notes = trim($_POST['adj_notes'] ?? '');

        if ($ingredientId <= 0 || $qty <= 0 || $reason === '') {
            $_SESSION['flash_error'] = 'Please select an ingredient, a valid quantity, and a reason.';
        } elseif ($entryDate < $minAllowedDate || $entryDate > date('Y-m-d')) {
            $_SESSION['flash_error'] = "Entry date must be between $minAllowedDate and today.";
        } else {
            $nameStmt = $pdo->prepare("SELECT name, stock_qty FROM ingredients WHERE id = ?");
            $nameStmt->execute([$ingredientId]);
            $ingRow = $nameStmt->fetch();

            if (!$ingRow) {
                $_SESSION['flash_error'] = 'Ingredient not found.';
            } elseif ($type === 'out' && $qty > $ingRow['stock_qty']) {
                $_SESSION['flash_error'] = 'Cannot remove more than current physical stock (' . $ingRow['stock_qty'] . ' on hand).';
            } else {
                $timestamp = $entryDate . ' ' . date('H:i:s');
                $stmt = $pdo->prepare("INSERT INTO ingredient_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ingredientId, $_SESSION['user_id'], $type, $qty, $reason, $notes ?: null, $timestamp]);

                $sign = $type === 'in' ? '+' : '-';
                $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty " . ($type === 'in' ? '+' : '-') . " ? WHERE id = ?");
                $stmt->execute([$qty, $ingredientId]);

                log_audit($pdo, $_SESSION['user_id'], 'Ingredient Stock Adjusted', "$sign$qty {$ingRow['name']} on $entryDate - $reason" . ($notes ? " ($notes)" : ''));

                $_SESSION['flash_message'] = 'Stock adjustment saved successfully for date: ' . $entryDate;
            }
        }
    }
    header("Location: inventory_monitoring?date=" . urlencode($_POST['adj_entry_date'] ?? date('Y-m-d')));
    exit;
}

if (isset($_GET['delete_adj']) && $isAdmin) {
    $adjId = (int)$_GET['delete_adj'];
    $stmt = $pdo->prepare("SELECT * FROM ingredient_adjustments WHERE id = ?");
    $stmt->execute([$adjId]);
    $adj = $stmt->fetch();

    if ($adj) {
        if ($adj['adjustment_type'] === 'in') {
            $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty - ? WHERE id = ?");
            $stmt->execute([$adj['quantity'], $adj['ingredient_id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE ingredients SET stock_qty = stock_qty + ? WHERE id = ?");
            $stmt->execute([$adj['quantity'], $adj['ingredient_id']]);
        }
        $stmt = $pdo->prepare("DELETE FROM ingredient_adjustments WHERE id = ?");
        $stmt->execute([$adjId]);

        log_audit($pdo, $_SESSION['user_id'], 'Ingredient Adjustment Deleted', "Reverted adjustment #{$adjId}");
        $_SESSION['flash_message'] = 'Adjustment entry reverted successfully.';
    }
    header("Location: inventory_monitoring?date=" . urlencode($_GET['date'] ?? date('Y-m-d')));
    exit;
}

require 'includes/header.php';

$viewDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) {
    $viewDate = date('Y-m-d');
}
$prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));
$isToday = ($viewDate === date('Y-m-d'));
$dayAfter = date('Y-m-d', strtotime($viewDate . ' +1 day'));
$today = date('Y-m-d');

$ingredients = $pdo->query("SELECT * FROM ingredients ORDER BY id")->fetchAll();

$rows = [];
foreach ($ingredients as $ing) {
    $id = (int)$ing['id'];
    $name = $ing['name'];
    $currentStock = (int)$ing['stock_qty'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM ingredient_receipts WHERE ingredient_id = ? AND DATE(created_at) = ?");
    $stmt->execute([$id, $viewDate]);
    $receivedOnDate = (int)$stmt->fetchColumn() + sumAdjustments($pdo, $id, 'in', $viewDate, $viewDate);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM ingredient_receipts WHERE ingredient_id = ? AND DATE(created_at) > ?");
    $stmt->execute([$id, $viewDate]);
    $receivedAfter = (int)$stmt->fetchColumn() + sumAdjustments($pdo, $id, 'in', $dayAfter, $today);

    $soldOnDate = computeIngredientSold($pdo, $id, $name, $viewDate, $viewDate) + sumAdjustments($pdo, $id, 'out', $viewDate, $viewDate);
    $soldAfter = computeIngredientSold($pdo, $id, $name, $dayAfter, $today) + sumAdjustments($pdo, $id, 'out', $dayAfter, $today);

    if ($isToday) {
        $endingOnDate = $currentStock;
        $openingOnDate = $endingOnDate - $receivedOnDate + $soldOnDate;
    } else {
        $endingOnDate = $currentStock - $receivedAfter + $soldAfter;
        $openingOnDate = $endingOnDate - $receivedOnDate + $soldOnDate;
    }

    $rows[] = [
        'id' => $id,
        'name' => $name,
        'opening' => $openingOnDate,
        'received' => $receivedOnDate,
        'sold' => $soldOnDate,
        'ending' => $endingOnDate,
        'current' => $currentStock,
        'low' => $endingOnDate <= $ing['low_stock_threshold'],
    ];
}

$adjHistory = $pdo->query("
    SELECT ia.*, i.name as ingredient_name, u.full_name
    FROM ingredient_adjustments ia
    JOIN ingredients i ON ia.ingredient_id = i.id
    JOIN users u ON ia.user_id = u.id
    ORDER BY ia.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
    <?php if ($isAdmin): ?>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Receive Stock <span class="text-muted small">(supplier delivery)</span></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-2">
                        <label class="form-label small">Delivery / Entry Date</label>
                        <input type="date" name="entry_date" class="form-control form-control-sm" value="<?= htmlspecialchars($viewDate) ?>" min="<?= $minAllowedDate ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Ingredient</label>
                        <select name="ingredient_id" class="form-select form-select-sm" required>
                            <?php foreach ($ingredients as $ing): ?>
                                <option value="<?= $ing['id'] ?>"><?= htmlspecialchars($ing['name']) ?> (now: <?= $ing['stock_qty'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Quantity Received</label>
                        <input type="number" name="quantity" class="form-control form-control-sm" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Notes <span class="text-muted">(optional)</span></label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Supplier delivery">
                    </div>
                    <button type="submit" name="receive_stock" class="btn btn-primary btn-sm w-100">Add to Stock</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Adjust Stock <span class="text-muted small">(admin control)</span></div>
            <div class="card-body">
                <form method="POST" id="adjustStockForm">
                    <input type="hidden" name="adjust_stock" value="1">
                    <div class="mb-2">
                        <label class="form-label small">Adjustment Date</label>
                        <input type="date" name="adj_entry_date" class="form-control form-control-sm" value="<?= htmlspecialchars($viewDate) ?>" min="<?= $minAllowedDate ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Ingredient</label>
                        <select name="adj_ingredient_id" class="form-select form-select-sm" required>
                            <?php foreach ($ingredients as $ing): ?>
                                <option value="<?= $ing['id'] ?>"><?= htmlspecialchars($ing['name']) ?> (now: <?= $ing['stock_qty'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Action</label>
                        <select name="adj_type" class="form-select form-select-sm" required>
                            <option value="in">Add Stock (+)</option>
                            <option value="out">Remove Stock (-)</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Quantity</label>
                        <input type="number" name="adj_quantity" class="form-control form-control-sm" min="1" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Reason</label>
                        <select name="adj_reason" class="form-select form-select-sm" required>
                            <option value="">-- Select reason --</option>
                            <option value="Spoilage/Damage">Spoilage/Damage</option>
                            <option value="Miscount Correction">Miscount Correction</option>
                            <option value="Internal Use">Internal Use</option>
                            <option value="Theft/Loss">Theft/Loss</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Notes <span class="text-muted">(optional)</span></label>
                        <input type="text" name="adj_notes" class="form-control form-control-sm" placeholder="Additional details">
                    </div>
                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="confirmAdjustment()">Apply Adjustment</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $isAdmin ? 'col-lg-8' : 'col-lg-12' ?>">
        <?php if (!$isAdmin): ?>
        <div class="alert alert-info d-flex align-items-center mb-3" role="alert">
            <i class="bi bi-info-circle-fill me-2 fs-5"></i>
            <div>
                <strong>View-Only Mode:</strong> Cashier access mode active.
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Inventory Monitoring Form</span>
                <div class="d-flex align-items-center gap-2">
                    <a href="?date=<?= $prevDate ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
                    <form method="GET" class="d-inline">
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($viewDate) ?>" onchange="this.form.submit()">
                    </form>
                    <a href="?date=<?= $nextDate ?>" class="btn btn-sm btn-outline-secondary <?= $isToday ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Showing: <strong><?= date('F d, Y (l)', strtotime($viewDate)) ?></strong>
                    <?= $isToday ? '<span class="badge bg-primary ms-1">Today</span>' : '' ?>
                </p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>No.</th>
                                <th>Item</th>
                                <th class="text-end" style="width: 120px;">Opening</th>
                                <th class="text-end" style="width: 120px;">Received</th>
                                <th class="text-end" style="width: 120px;">Sold</th>
                                <th class="text-end">Ending</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $r): ?>
                            <tr class="<?= $r['low'] ? 'table-danger' : '' ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <?= htmlspecialchars($r['name']) ?>
                                    <?php if ($r['low']): ?>
                                        <span class="badge bg-danger ms-1">Low</span>
                                    <?php endif; ?>
                                </td>
                                <!-- OPENING COLUMN (Editable para sa Admin) -->
                                <td class="text-end">
                                    <?php if ($isAdmin): ?>
                                    <form method="POST" class="d-flex align-items-center justify-content-end gap-1">
                                        <input type="hidden" name="update_opening_stock" value="1">
                                        <input type="hidden" name="edit_ingredient_id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="old_opening" value="<?= $r['opening'] ?>">
                                        <input type="hidden" name="edit_entry_date" value="<?= htmlspecialchars($viewDate) ?>">
                                        <input type="number" name="new_opening" class="form-control form-control-sm text-end p-1" style="width: 65px;" value="<?= $r['opening'] ?>" min="0" required>
                                        <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Save Opening">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <?= $r['opening'] ?>
                                    <?php endif; ?>
                                </td>
                                <!-- RECEIVED COLUMN (Editable para sa Admin) -->
                                <td class="text-end text-success">
                                    <?php if ($isAdmin): ?>
                                    <form method="POST" class="d-flex align-items-center justify-content-end gap-1">
                                        <input type="hidden" name="update_received_stock" value="1">
                                        <input type="hidden" name="edit_ingredient_id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="old_received" value="<?= $r['received'] ?>">
                                        <input type="hidden" name="edit_entry_date" value="<?= htmlspecialchars($viewDate) ?>">
                                        <input type="number" name="new_received" class="form-control form-control-sm text-end p-1" style="width: 65px;" value="<?= $r['received'] ?>" min="0" required>
                                        <button type="submit" class="btn btn-sm btn-outline-success py-0 px-1" title="Save Received">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <?= $r['received'] > 0 ? '+' . $r['received'] : $r['received'] ?>
                                    <?php endif; ?>
                                </td>
                                <!-- SOLD COLUMN (Editable para sa Admin) -->
                                <td class="text-end text-danger">
                                    <?php if ($isAdmin): ?>
                                    <form method="POST" class="d-flex align-items-center justify-content-end gap-1">
                                        <input type="hidden" name="update_sold_stock" value="1">
                                        <input type="hidden" name="edit_ingredient_id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="old_sold" value="<?= $r['sold'] ?>">
                                        <input type="hidden" name="edit_entry_date" value="<?= htmlspecialchars($viewDate) ?>">
                                        <input type="number" name="new_sold" class="form-control form-control-sm text-end p-1" style="width: 65px;" value="<?= $r['sold'] ?>" min="0" required>
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Save Sold">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <?= $r['sold'] > 0 ? '-' . $r['sold'] : $r['sold'] ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold"><?= $r['ending'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Recent Stock Adjustments</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Ingredient</th><th>Type</th><th>Qty</th><th>Reason</th><th>By</th><?php if ($isAdmin): ?><th>Action</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php if (!$adjHistory): ?>
                            <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center text-muted py-4">No manual adjustments yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($adjHistory as $a): ?>
                        <tr>
                            <td><?= date('M d, Y h:i A', strtotime($a['created_at'])) ?></td>
                            <td><?= htmlspecialchars($a['ingredient_name']) ?></td>
                            <td>
                                <?php if ($a['adjustment_type'] === 'in'): ?>
                                    <span class="badge bg-success">Add</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Remove</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $a['quantity'] ?></td>
                            <td><?= htmlspecialchars($a['reason']) ?><?= $a['notes'] ? ' - ' . htmlspecialchars($a['notes']) : '' ?></td>
                            <td><?= htmlspecialchars($a['full_name']) ?></td>
                            <?php if ($isAdmin): ?>
                            <td>
                                <a href="?date=<?= $viewDate ?>&delete_adj=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Undo/delete this adjustment? Stock will be reverted automatically.');" title="Revert Entry">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<script>
function confirmAdjustment() {
    const form = document.getElementById('adjustStockForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Apply Stock Adjustment?',
            text: 'Are you sure you want to apply this adjustment for the selected date?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, apply it!'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    } else {
        if (confirm('Apply this stock adjustment?')) {
            form.submit();
        }
    }
}
</script>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>