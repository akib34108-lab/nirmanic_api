<?php 
include 'connection.php';
$data = json_decode(file_get_contents("php://input"), true);
$data['password']=sha1($data['password']);
if($data){
	if($data['name'] && $data['email'] && $data['password']){
		$result=$db->common_insert("users",$data);
		echo json_encode($result);
	}else{
		echo json_encode(array("message" => "Name, email and password are required.",'status'=>false,'data'	=>[]));
	}
}