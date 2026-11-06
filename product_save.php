<?php
require 'config/database.php';
require 'config/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: products');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name']);
$description = trim($_POST['description'] ?? '') ?: null;
$sku = trim($_POST['sku']);
$category_id = (int)($_POST['category_id'] ?? 0) ?: null;
$price = (float)($_POST['price'] ?? 0);
$cost = (float)($_POST['cost'] ?? 0);
$stock_qty = (int)($_POST['stock_qty'] ?? 0);

// Start with whatever image path was already set (kept as-is unless a new file is uploaded)
$image = trim($_POST['existing_image'] ?? '') ?: null;

// Handle a newly uploaded image, if any
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

    if (in_array($ext, $allowedExt)) {
        $uploadDir = __DIR__ . '/assets/images/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'product_' . uniqid() . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
            $image = 'assets/images/products/' . $filename;
        }
    }
}

if ($id > 0) {
    // Update
    $stmt = $pdo->prepare("UPDATE products SET name=?, description=?, sku=?, category_id=?, price=?, cost=?, stock_qty=?, image=? WHERE id=?");
    $stmt->execute([$name, $description, $sku, $category_id, $price, $cost, $stock_qty, $image, $id]);
} else {
    // Insert
    $stmt = $pdo->prepare("INSERT INTO products (name, description, sku, category_id, price, cost, stock_qty, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $description, $sku, $category_id, $price, $cost, $stock_qty, $image]);
}

header('Location: products');
exit;
