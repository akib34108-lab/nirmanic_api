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

$sql = "SELECT mt.*, ml.material_name, projects.name AS project_name, CASE mt.ref_type WHEN 1 THEN 'Purchase' WHEN 2 THEN 'Issue' WHEN 3 THEN 'Opening Balance' ELSE mt.ref_type END AS reference_type_label, COALESCE(CAST(purchase.purchase_no AS CHAR), CAST(issue.issue_no AS CHAR)) AS reference_number FROM material_transaction mt LEFT JOIN material_list ml ON mt.material_id = ml.id LEFT JOIN projects ON mt.project_id = projects.id LEFT JOIN material_purchase AS purchase ON mt.ref_type = 1 AND mt.ref_id = purchase.id LEFT JOIN material_issue AS issue ON mt.ref_type = 2 AND mt.ref_id = issue.id WHERE mt.id = " . (int)$id . " AND mt.deleted_at IS NULL LIMIT 1";
echo json_encode($db->common_query($sql));
?>
