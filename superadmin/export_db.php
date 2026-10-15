<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
checkAccess('superadmin');

// Simple SQL Export
$tables = ['trainees', 'attendance', 'sanctions', 'transfers', 'schedules', 'audit_logs', 'system_settings', 'system_notifications', 'requests'];
$output = "-- QUEST HOTEL ELITE SYSTEM BACKUP\n";
$output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tables as $table) {
    $stmt = $pdo->query("SELECT * FROM $table");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $output .= "-- Table: $table\n";
    foreach ($rows as $row) {
        $keys = array_keys($row);
        $values = array_map(function($v) use ($pdo) {
            return $v === null ? 'NULL' : $pdo->quote($v);
        }, array_values($row));
        
        $output .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $values) . ");\n";
    }
    $output .= "\n";
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="quest_elite_backup_' . date('Ymd_His') . '.sql"');
echo $output;
exit();
?>