<?php
include '../connection.php';
include '../auth_check.php';
$res = [];
if($_POST['department_name']){
    $res = $db->common_insert('department', $_POST);
}
echo json_encode($res);
