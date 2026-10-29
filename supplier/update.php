<?php
include '../connection.php';
$data = $_POST;
$id=$_GET['id'];
 $res=$db->common_update('supplier',$data,['id'=>$id]);
 echo json_encode($res);