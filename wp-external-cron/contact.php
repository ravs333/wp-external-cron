<?php 
include('db.php');

class Cron {
    var $api_host = 'https://api.example.com/v1/';
    var $siteurl;

    var $endpoints = array(
        'property-list' => 'properties',
        'property'      => 'property',
        'contact'       => 'contact'
    );

    var $db_settings = array(
        'host' => 'localhost',
        'user' => 'root',
        'pass' => 'root',
        'dbname' => 'sample_database'
    );

    var $db; 
    var $is_active_contact_from7_plugin = false;
    var $link_shown = 0;
    var $lastError = 0;
    var $username = 0;
    var $password = 0;

    var $property_types = array(
        1=>'House and Land',
        2=>'Apartment',//'Apartment/Unit',
        3=>'Town House/Unit',//'Town House',
        4=>'Duplex',
        5=>'Dual Occupancy',
        6=>'Commercial',
        7=>'Dual Key',
        8=>'Display',
        9=>'Terrace/Villas'
    );

    var $terms;
    var $taxonomies = array(
        'state' => 'property_state',
        'city'  => 'property_city',
        'suburb'  => 'property_area',
        'status' => 'property_status',
        'property_type' => 'property_type'
    );


    public function __construct(){
        $this->db_settings = db_settings();

        $this->db = new mysqli($this->db_settings['host'], $this->db_settings['user'], $this->db_settings['pass'], $this->db_settings['dbname']);

        // Check connection
        if ($this->db->connect_error) {
            die("Connection failed: " . $this->db->connect_error);
        }

        $this->siteurl              = base_url();
        $this->username             = username();
        $this->password             = password();

        set_time_limit(1200);
    }

    function index($postData = []){

        $data = array(
            'firstname' => $postData['property_permalink'],
            'surname'   => $postData['property_permalink'],
            'phone' => $postData['phone'],
            'email' => $postData['email'],
            'postcode' => $postData['postcode'],
            'state' => $postData['state'],
            'message' => $postData['message'],
            'api_domain' => $this->siteurl,
            'version' => '2.0',
            'action' => 'contact',
            'access_token' => "",
            'usertype' => 'C',
            'page_type' => array(
                'properties',
                $postData['property_permalink'],
            )
        );

        $response = $this->sendRequest($this->api_host.$this->endpoints['contact'],$this->username, $this->password,$data);

        
        return $response;
    }

    function _slug($str){
        $str = strtolower($str);
        $str = str_replace("'", '', $str);
        $str = str_replace('"', '', $str);
        $str = str_replace('-', '', $str);
        $str = str_replace('  ', '-', $str);
        $str = str_replace(' ', '-', $str);
        $str = str_replace('(', '', $str);
        $str = str_replace(')', '', $str);

        return $str;
    }

    function sendRequest($url, $username = '', $password = '', $data = array()){
        $headers = array(
            'Origin: ' . $this->siteurl,
            // 'Content-Type:application/json',
            'Authorization: Basic '. base64_encode($username . ":" . $password) // <---
        );
        if (isset($data['url']))
            unset($data['url']);
        // $send_param = $data; 
        // debug_pre($data); die;
        $params = $this->bqs($data);

        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = curl_init();
            curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            // curl_setopt($ch, CURLOPT_ENCODING, "gzip,deflate");    
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            $curl_result = curl_exec($ch);

            // var_dump($curl_result); die;

            $this->lastError = curl_errno($ch);
            if (!$this->lastError) {
                if(!$this->isJson($curl_result)){
                    $result = gzdecode($curl_result);
                    $result = $curl_result;
                    echo $result;
                    return;
                }
                else{
                    $result = $curl_result; 
                }
            } else {
                $result = false;
            }
            curl_close($ch);
            
        } else {
            $url = $url."?".$params;
            $data = file_get_contents($url, false);
            $result = $data;                
        }
        return $result;
    }

    function _get($select = "*", $data, $table){
        $where = "";
        if(is_array($data)){
            $where = [];
            foreach($data as $i =>$j){
                $where[] = "$i = $j";
            }
            $where = implode(" AND ", $where);
        }

        if(is_string($data)){
            $where = $data;
        }
        
        $sql = "SELECT $select FROM $table WHERE $where";

        $result = $this->db->query($sql);

        if ($result->num_rows > 0) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            return $rows;
        }
        return $this->retRes('404', 'Get Error', array());
    }

    function _getRow($select = "*", $data, $table){
        $where = "";
        if(is_array($data)){
            $where = [];
            foreach($data as $i =>$j){
                if(is_int($j)){
                    $where[] = "$i = $j";
                }else{
                    $where[] = "$i = '".$j."'";
                }
            }
            $where = implode(" AND ", $where);
        }

        if(is_string($data)){
            $where = $data;
        }
        
        $sql = "SELECT $select FROM $table WHERE $where";

        $result = $this->db->query($sql);
        // print_r($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            return $row;
        }
        return 0;
    }

    function _update($key, $value, $table){
        $sql = "UPDATE $table SET ";
        if(is_array($value)){
            $upstmt = [];
            foreach($value as $i => $j){
                if(is_int($j)){
                    $upstmt[] = "$i = $j";
                }else{
                    $upstmt[] = "$i = '".$j."'";
                }
            }
            $upstmt = implode(", ", $upstmt);
            $sql .= "$upstmt "; 
        }else{
            $sql .= "$value ";
        }

        $where = "";
            
        if(is_array($key)){
            $where = [];
            foreach($key as $i =>$j){
                if(is_int($j)){
                    $where[] = "$i = $j";
                }else{
                    $where[] = "$i = '".$j."'";
                }
            }
            $where = implode(" AND ", $where);
        }else{
            $where = "ID = " . $key;
        }
        $sql .= "WHERE $where";

        $result = $this->db->query($sql);
        // debug_pre($sql);
        if ($result) {
            return true;
        }
        return $this->retRes('502', 'Update Error', mysqli_error($this->db));
    }

    function bqs($array, $qs = false) {
        $parts = array();
        if ($qs) {
            $parts[] = $qs;
        }
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $value2) {
                    $parts[] = http_build_query(array($key => $value2));
                }
            } else {
                $parts[] = http_build_query(array($key => $value));
            }
        }
        return join('&', $parts);
    }
}

function debug_pre($data, $exit = false){
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    if($exit) die;
}

$cls = new Cron();

print_r( $cls->index($_POST) );
exit;