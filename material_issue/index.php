<?php
include '../connection.php';
include '../auth_check.php';
$sql = "SELECT material_issue.*, projects.name AS project_name, employee.employee_name AS issue_to_name, GROUP_CONCAT(material_list.material_name SEPARATOR ', ') AS material_names FROM material_issue LEFT JOIN projects ON material_issue.project_id = projects.id LEFT JOIN employee ON material_issue.issue_to = employee.id LEFT JOIN material_issue_item ON material_issue.id = material_issue_item.issue_id LEFT JOIN material_list ON material_issue_item.material_id = material_list.id GROUP BY material_issue.id ORDER BY material_issue.id DESC";
$result = $db->common_query($sql);
echo json_encode($result);
?>
