<?php
include '../connection.php';
include '../auth_check.php';

$projectId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($projectId === false || $projectId === null || $projectId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'A valid project ID is required'
    ]);
    exit;
}

$purchaseColumnsResult = $db->conn->query('SHOW COLUMNS FROM material_purchase');
if (!$purchaseColumnsResult) {
    error_log('Project details purchase schema query failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load project details']);
    exit;
}
$purchaseColumns = [];
while ($column = $purchaseColumnsResult->fetch_assoc()) {
    $purchaseColumns[$column['Field']] = true;
}
$legacyTotal = isset($purchaseColumns['total_amount'])
    ? 'COALESCE(material_purchase.total_amount, 0)'
    : '0';

$projectStatement = $db->conn->prepare(
    "SELECT projects.*, clients.client_name, employee.employee_name,
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
    LEFT JOIN employee ON projects.employee_id = employee.id
    WHERE projects.id = ? AND projects.deleted_at IS NULL
    LIMIT 1"
);

if (!$projectStatement) {
    error_log('Project details query failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load project details']);
    exit;
}
$projectStatement->bind_param('i', $projectId);
if (!$projectStatement->execute()) {
    error_log('Project details query failed: ' . $projectStatement->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load project details']);
    exit;
}
$project = $projectStatement->get_result()->fetch_object();

if (!$project) {
    http_response_code(404);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Project was not found']);
    exit;
}

$purchaseStatement = $db->conn->prepare(
    "SELECT material_purchase.id, material_purchase.purchase_no, material_purchase.purchase_date,
        material_purchase.status, supplier.supplier_name,
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
        END AS total_amount
    FROM material_purchase
    LEFT JOIN supplier ON material_purchase.supplier_id = supplier.id
    WHERE material_purchase.project_id = ? AND material_purchase.deleted_at IS NULL
    ORDER BY material_purchase.purchase_date DESC, material_purchase.id DESC"
);

if (!$purchaseStatement) {
    error_log('Project purchase history query failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load project purchases']);
    exit;
}
$purchaseStatement->bind_param('i', $projectId);
if (!$purchaseStatement->execute()) {
    error_log('Project purchase history query failed: ' . $purchaseStatement->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load project purchases']);
    exit;
}

$purchases = [];
$purchaseResult = $purchaseStatement->get_result();
while ($purchase = $purchaseResult->fetch_object()) {
    $purchases[] = $purchase;
}

echo json_encode([
    'status' => true,
    'data' => [
        'project' => $project,
        'purchases' => $purchases
    ],
    'message' => 'Project details loaded successfully'
]);
?>
