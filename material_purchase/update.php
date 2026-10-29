<?php
include '../connection.php';
include '../auth_check.php';
include '../material_stock_helpers.php';

$res = [
    'status' => false,
    'data' => [],
    'message' => ''
];
$purchaseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$items = json_decode($_POST['items'] ?? '', true);
$purchaseNo = $_POST['purchase_no'] ?? null;
$purchaseDate = $_POST['purchase_date'] ?? null;
$supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
$projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
$status = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);

if ($purchaseId === false || $purchaseId === null || $purchaseId <= 0) {
    http_response_code(400);
    $res['message'] = 'A valid purchase ID is required';
    echo json_encode($res);
    exit;
}

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

    $purchaseStatement = $db->conn->prepare(
        'SELECT * FROM material_purchase WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
    );
    if (!$purchaseStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $purchaseStatement->bind_param('i', $purchaseId);
    if (!$purchaseStatement->execute()) {
        throw new mysqli_sql_exception($purchaseStatement->error);
    }
    $purchase = $purchaseStatement->get_result()->fetch_object();
    if (!$purchase) {
        throw new DomainException('Material purchase was not found');
    }

    assert_project_purchase_within_budget($db, $projectId, $totalAmount, $purchaseId);

    $oldItemStatement = $db->conn->prepare(
        'SELECT material_id, quantity, unit_price
        FROM material_purchase_item
        WHERE purchase_id = ? AND deleted_at IS NULL
        ORDER BY id ASC FOR UPDATE'
    );
    if (!$oldItemStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $oldItemStatement->bind_param('i', $purchaseId);
    if (!$oldItemStatement->execute()) {
        throw new mysqli_sql_exception($oldItemStatement->error);
    }
    $oldItems = [];
    $oldItemResult = $oldItemStatement->get_result();
    while ($oldItem = $oldItemResult->fetch_assoc()) {
        $oldItems[] = $oldItem;
    }
    if (count($oldItems) === 0 && !empty($purchase->material_id)) {
        $oldItems[] = [
            'material_id' => $purchase->material_id,
            'quantity' => $purchase->quantity ?? 0,
            'unit_price' => $purchase->unit_price ?? 0
        ];
    }

    if (has_material_reference_movement($db, 1, $purchaseId)) {
        foreach ($oldItems as $oldItem) {
            apply_material_stock_movement(
                $db,
                (int)$oldItem['material_id'],
                (int)$purchase->project_id,
                (float)$oldItem['quantity'],
                'purchase',
                $purchase->purchase_date,
                1,
                $purchaseId,
                'Purchase ' . $purchase->purchase_no . ' reversed',
                true
            );
        }
    }

    $columnsResult = $db->conn->query('SHOW COLUMNS FROM material_purchase');
    if (!$columnsResult) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $hasTotalAmount = false;
    while ($column = $columnsResult->fetch_assoc()) {
        if ($column['Field'] === 'total_amount') {
            $hasTotalAmount = true;
            break;
        }
    }
    $headerSql = 'UPDATE material_purchase
        SET purchase_no = ?, purchase_date = ?, supplier_id = ?, project_id = ?, status = ?';
    if ($hasTotalAmount) {
        $headerSql .= ', total_amount = ?';
    }
    $headerSql .= ', updated_at = CURRENT_TIMESTAMP WHERE id = ?';
    $headerStatement = $db->conn->prepare($headerSql);
    if (!$headerStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    if ($hasTotalAmount) {
        $headerStatement->bind_param(
            'ssiiidi',
            $purchaseNo,
            $purchaseDate,
            $supplierId,
            $projectId,
            $status,
            $totalAmount,
            $purchaseId
        );
    } else {
        $headerStatement->bind_param(
            'ssiiii',
            $purchaseNo,
            $purchaseDate,
            $supplierId,
            $projectId,
            $status,
            $purchaseId
        );
    }
    if (!$headerStatement->execute()) {
        throw new mysqli_sql_exception($headerStatement->error);
    }

    $deleteItems = $db->conn->prepare(
        'DELETE FROM material_purchase_item WHERE purchase_id = ?'
    );
    if (!$deleteItems) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $deleteItems->bind_param('i', $purchaseId);
    if (!$deleteItems->execute()) {
        throw new mysqli_sql_exception($deleteItems->error);
    }

    $materialStatement = $db->conn->prepare(
        'SELECT unit_id FROM material_list
        WHERE id = ? AND deleted_at IS NULL AND unit_id IS NOT NULL LIMIT 1'
    );
    if (!$materialStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $itemStatement = $db->conn->prepare(
        'INSERT INTO material_purchase_item
        (purchase_id, material_id, quantity, unit_id, unit_price, total_price)
        VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$itemStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }

    foreach ($purchaseItems as $item) {
        $materialId = $item['material_id'];
        $quantity = $item['quantity'];
        $unitPrice = $item['unit_price'];
        $lineTotal = $item['total_price'];

        $materialStatement->bind_param('i', $materialId);
        if (!$materialStatement->execute()) {
            throw new mysqli_sql_exception($materialStatement->error);
        }
        $materialStatement->bind_result($unitId);
        if (!$materialStatement->fetch()) {
            $materialStatement->free_result();
            throw new DomainException('Selected material was not found or has no unit');
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
    $res['data'] = ['purchase_id' => $purchaseId, 'total_amount' => $totalAmount];
    $res['message'] = 'Material purchase updated successfully';
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    $res['message'] = $exception->getMessage();
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $db->conn->rollback();
    }
    error_log('Material purchase update failed: ' . $exception->getMessage());
    $res['message'] = 'Unable to update material purchase. No purchase data was changed.';
}

echo json_encode($res);
?>
