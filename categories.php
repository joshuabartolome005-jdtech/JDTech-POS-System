<?php
$pageTitle = 'Categories';
require 'includes/header.php';
require 'includes/audit.php';

$message = '';

// Add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    if ($name !== '') {
        $stmt =$pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        log_audit($pdo,$_SESSION['user_id'], 'Category Added', "Added category \"$name\"");
        $message = 'Category added successfully.';
    }
}

// Delete category
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $nameStmt =$pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $nameStmt->execute([$delId]);
    $catName =$nameStmt->fetchColumn();

    $stmt =$pdo->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$delId]);

    if ($catName) {
        log_audit($pdo,$_SESSION['user_id'], 'Category Deleted', 'Deleted category "' . $catName . '"');
    }
    header('Location: categories');
    exit;
}

// Edit category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $stmt =$pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
    $stmt->execute([$name, $id]);$message = 'Category updated successfully.';
}

$categories =$pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count FROM categories c ORDER BY c.name")->fetchAll();
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Add Category</div>
            <div class="card-body">
                <?php if ($message): ?><div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <button type="submit" name="add_category" class="btn btn-primary w-100">Add</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Category List</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Product Count</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as$c): ?>
                        <tr>
                            <td>
                                <form method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>" class="form-control form-control-sm">
                                    <button type="submit" name="edit_category" class="btn btn-sm btn-outline-secondary">Save</button>
                                </form>
                            </td>
                            <td><?= $c['product_count'] ?></td>
                            <td class="text-end">
                                <a href="categories?delete=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="confirmDeleteCategory(event, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function confirmDeleteCategory(event, categoryName) {
    event.preventDefault();
    const targetUrl = event.currentTarget.getAttribute('href');

    Swal.fire({
        title: 'Delete Category?',
        text: `Are you sure you want to delete "${categoryName}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = targetUrl;
        }
    });
}
</script>

<?php require 'includes/footer.php'; ?>