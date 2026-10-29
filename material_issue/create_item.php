<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];
$issueId = filter_var($_POST['issue_id'] ?? null, FILTER_VALIDATE_INT);
$items = json_decode($_POST['items'] ?? '', true);

if ($issueId === false || $issueId <= 0 || !is_array($items) || count($items) === 0) {
    $res['message'] = 'A valid issue ID and at least one item are required';
    echo json_encode($res);
    exit;
}

$issueResult = $db->common_select('material_issue', '*', ['id' => $issueId]);
if (!$issueResult['status']) {
    $res['message'] = 'Material issue was not found';
    echo json_encode($res);
    exit;
}
$issue = $issueResult['data'][0];
$transactionStarted = false;

try {
    $db->conn->begin_transaction();
    $transactionStarted = true;
    $materialStatement = $db->conn->prepare(
        'SELECT unit_id FROM material_list WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $itemStatement = $db->conn->prepare(
        'INSERT INTO material_issue_item (issue_id, material_id, quantity, unit_id) VALUES (?, ?, ?, ?)'
    );
    if (!$materialStatement || !$itemStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }

    $insertedItems = [];
    foreach ($items as $item) {
        $materialId = is_array($item) ? filter_var($item['material_id'] ?? null, FILTER_VALIDATE_INT) : false;
        $unitId = is_array($item) ? filter_var($item['unit_id'] ?? null, FILTER_VALIDATE_INT) : false;
        $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
        if (
            $materialId === false ||
            $materialId <= 0 ||
            $unitId === false ||
            $unitId <= 0 ||
            !is_numeric($quantity) ||
            !is_finite((float)$quantity) ||
            (float)$quantity <= 0 ||
            floor((float)$quantity) !== (float)$quantity
        ) {
            throw new DomainException('Each item needs a material, unit and positive whole-number quantity');
        }
        $quantity = (float)$quantity;
        $materialStatement->bind_param('i', $materialId);
        if (!$materialStatement->execute()) {
            throw new mysqli_sql_exception($materialStatement->error);
        }
        $materialStatement->bind_result($materialUnitId);
        if (!$materialStatement->fetch() || (int)$materialUnitId !== $unitId) {
            $materialStatement->free_result();
            throw new DomainException('Selected unit does not match the material unit');
        }
        $materialStatement->free_result();

        $itemStatement->bind_param('iidi', $issueId, $materialId, $quantity, $unitId);
        if (!$itemStatement->execute()) {
            throw new mysqli_sql_exception($itemStatement->error);
        }
        $insertedItems[] = $db->conn->insert_id;
        apply_material_stock_movement(
            $db,
            $materialId,
            (int)$issue->project_id,
            $quantity,
            'issue',
            $issue->issue_date,
            2,
            $issueId,
            'Issue #' . $issue->issue_no
        );
    }

    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = $insertedItems;
    $res['message'] = 'Material issue items inserted successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material issue item creation failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to save material issue items. No stock was changed.';
}

echo json_encode($res);
?>
