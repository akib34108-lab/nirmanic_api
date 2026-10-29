<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];
$materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
$quantityAvailable = $_POST['quantity_available'] ?? 0;
$quantityUsed = $_POST['quantity_used'] ?? 0;
$minimumStock = $_POST['minimum_stock'] ?? 0;
$stockValues = [$quantityAvailable, $quantityUsed, $minimumStock];

if ($materialId === false || $materialId <= 0 || $projectId === false || $projectId <= 0) {
    $res['message'] = 'A valid material and project are required';
    echo json_encode($res);
    exit;
}
foreach ($stockValues as $value) {
    if (
        !is_numeric($value) ||
        !is_finite((float)$value) ||
        (float)$value < 0 ||
        floor((float)$value) !== (float)$value
    ) {
        $res['message'] = 'Stock values must be non-negative whole numbers';
        echo json_encode($res);
        exit;
    }
}

$quantityAvailable = (float)$quantityAvailable;
$quantityUsed = (float)$quantityUsed;
$minimumStock = (float)$minimumStock;
$currentStock = $quantityAvailable - $quantityUsed;
if ($currentStock < 0) {
    $res['message'] = 'Quantity used cannot be greater than quantity available';
    echo json_encode($res);
    exit;
}
$status = material_stock_status($currentStock, $minimumStock);
$transactionStarted = false;

try {
    $db->conn->begin_transaction();
    $transactionStarted = true;
    $duplicateStatement = $db->conn->prepare(
        'SELECT id FROM material_inventory WHERE material_id = ? AND project_id = ? AND deleted_at IS NULL FOR UPDATE'
    );
    if (!$duplicateStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $duplicateStatement->bind_param('ii', $materialId, $projectId);
    if (!$duplicateStatement->execute()) {
        throw new mysqli_sql_exception($duplicateStatement->error);
    }
    $duplicateResult = $duplicateStatement->get_result();
    if ($duplicateResult->fetch_assoc()) {
        throw new DomainException('Inventory already exists for this material and project');
    }

    $insertStatement = $db->conn->prepare(
        'INSERT INTO material_inventory (material_id, project_id, quantity_available, quantity_used, current_stock, minimum_stock, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insertStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $insertStatement->bind_param(
        'iiddddi',
        $materialId,
        $projectId,
        $quantityAvailable,
        $quantityUsed,
        $currentStock,
        $minimumStock,
        $status
    );
    if (!$insertStatement->execute()) {
        throw new mysqli_sql_exception($insertStatement->error);
    }
    $inventoryId = $db->conn->insert_id;

    if ($quantityAvailable > 0) {
        $openingTransaction = $db->conn->prepare(
            "INSERT INTO material_transaction (material_id, project_id, transaction_date, transaction_type, ref_type, ref_id, quantity_in, quantity_out, balance_after, notes) VALUES (?, ?, CURRENT_DATE, 1, 3, ?, ?, ?, ?, 'Opening inventory')"
        );
        if (!$openingTransaction) {
            throw new mysqli_sql_exception($db->conn->error);
        }
        $openingTransaction->bind_param(
            'iiiddd',
            $materialId,
            $projectId,
            $inventoryId,
            $quantityAvailable,
            $quantityUsed,
            $currentStock
        );
        if (!$openingTransaction->execute()) {
            throw new mysqli_sql_exception($openingTransaction->error);
        }
    }

    $materialStatement = $db->conn->prepare(
        'UPDATE material_list SET current_stock = (SELECT COALESCE(SUM(current_stock), 0) FROM material_inventory WHERE material_id = ? AND deleted_at IS NULL), updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $materialStatement->bind_param('ii', $materialId, $materialId);
    if (!$materialStatement->execute()) {
        throw new mysqli_sql_exception($materialStatement->error);
    }

    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = $inventoryId;
    $res['message'] = 'Material inventory created successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material inventory creation failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to create material inventory.';
}

echo json_encode($res);
?>
