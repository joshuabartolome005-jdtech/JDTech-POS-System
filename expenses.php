<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'includes/audit.php';
requireLogin();

$pageTitle = 'Expenses';

$userId = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $photoData = $_POST['photo'] ?? '';

    if ($description === '' || $amount <= 0) {
        $error = 'Please enter a description and a valid amount.';
    } else {
        $photoPath = null;

        if (preg_match('/^data:image\/(jpeg|jpg|png);base64,(.+)$/', $photoData, $matches)) {
            $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
            $binary = base64_decode($matches[2]);
            if ($binary !== false) {
                $uploadDir = __DIR__ . '/assets/images/expenses/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'exp_' . $userId . '_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.' . $ext;
                if (file_put_contents($uploadDir . $filename, $binary)) {
                    $photoPath = 'assets/images/expenses/' . $filename;
                }
            }
        }

        if (!$photoPath) {
            $error = 'A proof photo (receipt) is required. Please take a selfie of it or upload one.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO expenses (user_id, description, amount, proof_photo) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $description, $amount, $photoPath]);

            log_audit($pdo, $userId, 'Expense Logged', $description . ' - ' . number_format($amount, 2));

            $message = 'Expense recorded successfully.';
        }
    }
}

// History
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

if ($isAdmin) {
    $filterUser = (int)($_GET['user_id'] ?? 0);
    $sql = "SELECT e.*, u.full_name FROM expenses e JOIN users u ON e.user_id = u.id WHERE DATE(e.created_at) BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    if ($filterUser > 0) {
        $sql .= " AND e.user_id = ?";
        $params[] = $filterUser;
    }
    $sql .= " ORDER BY e.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();

    $employees = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
    $stmt->execute([$userId, $dateFrom, $dateTo]);
    $expenses = $stmt->fetchAll();
}

$totalForRange = array_sum(array_column($expenses, 'amount'));

require 'includes/header.php';
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Log an Expense</div>
            <div class="card-body">
                <p class="text-muted small">Bought something using cash from the drawer (e.g. foil, supplies)? Log it here so it reflects in your Shift Closing.</p>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" id="expDescription" class="form-control" placeholder="e.g. Aluminum foil">
                </div>
                <div class="mb-3">
                    <label class="form-label">Amount (&#8369;)</label>
                    <input type="number" step="0.01" id="expAmount" class="form-control" placeholder="0.00">
                </div>

                <button type="button" class="btn btn-primary w-100" onclick="openExpenseCameraModal()">
                    <i class="bi bi-camera"></i> Add Proof &amp; Save Expense
                </button>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><?= $isAdmin ? 'All Expenses' : 'My Expenses' ?></span>
                <span class="text-danger fw-bold">Total: &#8369;<?= number_format($totalForRange, 2) ?></span>
            </div>
            <div class="card-body">
                <form class="d-flex gap-2 mb-3 flex-wrap align-items-end" method="GET">
                    <?php if ($isAdmin): ?>
                    <div>
                        <label class="form-label small mb-1">Employee</label>
                        <select name="user_id" class="form-select">
                            <option value="0">All Employees</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= (($_GET['user_id'] ?? 0) == $e['id']) ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label small mb-1">From</label>
                        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div>
                        <label class="form-label small mb-1">To</label>
                        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                    <button class="btn btn-primary">Filter</button>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php if ($isAdmin): ?><th>Employee</th><?php endif; ?>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Proof</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$expenses): ?>
                                <tr><td colspan="<?= $isAdmin ? 5 : 4 ?>" class="text-center text-muted py-4">No expenses in this range.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($expenses as $exp): ?>
                            <tr>
                                <?php if ($isAdmin): ?><td><?= htmlspecialchars($exp['full_name']) ?></td><?php endif; ?>
                                <td><?= htmlspecialchars($exp['description']) ?></td>
                                <td>&#8369;<?= number_format($exp['amount'], 2) ?></td>
                                <td><?= date('M d, Y h:i A', strtotime($exp['created_at'])) ?></td>
                                <td>
                                    <?php if (!empty($exp['proof_photo'])): ?>
                                        <a href="<?= htmlspecialchars($exp['proof_photo']) ?>" target="_blank">
                                            <img src="<?= htmlspecialchars($exp['proof_photo']) ?>" class="attendance-thumb" style="max-height: 40px;" alt="proof">
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Proof photo modal -->
<div class="modal fade" id="expenseCameraModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Proof of Purchase</h5>
            </div>
            <div class="modal-body text-center">

                <div class="btn-group mb-3" role="group" id="expCaptureModeToggle">
                    <button type="button" class="btn btn-outline-primary active" id="expModeSelfieBtn" onclick="expSwitchMode('selfie')">
                        <i class="bi bi-camera"></i> Take Photo
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="expModeUploadBtn" onclick="expSwitchMode('upload')">
                        <i class="bi bi-upload"></i> Upload Photo
                    </button>
                </div>

                <div id="expSelfieMode">
                    <video id="expCameraVideo" autoplay playsinline class="camera-preview" style="width:100%; max-height:300px; background:#000;"></video>
                </div>

                <div id="expUploadMode" style="display:none;">
                    <input type="file" id="expUploadInput" accept="image/*" class="form-control mb-2" onchange="expHandleFileUpload(this)">
                    <p class="text-muted small mb-0">Choose a photo of the receipt.</p>
                </div>

                <img id="expCapturedPhoto" class="camera-preview" style="display:none; width:100%; max-height:300px;" alt="Captured photo">
                <canvas id="expCameraCanvas" style="display:none;"></canvas>
                <p id="expCameraError" class="text-danger small mt-2" style="display:none;"></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" onclick="expCancelCamera()">Cancel</button>
                <button type="button" class="btn btn-primary" id="expCaptureBtn" onclick="expCapturePhoto()">
                    <i class="bi bi-camera-fill"></i> Capture
                </button>
                <button type="button" class="btn btn-outline-secondary" id="expRetakeBtn" onclick="expRetakePhoto()" style="display:none;">Retake</button>
                <button type="button" class="btn btn-success" id="expConfirmBtn" onclick="expConfirmCapture()" style="display:none;">
                    <i class="bi bi-check-lg"></i> Confirm &amp; Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Invisible POST Form -->
<form id="expenseForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="add_expense" value="1">
    <input type="hidden" name="description" id="expPendingDescription">
    <input type="hidden" name="amount" id="expPendingAmount">
    <input type="hidden" name="photo" id="expPendingPhoto">
    <?php if (isset($_SESSION['csrf_token'])): ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <?php endif; ?>
</form>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let expCameraStream = null;
let expCurrentMode = 'selfie';

// Trigger server-side responses via SweetAlert2
<?php if ($message): ?>
Swal.fire({
    title: 'Success!',
    text: <?= json_encode($message) ?>,
    icon: 'success',
    confirmButtonColor: '#0d6efd'
});
<?php endif; ?>

<?php if ($error): ?>
Swal.fire({
    title: 'Error',
    text: <?= json_encode($error) ?>,
    icon: 'error',
    confirmButtonColor: '#dc3545'
});
<?php endif; ?>

function openExpenseCameraModal() {
    const desc = document.getElementById('expDescription').value.trim();
    const amt = parseFloat(document.getElementById('expAmount').value) || 0;

    if (!desc) {
        Swal.fire({
            title: 'Missing Information',
            text: 'Please enter a description.',
            icon: 'warning',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }
    if (amt <= 0) {
        Swal.fire({
            title: 'Invalid Amount',
            text: 'Please enter a valid amount.',
            icon: 'warning',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }

    document.getElementById('expCapturedPhoto').style.display = 'none';
    document.getElementById('expCameraError').style.display = 'none';
    document.getElementById('expRetakeBtn').style.display = 'none';
    document.getElementById('expConfirmBtn').style.display = 'none';
    document.getElementById('expUploadInput').value = '';
    document.getElementById('expCaptureModeToggle').style.display = '';

    expSwitchMode('selfie');

    const modal = new bootstrap.Modal(document.getElementById('expenseCameraModal'));
    modal.show();
}

function expSwitchMode(mode) {
    expCurrentMode = mode;
    document.getElementById('expCapturedPhoto').style.display = 'none';
    document.getElementById('expRetakeBtn').style.display = 'none';
    document.getElementById('expConfirmBtn').style.display = 'none';
    document.getElementById('expCameraError').style.display = 'none';

    document.getElementById('expModeSelfieBtn').classList.toggle('active', mode === 'selfie');
    document.getElementById('expModeUploadBtn').classList.toggle('active', mode === 'upload');

    if (mode === 'selfie') {
        document.getElementById('expSelfieMode').style.display = 'block';
        document.getElementById('expUploadMode').style.display = 'none';
        document.getElementById('expCaptureBtn').style.display = 'inline-block';

        expStopCamera();
        navigator.mediaDevices.getUserMedia({ video: true, audio: false })
            .then(function (stream) {
                expCameraStream = stream;
                const video = document.getElementById('expCameraVideo');
                video.srcObject = stream;
                video.style.display = 'block';
            })
            .catch(function (err) {
                const errEl = document.getElementById('expCameraError');
                errEl.innerText = 'Unable to access the camera: ' + err.message + '. You can use "Upload Photo" instead.';
                errEl.style.display = 'block';
            });
    } else {
        expStopCamera();
        document.getElementById('expSelfieMode').style.display = 'none';
        document.getElementById('expUploadMode').style.display = 'block';
        document.getElementById('expCaptureBtn').style.display = 'none';
    }
}

function expDrawTimestampAndShow(source, srcWidth, srcHeight) {
    const canvas = document.getElementById('expCameraCanvas');
    
    // Scale image down to maximum width/height of 1024px to prevent large Base64 payload
    const maxDimension = 1024;
    let targetWidth = srcWidth;
    let targetHeight = srcHeight;

    if (srcWidth > maxDimension || srcHeight > maxDimension) {
        if (srcWidth > srcHeight) {
            targetWidth = maxDimension;
            targetHeight = Math.round((srcHeight * maxDimension) / srcWidth);
        } else {
            targetHeight = maxDimension;
            targetWidth = Math.round((srcWidth * maxDimension) / srcHeight);
        }
    }

    canvas.width = targetWidth;
    canvas.height = targetHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(source, 0, 0, targetWidth, targetHeight);

    const now = new Date();
    const label = 'EXPENSE PROOF \u2022 ' + now.toLocaleString('en-US', {
        year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
    });

    const fontSize = Math.max(14, Math.round(canvas.width * 0.032));
    ctx.font = 'bold ' + fontSize + 'px Arial, sans-serif';
    const textWidth = ctx.measureText(label).width;
    const padX = 10;
    const barHeight = fontSize + 16;

    ctx.fillStyle = 'rgba(0,0,0,0.65)';
    ctx.fillRect(0, canvas.height - barHeight, Math.min(canvas.width, textWidth + padX * 2), barHeight);

    ctx.fillStyle = '#ffffff';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, padX, canvas.height - barHeight / 2);

    const dataUrl = canvas.toDataURL('image/jpeg', 0.80);
    document.getElementById('expCapturedPhoto').src = dataUrl;
    document.getElementById('expCapturedPhoto').style.display = 'block';

    document.getElementById('expCameraVideo').style.display = 'none';
    document.getElementById('expUploadMode').style.display = 'none';
    document.getElementById('expCaptureModeToggle').style.display = 'none';

    document.getElementById('expCaptureBtn').style.display = 'none';
    document.getElementById('expRetakeBtn').style.display = 'inline-block';
    document.getElementById('expConfirmBtn').style.display = 'inline-block';

    expStopCamera();
}

function expCapturePhoto() {
    const video = document.getElementById('expCameraVideo');
    expDrawTimestampAndShow(video, video.videoWidth, video.videoHeight);
}

function expHandleFileUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (!file.type.startsWith('image/')) {
        Swal.fire({
            title: 'Invalid File',
            text: 'Please select a valid image file.',
            icon: 'error',
            confirmButtonColor: '#dc3545'
        });
        return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
        const img = new Image();
        img.onload = function () {
            expDrawTimestampAndShow(img, img.naturalWidth, img.naturalHeight);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function expRetakePhoto() {
    document.getElementById('expCapturedPhoto').style.display = 'none';
    document.getElementById('expCaptureModeToggle').style.display = '';
    document.getElementById('expRetakeBtn').style.display = 'none';
    document.getElementById('expConfirmBtn').style.display = 'none';
    expSwitchMode(expCurrentMode);
}

function expConfirmCapture() {
    document.getElementById('expPendingDescription').value = document.getElementById('expDescription').value.trim();
    document.getElementById('expPendingAmount').value = parseFloat(document.getElementById('expAmount').value) || 0;
    document.getElementById('expPendingPhoto').value = document.getElementById('expCapturedPhoto').src;
    expStopCamera();
    document.getElementById('expenseForm').submit();
}

function expCancelCamera() {
    expStopCamera();
    const modalEl = document.getElementById('expenseCameraModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();
}

function expStopCamera() {
    if (expCameraStream) {
        expCameraStream.getTracks().forEach(function (track) { track.stop(); });
        expCameraStream = null;
    }
}

document.getElementById('expenseCameraModal').addEventListener('hidden.bs.modal', expStopCamera);
</script>

<?php require 'includes/footer.php'; ?>