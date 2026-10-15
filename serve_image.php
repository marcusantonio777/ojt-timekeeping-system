<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';

checkAccess('trainee, supervisor, admin, superadmin');

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($request_id > 0) {
    $stmt = $pdo->prepare("
        SELECT r.trainee_id, r.attachment_path, r.attachment_name, r.attachment_mime, t.department
        FROM requests r
        JOIN trainees t ON r.trainee_id = t.id
        WHERE r.id = ? AND r.deleted_at IS NULL AND t.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$request_id]);
    $row = $stmt->fetch();
    if (!$row || empty($row['attachment_path'])) {
        http_response_code(404);
        die("File not found.");
    }

    $allowed = false;
    if ($role === 'superadmin' || $role === 'admin') {
        $allowed = true;
    } elseif ($role === 'trainee' && (int)$row['trainee_id'] === $user_id) {
        $allowed = true;
    } elseif ($role === 'supervisor') {
        $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$user_id]);
        $sup_dept = $stmt->fetchColumn();
        if ($sup_dept && $sup_dept === $row['department']) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        http_response_code(403);
        die("Unauthorized access.");
    }

    $stored = basename($row['attachment_path']);
    $file_path = 'uploads/requests/' . $stored;
    if (!file_exists($file_path)) {
        http_response_code(404);
        die("File not found.");
    }

    $mime_type = $row['attachment_mime'] ?: (mime_content_type($file_path) ?: 'application/octet-stream');
    $download_name = $row['attachment_name'] ?: $stored;

    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($file_path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"');
    readfile($file_path);
    exit();
}

if ($role !== 'superadmin' && $role !== 'admin' && $role !== 'supervisor') {
    http_response_code(403);
    die("Unauthorized access.");
}

$file = $_GET['file'] ?? null;
if (!$file) {
    http_response_code(400);
    die("File parameter is required.");
}

$trainee_id = isset($_GET['trainee_id']) ? (int)$_GET['trainee_id'] : 0;
if ($trainee_id <= 0) {
    http_response_code(400);
    die("Trainee ID is required.");
}

// Security: Prevent directory traversal
$file = basename($file);
$file_path = 'uploads/attendance/' . $file;

if (!file_exists($file_path)) {
    http_response_code(404);
    die("File not found.");
}

if ($role === 'supervisor') {
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $sup_dept = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$trainee_id]);
    $trainee_dept = $stmt->fetchColumn();

    if (!$sup_dept || !$trainee_dept || $sup_dept !== $trainee_dept) {
        http_response_code(403);
        die("Unauthorized access.");
    }
}

$stmt = $pdo->prepare("SELECT id FROM attendance WHERE trainee_id = ? AND deleted_at IS NULL AND (photo_in = ? OR photo_out = ?) LIMIT 1");
$stmt->execute([$trainee_id, $file, $file]);
if (!$stmt->fetch()) {
    http_response_code(403);
    die("Unauthorized access.");
}

// Check if it's a valid image
$mime_type = mime_content_type($file_path);
if (strpos($mime_type, 'image/') !== 0) {
    http_response_code(400);
    die("Invalid file type.");
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit();
?>
