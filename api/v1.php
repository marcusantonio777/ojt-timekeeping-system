<?php
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';
$role = $_SESSION['role'] ?? '';

$adminOnly = ['get_stats', 'get_trainees', 'get_attendance_trend'];
if (in_array($action, $adminOnly, true) && !in_array($role, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

switch ($action) {
    case 'get_stats':
        $total_ojts = $pdo->query("SELECT COUNT(*) FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL")->fetchColumn();
        $active_now = $pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND time_out IS NULL AND deleted_at IS NULL")->fetchColumn();
        $pending_requests = $pdo->query("SELECT COUNT(*) FROM requests WHERE hr_status = 'pending' AND supervisor_status = 'approved' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn();
        echo json_encode([
            'total_ojts' => $total_ojts, 
            'active_now' => $active_now,
            'pending_requests' => $pending_requests
        ]);
        break;

    case 'get_trainees':
        $dept = isset($_GET['department']) ? trim((string)$_GET['department']) : null;
        if ($dept !== null && $dept !== '') {
            $dept = mb_substr($dept, 0, 50);
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, department, status FROM trainees WHERE role = 'trainee' AND department = ? AND deleted_at IS NULL");
            $stmt->execute([$dept]);
        } else {
            $stmt = $pdo->query("SELECT id, first_name, last_name, department, status FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL");
        }
        echo json_encode($stmt->fetchAll());
        break;

    case 'get_attendance_trend':
        $days = 7;
        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND deleted_at IS NULL");
            $count->execute([$date]);
            $data[] = [
                'date' => date('M d', strtotime($date)),
                'count' => $count->fetchColumn()
            ];
        }
        echo json_encode($data);
        break;
    
    default:
        echo json_encode(['message' => 'Quest API v1.0 Operational', 'status' => 'success']);
        break;
}
