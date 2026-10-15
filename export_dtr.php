<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';

checkAccess('trainee, supervisor, admin, superadmin');

$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

$trainee_id = (int)($_GET['id'] ?? 0);
if ($trainee_id <= 0) {
    die("Invalid trainee.");
}

// FIX: Handle both standard dashboard structures ('2026-04' format vs raw integers)
$get_month = $_GET['month'] ?? '';
if (strpos($get_month, '-') !== false) {
    list($year_val, $month_val) = explode('-', $get_month);
    $month = (int)$month_val;
    $year = (int)$year_val;
} else {
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
}

if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

// Handle period parameters dynamically ('1st_half'/'2nd_half' strings vs numeric flags)
$get_period = $_GET['period'] ?? '1';
if ($get_period === '1st_half' || $get_period === '1') {
    $period = 1;
} elseif ($get_period === '2nd_half' || $get_period === '2') {
    $period = 2;
} else {
    $period = 1; // Default fallback to Full Month logic if handling a third option
}

if ($role === 'trainee' && $trainee_id !== $user_id) {
    die("Unauthorized access.");
}

if ($role === 'supervisor') {
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $supervisor_dept = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$trainee_id]);
    $trainee_dept = $stmt->fetchColumn();

    if (!$supervisor_dept || !$trainee_dept || $supervisor_dept !== $trainee_dept) {
        die("Unauthorized access.");
    }
}

$stmt = $pdo->prepare("SELECT * FROM trainees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$trainee_id]);
$trainee = $stmt->fetch();
if (!$trainee) {
    die("Trainee not found.");
}

// Determine strict calendar ranges
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
if ($period === 1) {
    $startDay = 1;
    $endDay = 15;
} else {
    $startDay = 16;
    $endDay = $daysInMonth;
}

$startDateStr = sprintf('%04d-%02d-%02d', $year, $month, $startDay);
$endDateStr = sprintf('%04d-%02d-%02d', $year, $month, $endDay);

// Fetch DB Context Logs
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE trainee_id = ? AND date >= ? AND date <= ? AND deleted_at IS NULL ORDER BY date ASC");
$stmt->execute([$trainee_id, $startDateStr, $endDateStr]);
$logs = [];
foreach ($stmt->fetchAll() as $log) {
    $logs[$log['date']] = $log;
}

$stmt = $pdo->prepare("SELECT * FROM schedules WHERE trainee_id = ? AND date >= ? AND date <= ? ORDER BY date ASC");
$stmt->execute([$trainee_id, $startDateStr, $endDateStr]);
$schedules = [];
foreach ($stmt->fetchAll() as $sched) {
    $schedules[$sched['date']] = $sched;
}

$stmt = $pdo->prepare("SELECT date, name, type FROM holidays WHERE date >= ? AND date <= ?");
$stmt->execute([$startDateStr, $endDateStr]);
$holidays = [];
foreach ($stmt->fetchAll() as $h) {
    $holidays[$h['date']] = $h;
}

header("Content-Type: application/vnd.ms-excel");
$safe_last_name = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$trainee['last_name']);
header("Content-Disposition: attachment; filename=DTR_" . strtoupper($safe_last_name) . "_" . date('M_Y', mktime(0, 0, 0, $month, 1, $year)) . "_P" . $period . ".xls");
?>

<style>
    .header-title { font-size: 14pt; font-weight: bold; text-align: center; color: #4e1d69; }
    .table-header { background-color: #4e1d69; color: #ffffff; font-weight: bold; text-align: center; border: 0.5pt solid #000; }
    .border-cell { border: 0.5pt solid #cccccc; padding: 5px; }
    .summary-box { background-color: #f8fafc; font-weight: bold; }
</style>

<table>
    <tr><td colspan="6" class="header-title">QUEST HOTEL AND CONFERENCE CENTER - CEBU</td></tr>
    <tr><td colspan="6" style="text-align: center; font-weight: bold;">DAILY TIME RECORD (DTR)</td></tr>
    <tr><td colspan="6"></td></tr>
    <tr>
        <td style="font-weight: bold;">Name:</td>
        <td colspan="2"><?php echo strtoupper($trainee['first_name'] . ' ' . $trainee['last_name']); ?></td>
        <td style="font-weight: bold;">Period:</td>
        <td><?php echo date('F d', mktime(0,0,0,$month,$startDay,$year)) . '-' . date('d, Y', mktime(0,0,0,$month,$endDay,$year)); ?></td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Department:</td>
        <td colspan="5"><?php echo $trainee['department']; ?></td>
    </tr>
    <tr><td colspan="6"></td></tr>

    <thead>
        <tr>
            <th class="table-header" width="120">DATE</th>
            <th class="table-header" width="100">TIME IN</th>
            <th class="table-header" width="100">TIME OUT</th>
            <th class="table-header" width="80">HOURS</th>
            <th class="table-header" width="250">NARRATIVE / TASK DONE</th>
            <th class="table-header" width="100">STATUS</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $total_seconds = 0;
        
        // Loop through every single day sequentially without jumping or skipping entries
        for ($d = $startDay; $d <= $endDay; $d++): 
            $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $dayOfWeek = date('N', strtotime($currentDate)); // 1 (Mon) to 7 (Sun)
            
            $log = $logs[$currentDate] ?? null;
            $sched = $schedules[$currentDate] ?? null;
            $holiday = $holidays[$currentDate] ?? null;

            $diff = 0;
            
            // Check state conditions sequentially
            if ($log) {
                $status = $log['verified_at'] ? 'VERIFIED' : 'PENDING';
                if ($log['time_in'] && $log['time_out']) {
                    $diff = (strtotime($log['time_out']) - strtotime($log['time_in']));
                    $total_seconds += $diff;
                }
            } else if ($sched && $sched['status'] === 'day_off') {
                $status = 'DAY OFF';
            } else if ($holiday) {
                $status = 'HOLIDAY';
            } else if ($dayOfWeek >= 6) {
                // Fix for days off that don't have database schedule records explicitly added
                $status = 'WEEKEND';
            } else {
                $status = 'ABSENT / NO LOG';
            }
        ?>
        <tr>
            <td class="border-cell" align="center"><?php echo date('M d, Y (D)', strtotime($currentDate)); ?></td>
            <td class="border-cell" align="center">
                <?php 
                if ($log) echo date('h:i A', strtotime($log['time_in']));
                else if ($status === 'DAY OFF' || $status === 'WEEKEND') echo 'OFF';
                else if ($holiday) echo 'HOL';
                else echo '--';
                ?>
            </td>
            <td class="border-cell" align="center">
                <?php 
                if ($log && $log['time_out']) echo date('h:i A', strtotime($log['time_out']));
                else if ($status === 'DAY OFF' || $status === 'WEEKEND') echo 'OFF';
                else if ($holiday) echo 'HOL';
                else echo '--';
                ?>
            </td>
            <td class="border-cell" align="center">
                <?php echo ($log && $log['time_out']) ? number_format($diff/3600, 1) : '-'; ?>
            </td>
            <td class="border-cell">
                <?php 
                if ($log) echo htmlspecialchars($log['narrative']);
                else if ($status === 'DAY OFF') echo 'Scheduled Day Off';
                else if ($status === 'WEEKEND') echo 'Weekend Rest Day';
                else if ($holiday) echo htmlspecialchars($holiday['name']);
                else echo 'No attendance entry recorded.';
                ?>
            </td>
            <td class="border-cell" align="center" style="color: <?php echo ($status === 'VERIFIED') ? 'green' : (($status === 'PENDING' || $status === 'DAY OFF' || $status === 'WEEKEND') ? 'orange' : 'red'); ?>;"><?php echo $status; ?></td>
        </tr>
        <?php endfor; ?>
        <tr class="summary-box">
            <td colspan="4" align="right" class="border-cell">TOTAL HOURS FOR THIS PERIOD:</td>
            <td colspan="2" class="border-cell"><?php echo floor($total_seconds / 3600); ?> Hours and <?php echo floor(($total_seconds % 3600) / 60); ?> Minutes</td>
        </tr>
    </tbody>
    <tr><td colspan="6"></td></tr>
    <tr>
        <td colspan="2" style="text-align: center;"><br><br>__________________________<br>Trainee Signature</td>
        <td></td>
        <td colspan="2" style="text-align: center;"><br><br>__________________________<br>Department Supervisor</td>
    </tr>
</table>