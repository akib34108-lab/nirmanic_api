<?php
include '../connection.php';
$data = $_POST;
$id=$_GET['id'];
 $res=$db->common_update('clients',$data,['id'=>$id]);
 echo json_encode($res);