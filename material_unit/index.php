<?php
include '../connection.php';
// include '../auth_check.php';
// $sql = "SELECT projects.*, clients.client_name FROM projects LEFT JOIN clients ON projects.client_id = clients.id";
$result = $db->common_select('material_unit');
echo json_encode($result);