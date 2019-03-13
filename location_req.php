<?php

/**********/
$hud_mac = "CC:1B:E0:E0:13:5C";
//$node_mac = "D9:C6:06:FE:C7:1F"; //Relay1
//$node_mac = "D8:61:7C:F2:E1:EA"; //Relay2
include 'location.php';
/**********/

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "http://api.cassianetworks.com/oauth2/token",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => "{\"grant_type\" : \"client_credentials\"}",
  CURLOPT_HTTPHEADER => array(
    "authorization: Basic dGVzdGVyOjEwYjgzZjlhMmU4MjNjNDc=",
    "cache-control: no-cache",
    "content-type: application/json",
    "postman-token: dd332caa-2930-c9f9-ac79-48a87c070617"
  ),
));

$response = curl_exec($curl);
$err = curl_error($curl);
//curl_close($curl);
if($err)
	echo "cURL Error #:" . $err;

$cart = json_decode( $response );
$url = "http://api.cassianetworks.com/gap/nodes/?event=1&chip=1&mac=".$hud_mac."&access_token=".$cart->access_token;

$http = new Http($url,$hud_mac);
$response = $http->get();
echo $response;
?>