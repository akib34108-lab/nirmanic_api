<?php
include '../connection.php';
include '../auth_check.php';

$where = ["mt.deleted_at IS NULL"];
$materialId = filter_var($_GET['material_id'] ?? null, FILTER_VALIDATE_INT);
$transactionType = filter_var($_GET['transaction_type'] ?? null, FILTER_VALIDATE_INT);
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

if ($materialId !== false && $materialId !== null) {
    $where[] = 'mt.material_id = ' . (int)$materialId;
}
if ($transactionType !== false && $transactionType !== null) {
    $where[] = 'mt.transaction_type = ' . (int)$transactionType;
}
if (is_string($fromDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $where[] = "mt.transaction_date >= '" . $db->conn->real_escape_string($fromDate) . "'";
}
if (is_string($toDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $where[] = "mt.transaction_date <= '" . $db->conn->real_escape_string($toDate) . "'";
}

$sql = "SELECT mt.*, ml.material_name, projects.name AS project_name, CASE mt.ref_type WHEN 1 THEN 'Purchase' WHEN 2 THEN 'Issue' WHEN 3 THEN 'Opening Balance' ELSE mt.ref_type END AS reference_type_label, COALESCE(CAST(purchase.purchase_no AS CHAR), CAST(issue.issue_no AS CHAR)) AS reference_number FROM material_transaction mt LEFT JOIN material_list ml ON mt.material_id = ml.id LEFT JOIN projects ON mt.project_id = projects.id LEFT JOIN material_purchase AS purchase ON mt.ref_type = 1 AND mt.ref_id = purchase.id LEFT JOIN material_issue AS issue ON mt.ref_type = 2 AND mt.ref_id = issue.id WHERE " . implode(' AND ', $where) . " ORDER BY mt.transaction_date DESC, mt.id DESC";
echo json_encode($db->common_query($sql));
?>
