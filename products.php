<?php
$pageTitle = 'Products';
require 'includes/header.php';

$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.name LIKE ? OR p.sku LIKE ? ORDER BY p.id DESC");
    $like = "%$search%";
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC");
}
$products = $stmt->fetchAll();
?>

<!-- Include SweetAlert2 Library -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <form class="d-flex" method="GET" style="max-width:320px;">
        <input type="text" name="q" class="form-control me-2" placeholder="Search product..." value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg"></i> Add Product
    </button>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Image</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No products found.</td></tr>
                <?php endif; ?>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td>
                        <?php if (!empty($p['image'])): ?>
                            <img src="<?= htmlspecialchars($p['image']) ?>" class="product-thumb" alt="">
                        <?php else: ?>
                            <div class="product-thumb-placeholder"><i class="bi bi-basket2-fill"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($p['name']) ?>
                        <?php if (!empty($p['description'])): ?>
                            <div class="text-muted" style="font-size:0.78rem;"><?= htmlspecialchars($p['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['sku']) ?></td>
                    <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                    <td>&#8369;<?= number_format($p['price'], 2) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                            onclick='openEditModal(<?= json_encode($p) ?>)'
                            data-bs-toggle="modal" data-bs-target="#productModal">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $p['id'] ?>)" title="Delete Product">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="product_save" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="prod_id">
                    <input type="hidden" name="existing_image" id="prod_existing_image">

                    <div class="mb-3 text-center">
                        <img id="prod_image_preview" src="" alt="" class="product-preview no-image-yet">
                        <div class="mt-2">
                            <label class="form-label small d-block">Product Photo <span class="text-muted">(optional)</span></label>
                            <input type="file" name="image" id="prod_image" class="form-control" accept="image/*" onchange="previewImage(this)">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" id="prod_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description / Ingredients <span class="text-muted">(optional)</span></label>
                        <textarea name="description" id="prod_description" class="form-control" rows="2" placeholder="e.g. Chicken, cheese, napa cabbage, pickles, fries, tomato, onion"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SKU / Barcode</label>
                            <input type="text" name="sku" id="prod_sku" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category</label>
                            <select name="category_id" id="prod_category" class="form-select">
                                <?php foreach ($pdo->query("SELECT * FROM categories ORDER BY name") as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Price (&#8369;)</label>
                            <input type="number" step="0.01" name="price" id="prod_price" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost (&#8369;)</label>
                            <input type="number" step="0.01" name="cost" id="prod_cost" class="form-control" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    const preview = document.getElementById('prod_image_preview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.classList.remove('no-image-yet');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Add Product';
    document.getElementById('prod_id').value = '';
    document.getElementById('prod_existing_image').value = '';
    document.getElementById('prod_name').value = '';
    document.getElementById('prod_description').value = '';
    document.getElementById('prod_sku').value = '';
    document.getElementById('prod_price').value = '';
    document.getElementById('prod_cost').value = 0;
    document.getElementById('prod_image').value = '';
    const preview = document.getElementById('prod_image_preview');
    preview.src = '';
    preview.classList.add('no-image-yet');
}

function openEditModal(p) {
    document.getElementById('modalTitle').innerText = 'Edit Product';
    document.getElementById('prod_id').value = p.id;
    document.getElementById('prod_existing_image').value = p.image || '';
    document.getElementById('prod_name').value = p.name;
    document.getElementById('prod_description').value = p.description || '';
    document.getElementById('prod_sku').value = p.sku;
    document.getElementById('prod_category').value = p.category_id;
    document.getElementById('prod_price').value = p.price;
    document.getElementById('prod_cost').value = p.cost;
    document.getElementById('prod_image').value = '';
    const preview = document.getElementById('prod_image_preview');
    if (p.image) {
        preview.src = p.image;
        preview.classList.remove('no-image-yet');
    } else {
        preview.src = '';
        preview.classList.add('no-image-yet');
    }
}

function confirmDelete(productId) {
    Swal.fire({
        title: 'Delete this product?',
        text: "This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'product_delete?id=' + productId;
        }
    });
}
</script>

<?php require 'includes/footer.php'; ?>