<?php
include '../connection.php';
include '../auth_check.php';

// $uploadDir = '../';
// $image="";
// if (!empty($_FILES['image'])) {
//     $name = rand().basename($_FILES['image']['name']);
//     $targetPath = 'project/'.$name;
// 
//     if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $targetPath)) {
//         $_POST['image']=$targetPath;
//     }
// } 
$res=[];
if($_POST['name']){
    $res=$db->common_insert('projects', $_POST);
}
echo json_encode($res);
