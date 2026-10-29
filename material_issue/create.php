<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];

if (!isset($_POST['items'])) {
    if (!isset($_POST['issue_no']) || !$_POST['issue_no']) {
        $res['message'] = 'Issue number is required';
    } else {
        $res = $db->common_insert('material_issue', $_POST);
    }
    echo json_encode($res);
    exit;
}

$items = json_decode($_POST['items'], true);
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
$date = is_string($issueDate) ? DateTime::createFromFormat('!Y-m-d', $issueDate) : false;

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
        'INSERT INTO material_issue (issue_no, project_id, issue_date, issue_to, purpose, remark) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$issueStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $issueStatement->bind_param(
        'iissss',
        $issueNo,
        $projectId,
        $issueDate,
        $issueTo,
        $purpose,
        $remark
    );
    if (!$issueStatement->execute()) {
        throw new mysqli_sql_exception($issueStatement->error);
    }
    $issueId = $db->conn->insert_id;

    $materialStatement = $db->conn->prepare(
        'SELECT unit_id FROM material_list WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    if (!$materialStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $itemStatement = $db->conn->prepare(
        'INSERT INTO material_issue_item (issue_id, material_id, quantity, unit_id) VALUES (?, ?, ?, ?)'
    );
    if (!$itemStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }

    $insertedItems = [];
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

        $itemStatement->bind_param('iidi', $issueId, $materialId, $quantity, $unitId);
        if (!$itemStatement->execute()) {
            throw new mysqli_sql_exception($itemStatement->error);
        }
        $insertedItems[] = $db->conn->insert_id;

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
    $res['data'] = [
        'issue_id' => $issueId,
        'items' => $insertedItems
    ];
    $res['message'] = 'Material issue created successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material issue creation failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to save material issue. No issue data was saved.';
}

echo json_encode($res);
?>
