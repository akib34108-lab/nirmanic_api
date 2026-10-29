<?php
include '../connection.php';
include '../auth_check.php';
$id=$_GET['id'];
$res=$db->common_delete('department',['id'=>$id]);
echo json_encode($res);