<?php
include '../connection.php';
include '../auth_check.php';
require_once '../activity_log_helpers.php';

if (!ensure_activity_log_table($db->conn)) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'Unable to load activity history.'
    ]);
    exit;
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page !== false && $page > 0 ? $page : 1;
$pageSize = 50;
$action = isset($_GET['action']) && is_string($_GET['action']) ? trim($_GET['action']) : '';
$entityType = isset($_GET['entity_type']) && is_string($_GET['entity_type'])
    ? trim($_GET['entity_type'])
    : '';
$conditions = [];
$parameters = [];
$types = '';

if ($action !== '') {
    $conditions[] = 'activity_logs.action = ?';
    $parameters[] = $action;
    $types .= 's';
}
if ($entityType !== '') {
    $conditions[] = 'activity_logs.entity_type = ?';
    $parameters[] = $entityType;
    $types .= 's';
}
$where = count($conditions) > 0 ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStatement = $db->conn->prepare('SELECT COUNT(*) AS total FROM activity_logs' . $where);
if (!$countStatement) {
    error_log('Activity log count query preparation failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load activity history.']);
    exit;
}
if (count($parameters) === 1) {
    $countStatement->bind_param($types, $parameters[0]);
} elseif (count($parameters) === 2) {
    $countStatement->bind_param($types, $parameters[0], $parameters[1]);
}
if (!$countStatement->execute()) {
    error_log('Activity log count query failed: ' . $countStatement->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load activity history.']);
    exit;
}
$total = (int)$countStatement->get_result()->fetch_assoc()['total'];
$countStatement->close();
$totalPages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$sql = 'SELECT activity_logs.id, activity_logs.user_id, users.name AS user_name,
        activity_logs.action, activity_logs.entity_type, activity_logs.entity_id,
        activity_logs.description, activity_logs.created_at
    FROM activity_logs
    LEFT JOIN users ON users.id = activity_logs.user_id' . $where . '
    ORDER BY activity_logs.created_at DESC, activity_logs.id DESC
    LIMIT ' . $pageSize . ' OFFSET ' . $offset;
$statement = $db->conn->prepare($sql);
if (!$statement) {
    error_log('Activity log query preparation failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load activity history.']);
    exit;
}
if (count($parameters) === 1) {
    $statement->bind_param($types, $parameters[0]);
} elseif (count($parameters) === 2) {
    $statement->bind_param($types, $parameters[0], $parameters[1]);
}
if (!$statement->execute()) {
    error_log('Activity log query failed: ' . $statement->error);
    http_response_code(500);
    echo json_encode(['status' => false, 'data' => [], 'message' => 'Unable to load activity history.']);
    exit;
}

$result = $statement->get_result();
$activities = [];
while ($row = $result->fetch_assoc()) {
    $activities[] = $row;
}
$statement->close();

echo json_encode([
    'status' => true,
    'data' => $activities,
    'pagination' => [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => $totalPages
    ]
]);
