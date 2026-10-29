<?php
include '../connection.php';
$id=$_GET['id'];
 $res=$db->common_update('material_list',$_POST,['id'=>$id]);

 echo json_encode($res);
