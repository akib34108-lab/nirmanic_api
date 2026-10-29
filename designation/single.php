<?php
include '../connection.php';
$id=$_GET['id'];
$result=$db->common_select('designation','*',['id'=>$id]);
echo json_encode($result);