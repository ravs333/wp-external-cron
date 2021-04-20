<?php 
    include('../wp-config.php');
    $devpc = FALSE;
    if(!empty($_SESSION['DEVPC'])) {
        $devpc = TRUE;
    }

    if(!empty($_SERVER['SERVER_ADDR']) && ($_SERVER['SERVER_ADDR'] == '127.0.0.1' || strpos($_SERVER['HTTP_HOST'], '.local'))){
        // Always ON when running locally.
        $devpc = TRUE;
    }

    if ($devpc)
        define('CDNHOST', 'cdn.example.local');
    else
        define('CDNHOST', 'cdn.example.com.au');

    define('DEVPC',$devpc);

    // var_dump(DEVPC); die;

    function db_settings(){
        return array(
            'host' => DB_HOST,
            'user' => DB_USER,
            'pass' => DB_PASSWORD,
            'dbname' => DB_NAME
        );
    }

    function api_host(){
        return DEVPC ? 'http://api.example.local/v2/' : 'https://api.example.com/v2/';
    }

    function base_url(){
        return sprintf(
            "%s://%s%s",
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
            $_SERVER['SERVER_NAME'],
            $_SERVER['REQUEST_URI']
        );
    }

    function cdn_url(){

        return sprintf(
            "%s://%s%s",
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
            CDNHOST,
            '/'
        );
    }

    function username(){
        return 'sampleUsername';
    }

    function password(){
        return 'samplePassword';
    }

?>