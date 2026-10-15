<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';

checkAccess('supervisor, admin, superadmin');

$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');
$dept = $_GET['dept'] ?? '';
$period = $_GET['period'] ?? 'full';

// If supervisor, enforce dept filter to their own dept
if ($_SESSION['role'] === 'supervisor') {
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $supervisor_dept = $stmt->fetchColumn();
    $dept = $supervisor_dept;
}

// Determine date range based on period
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
if ($period === '1st_half') {
    $startDay = 1;
    $endDay = 15;
} elseif ($period === '2nd_half') {
    $startDay = 16;
    $endDay = $daysInMonth;
} else {
    $startDay = 1;
    $endDay = $daysInMonth;
}

$startDate = sprintf('%04d-%02d-%02d', $year, $month, $startDay);
$endDate = sprintf('%04d-%02d-%02d', $year, $month, $endDay);

// 1. FETCH DATA
$query = "
    SELECT 
        t.first_name, t.last_name, t.department, t.school,
        a.date, a.time_in, a.time_out, a.narrative, a.verified_at,
        s.first_name as supervisor_fname, s.last_name as supervisor_lname
    FROM attendance a
    JOIN trainees t ON a.trainee_id = t.id
    LEFT JOIN trainees s ON a.verified_by = s.id
    WHERE a.date >= ? AND a.date <= ? AND a.deleted_at IS NULL
";

$params = [$startDate, $endDate];
if ($dept) {
    $query .= " AND t.department = ?";
    $params[] = $dept;
}

$query .= " ORDER BY t.department, t.last_name, a.date";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. SET EXCEL HEADERS (Dili na CSV)
$periodLabel = ($period === '1st_half') ? '1st_Half' : (($period === '2nd_half') ? '2nd_Half' : 'Full');
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=QUEST_MASTER_REPORT_" . date('M_Y', mktime(0,0,0,$month,1,$year)) . "_" . $periodLabel . ".xls");
header("Pragma: no-cache");
header("Expires: 0");
?>

<style>
    .title { font-size: 16pt; font-weight: bold; color: #4e1d69; text-align: center; }
    .th { background-color: #4e1d69; color: #ffffff; font-weight: bold; border: 0.5pt solid #000000; text-align: center; }
    .td { border: 0.5pt solid #cccccc; vertical-align: middle; }
    .dept-row { background-color: #f3f4f6; font-weight: bold; }
    .text-center { text-align: center; }
</style>

<table>
    <tr>
        <td colspan="8" class="title">QUEST HOTEL AND CONFERENCE CENTER - CEBU</td>
    </tr>
    <tr>
        <td colspan="8" style="text-align: center; font-weight: bold;">OJT MASTER ATTENDANCE RECORD</td>
    </tr>
    <tr>
        <td colspan="8" style="text-align: center;">Period: <?php echo date('F d', mktime(0,0,0,$month,$startDay,$year)) . ' - ' . date('d, Y', mktime(0,0,0,$month,$endDay,$year)); ?></td>
    </tr>
    <tr><td colspan="8"></td></tr>

    <thead>
        <tr>
            <th class="th" width="200">TRAINEE NAME</th>
            <th class="th" width="150">DEPARTMENT</th>
            <th class="th" width="150">SCHOOL</th>
            <th class="th" width="100">DATE</th>
            <th class="th" width="80">IN</th>
            <th class="th" width="80">OUT</th>
            <th class="th" width="100">DURATION</th>
            <th class="th" width="200">VERIFIED BY</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($records) > 0): ?>
            <?php foreach ($records as $r): 
                $duration = '--';
                if ($r['time_in'] && $r['time_out']) {
                    $diff = strtotime($r['time_out']) - strtotime($r['time_in']);
                    $duration = floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm';
                }
                $v_by = $r['supervisor_fname'] ? $r['supervisor_fname'].' '.$r['supervisor_lname'] : 'PENDING';
            ?>
            <tr>
                <td class="td"><?php echo strtoupper($r['last_name'] . ', ' . $r['first_name']); ?></td>
                <td class="td text-center"><?php echo $r['department']; ?></td>
                <td class="td"><?php echo $r['school']; ?></td>
                <td class="td text-center"><?php echo date('M d, Y', strtotime($r['date'])); ?></td>
                <td class="td text-center"><?php echo date('h:i A', strtotime($r['time_in'])); ?></td>
                <td class="td text-center"><?php echo $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '--'; ?></td>
                <td class="td text-center"><?php echo $duration; ?></td>
                <td class="td" style="color: <?php echo $r['supervisor_fname'] ? '#059669' : '#d97706'; ?>;">
                    <?php echo $v_by; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="8" class="td text-center">No records found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>