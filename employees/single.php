<?php
include '../connection.php';
include '../auth_check.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'A valid employee ID is required.'
    ]);
    exit;
}

$sql = "SELECT employee.*, designation.designation_name, department.department_name
    FROM employee
    LEFT JOIN designation ON employee.designation_id = designation.id
    LEFT JOIN department ON employee.department_id = department.id
    WHERE employee.id = ? AND employee.deleted_at IS NULL
    LIMIT 1";
$statement = $db->conn->prepare($sql);
if (!$statement) {
    error_log('Employee profile query preparation failed: ' . $db->conn->error);
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'Unable to load employee profile.'
    ]);
    exit;
}

$statement->bind_param('i', $id);
if (!$statement->execute()) {
    error_log('Employee profile query failed: ' . $statement->error);
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'Unable to load employee profile.'
    ]);
    $statement->close();
    exit;
}

$queryResult = $statement->get_result();
$employee = $queryResult->fetch_object();
$statement->close();

if (!$employee) {
    http_response_code(404);
    echo json_encode([
        'status' => false,
        'data' => [],
        'message' => 'Employee not found.'
    ]);
    exit;
}

echo json_encode([
    'status' => true,
    'data' => [$employee],
    'message' => 'Employee profile loaded.'
]);