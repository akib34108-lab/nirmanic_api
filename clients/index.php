<?php
include '../connection.php';
include '../auth_check.php';
$sql = "SELECT clients.*, COUNT(projects.id) AS project_number FROM clients LEFT JOIN projects ON projects.client_id = clients.id GROUP BY clients.id";
$result=$db->common_query($sql);
echo json_encode($result);