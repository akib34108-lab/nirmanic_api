<?php
include '../connection.php';
include '../auth_check.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];
$inventoryId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($inventoryId === false || $inventoryId <= 0) {
    $res['message'] = 'A valid inventory ID is required';
    echo json_encode($res);
    exit;
}

$inventoryResult = $db->common_select('material_inventory', '*', ['id' => $inventoryId]);
if (!$inventoryResult['status']) {
    $res['message'] = 'Material inventory was not found';
    echo json_encode($res);
    exit;
}
$inventory = $inventoryResult['data'][0];
if (
    (float)$inventory->current_stock !== 0.0 ||
    (float)$inventory->quantity_available !== 0.0 ||
    (float)$inventory->quantity_used !== 0.0
) {
    $res['message'] = 'Inventory with stock or movement history cannot be deleted';
    echo json_encode($res);
    exit;
}

$db->conn->begin_transaction();
try {
    $statement = $db->conn->prepare('DELETE FROM material_inventory WHERE id = ?');
    if (!$statement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $statement->bind_param('i', $inventoryId);
    if (!$statement->execute()) {
        throw new mysqli_sql_exception($statement->error);
    }

    $materialId = (int)$inventory->material_id;
    $materialStatement = $db->conn->prepare(
        'UPDATE material_list SET current_stock = (SELECT COALESCE(SUM(current_stock), 0) FROM material_inventory WHERE material_id = ? AND deleted_at IS NULL), updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    if (!$materialStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $materialStatement->bind_param('ii', $materialId, $materialId);
    if (!$materialStatement->execute()) {
        throw new mysqli_sql_exception($materialStatement->error);
    }

    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = $statement->affected_rows;
    $res['message'] = 'Material inventory deleted successfully';
} catch (mysqli_sql_exception $exception) {
    $db->conn->rollback();
    error_log('Material inventory deletion failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to delete material inventory';
}

echo json_encode($res);
?>
