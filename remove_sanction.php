<?php
session_start();
require_once 'config/db.php';

header('Content-Type: application/json');

// Check authorization: HR Admin or Superadmin only
$allowedRoles = ['hr_admin', 'superadmin'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowedRoles)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Permitted for HR Admin and Superadmin only.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sanction_id = intval($_POST['sanction_id'] ?? 0);
    $removal_reason = trim($_POST['removal_reason'] ?? '');
    $admin_id = $_SESSION['user_id'] ?? 0;

    if ($sanction_id <= 0 || empty($removal_reason)) {
        echo json_encode(['success' => false, 'message' => 'Sanction ID and reason for removal are required.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE sanctions 
            SET is_removed = 1, 
                removed_by = ?, 
                removal_reason = ?, 
                removed_at = NOW() 
            WHERE id = ? AND is_removed = 0
        ");
        $stmt->execute([$admin_id, $removal_reason, $sanction_id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Sanction removed successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sanction record not found or already removed.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>