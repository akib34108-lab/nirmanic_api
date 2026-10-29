<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];
$issueId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($issueId === false || $issueId <= 0) {
    $res['message'] = 'A valid issue ID is required';
    echo json_encode($res);
    exit;
}

$issueResult = $db->common_select('material_issue', '*', ['id' => $issueId]);
$itemResult = $db->common_select('material_issue_item', '*', ['issue_id' => $issueId]);
if (!$issueResult['status']) {
    $res['message'] = 'Material issue was not found';
    echo json_encode($res);
    exit;
}
$issue = $issueResult['data'][0];
$items = $itemResult['data'] ?? [];
$transactionStarted = false;

try {
    $db->conn->begin_transaction();
    $transactionStarted = true;

    if (has_material_reference_movement($db, 2, $issueId)) {
        foreach ($items as $item) {
            apply_material_stock_movement(
                $db,
                (int)$item->material_id,
                (int)$issue->project_id,
                (float)$item->quantity,
                'issue',
                $issue->issue_date,
                2,
                $issueId,
                'Issue #' . $issue->issue_no . ' reversed',
                true
            );
        }
    }

    $itemStatement = $db->conn->prepare(
        'DELETE FROM material_issue_item WHERE issue_id = ?'
    );
    $issueStatement = $db->conn->prepare(
        'DELETE FROM material_issue WHERE id = ?'
    );
    if (!$itemStatement || !$issueStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $itemStatement->bind_param('i', $issueId);
    if (!$itemStatement->execute()) {
        throw new mysqli_sql_exception($itemStatement->error);
    }
    $issueStatement->bind_param('i', $issueId);
    if (!$issueStatement->execute()) {
        throw new mysqli_sql_exception($issueStatement->error);
    }

    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = $issueStatement->affected_rows;
    $res['message'] = 'Material issue deleted and stock restored successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material issue deletion failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to delete material issue. No stock was changed.';
}

echo json_encode($res);
?>
