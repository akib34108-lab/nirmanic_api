<?php
include '../connection.php';
include '../auth_check.php';
$sql = "SELECT material_inventory.*, material_list.material_name, projects.name FROM material_inventory LEFT JOIN material_list ON material_inventory.material_id = material_list.id LEFT JOIN projects ON material_inventory.project_id = projects.id WHERE material_inventory.deleted_at IS NULL";
$result = $db->common_query($sql);
echo json_encode($result);