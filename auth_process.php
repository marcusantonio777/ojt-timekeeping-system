<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: login.php?csrf_error=1");
        exit();
    }

    $action = $_POST['action'] ?? 'login';
    $username = trim($_POST['username'] ?? '');

    // BULLETPROOF CHECK: I-base sa specific username para walay mag-abot nga roles
    if ($action === 'login' && $username !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILED' AND details = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)");
        
        // Gigamitan natog "Strict Matching" sa exact username message
        $expected_detail = "Failed login attempt for username: " . $username;
        $stmt->execute([$expected_detail]);
        $failedCount = (int)($stmt->fetchColumn() ?: 0);
        
        if ($failedCount >= 5) {
            header("Location: login.php?error=locked");
            exit();
        }
    }

    if ($action === 'request_reset') {
        // ... (pabilin lang ang code sa reset request nimo diri, walay usbon)
        $identifier = trim($_POST['identifier'] ?? '');
        if ($identifier !== '') {
            $stmt = $pdo->prepare("SELECT id, email, username FROM trainees WHERE deleted_at IS NULL AND (username = ? OR email = ?) LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();
            if ($user && !empty($user['email'])) {
                $token = bin2hex(random_bytes(32));
                $hash = hash('sha256', $token);
                $stmt = $pdo->prepare("UPDATE trainees SET reset_token_hash = ?, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
                $stmt->execute([$hash, $user['id']]);
                $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/quest_ojt_system/login.php?reset=' . urlencode($token);
                $subject = "Password Reset Request";
                $body = "A password reset was requested for your account.\n\nOpen this link to set a new password:\n{$link}\n\nThis link expires in 30 minutes.\n";
                enqueueSystemEmail($user['email'], $subject, $body);
                logAudit($pdo, (int)$user['id'], 'RESET_REQUEST', "Password reset requested");
            }
        }
        header("Location: login.php?reset_sent=1");
        exit();
    }

    if ($action === 'reset_password') {
        // ... (pabilin lang ang code sa reset password nimo diri, walay usbon)
        $token = trim($_POST['token'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        if ($token === '' || $new_password === '' || strlen($new_password) < 8) {
            header("Location: login.php?reset_error=1");
            exit();
        }
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare("SELECT id FROM trainees WHERE reset_token_hash = ? AND reset_token_expires_at IS NOT NULL AND reset_token_expires_at > NOW() AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$hash]);
        $uid = $stmt->fetchColumn();
        if (!$uid) {
            header("Location: login.php?reset_invalid=1");
            exit();
        }

        $stmt = $pdo->prepare("UPDATE trainees SET password = ?, reset_token_hash = NULL, reset_token_expires_at = NULL, session_token = NULL WHERE id = ?");
        $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $uid]);
        logAudit($pdo, (int)$uid, 'RESET_PASSWORD', "Password reset completed");
        header("Location: login.php?reset_done=1");
        exit();
    }

    $password = trim($_POST['password'] ?? '');
    $role = login($username, $password, $pdo);

    // Role-based redirect
    if ($role === 'trainee') {
        header("Location: dashboard.php");
    } elseif ($role === 'admin') {
        header("Location: admin/dashboard.php");
    } elseif ($role === 'supervisor') {
        header("Location: supervisor/dashboard.php");
    } elseif ($role === 'superadmin') {
        header("Location: superadmin/dashboard.php");
    } elseif ($role === 'pending_approval') {
        header("Location: login.php?error=pending");
    } else {
        // FIXED: Gi-standardize nato ang text sa log para sa tanang roles
        $log_message = "Failed login attempt for username: " . $username;
        logAudit($pdo, 0, 'LOGIN_FAILED', $log_message);
        header("Location: login.php?error=failed");
    }
    exit();
} else {
    header("Location: login.php");
    exit();
}