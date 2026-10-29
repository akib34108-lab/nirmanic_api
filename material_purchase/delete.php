<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    "status" => false,
    "data" => [],
    "message" => ""
];
$purchaseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($purchaseId === false || $purchaseId <= 0) {
    $res['message'] = 'A valid purchase ID is required';
    echo json_encode($res);
    exit;
}

$purchaseResult = $db->common_select('material_purchase', '*', ['id' => $purchaseId]);
$itemResult = $db->common_select('material_purchase_item', '*', ['purchase_id' => $purchaseId]);
if (!$purchaseResult['status']) {
    $res['message'] = 'Material purchase was not found';
    echo json_encode($res);
    exit;
}
$purchase = $purchaseResult['data'][0];
$items = $itemResult['data'] ?? [];

$transactionStarted = false;
try {
    $db->conn->begin_transaction();
    $transactionStarted = true;

    if (has_material_reference_movement($db, 1, $purchaseId)) {
        foreach ($items as $item) {
            apply_material_stock_movement(
                $db,
                (int)$item->material_id,
                (int)$purchase->project_id,
                (float)$item->quantity,
                'purchase',
                $purchase->purchase_date,
                1,
                $purchaseId,
                'Purchase #' . $purchase->purchase_no . ' reversed',
                true
            );
        }
    }

    $itemStatement = $db->conn->prepare(
        'DELETE FROM material_purchase_item WHERE purchase_id = ?'
    );
    if (!$itemStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $itemStatement->bind_param('i', $purchaseId);
    if (!$itemStatement->execute()) {
        throw new mysqli_sql_exception($itemStatement->error);
    }

    $purchaseStatement = $db->conn->prepare(
        'DELETE FROM material_purchase WHERE id = ?'
    );
    if (!$purchaseStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $purchaseStatement->bind_param('i', $purchaseId);
    if (!$purchaseStatement->execute()) {
        throw new mysqli_sql_exception($purchaseStatement->error);
    }

    $deleted = $purchaseStatement->affected_rows;
    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = $deleted;
    $res['message'] = 'Material purchase deleted successfully';
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material purchase deletion failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to delete material purchase. No purchase data was changed.';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
}

echo json_encode($res);
?>
