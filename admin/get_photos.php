<?php
// I-adjust ang path kay naa man ni sulod sa 'admin' folder
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';

checkAccess('superadmin, admin, supervisor');

$trainee_id = $_GET['trainee_id'] ?? null;
if (!$trainee_id) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

if ($_SESSION['role'] === 'supervisor') {
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['user_id']]);
    $sup_dept = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$trainee_id]);
    $trainee_dept = $stmt->fetchColumn();
    if (!$sup_dept || !$trainee_dept || $sup_dept !== $trainee_dept) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit();
    }
}

try {
    // Siguroha nga ang column names (photo_in/photo_out) husto sa imong database
    $stmt = $pdo->prepare("
        (SELECT date, photo_in as photo, 'Time In' as type, time_in as time FROM attendance WHERE trainee_id = ? AND photo_in IS NOT NULL AND photo_in != '' AND deleted_at IS NULL)
        UNION
        (SELECT date, photo_out as photo, 'Time Out' as type, time_out as time FROM attendance WHERE trainee_id = ? AND photo_out IS NOT NULL AND photo_out != '' AND deleted_at IS NULL)
        ORDER BY date DESC, time DESC
        LIMIT 20
    ");
    $stmt->execute([$trainee_id, $trainee_id]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($photos);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([]);
}
exit();
