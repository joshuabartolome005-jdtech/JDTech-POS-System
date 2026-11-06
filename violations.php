<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'includes/audit.php';
requireAdmin();

$pageTitle = 'Violations';

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_violation'])) {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $violationDate = $_POST['violation_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $deduction = (float)($_POST['deduction_amount'] ?? 0);
    $photoData = $_POST['photo'] ?? '';

    if ($targetUserId <= 0 || $description === '' || $deduction <= 0) {
        $error = 'Please select an employee, enter a description, and a valid deduction amount.';
    } else {
        $photoPath = null;

        if (preg_match('/^data:image\/(jpeg|jpg|png);base64,(.+)$/', $photoData, $matches)) {
            $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
            $binary = base64_decode($matches[2]);
            if ($binary !== false) {
                $uploadDir = __DIR__ . '/assets/images/violations/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'viol_' . $targetUserId . '_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.' . $ext;
                file_put_contents($uploadDir . $filename, $binary);
                $photoPath = 'assets/images/violations/' . $filename;
            }
        }

        if (!$photoPath) {
            $error = 'Proof photo is required (e.g. a CCTV screenshot showing the violation).';
        } else {
            $stmt = $pdo->prepare("INSERT INTO violations (user_id, violation_date, description, deduction_amount, proof_photo, logged_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$targetUserId, $violationDate, $description, $deduction, $photoPath, $_SESSION['user_id']]);

            $nameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $nameStmt->execute([$targetUserId]);
            $empName = $nameStmt->fetchColumn();

            log_audit($pdo, $_SESSION['user_id'], 'Violation Logged', "$empName - $description (-" . number_format($deduction, 2) . ")");

            $message = 'Violation recorded successfully.';
        }
    }
}

require 'includes/header.php';

$employees = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$stmt = $pdo->prepare("SELECT v.*, u.full_name FROM violations v JOIN users u ON v.user_id = u.id WHERE v.violation_date BETWEEN ? AND ? ORDER BY v.violation_date DESC");
$stmt->execute([$dateFrom, $dateTo]);
$violations = $stmt->fetchAll();
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Log a Violation</div>
            <div class="card-body">
                <p class="text-muted small">e.g. Caught on CCTV without a hairnet or incomplete uniform. This deducts from that employee's uniform/gear reward for the pay period.</p>

                <div class="mb-3">
                    <label class="form-label">Employee</label>
                    <select id="violUserId" class="form-select">
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Date</label>
                    <input type="date" id="violDate" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" id="violDescription" class="form-control" placeholder="e.g. No hairnet worn">
                </div>
                <div class="mb-3">
                    <label class="form-label">Deduction Amount (&#8369;)</label>
                    <input type="number" step="0.01" id="violDeduction" class="form-control" placeholder="0.00">
                </div>

                <button type="button" class="btn btn-danger w-100" onclick="openViolCameraModal()">
                    <i class="bi bi-camera"></i> Add Proof &amp; Save Violation
                </button>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Violation History</div>
            <div class="card-body">
                <form class="d-flex gap-2 mb-3 flex-wrap align-items-end" method="GET">
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
                            <tr><th>Employee</th><th>Description</th><th>Deduction</th><th>Date</th><th>Proof</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$violations): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No violations in this range.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($violations as $v): ?>
                            <tr>
                                <td><?= htmlspecialchars($v['full_name']) ?></td>
                                <td><?= htmlspecialchars($v['description']) ?></td>
                                <td class="text-danger">-&#8369;<?= number_format($v['deduction_amount'], 2) ?></td>
                                <td><?= date('M d, Y', strtotime($v['violation_date'])) ?></td>
                                <td>
                                    <?php if (!empty($v['proof_photo'])): ?>
                                        <a href="<?= htmlspecialchars($v['proof_photo']) ?>" target="_blank">
                                            <img src="<?= htmlspecialchars($v['proof_photo']) ?>" class="attendance-thumb" style="max-height: 40px;" alt="proof">
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
<div class="modal fade" id="violCameraModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Violation Proof</h5>
            </div>
            <div class="modal-body text-center">

                <div class="btn-group mb-3" role="group" id="violCaptureModeToggle">
                    <button type="button" class="btn btn-outline-primary active" id="violModeSelfieBtn" onclick="violSwitchMode('selfie')">
                        <i class="bi bi-camera"></i> Take Photo
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="violModeUploadBtn" onclick="violSwitchMode('upload')">
                        <i class="bi bi-upload"></i> Upload Photo
                    </button>
                </div>

                <div id="violSelfieMode">
                    <video id="violCameraVideo" autoplay playsinline class="camera-preview" style="width:100%; max-height:300px; background:#000;"></video>
                </div>

                <div id="violUploadMode" style="display:none;">
                    <input type="file" id="violUploadInput" accept="image/*" class="form-control mb-2" onchange="violHandleFileUpload(this)">
                    <p class="text-muted small mb-0">Choose a CCTV screenshot or photo of the violation.</p>
                </div>

                <img id="violCapturedPhoto" class="camera-preview" style="display:none; width:100%; max-height:300px;" alt="Captured photo">
                <canvas id="violCameraCanvas" style="display:none;"></canvas>
                <p id="violCameraError" class="text-danger small mt-2" style="display:none;"></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" onclick="violCancelCamera()">Cancel</button>
                <button type="button" class="btn btn-primary" id="violCaptureBtn" onclick="violCapturePhoto()">
                    <i class="bi bi-camera-fill"></i> Capture
                </button>
                <button type="button" class="btn btn-outline-secondary" id="violRetakeBtn" onclick="violRetakePhoto()" style="display:none;">Retake</button>
                <button type="button" class="btn btn-success" id="violConfirmBtn" onclick="violConfirmCapture()" style="display:none;">
                    <i class="bi bi-check-lg"></i> Confirm &amp; Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Form to send POST request -->
<form id="violationForm" method="POST" action="violations" style="display:none;">
    <input type="hidden" name="add_violation" value="1">
    <input type="hidden" name="user_id" id="violPendingUserId">
    <input type="hidden" name="violation_date" id="violPendingDate">
    <input type="hidden" name="description" id="violPendingDescription">
    <input type="hidden" name="deduction_amount" id="violPendingDeduction">
    <input type="hidden" name="photo" id="violPendingPhoto">
</form>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let violCameraStream = null;
let violCurrentMode = 'selfie';

// Trigger server response via SweetAlert2
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

function openViolCameraModal() {
    const userId = document.getElementById('violUserId').value;
    const date = document.getElementById('violDate').value;
    const desc = document.getElementById('violDescription').value.trim();
    const deduction = parseFloat(document.getElementById('violDeduction').value) || 0;

    if (!desc) {
        Swal.fire({
            title: 'Missing Description',
            text: 'Please enter a description.',
            icon: 'warning',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }
    if (deduction <= 0) {
        Swal.fire({
            title: 'Invalid Amount',
            text: 'Please enter a valid deduction amount.',
            icon: 'warning',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }

    document.getElementById('violCapturedPhoto').style.display = 'none';
    document.getElementById('violCameraError').style.display = 'none';
    document.getElementById('violRetakeBtn').style.display = 'none';
    document.getElementById('violConfirmBtn').style.display = 'none';
    document.getElementById('violUploadInput').value = '';
    document.getElementById('violCaptureModeToggle').style.display = '';

    violSwitchMode('selfie');

    const modal = new bootstrap.Modal(document.getElementById('violCameraModal'));
    modal.show();
}

function violSwitchMode(mode) {
    violCurrentMode = mode;
    document.getElementById('violCapturedPhoto').style.display = 'none';
    document.getElementById('violRetakeBtn').style.display = 'none';
    document.getElementById('violConfirmBtn').style.display = 'none';
    document.getElementById('violCameraError').style.display = 'none';

    document.getElementById('violModeSelfieBtn').classList.toggle('active', mode === 'selfie');
    document.getElementById('violModeUploadBtn').classList.toggle('active', mode === 'upload');

    if (mode === 'selfie') {
        document.getElementById('violSelfieMode').style.display = 'block';
        document.getElementById('violUploadMode').style.display = 'none';
        document.getElementById('violCaptureBtn').style.display = 'inline-block';

        violStopCamera();
        navigator.mediaDevices.getUserMedia({ video: true, audio: false })
            .then(function (stream) {
                violCameraStream = stream;
                const video = document.getElementById('violCameraVideo');
                video.srcObject = stream;
                video.style.display = 'block';
            })
            .catch(function (err) {
                const errEl = document.getElementById('violCameraError');
                errEl.innerText = 'Unable to access the camera: ' + err.message + '. You can use "Upload Photo" instead.';
                errEl.style.display = 'block';
            });
    } else {
        violStopCamera();
        document.getElementById('violSelfieMode').style.display = 'none';
        document.getElementById('violUploadMode').style.display = 'block';
        document.getElementById('violCaptureBtn').style.display = 'none';
    }
}

function violDrawTimestampAndShow(source, srcWidth, srcHeight) {
    const canvas = document.getElementById('violCameraCanvas');
    canvas.width = srcWidth;
    canvas.height = srcHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(source, 0, 0, srcWidth, srcHeight);

    const now = new Date();
    const label = 'VIOLATION PROOF \u2022 ' + now.toLocaleString('en-US', {
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

    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('violCapturedPhoto').src = dataUrl;
    document.getElementById('violCapturedPhoto').style.display = 'block';

    document.getElementById('violCameraVideo').style.display = 'none';
    document.getElementById('violUploadMode').style.display = 'none';
    document.getElementById('violCaptureModeToggle').style.display = 'none';

    document.getElementById('violCaptureBtn').style.display = 'none';
    document.getElementById('violRetakeBtn').style.display = 'inline-block';
    document.getElementById('violConfirmBtn').style.display = 'inline-block';

    violStopCamera();
}

function violCapturePhoto() {
    const video = document.getElementById('violCameraVideo');
    violDrawTimestampAndShow(video, video.videoWidth, video.videoHeight);
}

function violHandleFileUpload(input) {
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
            violDrawTimestampAndShow(img, img.naturalWidth, img.naturalHeight);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function violRetakePhoto() {
    document.getElementById('violCapturedPhoto').style.display = 'none';
    document.getElementById('violCaptureModeToggle').style.display = '';
    document.getElementById('violRetakeBtn').style.display = 'none';
    document.getElementById('violConfirmBtn').style.display = 'none';
    violSwitchMode(violCurrentMode);
}

function violConfirmCapture() {
    document.getElementById('violPendingUserId').value = document.getElementById('violUserId').value;
    document.getElementById('violPendingDate').value = document.getElementById('violDate').value;
    document.getElementById('violPendingDescription').value = document.getElementById('violDescription').value.trim();
    document.getElementById('violPendingDeduction').value = parseFloat(document.getElementById('violDeduction').value) || 0;
    document.getElementById('violPendingPhoto').value = document.getElementById('violCapturedPhoto').src;
    violStopCamera();
    document.getElementById('violationForm').submit();
}

function violCancelCamera() {
    violStopCamera();
    const modalEl = document.getElementById('violCameraModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();
}

function violStopCamera() {
    if (violCameraStream) {
        violCameraStream.getTracks().forEach(function (track) { track.stop(); });
        violCameraStream = null;
    }
}

document.getElementById('violCameraModal').addEventListener('hidden.bs.modal', violStopCamera);
</script>

<?php require 'includes/footer.php'; ?>