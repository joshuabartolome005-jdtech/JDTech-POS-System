<?php
$pageTitle = 'Attendance';
require 'includes/header.php';

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$userId = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// Current user's open/most recent record for today
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND DATE(time_in) = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $today]);
$todayRecord = $stmt->fetch();

$state = 'not_timed_in';
if ($todayRecord) {
    if ($todayRecord['time_out']) {
        $state = 'completed';
    } elseif ($todayRecord['break_start'] && !$todayRecord['break_end']) {
        $state = 'on_break';
    } else {
        $state = 'on_duty';
    }
}

function calcHours($record) {
    if (!$record['time_out']) return null;
    $seconds = strtotime($record['time_out']) - strtotime($record['time_in']);
    if ($record['break_start'] && $record['break_end']) {
        $seconds -= (strtotime($record['break_end']) - strtotime($record['break_start']));
    }
    return max(0, $seconds) / 3600;
}

// History
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');

if ($isAdmin) {
    $filterUser = (int)($_GET['user_id'] ?? 0);
    $sql = "SELECT a.*, u.full_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE DATE(a.time_in) BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    if ($filterUser > 0) {
        $sql .= " AND a.user_id = ?";
        $params[] = $filterUser;
    }
    $sql .= " ORDER BY a.time_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll();

    $employees = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND DATE(time_in) BETWEEN ? AND ? ORDER BY time_in DESC");
    $stmt->execute([$userId, $dateFrom, $dateTo]);
    $history = $stmt->fetchAll();
}
?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
<?php elseif (isset($_GET['success'])): ?>
    <div class="alert alert-success">Recorded successfully.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body text-center py-4">
        <h5 class="mb-3">Hi, <?= htmlspecialchars($_SESSION['full_name']) ?></h5>

        <?php if ($state === 'not_timed_in'): ?>
            <p class="text-muted mb-3">You have not timed in today.</p>
            <button type="button" class="btn btn-success btn-lg px-5" onclick="openCameraModal('time_in')">
                <i class="bi bi-camera"></i> Time In
            </button>

        <?php elseif ($state === 'on_duty'): ?>
            <p class="text-muted mb-1">Timed in at <strong><?= date('h:i A', strtotime($todayRecord['time_in'])) ?></strong></p>
            <?php if ($todayRecord['break_end']): ?>
                <p class="text-muted mb-3">Break: <?= date('h:i A', strtotime($todayRecord['break_start'])) ?> - <?= date('h:i A', strtotime($todayRecord['break_end'])) ?></p>
            <?php endif; ?>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <?php if (!$todayRecord['break_start']): ?>
                <button type="button" class="btn btn-warning btn-lg px-4" onclick="openCameraModal('break_start')">
                    <i class="bi bi-camera"></i> Start Break
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-danger btn-lg px-4" onclick="openCameraModal('time_out')">
                    <i class="bi bi-camera"></i> Time Out
                </button>
            </div>

        <?php elseif ($state === 'on_break'): ?>
            <p class="text-muted mb-1">Timed in at <strong><?= date('h:i A', strtotime($todayRecord['time_in'])) ?></strong></p>
            <p class="text-warning mb-3"><i class="bi bi-cup-hot-fill"></i> On break since <?= date('h:i A', strtotime($todayRecord['break_start'])) ?></p>
            <button type="button" class="btn btn-primary btn-lg px-5" onclick="openCameraModal('break_end')">
                <i class="bi bi-camera"></i> End Break
            </button>

        <?php else: ?>
            <p class="text-success mb-1"><i class="bi bi-check-circle-fill"></i> You're done for today.</p>
            <p class="text-muted mb-1">
                In: <strong><?= date('h:i A', strtotime($todayRecord['time_in'])) ?></strong>
                &nbsp;|&nbsp; Out: <strong><?= date('h:i A', strtotime($todayRecord['time_out'])) ?></strong>
                <?php if ($todayRecord['break_start'] && $todayRecord['break_end']): ?>
                    &nbsp;|&nbsp; Break: <?= date('h:i A', strtotime($todayRecord['break_start'])) ?> - <?= date('h:i A', strtotime($todayRecord['break_end'])) ?>
                <?php endif; ?>
            </p>
            <p class="fw-bold fs-5 mb-0">Total: <?= number_format(calcHours($todayRecord), 2) ?> hrs</p>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <?= $isAdmin ? 'Employee Attendance History' : 'My Attendance History' ?>
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
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Break</th>
                        <th>Time Out</th>
                        <th>Total Hours</th>
                        <th>Selfies</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center text-muted py-4">No attendance records in this range.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <?php if ($isAdmin): ?><td><?= htmlspecialchars($h['full_name']) ?></td><?php endif; ?>
                        <td><?= date('M d, Y', strtotime($h['time_in'])) ?></td>
                        <td><?= date('h:i A', strtotime($h['time_in'])) ?></td>
                        <td>
                            <?php if ($h['break_start'] && $h['break_end']): ?>
                                <?= date('h:i A', strtotime($h['break_start'])) ?> - <?= date('h:i A', strtotime($h['break_end'])) ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($h['time_out']): ?>
                                <?= date('h:i A', strtotime($h['time_out'])) ?>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Ongoing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $hrs = calcHours($h); ?>
                            <?= $hrs !== null ? number_format($hrs, 2) . ' hrs' : '-' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php foreach (['time_in_photo' => 'In', 'break_start_photo' => 'BS', 'break_end_photo' => 'BE', 'time_out_photo' => 'Out'] as $col => $label): ?>
                                    <?php if (!empty($h[$col])): ?>
                                        <a href="<?= htmlspecialchars($h[$col]) ?>" target="_blank" title="<?= $label ?> photo">
                                            <img src="<?= htmlspecialchars($h[$col]) ?>" class="attendance-thumb" alt="<?= $label ?>">
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Photo verification modal: live selfie OR uploaded photo, both get a
     timestamp + action watermark burned into the image before saving -->
<div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Verify You're at the Kiosk</h5>
            </div>
            <div class="modal-body text-center">

                <div class="btn-group mb-3" role="group" id="captureModeToggle">
                    <button type="button" class="btn btn-outline-primary active" id="modeSelfieBtn" onclick="switchMode('selfie')">
                        <i class="bi bi-camera"></i> Take Selfie
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="modeUploadBtn" onclick="switchMode('upload')">
                        <i class="bi bi-upload"></i> Upload Photo
                    </button>
                </div>

                <div id="selfieMode">
                    <video id="cameraVideo" autoplay playsinline class="camera-preview"></video>
                </div>

                <div id="uploadMode" style="display:none;">
                    <input type="file" id="uploadInput" accept="image/*" class="form-control mb-2" onchange="handleFileUpload(this)">
                    <p class="text-muted small mb-0">Choose a photo showing you at the kiosk.</p>
                </div>

                <img id="capturedPhoto" class="camera-preview" style="display:none;" alt="Captured photo">
                <canvas id="cameraCanvas" style="display:none;"></canvas>
                <p id="cameraError" class="text-danger small mt-2" style="display:none;"></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" onclick="cancelCamera()">Cancel</button>
                <button type="button" class="btn btn-primary" id="captureBtn" onclick="capturePhoto()">
                    <i class="bi bi-camera-fill"></i> Capture
                </button>
                <button type="button" class="btn btn-outline-secondary" id="retakeBtn" onclick="retakePhoto()" style="display:none;">Retake</button>
                <button type="button" class="btn btn-success" id="confirmBtn" onclick="confirmCapture()" style="display:none;">
                    <i class="bi bi-check-lg"></i> Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<form id="attendanceForm" method="POST" action="attendance_action" style="display:none;">
    <input type="hidden" name="action" id="pendingAction">
    <input type="hidden" name="photo" id="pendingPhoto">
</form>

<script>
let cameraStream = null;
let pendingActionType = null;
let currentMode = 'selfie';

const actionLabels = {
    time_in: 'TIME IN',
    break_start: 'BREAK START',
    break_end: 'BREAK END',
    time_out: 'TIME OUT'
};

function openCameraModal(actionType) {
    pendingActionType = actionType;

    document.getElementById('capturedPhoto').style.display = 'none';
    document.getElementById('cameraError').style.display = 'none';
    document.getElementById('retakeBtn').style.display = 'none';
    document.getElementById('confirmBtn').style.display = 'none';
    document.getElementById('uploadInput').value = '';
    document.getElementById('captureModeToggle').style.display = '';

    switchMode('selfie');

    const modal = new bootstrap.Modal(document.getElementById('cameraModal'));
    modal.show();
}

function switchMode(mode) {
    currentMode = mode;
    document.getElementById('capturedPhoto').style.display = 'none';
    document.getElementById('retakeBtn').style.display = 'none';
    document.getElementById('confirmBtn').style.display = 'none';
    document.getElementById('cameraError').style.display = 'none';

    document.getElementById('modeSelfieBtn').classList.toggle('active', mode === 'selfie');
    document.getElementById('modeUploadBtn').classList.toggle('active', mode === 'upload');

    if (mode === 'selfie') {
        document.getElementById('selfieMode').style.display = 'block';
        document.getElementById('uploadMode').style.display = 'none';
        document.getElementById('captureBtn').style.display = 'inline-block';

        stopCamera();
        navigator.mediaDevices.getUserMedia({ video: true, audio: false })
            .then(function (stream) {
                cameraStream = stream;
                const video = document.getElementById('cameraVideo');
                video.srcObject = stream;
                video.style.display = 'block';
            })
            .catch(function (err) {
                const errEl = document.getElementById('cameraError');
                errEl.innerText = 'Unable to access the camera: ' + err.message + '. You can use "Upload Photo" instead.';
                errEl.style.display = 'block';
            });
    } else {
        stopCamera();
        document.getElementById('selfieMode').style.display = 'none';
        document.getElementById('uploadMode').style.display = 'block';
        document.getElementById('captureBtn').style.display = 'none';
    }
}

// Draws the source (video frame or uploaded image) onto the canvas, then
// burns a timestamp + action label into the bottom-left corner before
// showing it as the confirmable preview.
function drawTimestampAndShow(source, srcWidth, srcHeight) {
    const canvas = document.getElementById('cameraCanvas');
    canvas.width = srcWidth;
    canvas.height = srcHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(source, 0, 0, srcWidth, srcHeight);

    const now = new Date();
    const label = actionLabels[pendingActionType] + '  \u2022  ' + now.toLocaleString('en-US', {
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
    document.getElementById('capturedPhoto').src = dataUrl;
    document.getElementById('capturedPhoto').style.display = 'block';

    document.getElementById('cameraVideo').style.display = 'none';
    document.getElementById('uploadMode').style.display = 'none';
    document.getElementById('captureModeToggle').style.display = 'none';

    document.getElementById('captureBtn').style.display = 'none';
    document.getElementById('retakeBtn').style.display = 'inline-block';
    document.getElementById('confirmBtn').style.display = 'inline-block';

    stopCamera();
}

function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    drawTimestampAndShow(video, video.videoWidth, video.videoHeight);
}

function handleFileUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (!file.type.startsWith('image/')) {
        alert('Please select a valid image file.');
        return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
        const img = new Image();
        img.onload = function () {
            drawTimestampAndShow(img, img.naturalWidth, img.naturalHeight);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function retakePhoto() {
    document.getElementById('capturedPhoto').style.display = 'none';
    document.getElementById('captureModeToggle').style.display = '';
    document.getElementById('retakeBtn').style.display = 'none';
    document.getElementById('confirmBtn').style.display = 'none';
    switchMode(currentMode);
}

function confirmCapture() {
    document.getElementById('pendingAction').value = pendingActionType;
    document.getElementById('pendingPhoto').value = document.getElementById('capturedPhoto').src;
    stopCamera();
    document.getElementById('attendanceForm').submit();
}

function cancelCamera() {
    stopCamera();
    const modalEl = document.getElementById('cameraModal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();
}

function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(function (track) { track.stop(); });
        cameraStream = null;
    }
}

document.getElementById('cameraModal').addEventListener('hidden.bs.modal', stopCamera);
</script>

<?php require 'includes/footer.php'; ?>