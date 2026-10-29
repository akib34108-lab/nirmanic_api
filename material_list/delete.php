<?php
include '../connection.php';
include '../auth_check.php';

$id=$_GET['id'];
// if there was an image uploaded, delete the old image
// $get_res=$db->common_select('projects','*',['id'=>$id]);
// if($get_res['status'] && count($get_res['data'])>0){
//     $project=$get_res['data'][0];
//     if(file_exists('../'.$project->image)){
//         unlink('../'.$project->image);
//     }
// }
$res=$db->common_delete('material_list',['id'=>$id]);

echo json_encode($res);