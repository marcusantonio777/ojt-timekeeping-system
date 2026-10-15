<?php
/**
 * DATABASE CONFIGURATION
 * Optional .env in project root: DB_HOST, DB_NAME, DB_USER, DB_PASS
 */


$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '') {
            putenv("$k=$v");
        }
    }
}

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'quest_ojt_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     // AUTO-MIGRATION PROTOCOL (Quest Hotel Edition)
     // Ensure all required tables and columns exist
     $tables = [
         'trainees' => "CREATE TABLE IF NOT EXISTS `trainees` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `username` varchar(50) NOT NULL,
             `email` varchar(150) DEFAULT NULL,
             `password` varchar(255) NOT NULL,
             `first_name` varchar(100) NOT NULL,
             `last_name` varchar(100) NOT NULL,
             `department` varchar(100) NOT NULL,
             `school` varchar(150) NOT NULL DEFAULT 'Unknown',
             `course` varchar(150) NOT NULL DEFAULT '',
             `status` enum('pending','active','completed','suspended') NOT NULL DEFAULT 'pending',
             `required_hours` int(11) NOT NULL DEFAULT 400,
             `initial_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
             `shift_start` time DEFAULT '08:00:00',
             `shift_end` time DEFAULT '17:00:00',
             `rating` int(1) DEFAULT NULL,
             `rating_comment` text DEFAULT NULL,
             `role` enum('superadmin','admin','supervisor','trainee') NOT NULL DEFAULT 'trainee',
             `deleted_at` timestamp NULL DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             `is_cleared` tinyint(1) DEFAULT 0,
             `phone` varchar(20) DEFAULT NULL,
             `profile_picture` text DEFAULT NULL,
             `session_token` varchar(64) DEFAULT NULL,
             `reset_token_hash` varchar(64) DEFAULT NULL,
             `reset_token_expires_at` datetime DEFAULT NULL,
             `achievements` text DEFAULT NULL,
             PRIMARY KEY (`id`),
             UNIQUE KEY `uniq_username` (`username`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
 
         'attendance' => "CREATE TABLE IF NOT EXISTS `attendance` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `trainee_id` int(11) NOT NULL,
             `date` date NOT NULL,
             `time_in` time NOT NULL,
             `time_out` time DEFAULT NULL,
             `photo_in` varchar(255) DEFAULT NULL,
             `photo_out` varchar(255) DEFAULT NULL,
             `latitude` decimal(10,8) DEFAULT NULL,
             `longitude` decimal(11,8) DEFAULT NULL,
             `hours_worked` decimal(5,2) DEFAULT '0.00',
             `narrative` text DEFAULT NULL,
             `is_late` tinyint(1) DEFAULT 0,
             `deleted_at` timestamp NULL DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             `verified_at` timestamp NULL DEFAULT NULL,
             `verified_by` int(11) DEFAULT NULL,
             `pending_ot_seconds` int DEFAULT 0,
             `verified_ot_seconds` int DEFAULT 0,
             PRIMARY KEY (`id`),
             UNIQUE KEY `uniq_attendance_day` (`trainee_id`,`date`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
 
         'system_settings' => "CREATE TABLE IF NOT EXISTS `system_settings` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `setting_key` varchar(50) NOT NULL UNIQUE,
             `setting_value` text DEFAULT NULL,
             `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'system_notifications' => "CREATE TABLE IF NOT EXISTS `system_notifications` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `title` varchar(100) NOT NULL,
             `message` text NOT NULL,
             `image_path` varchar(255) DEFAULT NULL,
             `role_target` varchar(20) DEFAULT 'all',
             `created_by` int(11) DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'messages' => "CREATE TABLE IF NOT EXISTS `messages` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `sender_id` int(11) NOT NULL,
             `receiver_id` int(11) NOT NULL,
             `message` text NOT NULL,
             `attachment_path` varchar(255) DEFAULT NULL,
             `is_read` tinyint(1) DEFAULT 0,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'schedules' => "CREATE TABLE IF NOT EXISTS `schedules` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `trainee_id` int(11) NOT NULL,
             `date` date NOT NULL,
             `shift_start` time NOT NULL,
             `shift_end` time NOT NULL,
             `status` enum('duty','day_off') DEFAULT 'duty',
             `deleted_at` timestamp NULL DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`),
             UNIQUE KEY `trainee_date` (`trainee_id`,`date`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'sanctions' => "CREATE TABLE IF NOT EXISTS `sanctions` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `trainee_id` int(11) NOT NULL,
             `hours_added` decimal(5,2) NOT NULL,
             `kind` enum('penalty','credit') NOT NULL DEFAULT 'penalty',
             `reason` text DEFAULT NULL,
             `admin_id` int(11) NOT NULL,
             `is_removed` TINYINT(1) NOT NULL DEFAULT 0,
             `removed_by` int(11) DEFAULT NULL,
             `removal_reason` text DEFAULT NULL,
             `removed_at` timestamp NULL DEFAULT NULL,
             `deleted_at` timestamp NULL DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'transfers' => "CREATE TABLE IF NOT EXISTS `transfers` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `trainee_id` int(11) NOT NULL,
             `old_department` varchar(50) NOT NULL,
             `new_department` varchar(50) NOT NULL,
             `transfer_date` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'requests' => "CREATE TABLE IF NOT EXISTS `requests` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `trainee_id` int(11) NOT NULL,
             `type` enum('leave', 'overtime', 'undertime') NOT NULL,
             `start_date` date NOT NULL,
             `end_date` date DEFAULT NULL,
             `start_time` time DEFAULT NULL,
             `end_time` time DEFAULT NULL,
             `reason` text NOT NULL,
             `attachment_path` varchar(255) DEFAULT NULL,
             `attachment_name` varchar(255) DEFAULT NULL,
             `attachment_mime` varchar(100) DEFAULT NULL,
             `attachment_size` int(11) DEFAULT NULL,
             `supervisor_status` enum('pending', 'approved', 'rejected') DEFAULT 'pending',
             `supervisor_id` int(11) DEFAULT NULL,
             `supervisor_note` text DEFAULT NULL,
             `hr_status` enum('pending', 'approved', 'rejected') DEFAULT 'pending',
             `hr_id` int(11) DEFAULT NULL,
             `hr_note` text DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             `deleted_at` timestamp NULL DEFAULT NULL,
             `archived_at` timestamp NULL DEFAULT NULL,
             `supervisor_approved_at` datetime DEFAULT NULL,
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'audit_logs' => "CREATE TABLE IF NOT EXISTS `audit_logs` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `user_id` int(11) DEFAULT NULL,
             `action` varchar(50) NOT NULL,
             `details` text NOT NULL,
             `ip_address` varchar(45) DEFAULT NULL,
             `deleted_at` timestamp NULL DEFAULT NULL,
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

         'holidays' => "CREATE TABLE IF NOT EXISTS `holidays` (
             `id` int(11) NOT NULL AUTO_INCREMENT,
             `date` date NOT NULL,
             `name` varchar(150) NOT NULL,
             `type` enum('holiday','special') NOT NULL DEFAULT 'holiday',
             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
             PRIMARY KEY (`id`),
             UNIQUE KEY `uniq_holiday_date` (`date`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
     ];

     foreach ($tables as $name => $sql) {
         $pdo->exec($sql);
     }

     // 2. COLUMN MIGRATIONS (DYNAMIC)
     $migrations = [
         'trainees' => [
             'deleted_at' => "ALTER TABLE trainees ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL",
             'is_cleared' => "ALTER TABLE trainees ADD COLUMN is_cleared TINYINT(1) DEFAULT 0",
             'rating' => "ALTER TABLE trainees ADD COLUMN rating INT(1) DEFAULT NULL",
             'rating_comment' => "ALTER TABLE trainees ADD COLUMN rating_comment TEXT DEFAULT NULL",
             'email' => "ALTER TABLE trainees ADD COLUMN email VARCHAR(100) DEFAULT NULL",
             'phone' => "ALTER TABLE trainees ADD COLUMN phone VARCHAR(20) DEFAULT NULL",
             'profile_picture' => "ALTER TABLE trainees ADD COLUMN profile_picture TEXT DEFAULT NULL",
             'session_token' => "ALTER TABLE trainees ADD COLUMN session_token VARCHAR(64) DEFAULT NULL",
             'reset_token_hash' => "ALTER TABLE trainees ADD COLUMN reset_token_hash VARCHAR(64) DEFAULT NULL",
             'reset_token_expires_at' => "ALTER TABLE trainees ADD COLUMN reset_token_expires_at DATETIME DEFAULT NULL",
             'school' => "ALTER TABLE trainees ADD COLUMN school VARCHAR(150) DEFAULT 'Unknown'",
             'course' => "ALTER TABLE trainees ADD COLUMN course VARCHAR(150) NOT NULL DEFAULT ''",
             'required_hours' => "ALTER TABLE trainees ADD COLUMN required_hours INT(11) NOT NULL DEFAULT 400",
             'initial_hours' => "ALTER TABLE trainees ADD COLUMN initial_hours DECIMAL(10,2) NOT NULL DEFAULT 0.00",
             'shift_start' => "ALTER TABLE trainees ADD COLUMN shift_start TIME DEFAULT '08:00:00'",
             'shift_end' => "ALTER TABLE trainees ADD COLUMN shift_end TIME DEFAULT '17:00:00'",
             'achievements' => "ALTER TABLE trainees ADD COLUMN achievements TEXT DEFAULT NULL"
         ],
         'attendance' => [
             'verified_at' => "ALTER TABLE attendance ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL",
             'verified_by' => "ALTER TABLE attendance ADD COLUMN verified_by INT(11) DEFAULT NULL",
             'deleted_at' => "ALTER TABLE attendance ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL",
             'photo_in' => "ALTER TABLE attendance ADD COLUMN photo_in TEXT DEFAULT NULL",
             'photo_out' => "ALTER TABLE attendance ADD COLUMN photo_out TEXT DEFAULT NULL",
             'pending_ot_seconds' => "ALTER TABLE attendance ADD COLUMN pending_ot_seconds INT DEFAULT 0",
             'verified_ot_seconds' => "ALTER TABLE attendance ADD COLUMN verified_ot_seconds INT DEFAULT 0"
         ],
         'sanctions' => [
             'deleted_at' => "ALTER TABLE sanctions ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL",
             'kind' => "ALTER TABLE sanctions ADD COLUMN kind enum('penalty','credit') NOT NULL DEFAULT 'penalty'",
             'is_removed' => "ALTER TABLE sanctions ADD COLUMN is_removed TINYINT(1) NOT NULL DEFAULT 0",
             'removed_by' => "ALTER TABLE sanctions ADD COLUMN removed_by INT(11) DEFAULT NULL",
             'removal_reason' => "ALTER TABLE sanctions ADD COLUMN removal_reason TEXT DEFAULT NULL",
             'removed_at' => "ALTER TABLE sanctions ADD COLUMN removed_at TIMESTAMP NULL DEFAULT NULL"
         ],
         'audit_logs' => [
             'deleted_at' => "ALTER TABLE audit_logs ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL"
         ],
         'requests' => [
             'archived_at' => "ALTER TABLE requests ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL",
             'supervisor_approved_at' => "ALTER TABLE requests ADD COLUMN supervisor_approved_at DATETIME DEFAULT NULL",
             'attachment_path' => "ALTER TABLE requests ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL",
             'attachment_name' => "ALTER TABLE requests ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL",
             'attachment_mime' => "ALTER TABLE requests ADD COLUMN attachment_mime VARCHAR(100) DEFAULT NULL",
             'attachment_size' => "ALTER TABLE requests ADD COLUMN attachment_size INT(11) DEFAULT NULL"
         ],
         'schedules' => [
             'status' => "ALTER TABLE schedules ADD COLUMN status ENUM('duty','day_off') DEFAULT 'duty'",
             'deleted_at' => "ALTER TABLE schedules ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL"
         ],
         'holidays' => [
             'type' => "ALTER TABLE holidays ADD COLUMN type ENUM('holiday','special') NOT NULL DEFAULT 'holiday'"
         ],
         'system_notifications' => [
             'image_path' => "ALTER TABLE system_notifications ADD COLUMN image_path VARCHAR(255) DEFAULT NULL"
         ]
     ];

     foreach ($migrations as $table => $cols) {
         foreach ($cols as $col => $sql) {
             $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
             if (!$check) {
                 $pdo->exec($sql);
             }
         }
     }

     // 3. INDEX MIGRATIONS (PERFORMANCE)
     $indexes = [
         ['table' => 'trainees',  'name' => 'idx_trainee_dept',              'sql'  => "ALTER TABLE trainees ADD INDEX idx_trainee_dept (department, role)"],
         ['table' => 'trainees',  'name' => 'idx_trainee_email',             'sql'  => "ALTER TABLE trainees ADD INDEX idx_trainee_email (email)"],
         ['table' => 'attendance','name' => 'idx_attendance_trainee_date',   'sql'  => "ALTER TABLE attendance ADD INDEX idx_attendance_trainee_date (trainee_id, date)"],
         ['table' => 'attendance','name' => 'idx_attendance_pending_ot',     'sql'  => "ALTER TABLE attendance ADD INDEX idx_attendance_pending_ot (trainee_id, pending_ot_seconds, deleted_at)"],
         ['table' => 'requests',  'name' => 'idx_requests_status',           'sql'  => "ALTER TABLE requests ADD INDEX idx_requests_status (supervisor_status, hr_status, deleted_at, archived_at)"],
         ['table' => 'requests',  'name' => 'idx_requests_trainee_date',     'sql'  => "ALTER TABLE requests ADD INDEX idx_requests_trainee_date (trainee_id, start_date, type)"],
         ['table' => 'requests',  'name' => 'idx_requests_created',          'sql'  => "ALTER TABLE requests ADD INDEX idx_requests_created (created_at)"],
         ['table' => 'schedules', 'name' => 'idx_schedule_trainee_date',     'sql'  => "ALTER TABLE schedules ADD INDEX idx_schedule_trainee_date (trainee_id, date)"],
         ['table' => 'sanctions', 'name' => 'idx_sanctions_trainee',         'sql'  => "ALTER TABLE sanctions ADD INDEX idx_sanctions_trainee (trainee_id, deleted_at)"],
         ['table' => 'audit_logs','name' => 'idx_audit_user_action',         'sql'  => "ALTER TABLE audit_logs ADD INDEX idx_audit_user_action (user_id, action, created_at)"],
         ['table' => 'messages',  'name' => 'idx_messages_receiver_read',    'sql'  => "ALTER TABLE messages ADD INDEX idx_messages_receiver_read (receiver_id, is_read, created_at)"],
         ['table' => 'messages',  'name' => 'idx_messages_sender',           'sql'  => "ALTER TABLE messages ADD INDEX idx_messages_sender (sender_id, created_at)"]
     ];

     foreach ($indexes as $idx) {
         try {
             $exists = $pdo->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1");
             $exists->execute([$idx['table'], $idx['name']]);
             if (!$exists->fetchColumn()) {
                 $pdo->exec($idx['sql']);
             }
         } catch (Exception $e) {
             // Index already exists or column missing; ignore.
         }
     }

     // Initial Settings Seed
     $initial_settings = [
         'maintenance_mode' => '0',
         'geofencing_radius_meters' => '500',
         'hotel_lat' => '10.3167',
         'hotel_lng' => '123.9000',
         'auto_sanction_late_minutes' => '15',
         'auto_sanction_hours_penalty' => '1'
     ];
     foreach ($initial_settings as $key => $val) {
         $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
         $stmt->execute([$key, $val]);
     }

} catch (\PDOException $e) {
     die("Database Connection Error: " . $e->getMessage());
}

// Project Global Settings
define('DEFAULT_REQUIRED_HOURS', 400);
define('PROJECT_NAME', 'Quest Hotel OJT Portal');
define('TIMEZONE', 'Asia/Manila');

// QUEST HOTEL DEPARTMENTS
$DEPARTMENTS = [
    'IT',
    'Engineering',
    'F&B Banquet',
    'F&B Kitchen',
    'F&B Service',
    'Human Resource',
    'Finance',
    'Front Office',
    'HouseKeeping',
    'Sales & Marketing'
];

date_default_timezone_set(TIMEZONE);
?>
