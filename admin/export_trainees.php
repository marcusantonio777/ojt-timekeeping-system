<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
checkAccess('admin, superadmin');

$school_filter = (isset($_GET['school']) && $_GET['school'] !== '') ? $_GET['school'] : 'All';

// Set headers for Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=OJT_SCHOOL_REPORT_" . date('Ymd') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

// Fetch Trainees
$query = "
    SELECT t.*, 
    (SELECT SUM(hours_worked) FROM attendance WHERE trainee_id = t.id AND deleted_at IS NULL) as rendered_standard,
    (SELECT SUM(verified_ot_seconds) FROM attendance WHERE trainee_id = t.id AND deleted_at IS NULL) as rendered_ot_seconds
    FROM trainees t
    WHERE t.role = 'trainee' AND t.deleted_at IS NULL
";
$params = [];
if ($school_filter !== 'All') {
    $query .= " AND t.school = ?";
    $params[] = $school_filter;
}
$query .= " ORDER BY t.school ASC, t.last_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$trainees = $stmt->fetchAll();

// Group by School if categorized
$grouped = [];
foreach ($trainees as $t) {
    $grouped[$t['school']][] = $t;
}
?>

<style>
    .header { background-color: #6D3089; color: #ffffff; font-weight: bold; text-align: center; border: 1pt solid #D4AF37; }
    .school-header { background-color: #F3E8FF; color: #6D3089; font-weight: bold; border-bottom: 2pt solid #D4AF37; }
    .border { border: 0.5pt solid #E2E8F0; }
    .title { font-size: 16pt; font-weight: bold; color: #6D3089; border-bottom: 1pt solid #D4AF37; }
    .stats { font-weight: bold; color: #059669; }
    .sanction { color: #DC2626; font-size: 8pt; }
</style>

<table>
    <tr>
        <td colspan="12" class="title" style="text-align: center;">QUEST HOTEL AND CONFERENCE CENTER - CEBU</td>
    </tr>
    <tr>
        <td colspan="12" style="text-align: center; font-weight: bold;">OJT SCHOOL COMPLIANCE REPORT (RENDERED HOURS)</td>
    </tr>
    <tr><td colspan="12"></td></tr>

    <?php foreach ($grouped as $school => $students): ?>
        <tr>
            <td colspan="12" class="school-header border" style="font-size: 12pt; padding: 10px;">
                SCHOOL: <?php echo htmlspecialchars(strtoupper($school)); ?> 
                (<?php echo count($students); ?> Trainees)
            </td>
        </tr>
        <thead>
            <tr>
                <th class="header border">TRAINEE ID</th>
                <th class="header border">NAME</th>
                <th class="header border">COURSE</th>
                <th class="header border">DEPARTMENT</th>
                <th class="header border">START DATE</th>
                <th class="header border">STD HRS</th>
                <th class="header border">VERIFIED OT</th>
                <th class="header border">TOTAL HRS</th>
                <th class="header border">REQUIRED</th>
                <th class="header border">SANCTION</th>
                <th class="header border">REMAINING</th>
                <th class="header border">STATUS</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $s): 
                $initial_hours = (float)($s['initial_hours'] ?? 0);
                $rendered_standard = (float)($s['rendered_standard'] ?? 0);
                $ot_hours = round(($s['rendered_ot_seconds'] ?? 0) / 3600, 2);
                $total_rendered = $initial_hours + $rendered_standard + $ot_hours;
                
                $required_hours = (int)($s['required_hours'] ?: DEFAULT_REQUIRED_HOURS);
                
                // Fetch Sanctions
                $stmt = $pdo->prepare("SELECT reason, hours_added, created_at FROM sanctions WHERE trainee_id = ? AND deleted_at IS NULL");
                $stmt->execute([$s['id']]);
                $sanctions = $stmt->fetchAll();
                
                $total_sanctions = 0;
                foreach($sanctions as $san) $total_sanctions += (float)$san['hours_added'];
                $goal = $required_hours + $total_sanctions;
                $remaining = max(0, $goal - $total_rendered);
            ?>
            <tr>
                <td class="border" style="text-align: center;"><?php echo htmlspecialchars($s['username'] ?? ''); ?></td>
                <td class="border"><?php echo htmlspecialchars(strtoupper($s['last_name'] . ', ' . $s['first_name'])); ?></td>
                <td class="border"><?php echo htmlspecialchars($s['course'] ?? ''); ?></td>
                <td class="border" style="text-align: center;"><?php echo htmlspecialchars($s['department']); ?></td>
                <td class="border" style="text-align: center;"><?php echo !empty($s['created_at']) ? date('Y-m-d', strtotime($s['created_at'])) : ''; ?></td>
                <td class="border" style="text-align: center;"><?php echo number_format($rendered_standard + $initial_hours, 2); ?></td>
                <td class="border" style="text-align: center;"><?php echo number_format($ot_hours, 2); ?></td>
                <td class="border stats" style="text-align: center;"><?php echo number_format($total_rendered, 2); ?></td>
                <td class="border" style="text-align: center;"><?php echo number_format($goal, 2); ?></td>
                <td class="border" style="text-align: center;"><?php echo number_format($total_sanctions, 2); ?></td>
                <td class="border" style="text-align: center;"><?php echo number_format($remaining, 2); ?></td>
                <td class="border" style="text-align: center; font-weight: bold;"><?php echo strtoupper($s['status']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tr><td colspan="12"></td></tr>
    <?php endforeach; ?>

    <tr><td colspan="12"></td></tr>
    <tr>
        <td colspan="5" style="font-size: 8pt; font-style: italic;">School Compliance Report - Quest Hotel HR Division</td>
        <td colspan="7" style="font-size: 8pt; text-align: right; italic">Generated: <?php echo date('Y-m-d H:i:s'); ?></td>
    </tr>
</table>
