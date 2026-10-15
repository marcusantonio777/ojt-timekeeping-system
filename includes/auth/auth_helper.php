<?php
/**
 * AUTHENTICATION CORE
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Handle Login Process
 */
function login($username, $password, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM trainees WHERE username = ? AND deleted_at IS NULL");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if ($user && password_verify(trim($password), $user['password'])) {
        // Check if account is active
        if ($user['role'] === 'trainee' && $user['status'] !== 'active') {
            return 'pending_approval';
        }
        session_regenerate_id(true);
        $sessionToken = bin2hex(random_bytes(32));
        try {
            $stmt = $pdo->prepare("UPDATE trainees SET session_token = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$sessionToken, $user['id']]);
        } catch (Exception $e) {
            $sessionToken = null;
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['role'] = $user['role']; 
        $_SESSION['session_token'] = $sessionToken;
        $_SESSION['last_activity'] = time();
        
        // Audit Log for Login
        logAudit($pdo, $user['id'], 'LOGIN', "User logged in: " . $user['username']);
        
        return $user['role'];
    }
    return false;
}

/**
 * Ghost Protocol: Impersonation
 */
function impersonate($user_id, $pdo) {
    if ($_SESSION['role'] !== 'superadmin' && !isset($_SESSION['impersonator_id'])) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT * FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        if (!isset($_SESSION['impersonator_id'])) {
            $_SESSION['impersonator_id'] = $_SESSION['user_id'];
            $_SESSION['impersonator_name'] = $_SESSION['full_name'];
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['role'] = $user['role'];
        
        logAudit($pdo, $_SESSION['impersonator_id'], 'GHOST_MODE', "Impersonating user ID: $user_id");
        
        return $user['role'];
    }
    return false;
}

function stopImpersonation($pdo) {
    if (isset($_SESSION['impersonator_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM trainees WHERE id = ?");
        $stmt->execute([$_SESSION['impersonator_id']]);
        $admin = $stmt->fetch();
        
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['full_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
        $_SESSION['role'] = $admin['role'];
        
        $imp_id = $_SESSION['impersonator_id'];
        unset($_SESSION['impersonator_id']);
        unset($_SESSION['impersonator_name']);
        
        logAudit($pdo, $imp_id, 'GHOST_MODE_STOP', "Stopped impersonation");
        
        return true;
    }
    return false;
}

/**
 * Access Control
 */
function checkAccess($role) {
    if (!isset($_SESSION['role'])) {
        header("Location: /quest_ojt_system/index.php");
        exit();
    }
    $timeoutSeconds = 60 * 60;
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeoutSeconds) {
        session_unset();
        session_destroy();
        header("Location: /quest_ojt_system/login.php?expired=1");
        exit();
    }
    $_SESSION['last_activity'] = time();

    if (!isset($_SESSION['impersonator_id']) && isset($_SESSION['user_id']) && array_key_exists('session_token', $_SESSION)) {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT session_token FROM trainees WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$_SESSION['user_id']]);
            $dbToken = $stmt->fetchColumn();
            $sessToken = $_SESSION['session_token'];
            if (($dbToken === null && $sessToken !== null) || ($dbToken !== null && $sessToken !== $dbToken)) {
                session_unset();
                session_destroy();
                header("Location: /quest_ojt_system/login.php?expired=1");
                exit();
            }
        }
    }

    $allowed_roles = array_map('trim', explode(',', $role));
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: /quest_ojt_system/index.php");
        exit();
    }
}

/**
 * CSRF Protection
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    $token = (string)$token;
    return hash_equals((string)$_SESSION['csrf_token'], $token);
}

/**
 * Maintenance Mode Check
 */
function isMaintenanceMode($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'");
    $res = $stmt->fetch();
    return ($res && $res['setting_value'] == '1');
}

/**
 * Audit Logging
 */
function logAudit($pdo, $user_id, $action, $details = '') {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR']]);
}

/**
 * XSS Protection Helper
 */
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function handleUploadedImage($file, $targetDir, $prefix = 'img_', $maxBytes = 5242880) {
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > (int)$maxBytes) {
        return null;
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($map[$mime])) {
        return null;
    }

    $targetDir = rtrim((string)$targetDir, "/\\") . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $name = $prefix . time() . '_' . bin2hex(random_bytes(8)) . '.' . $map[$mime];
    $dest = $targetDir . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return null;
    }

    return $name;
}

/**
 * Get System Setting
 */
function getSetting($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function ensureEmailQueueTable($pdo) {
    static $created = null;
    if ($created === true) return true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_to VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 10,
            status ENUM('queued','sending','sent','failed','dead') NOT NULL DEFAULT 'queued',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            claimed_by VARCHAR(64) NULL,
            INDEX eq_status_available (status, available_at),
            INDEX eq_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $created = true;
    } catch (Exception $e) {
        $created = false;
    }
    return $created;
}

function enqueueSystemEmail($to, $subject, $message, $priority = 10) {
    global $pdo;
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = (string)$subject;
    $message = (string)$message;
    if ($pdo === null) {
        return sendSystemEmail($to, $subject, $message);
    }
    ensureEmailQueueTable($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO email_queue (email_to, subject, body, priority) VALUES (?, ?, ?, ?)");
        $stmt->execute([$to, $subject, $message, (int)$priority]);
        return (int)$pdo->lastInsertId() > 0;
    } catch (Exception $e) {
        return sendSystemEmail($to, $subject, $message);
    }
}

function sendSystemEmail($to, $subject, $message) {
    $to = trim((string)$to);
    if ($to === '') {
        return false;
    }
    $subject = (string)$subject;
    $message = (string)$message;

    $smtpHost = getenv('SMTP_HOST') ?: '';
    if ($smtpHost !== '') {
        $smtpPort = (int)(getenv('SMTP_PORT') ?: 0);
        $smtpUser = getenv('SMTP_USER') ?: '';
        $smtpPass = getenv('SMTP_PASS') !== false ? (string)getenv('SMTP_PASS') : '';
        $smtpSecure = strtolower((string)(getenv('SMTP_SECURE') ?: 'tls'));
        $fromEmail = getenv('SMTP_FROM') ?: 'noreply@questojt.local';
        $fromName = getenv('SMTP_FROM_NAME') ?: 'Quest OJT System';
        $ok = smtpSendEmail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $fromEmail, $fromName, $to, $subject, $message);
        if ($ok) {
            return true;
        }
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/plain;charset=UTF-8\r\n";
    $headers .= "From: Quest OJT System <noreply@questojt.local>\r\n";
    return @mail($to, $subject, $message, $headers);
}

function smtpSendEmail($host, $port, $username, $password, $secure, $fromEmail, $fromName, $to, $subject, $body) {
    $host = trim((string)$host);
    $secure = strtolower(trim((string)$secure));
    $fromEmail = trim((string)$fromEmail);
    $fromName = trim((string)$fromName);
    $to = trim((string)$to);
    if ($host === '' || $fromEmail === '' || $to === '') {
        return false;
    }

    $fromEmail = str_replace(["\r", "\n"], '', $fromEmail);
    $fromName = str_replace(["\r", "\n"], '', $fromName);
    $to = str_replace(["\r", "\n"], '', $to);

    if ($port <= 0) {
        if ($secure === 'ssl') $port = 465;
        elseif ($secure === 'tls') $port = 587;
        else $port = 25;
    }

    $timeout = 5;
    $remote = ($secure === 'ssl' ? "ssl://" : "tcp://") . $host . ":" . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, $timeout);

    if (!smtpExpect($fp, 220)) {
        fclose($fp);
        return false;
    }

    $client = 'quest-ojt';
    smtpWrite($fp, "EHLO {$client}\r\n");
    if (!smtpExpect($fp, 250)) {
        smtpWrite($fp, "HELO {$client}\r\n");
        if (!smtpExpect($fp, 250)) {
            fclose($fp);
            return false;
        }
    }

    if ($secure === 'tls') {
        smtpWrite($fp, "STARTTLS\r\n");
        if (!smtpExpect($fp, 220)) {
            fclose($fp);
            return false;
        }
        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            fclose($fp);
            return false;
        }
        smtpWrite($fp, "EHLO {$client}\r\n");
        if (!smtpExpect($fp, 250)) {
            fclose($fp);
            return false;
        }
    }

    $username = (string)$username;
    if (trim($username) !== '') {
        smtpWrite($fp, "AUTH LOGIN\r\n");
        if (!smtpExpect($fp, 334)) {
            fclose($fp);
            return false;
        }
        smtpWrite($fp, base64_encode($username) . "\r\n");
        if (!smtpExpect($fp, 334)) {
            fclose($fp);
            return false;
        }
        smtpWrite($fp, base64_encode((string)$password) . "\r\n");
        if (!smtpExpect($fp, 235)) {
            fclose($fp);
            return false;
        }
    }

    smtpWrite($fp, "MAIL FROM:<{$fromEmail}>\r\n");
    if (!smtpExpect($fp, 250)) {
        fclose($fp);
        return false;
    }

    smtpWrite($fp, "RCPT TO:<{$to}>\r\n");
    if (!smtpExpect($fp, 250) && !smtpExpect($fp, 251)) {
        fclose($fp);
        return false;
    }

    smtpWrite($fp, "DATA\r\n");
    if (!smtpExpect($fp, 354)) {
        fclose($fp);
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $date = date('r');
    $messageId = '<' . bin2hex(random_bytes(16)) . '@' . preg_replace('/[^a-z0-9\.\-]/i', '', $host) . '>';
    $headers = [];
    $headers[] = "Date: {$date}";
    $headers[] = "Message-ID: {$messageId}";
    $headers[] = "From: " . ($fromName !== '' ? "{$fromName} <{$fromEmail}>" : "<{$fromEmail}>");
    $headers[] = "To: <{$to}>";
    $headers[] = "Subject: {$encodedSubject}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";

    $data = implode("\r\n", $headers) . "\r\n\r\n" . (string)$body;
    $data = str_replace(["\r\n", "\r"], "\n", $data);
    $data = str_replace("\n", "\r\n", $data);
    $data = preg_replace('/\r\n\./', "\r\n..", $data);
    if (isset($data[0]) && $data[0] === '.') {
        $data = '.' . $data;
    }
    smtpWrite($fp, $data . "\r\n.\r\n");

    if (!smtpExpect($fp, 250)) {
        fclose($fp);
        return false;
    }

    smtpWrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

function smtpWrite($fp, $data) {
    return fwrite($fp, $data);
}

function smtpReadLine($fp) {
    $line = fgets($fp, 515);
    return $line === false ? '' : $line;
}

function smtpExpect($fp, $code) {
    $code = (int)$code;
    $ok = false;
    $loops = 0;
    while ($loops < 100) {
        $loops++;
        $line = smtpReadLine($fp);
        if ($line === '') {
            break;
        }
        $lineCode = (int)substr($line, 0, 3);
        $more = isset($line[3]) && $line[3] === '-';
        if ($lineCode === $code) {
            $ok = true;
        }
        if (!$more) {
            break;
        }
    }
    return $ok;
}
