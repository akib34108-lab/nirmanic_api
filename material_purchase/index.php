<?php
include '../connection.php';
include '../auth_check.php';

$columnResult = $db->conn->query('SHOW COLUMNS FROM material_purchase');
if (!$columnResult) {
    echo json_encode([
        "status" => false,
        "data" => [],
        "message" => 'Unable to read material purchase columns'
    ]);
    exit;
}

$purchaseColumns = [];
while ($column = $columnResult->fetch_assoc()) {
    $purchaseColumns[$column['Field']] = true;
}

$legacyMaterialJoin = isset($purchaseColumns['material_id'])
    ? 'LEFT JOIN material_list AS legacy_material ON material_purchase.material_id = legacy_material.id'
    : '';
$legacyMaterialName = isset($purchaseColumns['material_id'])
    ? 'legacy_material.material_name'
    : 'NULL';
$legacyQuantity = isset($purchaseColumns['quantity'])
    ? 'material_purchase.quantity'
    : 'NULL';
$legacyUnitPrice = isset($purchaseColumns['unit_price'])
    ? 'material_purchase.unit_price'
    : 'NULL';
$legacyTotal = isset($purchaseColumns['total_amount'])
    ? 'material_purchase.total_amount'
    : '0';

$sql = "SELECT material_purchase.id, material_purchase.purchase_no, material_purchase.purchase_date, material_purchase.supplier_id, material_purchase.project_id, material_purchase.status, material_purchase.notes, material_purchase.created_at, material_purchase.updated_at, material_purchase.deleted_at, $legacyQuantity AS quantity, $legacyUnitPrice AS unit_price, COALESCE(GROUP_CONCAT(DISTINCT item_material.material_name ORDER BY item_material.material_name SEPARATOR ', '), $legacyMaterialName) AS material_names, $legacyMaterialName AS material_name, COUNT(DISTINCT material_purchase_item.id) AS item_count, COALESCE(SUM(material_purchase_item.total_price), $legacyTotal, 0) AS total_amount, supplier.supplier_name, projects.name FROM material_purchase LEFT JOIN projects ON material_purchase.project_id = projects.id $legacyMaterialJoin LEFT JOIN material_purchase_item ON material_purchase.id = material_purchase_item.purchase_id AND material_purchase_item.deleted_at IS NULL LEFT JOIN material_list AS item_material ON material_purchase_item.material_id = item_material.id LEFT JOIN supplier ON material_purchase.supplier_id = supplier.id GROUP BY material_purchase.id";
$result = $db->common_query($sql);
echo json_encode($result);
?>
