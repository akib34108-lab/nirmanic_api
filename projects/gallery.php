<?php
include '../connection.php';
include '../auth_check.php';

function project_gallery_response($status, $data, $message, $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'status' => $status,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

function get_project_gallery_project($db, $projectId)
{
    $statement = $db->conn->prepare(
        'SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    if (!$statement) {
        error_log('Project gallery project query failed: ' . $db->conn->error);
        project_gallery_response(false, [], 'Unable to load project gallery', 500);
    }

    $statement->bind_param('i', $projectId);
    if (!$statement->execute()) {
        error_log('Project gallery project query failed: ' . $statement->error);
        project_gallery_response(false, [], 'Unable to load project gallery', 500);
    }

    return $statement->get_result()->fetch_assoc();
}

$method = $_SERVER['REQUEST_METHOD'];
$projectId = filter_var(
    $method === 'POST' ? ($_POST['project_id'] ?? null) : ($_GET['project_id'] ?? null),
    FILTER_VALIDATE_INT
);

if ($method === 'GET' || $method === 'POST') {
    if ($projectId === false || $projectId === null || $projectId <= 0) {
        project_gallery_response(false, [], 'A valid project ID is required', 400);
    }

    if (!get_project_gallery_project($db, $projectId)) {
        project_gallery_response(false, [], 'Project was not found', 404);
    }
}

if ($method === 'GET') {
    $statement = $db->conn->prepare(
        'SELECT id, project_id, image_path, caption, created_at
        FROM project_progress_images
        WHERE project_id = ?
        ORDER BY created_at DESC, id DESC'
    );
    if (!$statement) {
        error_log('Project gallery query failed: ' . $db->conn->error);
        project_gallery_response(false, [], 'Unable to load project gallery', 500);
    }

    $statement->bind_param('i', $projectId);
    if (!$statement->execute()) {
        error_log('Project gallery query failed: ' . $statement->error);
        project_gallery_response(false, [], 'Unable to load project gallery', 500);
    }

    $images = [];
    $result = $statement->get_result();
    while ($image = $result->fetch_object()) {
        $images[] = $image;
    }

    project_gallery_response(true, $images, 'Project gallery loaded successfully');
}

if ($method === 'POST') {
    if (empty($_FILES['image']) || !isset($_FILES['image']['error'])) {
        project_gallery_response(false, [], 'Choose an image to upload', 400);
    }

    $upload = $_FILES['image'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'The image exceeds the server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'The image is too large',
            UPLOAD_ERR_PARTIAL => 'The image upload was incomplete',
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload',
            UPLOAD_ERR_NO_TMP_DIR => 'The server could not receive the image',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the image',
            UPLOAD_ERR_EXTENSION => 'The server rejected the image upload'
        ];
        $statusCode = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 413
            : 400;
        project_gallery_response(
            false,
            [],
            $uploadErrors[$upload['error']] ?? 'The image upload failed',
            $statusCode
        );
    }

    if ($upload['size'] > 10 * 1024 * 1024) {
        project_gallery_response(false, [], 'Images must be 10 MB or smaller', 413);
    }

    if (!is_uploaded_file($upload['tmp_name'])) {
        project_gallery_response(false, [], 'The uploaded image is invalid', 400);
    }

    $imageInfo = @getimagesize($upload['tmp_name']);
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($imageInfo === false || $fileInfo === false) {
        if ($fileInfo !== false) {
            finfo_close($fileInfo);
        }
        project_gallery_response(false, [], 'The uploaded file is not a valid image', 400);
    }

    $mimeType = finfo_file($fileInfo, $upload['tmp_name']);
    finfo_close($fileInfo);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    if (
        !isset($allowedTypes[$mimeType]) ||
        $imageInfo['mime'] !== $mimeType ||
        $imageInfo[0] <= 0 ||
        $imageInfo[1] <= 0
    ) {
        project_gallery_response(false, [], 'Only valid JPEG, PNG, and WebP images are allowed', 400);
    }

    $caption = trim((string) ($_POST['caption'] ?? ''));
    $captionLength = preg_match_all('/./us', $caption, $captionCharacters);
    if ($captionLength === false || $captionLength > 255) {
        project_gallery_response(false, [], 'Captions must be 255 characters or fewer', 400);
    }
    $caption = $caption === '' ? null : $caption;

    $uploadDirectory = __DIR__ . '/../image/project_progress/';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        error_log('Project gallery upload directory could not be created: ' . $uploadDirectory);
        project_gallery_response(false, [], 'The server could not save the image', 500);
    }

    try {
        $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    } catch (Throwable $error) {
        error_log('Project gallery filename generation failed: ' . $error->getMessage());
        project_gallery_response(false, [], 'The server could not save the image', 500);
    }

    if (!move_uploaded_file($upload['tmp_name'], $uploadDirectory . $filename)) {
        error_log('Project gallery image could not be moved into storage');
        project_gallery_response(false, [], 'The server could not save the image', 500);
    }

    $imagePath = 'image/project_progress/' . $filename;
    $statement = $db->conn->prepare(
        'INSERT INTO project_progress_images (project_id, image_path, caption)
        VALUES (?, ?, ?)'
    );
    if (!$statement) {
        unlink($uploadDirectory . $filename);
        error_log('Project gallery insert preparation failed: ' . $db->conn->error);
        project_gallery_response(false, [], 'Unable to save the gallery image', 500);
    }

    $statement->bind_param('iss', $projectId, $imagePath, $caption);
    if (!$statement->execute()) {
        unlink($uploadDirectory . $filename);
        error_log('Project gallery insert failed: ' . $statement->error);
        project_gallery_response(false, [], 'Unable to save the gallery image', 500);
    }

    project_gallery_response(true, [
        'id' => $db->conn->insert_id,
        'project_id' => $projectId,
        'image_path' => $imagePath,
        'caption' => $caption,
        'created_at' => date('Y-m-d H:i:s')
    ], 'Progress image uploaded successfully');
}

if ($method === 'DELETE') {
    $imageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($imageId === false || $imageId === null || $imageId <= 0) {
        project_gallery_response(false, [], 'A valid image ID is required', 400);
    }

    $statement = $db->conn->prepare(
        'SELECT image_path FROM project_progress_images WHERE id = ? LIMIT 1'
    );
    if (!$statement) {
        error_log('Project gallery image query failed: ' . $db->conn->error);
        project_gallery_response(false, [], 'Unable to delete gallery image', 500);
    }

    $statement->bind_param('i', $imageId);
    if (!$statement->execute()) {
        error_log('Project gallery image query failed: ' . $statement->error);
        project_gallery_response(false, [], 'Unable to delete gallery image', 500);
    }
    $image = $statement->get_result()->fetch_assoc();
    if (!$image) {
        project_gallery_response(false, [], 'Gallery image was not found', 404);
    }

    $statement = $db->conn->prepare('DELETE FROM project_progress_images WHERE id = ?');
    if (!$statement) {
        error_log('Project gallery delete preparation failed: ' . $db->conn->error);
        project_gallery_response(false, [], 'Unable to delete gallery image', 500);
    }

    $statement->bind_param('i', $imageId);
    if (!$statement->execute()) {
        error_log('Project gallery delete failed: ' . $statement->error);
        project_gallery_response(false, [], 'Unable to delete gallery image', 500);
    }

    $storedFilename = basename($image['image_path']);
    $imagePath = __DIR__ . '/../image/project_progress/' . $storedFilename;
    if (
        strpos($image['image_path'], 'image/project_progress/') !== 0 ||
        ($storedFilename !== '' && is_file($imagePath) && !unlink($imagePath))
    ) {
        error_log('Project gallery image file could not be removed: ' . $image['image_path']);
        project_gallery_response(
            true,
            [],
            'Gallery image removed, but its stored file could not be deleted'
        );
    }

    project_gallery_response(true, [], 'Progress image deleted successfully');
}

header('Allow: GET, POST, DELETE');
project_gallery_response(false, [], 'Method not allowed', 405);
