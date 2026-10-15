<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';

checkAccess('trainee, supervisor, admin, superadmin');

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// If trainee, they can only view their own schedule
if ($role === 'trainee') {
    $trainee_id = $user_id; // Override trainee_id to current user
}

$date = $_GET['date'] ?? null;
$trainee_id = $trainee_id ?? ($_GET['trainee_id'] ?? null);
$month = $_GET['month'] ?? null;
$year = $_GET['year'] ?? null;

// Base query parts
$dept_filter = "";
$params = [];

if ($role === 'supervisor') {
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ?");
    $stmt->execute([$user_id]);
    $dept = $stmt->fetchColumn();
    $dept_filter = " AND t.department = ?";
}

if ($trainee_id) {
    // Fetch individual schedule for a month with attendance data
    $query = "
        SELECT s.date, s.shift_start, s.shift_end, s.status, t.first_name, t.last_name,
               a.time_in, a.time_out
        FROM schedules s
        JOIN trainees t ON s.trainee_id = t.id
        LEFT JOIN attendance a ON a.trainee_id = s.trainee_id AND a.date = s.date AND a.deleted_at IS NULL
        WHERE s.trainee_id = ? 
        AND MONTH(s.date) = ? 
        AND YEAR(s.date) = ?
        AND s.deleted_at IS NULL AND t.deleted_at IS NULL $dept_filter
    ";
    $params = [$trainee_id, $month, $year];
    if ($role === 'supervisor') $params[] = $dept;
} else {
    // Fetch global schedule for a specific date with attendance data
    $query = "
        SELECT t.first_name, t.last_name, t.department, s.shift_start, s.shift_end, s.status,
               a.time_in, a.time_out
        FROM schedules s
        JOIN trainees t ON s.trainee_id = t.id
        LEFT JOIN attendance a ON a.trainee_id = t.id AND a.date = s.date AND a.deleted_at IS NULL
        WHERE s.date = ? AND s.deleted_at IS NULL AND t.deleted_at IS NULL $dept_filter
    ";
    $params = [$date];
    if ($role === 'supervisor') $params[] = $dept;
    $query .= " ORDER BY t.department, t.last_name, t.first_name";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$scheduled = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($scheduled);
