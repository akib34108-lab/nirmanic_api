<?php

include 'connection.php';
$data = json_decode(file_get_contents("php://input"),true);
$data['password'] = sha1(trim($data['password']));
$rdata=[];
$result=$db->common_select("users",'*',$data);
if($result['status'] === true && count($result['data']) > 0){
	$rdata=$result['data'][0];
	$remember_token=rand(1111,9999).time().rand(111111,999999);
	/* update remember_token in database */
	$db->common_update("users",['remember_token'=>$remember_token],['id'=>$rdata->id]);
	echo json_encode(
		array(
			"message" => "Successful login.",
			"jwt" => $remember_token,
			"data"=>json_encode($rdata)
		));
}else{
	echo json_encode(array("message" => "Login failed."));
}