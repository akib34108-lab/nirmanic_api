<?php
include '../connection.php';
include '../auth_check.php';

$purchaseColumnsResult = $db->conn->query('SHOW COLUMNS FROM material_purchase');
if (!$purchaseColumnsResult) {
    error_log('Project list purchase schema query failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'Unable to load project purchase totals'
    ]);
    exit;
}

$purchaseColumns = [];
while ($column = $purchaseColumnsResult->fetch_assoc()) {
    $purchaseColumns[$column['Field']] = true;
}
$legacyTotal = isset($purchaseColumns['total_amount'])
    ? 'COALESCE(material_purchase.total_amount, 0)'
    : '0';

$sql = "SELECT projects.*, clients.client_name, employee.employee_name,
    COALESCE((
        SELECT SUM(
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM material_purchase_item
                    WHERE material_purchase_item.purchase_id = material_purchase.id
                        AND material_purchase_item.deleted_at IS NULL
                ) THEN (
                    SELECT SUM(material_purchase_item.total_price)
                    FROM material_purchase_item
                    WHERE material_purchase_item.purchase_id = material_purchase.id
                        AND material_purchase_item.deleted_at IS NULL
                )
                ELSE $legacyTotal
            END
        )
        FROM material_purchase
        WHERE material_purchase.project_id = projects.id
            AND material_purchase.deleted_at IS NULL
    ), 0) AS spent_amount
    FROM projects
    LEFT JOIN clients ON projects.client_id = clients.id
    LEFT JOIN employee ON projects.employee_id = employee.id";
$result = $db->common_query($sql);
echo json_encode($result);