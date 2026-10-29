<?php
include '../connection.php';
include '../auth_check.php';

function boqRespond($status, $data, $message = '', $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'status' => $status,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

function boqItems($db, $boqId)
{
    $statement = $db->conn->prepare(
        'SELECT boq_item.*, material_list.material_name
         FROM boq_item
         LEFT JOIN material_list ON material_list.id = boq_item.material_id
         WHERE boq_item.boq_id = ?
         ORDER BY boq_item.sort_order, boq_item.id'
    );
    if (!$statement) {
        throw new RuntimeException('Unable to prepare BOQ item query.');
    }
    $statement->bind_param('i', $boqId);
    if (!$statement->execute()) {
        throw new RuntimeException('Unable to load BOQ items.');
    }
    $result = $statement->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $statement->close();
    return $items;
}

function boqError($db, $message, $statusCode = 400)
{
    error_log('BOQ API: ' . $message . ($db->conn->error ? ' ' . $db->conn->error : ''));
    boqRespond(false, null, $message, $statusCode);
}

$action = $_GET['action'] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'];

try {
    if ($requestMethod === 'GET') {
        if ($action === 'report') {
            $projectId = isset($_GET['project_id']) && $_GET['project_id'] !== ''
                ? filter_var($_GET['project_id'], FILTER_VALIDATE_INT)
                : null;
            if (isset($_GET['project_id']) && $_GET['project_id'] !== '' && (!$projectId || $projectId < 1)) {
                boqRespond(false, null, 'Invalid project selected.', 400);
            }
            $purchaseColumnsResult = $db->conn->query('SHOW COLUMNS FROM material_purchase');
            if (!$purchaseColumnsResult) {
                throw new RuntimeException('Unable to inspect material purchase schema.');
            }
            $purchaseColumns = [];
            while ($column = $purchaseColumnsResult->fetch_assoc()) {
                $purchaseColumns[$column['Field']] = true;
            }
            $legacyPurchaseAmount = isset($purchaseColumns['total_amount'])
                ? 'purchase.total_amount'
                : '0';

            $sql = "
                SELECT b.id, b.boq_no, b.title, b.project_id, b.revision, b.status,
                    p.name AS project_name,
                    COALESCE(items.estimate_total, 0) AS estimate_total,
                    CASE
                        WHEN COALESCE(project_estimates.estimate_total, 0) > 0
                        THEN ROUND(COALESCE(project_actuals.actual_total, 0)
                            * COALESCE(items.estimate_total, 0)
                            / project_estimates.estimate_total, 2)
                        ELSE 0
                    END AS actual_total
                FROM boq b
                INNER JOIN projects p ON p.id = b.project_id AND p.deleted_at IS NULL
                LEFT JOIN (
                    SELECT boq_id, SUM(amount) AS estimate_total
                    FROM boq_item
                    GROUP BY boq_id
                ) items ON items.boq_id = b.id
                LEFT JOIN (
                    SELECT approved_boq.project_id, SUM(approved_items.estimate_total) AS estimate_total
                    FROM boq approved_boq
                    LEFT JOIN (
                        SELECT boq_id, SUM(amount) AS estimate_total
                        FROM boq_item
                        GROUP BY boq_id
                    ) approved_items ON approved_items.boq_id = approved_boq.id
                    WHERE approved_boq.deleted_at IS NULL AND approved_boq.status = 'approved'
                    GROUP BY approved_boq.project_id
                ) project_estimates ON project_estimates.project_id = b.project_id
                LEFT JOIN (
                    SELECT spend.project_id, SUM(spend.amount) AS actual_total
                    FROM (
                        SELECT purchase.project_id, purchase_item.total_price AS amount
                        FROM material_purchase purchase
                        INNER JOIN material_purchase_item purchase_item
                            ON purchase_item.purchase_id = purchase.id
                            AND purchase_item.deleted_at IS NULL
                        WHERE purchase.deleted_at IS NULL AND purchase.status IN (4, 5)
                        UNION ALL
                        SELECT purchase.project_id, $legacyPurchaseAmount AS amount
                        FROM material_purchase purchase
                        WHERE purchase.deleted_at IS NULL AND purchase.status IN (4, 5)
                            AND NOT EXISTS (
                                SELECT 1 FROM material_purchase_item purchase_item
                                WHERE purchase_item.purchase_id = purchase.id
                                    AND purchase_item.deleted_at IS NULL
                            )
                    ) spend
                    GROUP BY spend.project_id
                ) project_actuals ON project_actuals.project_id = b.project_id
                WHERE b.deleted_at IS NULL AND b.status = 'approved'";
            if ($projectId) {
                $sql .= ' AND b.project_id = ?';
            }
            $sql .= ' ORDER BY p.name, b.boq_no';
            $statement = $db->conn->prepare($sql);
            if (!$statement) {
                throw new RuntimeException('Unable to prepare budget report query.');
            }
            if ($projectId) {
                $statement->bind_param('i', $projectId);
            }
            if (!$statement->execute()) {
                throw new RuntimeException('Unable to load budget report.');
            }
            $result = $statement->get_result();
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $statement->close();
            boqRespond(true, $rows, count($rows) ? 'Records found' : 'No records found');
        }

        if (isset($_GET['id'])) {
            $boqId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if (!$boqId || $boqId < 1) {
                boqRespond(false, null, 'Invalid BOQ selected.', 400);
            }
            $statement = $db->conn->prepare(
                'SELECT b.*, p.name AS project_name, p.project_code
                 FROM boq b
                 INNER JOIN projects p ON p.id = b.project_id
                 WHERE b.id = ? AND b.deleted_at IS NULL AND p.deleted_at IS NULL
                 LIMIT 1'
            );
            if (!$statement) {
                throw new RuntimeException('Unable to prepare BOQ details query.');
            }
            $statement->bind_param('i', $boqId);
            if (!$statement->execute()) {
                throw new RuntimeException('Unable to load BOQ details.');
            }
            $boq = $statement->get_result()->fetch_assoc();
            $statement->close();
            if (!$boq) {
                boqRespond(false, null, 'BOQ not found.', 404);
            }
            $boq['items'] = boqItems($db, $boqId);
            $boq['estimate_total'] = array_sum(array_map(function ($item) {
                return (float) $item['amount'];
            }, $boq['items']));

            $historyStatement = $db->conn->prepare(
                "SELECT history.*, users.name AS actor_name
                 FROM (
                    SELECT id, 'revision' AS entry_type, revision, change_note, NULL AS action,
                        NULL AS comment, changed_by AS actor_id, created_at
                    FROM boq_revision WHERE boq_id = ?
                    UNION ALL
                    SELECT id, 'approval' AS entry_type, revision, NULL AS change_note,
                        action, comment, actor_id, created_at
                    FROM boq_approval WHERE boq_id = ?
                 ) history
                 LEFT JOIN users ON users.id = history.actor_id
                 ORDER BY history.created_at DESC, history.id DESC"
            );
            if (!$historyStatement) {
                throw new RuntimeException('Unable to prepare BOQ history query.');
            }
            $historyStatement->bind_param('ii', $boqId, $boqId);
            if (!$historyStatement->execute()) {
                throw new RuntimeException('Unable to load BOQ history.');
            }
            $historyResult = $historyStatement->get_result();
            $boq['history'] = [];
            while ($entry = $historyResult->fetch_assoc()) {
                $boq['history'][] = $entry;
            }
            $historyStatement->close();
            boqRespond(true, $boq, 'BOQ loaded');
        }

        $result = $db->conn->query(
            "SELECT b.id, b.boq_no, b.project_id, b.title, b.status, b.revision,
                b.updated_at, p.name AS project_name, COALESCE(SUM(bi.amount), 0) AS estimate_total
             FROM boq b
             INNER JOIN projects p ON p.id = b.project_id
             LEFT JOIN boq_item bi ON bi.boq_id = b.id
             WHERE b.deleted_at IS NULL AND p.deleted_at IS NULL
             GROUP BY b.id
             ORDER BY b.updated_at DESC, b.id DESC"
        );
        if (!$result) {
            throw new RuntimeException('Unable to load BOQ list.');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        boqRespond(true, $rows, count($rows) ? 'Records found' : 'No records found');
    }

    if ($requestMethod !== 'POST') {
        boqRespond(false, null, 'Unsupported request method.', 405);
    }

    $items = json_decode($_POST['items'] ?? '', true);
    if ($action === 'create' || $action === 'update') {
        $projectId = filter_var($_POST['project_id'] ?? '', FILTER_VALIDATE_INT);
        $title = trim($_POST['title'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!$projectId || $projectId < 1 || $title === '' || strlen($title) > 180) {
            boqRespond(false, null, 'Project and a title of at most 180 characters are required.', 400);
        }
        if (!is_array($items) || count($items) < 1 || count($items) > 500) {
            boqRespond(false, null, 'Add between 1 and 500 valid BOQ items.', 400);
        }

        $projectStatement = $db->conn->prepare(
            'SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if (!$projectStatement) {
            throw new RuntimeException('Unable to prepare project validation.');
        }
        $projectStatement->bind_param('i', $projectId);
        $projectStatement->execute();
        $projectExists = $projectStatement->get_result()->num_rows === 1;
        $projectStatement->close();
        if (!$projectExists) {
            boqRespond(false, null, 'The selected project does not exist.', 400);
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            $description = trim($item['description'] ?? '');
            $unit = trim($item['unit'] ?? '');
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $rate = filter_var($item['rate'] ?? null, FILTER_VALIDATE_FLOAT);
            $materialId = isset($item['material_id']) && $item['material_id'] !== ''
                ? filter_var($item['material_id'], FILTER_VALIDATE_INT)
                : null;
            if (
                $description === '' || strlen($description) > 255 ||
                $unit === '' || strlen($unit) > 50 ||
                $quantity === false || !is_finite((float) $quantity) || $quantity <= 0 ||
                $rate === false || !is_finite((float) $rate) || $rate < 0 ||
                ($materialId !== null && (!$materialId || $materialId < 1))
            ) {
                boqRespond(false, null, 'Each item needs a description, unit, positive quantity, non-negative rate, and valid material link.', 400);
            }
            if (
                $quantity > 999999999999.999 ||
                $rate > 9999999999999.99 ||
                round((float) $quantity * (float) $rate, 2) > 999999999999999.99
            ) {
                boqRespond(false, null, 'Item quantity or rate exceeds the supported limit.', 400);
            }
            if ($materialId !== null) {
                $materialStatement = $db->conn->prepare(
                    'SELECT id FROM material_list WHERE id = ? AND deleted_at IS NULL LIMIT 1'
                );
                if (!$materialStatement) {
                    throw new RuntimeException('Unable to prepare material validation.');
                }
                $materialStatement->bind_param('i', $materialId);
                $materialStatement->execute();
                $materialExists = $materialStatement->get_result()->num_rows === 1;
                $materialStatement->close();
                if (!$materialExists) {
                    boqRespond(false, null, 'A linked material does not exist.', 400);
                }
            }
            $normalizedItems[] = [
                'material_id' => $materialId,
                'description' => $description,
                'unit' => $unit,
                'quantity' => (float) $quantity,
                'rate' => (float) $rate,
                'amount' => round((float) $quantity * (float) $rate, 2)
            ];
        }

        $db->conn->begin_transaction();
        try {
            if ($action === 'create') {
                $boqNumber = 'BOQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $statement = $db->conn->prepare(
                    "INSERT INTO boq (boq_no, project_id, title, status, revision, notes, created_by)
                     VALUES (?, ?, ?, 'draft', 1, ?, ?)"
                );
                if (!$statement) {
                    throw new RuntimeException('Unable to prepare BOQ creation.');
                }
                $statement->bind_param('sissi', $boqNumber, $projectId, $title, $notes, $access_user_id);
                if (!$statement->execute()) {
                    throw new RuntimeException('Unable to create BOQ.');
                }
                $boqId = $db->conn->insert_id;
                $statement->close();
            } else {
                $boqId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
                $changeNote = trim($_POST['change_note'] ?? '');
                if (!$boqId || $boqId < 1 || $changeNote === '' || strlen($changeNote) > 255) {
                    throw new InvalidArgumentException('A valid BOQ and revision note are required.');
                }
                $lock = $db->conn->prepare(
                    "SELECT * FROM boq WHERE id = ? AND deleted_at IS NULL FOR UPDATE"
                );
                if (!$lock) {
                    throw new RuntimeException('Unable to prepare BOQ revision.');
                }
                $lock->bind_param('i', $boqId);
                if (!$lock->execute()) {
                    throw new RuntimeException('Unable to load BOQ for revision.');
                }
                $current = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!$current) {
                    throw new InvalidArgumentException('BOQ not found.');
                }
                if (!in_array($current['status'], ['draft', 'rejected', 'approved'], true)) {
                    throw new InvalidArgumentException('Pending BOQs cannot be edited until they are approved or returned for revision.');
                }
                $currentItems = boqItems($db, $boqId);
                $snapshot = json_encode([
                    'boq' => $current,
                    'items' => $currentItems
                ], JSON_UNESCAPED_UNICODE);
                $revisionNumber = (int) $current['revision'];
                $revision = $db->conn->prepare(
                    'INSERT INTO boq_revision (boq_id, revision, snapshot, change_note, changed_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                if (!$revision) {
                    throw new RuntimeException('Unable to prepare revision history.');
                }
                $revision->bind_param('iissi', $boqId, $revisionNumber, $snapshot, $changeNote, $access_user_id);
                if (!$revision->execute()) {
                    throw new RuntimeException('Unable to save revision history.');
                }
                $revision->close();

                $update = $db->conn->prepare(
                    "UPDATE boq SET project_id = ?, title = ?, notes = ?, revision = revision + 1,
                        status = 'draft', rejection_note = NULL, approved_by = NULL, approved_at = NULL
                     WHERE id = ?"
                );
                if (!$update) {
                    throw new RuntimeException('Unable to prepare BOQ update.');
                }
                $update->bind_param('issi', $projectId, $title, $notes, $boqId);
                if (!$update->execute()) {
                    throw new RuntimeException('Unable to update BOQ.');
                }
                $update->close();
                $log = $db->conn->prepare(
                    "INSERT INTO boq_approval (boq_id, revision, action, comment, actor_id)
                     VALUES (?, ?, 'revised', ?, ?)"
                );
                if (!$log) {
                    throw new RuntimeException('Unable to prepare revision activity.');
                }
                $nextRevision = $revisionNumber + 1;
                $log->bind_param('iisi', $boqId, $nextRevision, $changeNote, $access_user_id);
                if (!$log->execute()) {
                    throw new RuntimeException('Unable to log revision activity.');
                }
                $log->close();
                $delete = $db->conn->prepare('DELETE FROM boq_item WHERE boq_id = ?');
                if (!$delete) {
                    throw new RuntimeException('Unable to prepare old item replacement.');
                }
                $delete->bind_param('i', $boqId);
                if (!$delete->execute()) {
                    throw new RuntimeException('Unable to replace BOQ items.');
                }
                $delete->close();
            }

            $itemStatement = $db->conn->prepare(
                'INSERT INTO boq_item (boq_id, material_id, description, unit, quantity, rate, amount, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$itemStatement) {
                throw new RuntimeException('Unable to prepare BOQ items.');
            }
            foreach ($normalizedItems as $index => $item) {
                $materialId = $item['material_id'];
                $description = $item['description'];
                $unit = $item['unit'];
                $quantity = $item['quantity'];
                $rate = $item['rate'];
                $amount = $item['amount'];
                $itemStatement->bind_param(
                    'iissdddi',
                    $boqId,
                    $materialId,
                    $description,
                    $unit,
                    $quantity,
                    $rate,
                    $amount,
                    $index
                );
                if (!$itemStatement->execute()) {
                    throw new RuntimeException('Unable to save a BOQ item.');
                }
            }
            $itemStatement->close();
            $db->conn->commit();
            boqRespond(true, ['id' => $boqId], $action === 'create' ? 'BOQ created.' : 'BOQ revision saved.');
        } catch (Throwable $error) {
            $db->conn->rollback();
            throw $error;
        }
    }

    $boqId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if (!$boqId || $boqId < 1) {
        boqRespond(false, null, 'A valid BOQ is required.', 400);
    }
    $db->conn->begin_transaction();
    try {
        $lock = $db->conn->prepare(
            'SELECT id, status, revision FROM boq WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
        );
        if (!$lock) {
            throw new RuntimeException('Unable to prepare approval action.');
        }
        $lock->bind_param('i', $boqId);
        if (!$lock->execute()) {
            throw new RuntimeException('Unable to load BOQ for approval.');
        }
        $current = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$current) {
            throw new InvalidArgumentException('BOQ not found.');
        }
        $comment = trim($_POST['comment'] ?? '');
        if ($action === 'submit') {
            if (!in_array($current['status'], ['draft', 'rejected'], true)) {
                throw new InvalidArgumentException('Only draft or rejected BOQs can be submitted.');
            }
            $newStatus = 'pending';
            $logAction = 'submitted';
            $update = $db->conn->prepare(
                "UPDATE boq SET status = 'pending', rejection_note = NULL WHERE id = ?"
            );
        } elseif ($action === 'approve') {
            if ($current['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending BOQs can be approved.');
            }
            $newStatus = 'approved';
            $logAction = 'approved';
            $update = $db->conn->prepare(
                "UPDATE boq SET status = 'approved', approved_by = ?, approved_at = NOW()
                 WHERE id = ?"
            );
            if ($update) {
                $update->bind_param('ii', $access_user_id, $boqId);
            }
        } elseif ($action === 'reject') {
            if ($current['status'] !== 'pending' || $comment === '') {
                throw new InvalidArgumentException('A pending BOQ and a revision request note are required.');
            }
            $newStatus = 'rejected';
            $logAction = 'revision requested';
            $update = $db->conn->prepare(
                "UPDATE boq SET status = 'rejected', rejection_note = ?, approved_by = NULL, approved_at = NULL
                 WHERE id = ?"
            );
            if ($update) {
                $update->bind_param('si', $comment, $boqId);
            }
        } else {
            throw new InvalidArgumentException('Unsupported BOQ action.');
        }
        if (!$update) {
            throw new RuntimeException('Unable to prepare BOQ status update.');
        }
        if ($action === 'submit') {
            $update->bind_param('i', $boqId);
        }
        if (!$update->execute()) {
            throw new RuntimeException('Unable to update BOQ status.');
        }
        $update->close();

        $log = $db->conn->prepare(
            'INSERT INTO boq_approval (boq_id, revision, action, comment, actor_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$log) {
            throw new RuntimeException('Unable to prepare BOQ approval history.');
        }
        $revision = (int) $current['revision'];
        $log->bind_param('iissi', $boqId, $revision, $logAction, $comment, $access_user_id);
        if (!$log->execute()) {
            throw new RuntimeException('Unable to save BOQ approval history.');
        }
        $log->close();
        $db->conn->commit();
        boqRespond(true, ['id' => $boqId, 'status' => $newStatus], 'BOQ ' . $logAction . '.');
    } catch (Throwable $error) {
        $db->conn->rollback();
        throw $error;
    }
} catch (InvalidArgumentException $error) {
    boqRespond(false, null, $error->getMessage(), 400);
} catch (Throwable $error) {
    error_log('BOQ API failure: ' . $error->getMessage());
    boqError($db, 'Unable to process request.', 500);
}
