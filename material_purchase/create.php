<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    "status" => false,
    "data" => [],
    "message" => ""
];

if (!isset($_POST['items'])) {
    $res['message'] = 'At least one material item is required';
    echo json_encode($res);
    exit;
}

$items = json_decode($_POST['items'], true);
$purchaseNo = $_POST['purchase_no'] ?? null;
$purchaseDate = $_POST['purchase_date'] ?? null;
$supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
$status = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);

if (!is_string($purchaseNo) || !is_string($purchaseDate)) {
    $res['message'] = 'Purchase number and date are required';
    echo json_encode($res);
    exit;
}

$purchaseNo = trim($purchaseNo);
$date = DateTime::createFromFormat('!Y-m-d', $purchaseDate);

if (
    !$purchaseNo ||
    !$date ||
    $date->format('Y-m-d') !== $purchaseDate ||
    $supplierId === false ||
    $supplierId <= 0 ||
    $projectId === false ||
    $projectId <= 0 ||
    $status === false ||
    !in_array($status, [1, 2, 3, 4, 5, 6], true)
) {
    $res['message'] = 'Purchase number, date, supplier, project and a valid status are required';
    echo json_encode($res);
    exit;
}

if (!is_array($items) || count($items) === 0) {
    $res['message'] = 'At least one material item is required';
    echo json_encode($res);
    exit;
}

$purchaseItems = [];
$totalAmount = 0;
foreach ($items as $item) {
    if (!is_array($item)) {
        $res['message'] = 'Invalid material item';
        echo json_encode($res);
        exit;
    }

    $materialId = filter_var($item['material_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = $item['quantity'] ?? null;
    $unitPrice = $item['unit_price'] ?? null;

    if (
        $materialId === false ||
        $materialId <= 0 ||
        !is_numeric($quantity) ||
        !is_finite((float)$quantity) ||
        (float)$quantity <= 0 ||
        floor((float)$quantity) !== (float)$quantity ||
        !is_numeric($unitPrice) ||
        !is_finite((float)$unitPrice) ||
        (float)$unitPrice < 0 ||
        floor((float)$unitPrice) !== (float)$unitPrice
    ) {
        $res['message'] = 'Each item needs a material, a positive whole-number quantity and a whole-number unit price';
        echo json_encode($res);
        exit;
    }

    $quantity = (float)$quantity;
    $unitPrice = (float)$unitPrice;
    $lineTotal = round($quantity * $unitPrice);
    $totalAmount += $lineTotal;
    $purchaseItems[] = [
        'material_id' => $materialId,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'total_price' => $lineTotal
    ];
}
$totalAmount = round($totalAmount);

$transactionStarted = false;
try {
    $db->conn->begin_transaction();
    $transactionStarted = true;

    assert_project_purchase_within_budget($db, $projectId, $totalAmount);

    $headerStatement = $db->conn->prepare(
        'INSERT INTO material_purchase (purchase_no, purchase_date, supplier_id, project_id, status) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$headerStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $headerStatement->bind_param(
        'ssiii',
        $purchaseNo,
        $purchaseDate,
        $supplierId,
        $projectId,
        $status
    );
    if (!$headerStatement->execute()) {
        throw new mysqli_sql_exception($headerStatement->error);
    }

    $purchaseId = $db->conn->insert_id;
    $materialStatement = $db->conn->prepare(
        'SELECT unit_id FROM material_list WHERE id = ? AND deleted_at IS NULL AND unit_id IS NOT NULL LIMIT 1'
    );
    if (!$materialStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $itemStatement = $db->conn->prepare(
        'INSERT INTO material_purchase_item (purchase_id, material_id, quantity, unit_id, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$itemStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }

    $insertedItems = [];
    foreach ($purchaseItems as $item) {
        $materialId = $item['material_id'];
        $quantity = $item['quantity'];
        $unitPrice = $item['unit_price'];
        $lineTotal = $item['total_price'];
        $materialStatement->bind_param('i', $materialId);
        if (!$materialStatement->execute() || !$materialStatement->bind_result($unitId) || !$materialStatement->fetch()) {
            throw new mysqli_sql_exception('Selected material was not found or has no unit');
        }
        $materialStatement->free_result();
        $itemStatement->bind_param(
            'iididd',
            $purchaseId,
            $materialId,
            $quantity,
            $unitId,
            $unitPrice,
            $lineTotal
        );
        if (!$itemStatement->execute()) {
            throw new mysqli_sql_exception($itemStatement->error);
        }
        $insertedItems[] = $db->conn->insert_id;
        apply_material_stock_movement(
            $db,
            $materialId,
            $projectId,
            $quantity,
            'purchase',
            $purchaseDate,
            1,
            $purchaseId,
            'Purchase ' . $purchaseNo
        );
    }

    $db->conn->commit();
    $res['status'] = true;
    $res['data'] = [
        'purchase_id' => $purchaseId,
        'items' => $insertedItems,
        'total_amount' => $totalAmount
    ];
    $res['message'] = 'Material purchase created successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material purchase creation failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to save material purchase. No purchase data was saved.';
}

echo json_encode($res);
?>
