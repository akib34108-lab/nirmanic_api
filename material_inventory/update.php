<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];
$inventoryId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$minimumStock = $_POST['minimum_stock'] ?? null;

if ($inventoryId === false || $inventoryId <= 0) {
    $res['message'] = 'A valid inventory ID is required';
    echo json_encode($res);
    exit;
}
if (
    !is_numeric($minimumStock) ||
    !is_finite((float)$minimumStock) ||
    (float)$minimumStock < 0 ||
    floor((float)$minimumStock) !== (float)$minimumStock
) {
    $res['message'] = 'Minimum stock must be a non-negative whole number';
    echo json_encode($res);
    exit;
}
$minimumStock = (float)$minimumStock;

$inventoryResult = $db->common_select('material_inventory', '*', ['id' => $inventoryId]);
if (!$inventoryResult['status']) {
    $res['message'] = 'Material inventory was not found';
    echo json_encode($res);
    exit;
}
$inventory = $inventoryResult['data'][0];
$status = material_stock_status((float)$inventory->current_stock, $minimumStock);
$statement = $db->conn->prepare(
    'UPDATE material_inventory SET minimum_stock = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL'
);
if (!$statement) {
    $res['message'] = 'Unable to prepare inventory update';
    echo json_encode($res);
    exit;
}
$statement->bind_param('dii', $minimumStock, $status, $inventoryId);
if ($statement->execute()) {
    $res['status'] = true;
    $res['data'] = $statement->affected_rows;
    $res['message'] = 'Material inventory updated successfully';
} else {
    error_log('Material inventory update failed: ' . $statement->error);
    $res['message'] = 'Unable to update material inventory';
}
echo json_encode($res);
?>
