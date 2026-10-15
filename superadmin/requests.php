<?php
// Superadmin view of requests
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';

checkAccess('superadmin');

// Just redirect to the admin one since we already allowed superadmin there
header("Location: ../admin/requests.php");
exit();
?>
