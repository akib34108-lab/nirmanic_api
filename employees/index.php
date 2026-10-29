<?php
include '../connection.php';
include '../auth_check.php';
$sql = "SELECT employee.*, designation.designation_name, department.department_name FROM employee LEFT JOIN designation ON employee.designation_id = designation.id LEFT JOIN department ON employee.department_id = department.id;";
$result = $db->common_query($sql);
echo json_encode($result);