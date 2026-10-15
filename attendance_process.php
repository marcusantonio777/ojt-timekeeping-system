<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';
require_once 'includes/attendance_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_kiosk = isset($_POST['is_kiosk']) && $_POST['is_kiosk'] == '1';

    // CSRF Check
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $msg = "Security session expired.";
        if ($is_kiosk) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); }
        else { header("Location: dashboard.php?status=error&message=" . urlencode($msg)); }
        exit();
    }

    $user_id = null;

    if ($is_kiosk) {
        $username = trim($_POST['trainee_username'] ?? '');
        if (empty($username)) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Trainee ID required.']); exit(); }

        $stmt = $pdo->prepare("SELECT id, status FROM trainees WHERE username = ? AND role = 'trainee' AND deleted_at IS NULL");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid Trainee ID.']); exit(); }
        if ($user['status'] !== 'active') { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Account is ' . $user['status'] . '.']); exit(); }
        $user_id = $user['id'];
    } else {
        checkAccess('trainee');
        $user_id = $_SESSION['user_id'];
    }

    $type = $_POST['type'] ?? '';
    if ($type === 'time_in') $type = 'in';
    if ($type === 'time_out') $type = 'out';
    $photo = $_POST['image'] ?? $_POST['photo'] ?? '';
    $narrative = trim($_POST['narrative'] ?? '');

    // if ($type === 'out' && empty($narrative)) {
    //     $msg = "Narrative is required for Time Out.";
    //     if ($is_kiosk) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); }
    //     else { header("Location: dashboard.php?status=error&message=" . urlencode($msg)); }
    //     exit();
    // }

    $extra_data = ['lat' => $_POST['lat'] ?? null, 'lng' => $_POST['lng'] ?? null, 'narrative' => $narrative];
    $result = processAttendance($pdo, $user_id, $type, $photo, $extra_data);

    if ($is_kiosk) {
        header('Content-Type: application/json');
        echo json_encode($result);
    } else {
        $status = $result['success'] ? 'success' : 'error';
        header("Location: dashboard.php?status=$status&message=" . urlencode($result['message']));
    }
    exit();
}
header("Location: index.php");
exit();
