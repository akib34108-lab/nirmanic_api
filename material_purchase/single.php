<?php
include '../connection.php';
include '../auth_check.php';

$purchaseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($purchaseId === false || $purchaseId === null || $purchaseId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'A valid purchase ID is required']);
    exit;
}

$result = $db->common_select('material_purchase', '*', ['id' => $purchaseId]);
if (!$result['status']) {
    http_response_code(404);
    echo json_encode($result);
    exit;
}

$purchase = $result['data'][0];
$itemStatement = $db->conn->prepare(
    'SELECT material_id, quantity, unit_price, total_price
    FROM material_purchase_item
    WHERE purchase_id = ? AND deleted_at IS NULL
    ORDER BY id ASC'
);
if (!$itemStatement) {
    error_log('Material purchase item query failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load purchase items']);
    exit;
}
$itemStatement->bind_param('i', $purchaseId);
if (!$itemStatement->execute()) {
    error_log('Material purchase item query failed: ' . $itemStatement->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load purchase items']);
    exit;
}

$items = [];
$itemResult = $itemStatement->get_result();
while ($item = $itemResult->fetch_object()) {
    $items[] = $item;
}

if (count($items) === 0 && !empty($purchase->material_id)) {
    $items[] = (object)[
        'material_id' => $purchase->material_id,
        'quantity' => $purchase->quantity ?? '',
        'unit_price' => $purchase->unit_price ?? '',
        'total_price' => $purchase->total_amount ?? ''
    ];
}

$purchase->items = $items;
echo json_encode($result);