<?php
include '../connection.php';
include '../auth_check.php';
$result=$db->common_select('supplier');
echo json_encode($result);