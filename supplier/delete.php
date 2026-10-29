<?php
include '../connection.php';
include '../auth_check.php';

$id=$_GET['id'];
// $get_res=$db->common_select('clients','*',['id'=>$id]);
// if($get_res['status'] && count($get_res['data'])>0){
//     $client=$get_res['data'][0];
//     if(file_exists('../'.$client->image)){
//         unlink('../'.$client->image);
//     }
// }
$res=$db->common_delete('supplier',['id'=>$id]);

echo json_encode($res);