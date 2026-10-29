<?php
include '../connection.php';
include '../auth_check.php';
$id = $_GET['id'];
$uploadDir = '../image/employee/';
if (!empty($_FILES['image']['name'])){
    $name = rand() . '_' . basename($_FILES['image']['name']);
    $targetPath = 'image/employee/' . $name;
    if (move_uploaded_file($_FILES['image']['tmp_name'],$uploadDir . $name)) {
        $_POST['image'] = $targetPath;
    } }
$res = $db->common_update('employee', $_POST, ['id' => $id]);
echo json_encode($res);