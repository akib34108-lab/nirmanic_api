<?php
include '../connection.php';
include '../auth_check.php';
$res = [];
if($_POST['designation_name']){
    $res = $db->common_insert('designation', $_POST);
}
echo json_encode($res);
