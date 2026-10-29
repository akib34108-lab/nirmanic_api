<?php
include '../connection.php';
include '../auth_check.php';

$id=$_GET['id'];
 $get_res=$db->common_select('employee','*',['id'=>$id]);
 if($get_res['status'] && count($get_res['data'])>0){
     $employee=$get_res['data'][0];
     if (!empty($employee->image)) {
        $imagePath = __DIR__ . '/../' . ltrim($employee->image, '/\\'); 
        if (is_file($imagePath)) {
            unlink($imagePath);
        }
    }
 }
$res=$db->common_delete('employee',['id'=>$id]);

echo json_encode($res);