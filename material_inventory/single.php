<?php
include '../connection.php';
$id=$_GET['id'];
$result=$db->common_select('material_inventory','*',['id'=>$id]);
echo json_encode($result);