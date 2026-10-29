
<?php
include '../connection.php';
include '../auth_check.php';

$res = [
    "status" => false,
    "data" => [],
    "message" => ""
];

if (isset($_GET['id'])) {

    $id = $_GET['id'];

    // Get main material issue
    $issueResult = $db->common_select(
        'material_issue',
        '*',
        ['id' => $id]
    );

    if ($issueResult['status']) {

        $issue = $issueResult['data'][0];

        // Get all items of this issue
        $itemResult = $db->common_select(
            'material_issue_item',
            '*',
            ['issue_id' => $id]
        );

        $items = [];

        if ($itemResult['status']) {
            $items = $itemResult['data'];
        }

        // Combine issue and items
        $issue->items = $items;

        $res['status'] = true;
        $res['data'] = $issue;
        $res['message'] = 'Material issue found';

    } else {
        $res['message'] = 'Material issue not found';
    }

} else {
    $res['message'] = 'Issue ID is required';
}

echo json_encode($res);
?>
