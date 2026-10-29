<?php
include '../connection.php';
include '../auth_check.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'Valid transaction ID is required'
    ]);
    exit;
}

$sql = "SELECT mt.*, ml.material_name, projects.name AS project_name FROM material_transaction mt LEFT JOIN material_list ml ON mt.material_id = ml.id LEFT JOIN projects ON mt.project_id = projects.id WHERE mt.id = " . (int)$id . " AND mt.deleted_at IS NULL LIMIT 1";
echo json_encode($db->common_query($sql));
?>
