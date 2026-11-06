<?php
require 'config/database.php';
require 'config/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendance');
    exit;
}

// Always the currently logged-in user - there is no field for selecting
// a different employee, so it is not possible to clock in/out on behalf
// of someone else.
$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$photoData = $_POST['photo'] ?? '';
$today = date('Y-m-d');

// Save the selfie photo (sent as a base64 data URL from the camera capture)
function save_attendance_photo(string $photoData, int $userId): ?string
{
    if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,(.+)$/', $photoData, $matches)) {
        return null;
    }

    $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
    $binary = base64_decode($matches[2]);
    if ($binary === false) {
        return null;
    }

    $uploadDir = __DIR__ . '/assets/images/attendance/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'att_' . $userId . '_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.' . $ext;
    file_put_contents($uploadDir . $filename, $binary);

    return 'assets/images/attendance/' . $filename;
}

$photoPath = save_attendance_photo($photoData, $userId);

if (!$photoPath) {
    header('Location: attendance?error=' . urlencode('A selfie photo is required. Please allow camera access and try again.'));
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND DATE(time_in) = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $today]);
$record = $stmt->fetch();

switch ($action) {
    case 'time_in':
        if ($record && !$record['time_out']) {
            header('Location: attendance?error=' . urlencode('You already have an open time-in for today.'));
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO attendance (user_id, time_in, time_in_photo) VALUES (?, NOW(), ?)");
        $stmt->execute([$userId, $photoPath]);
        break;

    case 'break_start':
        if (!$record || $record['time_out'] || $record['break_start']) {
            header('Location: attendance?error=' . urlencode('Unable to start break.'));
            exit;
        }
        $stmt = $pdo->prepare("UPDATE attendance SET break_start = NOW(), break_start_photo = ? WHERE id = ?");
        $stmt->execute([$photoPath, $record['id']]);
        break;

    case 'break_end':
        if (!$record || $record['time_out'] || !$record['break_start'] || $record['break_end']) {
            header('Location: attendance?error=' . urlencode('Unable to end break.'));
            exit;
        }
        $stmt = $pdo->prepare("UPDATE attendance SET break_end = NOW(), break_end_photo = ? WHERE id = ?");
        $stmt->execute([$photoPath, $record['id']]);
        break;

    case 'time_out':
        if (!$record || $record['time_out']) {
            header('Location: attendance?error=' . urlencode('Unable to time out.'));
            exit;
        }
        $stmt = $pdo->prepare("UPDATE attendance SET time_out = NOW(), time_out_photo = ? WHERE id = ?");
        $stmt->execute([$photoPath, $record['id']]);
        break;

    default:
        header('Location: attendance?error=' . urlencode('Invalid action.'));
        exit;
}

header('Location: attendance?success=1');
exit;
