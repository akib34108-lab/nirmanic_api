<?php

function ensure_activity_log_table($connection)
{
    static $tableReady = false;
    if ($tableReady) {
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(30) NOT NULL,
        entity_type VARCHAR(80) NOT NULL,
        entity_id VARCHAR(80) NULL,
        description TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX activity_logs_created_at (created_at),
        INDEX activity_logs_entity (entity_type, entity_id),
        INDEX activity_logs_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$connection->query($sql)) {
        error_log('Activity log table setup failed: ' . $connection->error);
        return false;
    }

    $tableReady = true;
    return true;
}

function record_activity($connection, $userId, $action, $entityType, $entityId, $description)
{
    if (!ensure_activity_log_table($connection)) {
        return false;
    }

    $statement = $connection->prepare(
        'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$statement) {
        error_log('Activity log preparation failed: ' . $connection->error);
        return false;
    }

    $userId = $userId !== null ? (int)$userId : null;
    $entityId = $entityId !== null ? (string)$entityId : null;
    $statement->bind_param('issss', $userId, $action, $entityType, $entityId, $description);
    if (!$statement->execute()) {
        error_log('Activity log insert failed: ' . $statement->error);
        $statement->close();
        return false;
    }

    $statement->close();
    return true;
}
