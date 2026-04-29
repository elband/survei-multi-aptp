<?php
ob_start();
require_once 'db.php';
$_SESSION['admin_id'] = 1;
$_SESSION['admin_user'] = 'admin';
$_GET['id'] = 0; // Main dashboard
require_once 'auth.php';

require 'admin.php';
$html = ob_get_clean();
file_put_contents('output.html', $html);
echo "Done";
