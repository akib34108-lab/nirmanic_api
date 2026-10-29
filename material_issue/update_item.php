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
$issueNoInput = $_POST['issue_no'] ?? '';
$issueNoInput = is_string($issueNoInput) ? trim($issueNoInput) : '';
$normalizedIssueNo = ltrim($issueNoInput, '0');
$issueNo = ctype_digit($issueNoInput)
    ? filter_var($normalizedIssueNo === '' ? '0' : $normalizedIssueNo, FILTER_VALIDATE_INT)
    : false;
$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
$issueDate = $_POST['issue_date'] ?? null;
$issueTo = $_POST['issue_to'] ?? '';
$purpose = $_POST['purpose'] ?? '';
$remark = $_POST['remark'] ?? '';
$items = json_decode($_POST['items'] ?? '', true);
$date = is_string($issueDate) ? DateTime::createFromFormat('!Y-m-d', $issueDate) : false;

if ($issueId === false || $issueId <= 0) {
    $res['message'] = 'A valid issue ID is required';
    echo json_encode($res);
    exit;
}
if ($issueNo === false || $issueNo <= 0 || $issueNo > 2147483647) {
    $res['message'] = 'Issue number must be a positive whole number within the supported range';
    echo json_encode($res);
    exit;
}
if ($projectId === false || $projectId <= 0) {
    $res['message'] = 'Please select a valid project';
    echo json_encode($res);
    exit;
}
if (!$date || $date->format('Y-m-d') !== $issueDate) {
    $res['message'] = 'Please enter a valid issue date';
    echo json_encode($res);
    exit;
}
if (!is_string($issueTo) || trim($issueTo) === '') {
    $res['message'] = 'Please select an issue recipient';
    echo json_encode($res);
    exit;
}
if (!is_array($items) || count($items) === 0) {
    $res['message'] = 'At least one material item is required';
    echo json_encode($res);
    exit;
}

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
        $res['message'] = 'Each item needs a material, unit and positive whole-number quantity';
        echo json_encode($res);
        exit;
    }
}

$transactionStarted = false;
try {
    $db->conn->begin_transaction();
    $transactionStarted = true;

    $issueStatement = $db->conn->prepare(
        'SELECT id FROM material_issue WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
    );
    $issueStatement->bind_param('i', $issueId);
    if (!$issueStatement->execute() || !$issueStatement->get_result()->fetch_assoc()) {
        throw new DomainException('Material issue was not found');
    }

    $movementStatement = $db->conn->prepare(
        'SELECT material_id, project_id, SUM(quantity_out) - SUM(quantity_in) AS net_quantity FROM material_transaction WHERE ref_type = 2 AND ref_id = ? AND deleted_at IS NULL GROUP BY material_id, project_id HAVING net_quantity > 0'
    );
    if (!$movementStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $movementStatement->bind_param('i', $issueId);
    if (!$movementStatement->execute()) {
        throw new mysqli_sql_exception($movementStatement->error);
    }
    $movements = $movementStatement->get_result();
    while ($movement = $movements->fetch_assoc()) {
        apply_material_stock_movement(
            $db,
            (int)$movement['material_id'],
            (int)$movement['project_id'],
            (float)$movement['net_quantity'],
            'issue',
            $issueDate,
            2,
            $issueId,
            'Issue #' . $issueNo . ' reversed for edit',
            true
        );
    }

    $deleteItems = $db->conn->prepare('DELETE FROM material_issue_item WHERE issue_id = ?');
    if (!$deleteItems) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $deleteItems->bind_param('i', $issueId);
    if (!$deleteItems->execute()) {
        throw new mysqli_sql_exception($deleteItems->error);
    }

    $updateIssue = $db->conn->prepare(
        'UPDATE material_issue SET issue_no = ?, project_id = ?, issue_date = ?, issue_to = ?, purpose = ?, remark = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    if (!$updateIssue) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $updateIssue->bind_param('iissssi', $issueNo, $projectId, $issueDate, $issueTo, $purpose, $remark, $issueId);
    if (!$updateIssue->execute()) {
        throw new mysqli_sql_exception($updateIssue->error);
    }

    $materialStatement = $db->conn->prepare(
        'SELECT unit_id FROM material_list WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $insertItem = $db->conn->prepare(
        'INSERT INTO material_issue_item (issue_id, material_id, quantity, unit_id) VALUES (?, ?, ?, ?)'
    );
    if (!$materialStatement || !$insertItem) {
        throw new mysqli_sql_exception($db->conn->error);
    }

    $savedItems = [];
    foreach ($items as $item) {
        $materialId = (int)$item['material_id'];
        $unitId = (int)$item['unit_id'];
        $quantity = (float)$item['quantity'];
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

        $insertItem->bind_param('iidi', $issueId, $materialId, $quantity, $unitId);
        if (!$insertItem->execute()) {
            throw new mysqli_sql_exception($insertItem->error);
        }
        $savedItems[] = $db->conn->insert_id;
        apply_material_stock_movement(
            $db,
            $materialId,
            $projectId,
            $quantity,
            'issue',
            $issueDate,
            2,
            $issueId,
            'Issue #' . $issueNo
        );
    }

    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = $savedItems;
    $res['message'] = 'Material issue and stock updated successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material issue update failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to update material issue. No issue data or stock was changed.';
}

echo json_encode($res);
?>
