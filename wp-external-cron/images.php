<?php
include('db.php');
include(__DIR__ . '/lib/ImageResize.php');
include(__DIR__ . '/lib/ImageResizeException.php');

use \Gumlet\ImageResize;
use \Gumlet\ImageResizeException;

class Cron {
    var $api_host = 'https://api.example.com/v1/';
    var $siteurl;

    var $endpoints = array(
        'property-list' => 'properties',
        'property'      => 'property'
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

        $this->siteurl = base_url();

        $this->username             = 'vicks';
        $this->password             = 'Subvick';

        set_time_limit(1200);
    }

    function image_resize($src, $dst, $width, $height, $crop=0){

        if(!list($w, $h) = getimagesize($src)) return "Unsupported picture type!";

        $type = strtolower(substr(strrchr($src,"."),1));
        if($type == 'jpeg') $type = 'jpg';
        switch($type){
        case 'bmp': $img = imagecreatefromwbmp($src); break;
        case 'gif': $img = imagecreatefromgif($src); break;
        case 'jpg': $img = imagecreatefromjpeg($src); break;
        case 'png': $img = imagecreatefrompng($src); break;
        default : return "Unsupported picture type!";
        }

        // resize
        if($crop){
            if($w < $width or $h < $height) return "Picture is too small!";
            $ratio = max($width/$w, $height/$h);
            $h = $height / $ratio;
            $x = ($w - $width / $ratio) / 2;
            $w = $width / $ratio;
        }else{
            if($w < $width and $h < $height) return "Picture is too small!";
            $ratio = min($width/$w, $height/$h);
            $width = $w * $ratio;
            $height = $h * $ratio;
            $x = 0;
        }

        $new = imagecreatetruecolor($width, $height);

        // preserve transparency
        if($type == "gif" or $type == "png"){
        imagecolortransparent($new, imagecolorallocatealpha($new, 0, 0, 0, 127));
        imagealphablending($new, false);
        imagesavealpha($new, true);
        }

        imagecopyresampled($new, $img, 0, 0, $x, 0, $width, $height, $w, $h);

        switch($type){
        case 'bmp': imagewbmp($new, $dst); break;
        case 'gif': imagegif($new, $dst); break;
        case 'jpg': imagejpeg($new, $dst); break;
        case 'png': imagepng($new, $dst); break;
        }
        return true;
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
}

function debug_pre($data, $exit = false){
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    if($exit) die;
}

function UR_exists($url){
    $headers=get_headers($url);
    return stripos($headers[0],"200 OK")?true:false;
} 

$cls = new Cron();
$attachments = [];
$basepath = __DIR__ . '/../wp-content/uploads/';

if(isset($_GET['property_ID'])){
    $property_ID = $_GET['property_ID'];

    $sql = "SELECT pm2.meta_value AS _api_property_image_url, 
        pm3.meta_value AS _wp_attachment_metadata, 
        post.*, pm1.meta_id AS meta_ID 
        FROM wp_postmeta AS pm1
        LEFT JOIN wp_postmeta AS pm2 ON pm2.post_id = pm1.post_id
        LEFT JOIN wp_postmeta AS pm3 ON pm3.post_id = pm1.post_id
        LEFT JOIN wp_postmeta AS pm4 ON pm4.post_id = pm1.post_id
        LEFT JOIN wp_posts AS post ON post.ID = pm1.post_id
        WHERE pm1.meta_key = '_api_sync_status'
        AND pm2.meta_key = '_api_property_image_url' 
        AND pm3.meta_key = '_wp_attachment_metadata' 
        AND pm4.meta_key = 'piab_property_id'
        AND pm4.meta_value = ".$property_ID."
        LIMIT 0,10";
}else{
    $sql = "SELECT pm2.meta_value AS _api_property_image_url, 
        pm3.meta_value AS _wp_attachment_metadata, 
        post.*, pm1.meta_id AS meta_ID 
        FROM wp_postmeta AS pm1
        LEFT JOIN wp_postmeta AS pm2 ON pm2.post_id = pm1.post_id
        LEFT JOIN wp_postmeta AS pm3 ON pm3.post_id = pm1.post_id
        LEFT JOIN wp_posts AS post ON post.ID = pm1.post_id
        WHERE pm1.meta_key = '_api_sync_status' AND pm1.meta_value = 0 
        AND pm2.meta_key = '_api_property_image_url' 
        AND pm3.meta_key = '_wp_attachment_metadata'
        ORDER BY pm1.post_id DESC
        LIMIT 0,10";
}

$result = $cls->db->query($sql);

if ($result) {
    $attachments = $result->fetch_all(MYSQLI_ASSOC);
    // debug_pre($attachments);
    foreach($attachments as $attach){
        $remotePath = $attach['_api_property_image_url'];
        $imageData = unserialize($attach['_wp_attachment_metadata']);
        $localPath =  $basepath . $imageData['file']; 
        debug_pre($localPath);

        if(UR_exists($remotePath)){
            $localDirPath = dirname($basepath . $imageData['file']);
        
            if(!is_dir($localDirPath)){
                mkdir($localDirPath);
            }
            
            if(!file_exists($localPath)){
                copy($remotePath, $localPath);
            }

            foreach($imageData['sizes'] as $size){
                // debug_pre($basepath . $size['file']); die;
                if(!file_exists($basepath . $size['file'])){
                    $image = new ImageResize($basepath . $imageData['file']);
                    $image->resize($size['width'], $size['height']);
                    $image->save($basepath . $size['file']);
                }
            }

            $cls->_update(
                array(
                    'meta_id' => $attach['meta_ID']
                ),
                array(
                    'meta_value' => 1
                ),
                'wp_postmeta'
            );
        }else{
            echo $remotePath . ' 404 Not Found';
        }
        
    }
}