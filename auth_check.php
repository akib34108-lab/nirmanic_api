<?php
$headers = getallheaders();
// Access the Authorization header
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : null;
if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $accessToken = $matches[1];
	$authresult=$db->common_select('users','*',['remember_token'=>$accessToken]);
    $access_user_id="";
	if($authresult['data'] > 0){
		$access_user_id=$authresult['data'][0]->id;
	}else{
        echo json_encode(array("message" => "Token is not available in database.",'status'=>false));
        exit;
    }
}else{
    echo json_encode(array("message" => "Token is not available in database.",'status'=>false));
    exit;
}