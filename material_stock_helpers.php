<?php

function material_stock_status($currentStock, $minimumStock)
{
    if ($currentStock <= 0) {
        return 3;
    }
    if ($currentStock <= $minimumStock) {
        return 2;
    }
    return 1;
}

function has_material_reference_movement($db, $referenceType, $referenceId)
{
    $statement = $db->conn->prepare(
        'SELECT id FROM material_transaction WHERE ref_type = ? AND ref_id = ? AND deleted_at IS NULL LIMIT 1'
    );
    if (!$statement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $statement->bind_param('ii', $referenceType, $referenceId);
    if (!$statement->execute()) {
        throw new mysqli_sql_exception($statement->error);
    }
    return (bool)$statement->get_result()->fetch_assoc();
}

function parse_project_budget_amount($budget)
{
    if (!is_string($budget) && !is_numeric($budget)) {
        return null;
    }

    $normalized = str_replace(',', '', trim((string)$budget));
    if (!preg_match('/^(?:৳\s*|BDT\s*)?(\d+(?:\.\d+)?)\s*(cr|crore|crores|lakh|lac|k)?$/i', $normalized, $matches)) {
        return null;
    }

    $multipliers = [
        'cr' => 10000000,
        'crore' => 10000000,
        'crores' => 10000000,
        'lakh' => 100000,
        'lac' => 100000,
        'k' => 1000
    ];
    $unit = strtolower($matches[2] ?? '');
    $amount = (float)$matches[1] * ($multipliers[$unit] ?? 1);

    return is_finite($amount) && $amount >= 0 ? $amount : null;
}

function assert_project_purchase_within_budget($db, $projectId, $purchaseAmount, $excludePurchaseId = null)
{
    $projectStatement = $db->conn->prepare(
        'SELECT budget FROM projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
    );
    if (!$projectStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $projectStatement->bind_param('i', $projectId);
    if (!$projectStatement->execute()) {
        throw new mysqli_sql_exception($projectStatement->error);
    }
    $project = $projectStatement->get_result()->fetch_assoc();
    if (!$project) {
        throw new DomainException('Selected project was not found');
    }

    $budget = parse_project_budget_amount($project['budget']);
    if ($budget === null) {
        throw new DomainException('Set a valid numeric budget for this project before recording a material purchase');
    }

    $columnsResult = $db->conn->query('SHOW COLUMNS FROM material_purchase');
    if (!$columnsResult) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $columns = [];
    while ($column = $columnsResult->fetch_assoc()) {
        $columns[$column['Field']] = true;
    }

    $legacyAmounts = [];
    if (isset($columns['total_amount'])) {
        $legacyAmounts[] = 'material_purchase.total_amount';
    }
    if (isset($columns['quantity'], $columns['unit_price'])) {
        $legacyAmounts[] = '(material_purchase.quantity * material_purchase.unit_price)';
    }
    $legacyTotal = $legacyAmounts
        ? 'COALESCE(' . implode(', ', $legacyAmounts) . ', 0)'
        : '0';

    $sql = "SELECT COALESCE(SUM(
        CASE
            WHEN COALESCE(item_totals.item_count, 0) > 0
                THEN COALESCE(item_totals.total_amount, 0)
            ELSE $legacyTotal
        END
    ), 0) AS spent_amount
    FROM material_purchase
    LEFT JOIN (
        SELECT purchase_id, COUNT(*) AS item_count, SUM(total_price) AS total_amount
        FROM material_purchase_item
        WHERE deleted_at IS NULL
        GROUP BY purchase_id
    ) AS item_totals ON item_totals.purchase_id = material_purchase.id
    WHERE material_purchase.project_id = ? AND material_purchase.deleted_at IS NULL";

    if ($excludePurchaseId !== null) {
        $sql .= ' AND material_purchase.id <> ?';
    }

    $spendStatement = $db->conn->prepare($sql);
    if (!$spendStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    if ($excludePurchaseId !== null) {
        $spendStatement->bind_param('ii', $projectId, $excludePurchaseId);
    } else {
        $spendStatement->bind_param('i', $projectId);
    }
    if (!$spendStatement->execute()) {
        throw new mysqli_sql_exception($spendStatement->error);
    }
    $spentAmount = (float)$spendStatement->get_result()->fetch_assoc()['spent_amount'];
    $proposedSpend = round($spentAmount + (float)$purchaseAmount, 2);

    if ($proposedSpend > round($budget, 2)) {
        $availableAmount = max(0, $budget - $spentAmount);
        throw new DomainException(
            'Purchase exceeds the project budget. Budget: ৳' . number_format($budget, 2) .
            ', already spent: ৳' . number_format($spentAmount, 2) .
            ', available: ৳' . number_format($availableAmount, 2) . '.'
        );
    }
}

function apply_material_stock_movement(
    $db,
    $materialId,
    $projectId,
    $quantity,
    $movementType,
    $transactionDate,
    $referenceType,
    $referenceId,
    $notes,
    $reverse = false
) {
    if (!in_array($movementType, ['purchase', 'issue'], true)) {
        throw new InvalidArgumentException('Invalid material movement type');
    }

    $inventoryStatement = $db->conn->prepare(
        'SELECT id, quantity_available, quantity_used, current_stock, minimum_stock FROM material_inventory WHERE material_id = ? AND project_id = ? AND deleted_at IS NULL FOR UPDATE'
    );
    if (!$inventoryStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $inventoryStatement->bind_param('ii', $materialId, $projectId);
    if (!$inventoryStatement->execute()) {
        throw new mysqli_sql_exception($inventoryStatement->error);
    }
    $inventoryResult = $inventoryStatement->get_result();
    $inventory = $inventoryResult->fetch_assoc();

    $quantityAvailable = $inventory ? (float)$inventory['quantity_available'] : 0;
    $quantityUsed = $inventory ? (float)$inventory['quantity_used'] : 0;
    $currentStock = $inventory ? (float)$inventory['current_stock'] : 0;
    $minimumStock = $inventory ? (float)$inventory['minimum_stock'] : 0;
    $quantityDelta = $reverse ? -(float)$quantity : (float)$quantity;

    if ($movementType === 'purchase') {
        $quantityAvailable += $quantityDelta;
        $currentStock += $quantityDelta;
    } else {
        $quantityUsed += $quantityDelta;
        $currentStock -= $quantityDelta;
    }

    if (
        $quantityAvailable < 0 ||
        $quantityUsed < 0 ||
        $currentStock < 0
    ) {
        throw new DomainException(
            $movementType === 'purchase'
                ? 'This purchase cannot be reversed because some of its material has already been used'
                : 'Insufficient stock for this material in the selected project'
        );
    }

    $status = material_stock_status($currentStock, $minimumStock);
    if ($inventory) {
        $inventoryId = (int)$inventory['id'];
        $updateStatement = $db->conn->prepare(
            'UPDATE material_inventory SET quantity_available = ?, quantity_used = ?, current_stock = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        if (!$updateStatement) {
            throw new mysqli_sql_exception($db->conn->error);
        }
        $updateStatement->bind_param(
            'dddii',
            $quantityAvailable,
            $quantityUsed,
            $currentStock,
            $status,
            $inventoryId
        );
        if (!$updateStatement->execute()) {
            throw new mysqli_sql_exception($updateStatement->error);
        }
    } else {
        if ($movementType !== 'purchase' || $reverse) {
            throw new DomainException('No inventory record exists for this material in the selected project');
        }
        $insertStatement = $db->conn->prepare(
            'INSERT INTO material_inventory (material_id, project_id, quantity_available, quantity_used, current_stock, minimum_stock, status) VALUES (?, ?, ?, 0, ?, 0, ?)'
        );
        if (!$insertStatement) {
            throw new mysqli_sql_exception($db->conn->error);
        }
        $insertStatement->bind_param(
            'iiddi',
            $materialId,
            $projectId,
            $quantity,
            $quantity,
            $status
        );
        if (!$insertStatement->execute()) {
            throw new mysqli_sql_exception($insertStatement->error);
        }
        $currentStock = (float)$quantity;
    }

    $materialStatement = $db->conn->prepare(
        'UPDATE material_list SET current_stock = (SELECT COALESCE(SUM(current_stock), 0) FROM material_inventory WHERE material_id = ? AND deleted_at IS NULL), updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL'
    );
    if (!$materialStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $materialStatement->bind_param('ii', $materialId, $materialId);
    if (!$materialStatement->execute()) {
        throw new mysqli_sql_exception($materialStatement->error);
    }

    $transactionType = ($movementType === 'purchase') !== $reverse ? 1 : 2;
    $quantityIn = $transactionType === 1 ? $quantity : 0;
    $quantityOut = $transactionType === 2 ? $quantity : 0;
    $transactionStatement = $db->conn->prepare(
        'INSERT INTO material_transaction (material_id, project_id, transaction_date, transaction_type, ref_type, ref_id, quantity_in, quantity_out, balance_after, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$transactionStatement) {
        throw new mysqli_sql_exception($db->conn->error);
    }
    $transactionStatement->bind_param(
        'iisiiiddds',
        $materialId,
        $projectId,
        $transactionDate,
        $transactionType,
        $referenceType,
        $referenceId,
        $quantityIn,
        $quantityOut,
        $currentStock,
        $notes
    );
    if (!$transactionStatement->execute()) {
        throw new mysqli_sql_exception($transactionStatement->error);
    }

    return $currentStock;
}
