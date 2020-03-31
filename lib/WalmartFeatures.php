<?php
require("Client.php");
class WalmartFeatures {
    protected static $api_url = "https://marketplace.walmartapis.com";
    protected static $api_test_url = "https://developer.walmart.com/proxy/item-api-doc-app/rest";

    public static function getBatchItems($seller, $limit= null, $offset = null){
        $url = self::$api_url;
        $params = $limit === null ? [] : ["offset"=>$offset, "limit"=>$limit];
        $xml = self::_call_api_get($seller, $url."/v3/items", $params);
        $res = self::xml_to_obj($xml);
        return $res;
    }

    protected static function _call_api_get($seller, $url, $params=array())
    {
        $escaped_params=array();
        $flag = 0;

        foreach($params as $k=>$v)
        {
            $k=rawurlencode($k);
            $v=rawurlencode($v);
            $escaped_params[]="$k=$v";
        }
        $param_string=implode('&', $escaped_params);

        $final_url= empty($params)? $url :$url.'?'.$param_string;
        $time = intval(round(microtime(true) * 1000));

        $c=new Client();
        $c->custom_header= self::generate_headers($seller, $final_url, $time, "GET");
        $response=$c->get($final_url);


        return $response;
    }

    static function generate_headers($seller, $url, $time, $method, $other_header=[]){
        list($auth, $token)=self::refresh_token($seller);

        return array_merge([
            'Authorization'=>$auth,
            'WM_CONSUMER.ID'=>$seller->customer_id,//
            'WM_SEC.TIMESTAMP'=>$time,
            'WM_SEC.ACCESS_TOKEN'=>$token,
            'WM_SVC.NAME'=>'Walmart',
            'WM_QOS.CORRELATION_ID'=>sha1(microtime()),
            'WM_CONSUMER.CHANNEL.TYPE'=> $seller->channel_type,
        ], $other_header);
    }

    static function refresh_token($seller){
        $c=new Client();

        $client_id=$seller->customer_id;
        $client_secret=$seller->api_key;
        $auth='Basic '.base64_encode("$client_id:$client_secret");

        $c->custom_header=[
            'Authorization'=>$auth,
            'WM_SVC.NAME'=>'Walmart',
            'WM_QOS.CORRELATION_ID'=>sha1(microtime()),
            'Content-Type'=> "application/x-www-form-urlencoded",
            "Accept"=> "application/json"
        ];

        $json=$c->post(self::$api_url.'/v3/token', 'grant_type=client_credentials');
        $json=json_decode($json, true);
        $seller->access_token=$json['access_token'];

        return [$auth, $seller->access_token];
    }    

    public static function xml_to_obj($xml){
        $res = str_replace("ns2:", "", $xml);
        $res = str_replace("ns3:", "", $res);
        $res = str_replace("ns4:", "", $res);
        return json_decode(json_encode(simplexml_load_string($res), 1));
    }    
}
