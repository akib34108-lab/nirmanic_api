<?php
include '../connection.php';
include '../auth_check.php';

$sql = "SELECT material_list.*, material_category.category_name, material_unit.unit_name, COALESCE(inventory_totals.current_stock, material_list.current_stock, 0) AS current_stock FROM material_list LEFT JOIN material_category ON material_list.category_id = material_category.id LEFT JOIN material_unit ON material_list.unit_id = material_unit.id LEFT JOIN (SELECT material_id, SUM(current_stock) AS current_stock FROM material_inventory WHERE deleted_at IS NULL GROUP BY material_id) AS inventory_totals ON inventory_totals.material_id = material_list.id";
echo json_encode($db->common_query($sql));
?>
