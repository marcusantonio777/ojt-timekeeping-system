<?php
require_once 'includes/auth/auth_helper.php';
require_once 'config/db.php';

if (isset($_SESSION['user_id'])) {
    logAudit($pdo, $_SESSION['user_id'], 'LOGOUT', "User logged out");
    try {
        $stmt = $pdo->prepare("UPDATE trainees SET session_token = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {
    }
}

session_unset();
session_destroy();
header("Location: index.php");
exit();
