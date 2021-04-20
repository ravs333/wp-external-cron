<?php 
ini_set('memory_limit', '-1');
ini_set('max_execution_time', 1500);
include('db.php');

class Cron {
    var $api_host;
    var $cdnurl;
    var $siteurl;

    var $endpoints = array(
        'property-list' => 'properties',
        'property-remove' => 'properties-remove',
        'contact'      => 'contact'
    );

    var $db_settings = array(
        'host' => 'localhost',
        'user' => 'root',
        'pass' => 'root',
        'dbname' => ''
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

        $this->api_host = api_host();
        $this->siteurl = base_url();
        $this->cdnurl = cdn_url();

        $this->username             = username();
        $this->password             = password();
    }

    function property_list(){
        $data = array(
            'available' => 1,
            'lots_data' => true,
            'page' =>  0,
        );

        $property_data = $this->sendRequest($this->api_host.$this->endpoints['property-list'],$this->username, $this->password,$data);
        // debug_pre($property_data);
        return $property_data;
    }

    function property_unlist(){
        $data = array(
            'available' => 0,
            'available_since' => date('Y-m-d H:i:s', strtotime('-1 week')),
            // 'lots_data' => true,
            'page' =>  0,
        );
        $property_data = $this->sendRequest($this->api_host.$this->endpoints['property-remove'],$this->username, $this->password,$data);
        return $property_data;
    }

    function get_terms(){
        $terms = [];
        $taxonomies = $this->taxonomies;
        $comma_separated = implode("','", $taxonomies);
        $in_cond = "'".$comma_separated."'";

        $sql = "SELECT * FROM wp_terms LEFT JOIN wp_term_taxonomy ON wp_term_taxonomy.term_id = wp_terms.term_id WHERE wp_term_taxonomy.taxonomy IN ($in_cond) ";

        $result = $this->db->query($sql);

        if ($result->num_rows > 0) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            foreach($rows as $row){
                if(in_array($row['taxonomy'], $taxonomies)){
                    $key =  array_keys($taxonomies, $row['taxonomy']);
                    $key = $key[0];
                    // print_r($key);
                    if(!isset($terms[$key])) $terms[$key] = array();
                    $terms[$key][$row['term_id']] = $row['slug'];
                }
                
            }
            // echo "<pre>";
            // print_r($terms);
            // echo "</pre>";
        }

        return $this->terms = $terms;

    }

    function set_terms($data, $force_update = 0){

        $taxonomies = $this->taxonomies;
        $terms = $this->get_terms();
        $term_key = array_search($data['taxonomy'], $taxonomies);
        $terms = $terms[$term_key];
        $slug = $this->_slug($data['name']);
        $term_id = array_keys($terms, $slug);
        $term_id = is_array($term_id) && count($term_id) > 0 ? reset($term_id) : 0;
        $term_taxonomy_id = 0;
        if(!$term_id){
            
            $term_id = $this->_insert(array( 
                'name' => $data['name'],
                'slug' => $this->_slug($data['name']),
                'term_group' => 0
            ), 'wp_terms');
    
            $term_taxonomy_id = $this->_insert( array(
                'term_id' => $term_id,
                'taxonomy' => $data['taxonomy'],
                'description' => "",
                'parent' => 0,
                'count' => $data['post_id'] > 0 ? 1 : 0
            ),'wp_term_taxonomy');
        }

        if($term_key != 'status'){
            $wp_options_key = '_houzez_property_'.$term_key.'_'.$term_id;
            $wp_options_arr = array();

            switch ($term_key) {
                case 'city':
                    $wp_options_arr[$wp_options_key] = array(
                        'parent_state' => $data['parent_state']
                    );
                    break;
                case 'state':
                    $wp_options_arr[$wp_options_key] = array(
                        'parent_country' => 'AU'
                    );
                    break;
                case 'suburb':
                    $wp_options_key = '_houzez_property_area_'.$term_id;
                    $wp_options_arr[$wp_options_key] = array(
                        'parent_city' => $data['parent_city']
                    );
                    break;
                
                default:
                    # code...
                    break;
            }
            if(count($wp_options_arr)){
                $row = $this->_getRow('*', array(
                        'option_name' => $wp_options_key
                    ),
                    'wp_options');
                
                // echo "<pre>";
                // print_r($wp_options_arr);
                // print_r($row);
                // echo "</pre>";
                // die;

                if($row){
                    if($row['option_value'] != serialize($wp_options_arr[$wp_options_key])){
                        $this->_update(
                            array(
                                'option_id'      => $row['option_id'],
                            ),
                            array(
                                'option_value' => serialize($wp_options_arr[$wp_options_key])
                            ),
                            'wp_options');
                    }
                }else{
                    $this->_insert(
                        array(
                            'option_name' => $wp_options_key,
                            'option_value' => serialize($wp_options_arr[$wp_options_key])
                        ),
                        'wp_options');
                }
            }

        }

        //Update relationships
        if($data['post_id'] > 0){

            $term_taxonomy_row = $this->_getRow("*",array(
                'term_id' => $term_id,
                'taxonomy' => $data['taxonomy']
            ), 'wp_term_taxonomy');

            $term_taxonomy_id =  $term_taxonomy_row['term_taxonomy_id'];

            $term_relationship_row = $this->_getRow("*",array(
                'object_id' => $data['post_id'],
                'term_taxonomy_id' => $term_taxonomy_id
            ), 'wp_term_relationships');
            // debug_pre($term_relationship_row);
            if(!$term_relationship_row){
                $this->_insert( array( 
                    'object_id' => $data['post_id'],
                    'term_taxonomy_id' => $term_taxonomy_id,
                    'term_order' => 0
                ), 'wp_term_relationships');
                $this->_update(array(
                    'term_id' => $term_id,
                    'taxonomy' => $data['taxonomy']
                ),
                array(
                    'count' => $data['post_id'] > 0 ?  $term_taxonomy_row['count'] + 1 :  $term_taxonomy_row['count']
                ), 'wp_term_taxonomy');
            }
        }

        $this->terms = $this->get_terms();

        return $term_id;
    }

    function unlist_properties($properties){
        $sql = "SELECT * FROM wp_postmeta WHERE meta_key = 'piab_property_id' AND  meta_value IN (" . implode(",", $properties) . ")";
        $result = $this->db->query($sql);

        if ($result->num_rows > 0) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $posts = [];
            foreach($rows as $meta){
                $posts[] = $meta['post_id'];
            } 

            $sql = "UPDATE wp_posts ".
                    "SET post_status ='expired', ".
                    "post_modified = '" . date('Y-m-d H:i:s') . "', post_modified_gmt = '" . date('Y-m-d H:i:s') . "' ".
                    "WHERE ID IN (" . implode(",", $posts) . ")";
            $this->db->query($sql);
            return $posts;
        }

        return [];
    }

    function save_property($property){
        $row = $this->_getRow('*', array(
                'meta_key' => 'piab_property_id',
                'meta_value' => $property['ID']
            ),
            'wp_postmeta');

        if(isset($row['meta_id'])){
            $post_id = $row['post_id'];
            $post = $this->_getRow('*',array(
                'ID' => $post_id
            ), 'wp_posts');
            $updata = [];
            if($property['long_description'] != $post['post_content']){
                $updata['post_content'] = $property['long_description'];
                $updata['post_excerpt'] = $property['short_description'];
            }
            if(mysqli_real_escape_string($this->db, $property['text_title']) != mysqli_real_escape_string($this->db, $post['post_title'])){
                $updata['post_title'] = mysqli_real_escape_string($this->db, $property['text_title']);
                $updata['post_name'] = mysqli_real_escape_string($this->db, $this->_slug($property['text_title']));
            }
            if(count($updata)){
                $updata['post_modified']     = date('Y-m-d H:i:s');
                $updata['post_modified_gmt'] = gmdate('Y-m-d H:i:s', time());
                $update = $this->_update(
                    array('ID' => $post['ID']),
                    $updata,
                    'wp_posts'
                );
            }
            
            $post = array_merge($post, $updata);
            $post['images'] = $this->save_property_images($property, $post);
            $post['postmeta'] = $this->save_property_meta($property, $post, 2);
            return $post;
        }
        $post = $postdata = array(
            'post_author'           => 1,
            'post_date'             => date('Y-m-d H:i:s'),
            'post_date_gmt'         => gmdate('Y-m-d H:i:s', time()),
            'post_content'          => mysqli_real_escape_string($this->db, $property['long_description']),
            'post_title'            => mysqli_real_escape_string($this->db, $property['text_title']),
            'post_excerpt'          => mysqli_real_escape_string($this->db, $property['short_description']),
            'post_status'           => 'publish',
            'comment_status'        => 'closed',
            'ping_status'           => 'closed',
            'post_password'         => '',
            'post_name'             => mysqli_real_escape_string($this->db, $this->_slug($property['text_title'])),
            'to_ping'               => '',
            'pinged'                => '',
            'post_modified'         => date('Y-m-d H:i:s'),
            'post_modified_gmt'     => gmdate('Y-m-d H:i:s', time()),
            'post_content_filtered' => '',
            'post_parent'           => 0,
            'guid'                  => $this->siteurl . '/?post_type=property&#038;p=',
            'menu_order'            => 0,
            'post_type'             => 'property',
            'post_mime_type'        => '',
            'comment_count'         => 0
        );
        
        if($post['ID'] = $this->_insert($postdata, 'wp_posts')){
            $this->_update(
                $post['ID'], 
                array('guid' => $post['guid'].$post['ID']), 
                'wp_posts');
            $post['images'] = $this->save_property_images($property, $post);
            $post['postmeta'] = $this->save_property_meta($property, $post, 1);
            return $post;
        }
        return false;
        
    }

    function save_property_meta($property, $post, $insert = 1){

        $multi_units = array();
        $first_lot = reset($property['lots']);
        $size_low = $size_high = $this->_eval($first_lot['living_area']);
        $land_low = $land_high = $this->_eval($first_lot['land_size']);
        $bed_low = $bed_high = $this->_eval($first_lot['bedrooms']);
        $bath_low = $bath_high = $this->_eval($first_lot['bathrooms']);
        $garage_low = $garage_high = $this->_eval($first_lot['garage']);
         
        foreach($property['lots'] as $lot){
            $multi_units[] = array(
                'fave_mu_title' => $lot['lot_name'],
                'fave_mu_price' => $lot['price'],
                'fave_mu_beds' => $lot['bedrooms'],
                'fave_mu_baths' => $lot['bathrooms'],
                'fave_mu_size' => $lot['land_size'],
                'fave_mu_size_postfix' => 'm<sup>2</sup>',
                'fave_mu_type' => $property['property_type']
            );
            if($property['property_type_id'] == 1){
                if($size_low > $this->_eval($lot['living_area'])){
                    $size_low = $lot['living_area'];
                }
                if($size_high < $this->_eval($lot['living_area'])){
                    $size_high = $lot['living_area'];
                }

                if($land_low > $this->_eval($lot['land_size'])){
                    $land_low = $lot['land_size'];
                }
                if($land_high < $this->_eval($lot['land_size'])){
                    $land_high = $lot['land_size'];
                }
            }

            $bed_low = $bed_low > $this->_eval($lot['bedrooms']) ? $this->_eval($lot['bedrooms']) : $bed_low;
            $bed_high = $bed_high < $this->_eval($lot['bedrooms']) ? $this->_eval($lot['bedrooms']) : $bed_high;
            $bath_low = $bath_low > $this->_eval($lot['bathrooms']) ? $this->_eval($lot['bathrooms']) : $bath_low;
            $bath_high = $bath_high > $this->_eval($lot['bathrooms']) ? $this->_eval($lot['bathrooms']) : $bath_high;
            if(isset($lot['garage']) && !empty($lot['garage'])){
                $garage_low = $garage_low > $this->_eval($lot['garage']) ? $this->_eval($lot['garage']) : $bed_low;
                $garage_high = $bed_low > $this->_eval($lot['bedrooms']) ? $this->_eval($lot['bedrooms']) : $bed_low;
            }

            if(isset($lot['car']) && !empty($lot['car'])){
                $garage_low = $garage_low > $this->_eval($lot['car']) ? $this->_eval($lot['car']) : $bed_low;
                $garage_high = $bed_low > $this->_eval($lot['car']) ? $this->_eval($lot['car']) : $bed_low;
            }
            
        }

        $meta_map = array(
            '_edit_lock' => time() . ':2',
            '_edit_last' => 2,
            'slide_template' => 'default',
            '_yoast_wpseo_title' => mysqli_real_escape_string($this->db, $property['text_title']),
            '_yoast_wpseo_content_score' => 60,
            'fave_property_size' => '',
            'fave_property_size_low' => $size_low,
            'fave_property_size_high' => $size_high,
            'fave_property_size_prefix' => 'm<sup>2</sup>',
            'fave_property_land' => '',
            'fave_property_land_low' => $land_low,
            'fave_property_land_high' => $land_high,
            'fave_property_land_postfix' => 'm<sup>2</sup>',
            'fave_property_bedrooms' => $property['bedrooms_low'] . '-' . $property['bedrooms_high'],
            'fave_property_bedrooms_low' => $bed_low,
            'fave_property_bedrooms_high' => $bed_high,
            'fave_property_bathrooms' => $property['bathrooms_low'] . '-' . $property['bathrooms_high'],
            'fave_property_bathrooms_low' => $bath_low,
            'fave_property_bathrooms_high' => $bath_high,
            'fave_property_garage' => $property['garage_low'] . '-' . $property['garage_high'],
            'fave_property_garage_low' => $garage_low,
            'fave_property_garage_high' => $garage_high,
            'fave_property_garage_size' => '',
            'fave_property_id' => $property['ID'],
            'piab_property_id' => $property['ID'],
            'fave_property_map' => 1,
            'fave_property_map_address' => $property['suburb'] . ', ' . $property['city'] . ', ' . $property['state'] . ', ' .$property['pincode'] . ', Australia',
            'fave_property_location' => $property['latitude'].','.$property['longitude'],
            'fave_property_map_street_view' => 'show',
            'fave_property_address' => $property['suburb'] . ', ' . $property['city'] . ', ' . $property['state'] . ', ' .$property['pincode'] . ', Australia',
            'fave_property_zip' => $property['pincode'],
            'fave_property_country' => 'AU',
            'fave_featured' => 0,
            'fave_loggedintoview' => 0,
            'fave_agent_display_option' => 'author_info',
            'fave_agents' => '-1',
            'fave_prop_homeslider' => 'no',
            'fave_multiunit_plans_enable' => 'enable',
            'fave_floor_plans_enable' => 'disable',
            'fave_attachments' => '',
            'fave_single_top_area' => 'global',
            'fave_single_content_area' => 'global',
            'fave_additional_features_enable' => 'disable',
            'fave_currency_info' => '',
            'houzez_geolocation_lat' => $property['latitude'],
            'houzez_geolocation_long' => $property['longitude'],
            '_yoast_wpseo_primary_property_type' => 18,
            '_yoast_wpseo_primary_property_status' => 19,
            '_yoast_wpseo_primary_property_feature' => '',
            '_yoast_wpseo_primary_property_label' => '',
            '_yoast_wpseo_primary_property_city' => 6,
            '_yoast_wpseo_primary_property_area' => 10,
            '_yoast_wpseo_primary_property_state' => 3,
            'houzez_manual_expire' => null,
            '_houzez_expiration_date_status' => 'saved',
            'houzez_total_property_views' => '0',
            'houzez_recently_viewed' => date('d-m-y H:i:s'),
            'fave_multi_units' => serialize($multi_units),
            'fave_property_price' => '',
            'fave_property_price_low' => $property['price_low'],
            'fave_property_price_high' => $property['price_high'],
            'fave_property_sec_price' => $property['rent_weekly_avg'] * 4,
            'fave_property_sec_price_postfix' => '/month',
            'fave_property_sec_price_low' => $property['rent_weekly_low'] * 4,
            'fave_property_sec_price_high' => $property['rent_weekly_high'] * 4
        );

        $taxonomies = $this->taxonomies;
        $terms = $this->terms;

        foreach($taxonomies as $key => $taxonomy){
            if(!isset($terms[$key])) $terms[$key] = array();
            $terms = $terms[$key]; 
            if($key == 'status'){
                $terms_data = array(
                    'name' => 'For Sale',
                    'taxonomy' => $taxonomy,
                    'post_id' => $post['ID']
                );
                $term_id = $this->set_terms($terms_data, true);
                $meta_map['_yoast_wpseo_primary_property_status'] = $term_id;
            }else{
                $terms_data = array(
                    'name' => $property[$key],
                    'taxonomy' => $taxonomy,
                    'post_id' => $post['ID']
                );
                
                switch ($key) {
                    case 'state':
                        $terms_data['parent_country'] = 'AU';
                        $term_id = $this->set_terms($terms_data, true);
                        $meta_map['_yoast_wpseo_primary_property_state'] = $term_id;
                        break;
                    case 'city':
                        $terms_data['parent_state'] = $this->_slug($property['state']);
                        $term_id = $this->set_terms($terms_data, true);
                        $meta_map['_yoast_wpseo_primary_property_city'] = $term_id;
                        break;
                    case 'suburb':
                        $terms_data['parent_city'] = $this->_slug($property['city']);
                        $term_id = $this->set_terms($terms_data, true);
                        $meta_map['_yoast_wpseo_primary_property_area'] = $term_id;
                        break;
                    case 'property_type':
                        $term_id = $this->set_terms($terms_data, true);
                        $meta_map['_yoast_wpseo_primary_property_type'] = $term_id;
                        break;
                    
                    default:
                        # code...
                        break;
                }
            }

            $terms = $this->terms;
        }
        
        // debug_pre($post['images']); die;
        foreach($meta_map as $meta_key => $meta){
            if($insert == 2){
                // if($meta_key == 'fave_property_location'){
                //     debug_pre($meta);
                // }
                if(is_array($meta)){
                    foreach($meta as $i => $j){
                        $this->_update(
                            array(
                                'post_id'      => $post['ID'],
                                'meta_key'     => $meta_key
                            ),
                            array(
                                'meta_value' => $j
                            ),
                            'wp_postmeta'
                        );
                    }
                }else{
                    $this->_update(
                        array(
                            'post_id'      => $post['ID'],
                            'meta_key'     => $meta_key
                        ),
                        array(
                            'meta_value' => $meta
                        ),
                        'wp_postmeta'
                    );
                }
            }

            if($insert == 1){

                if(is_array($meta)){
                    foreach($meta as $i => $j){
                        $this->_insert(
                            array(
                                'post_id'       => $post['ID'],
                                'meta_key'      => $meta_key,
                                'meta_value'    => $j
                            ),
                            'wp_postmeta'
                        );
                    }
                }else{
                    $this->_insert(
                        array(
                            'post_id'       => $post['ID'],
                            'meta_key'      => $meta_key,
                            'meta_value'    => $meta
                        ),
                        'wp_postmeta'
                    );
                }
            }
        }

        return $meta_map;
    }

    function save_property_images($property, $prop_post){
        $images = [];
        $extensions = array(
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg'
        );
        foreach ($property['image_gallery'] as $i => $imgArr){
            if(isset($imgArr['path']) && $imgArr['path'] != ""){
                $propertyID = $property['ID'];
                $remotePath = $imgArr['path'].'/'.$imgArr['image'];
                $path      = parse_url($remotePath, PHP_URL_PATH);       // get path from url
                $extension = pathinfo($path, PATHINFO_EXTENSION); // get ext from path
                $filename  = pathinfo($path, PATHINFO_FILENAME);  // get name from path
                // echo 'cdn/' . $propertyID  . '/' . $this->_slug($filename) . '.' .$extension; die;
                if(isset($extensions[$extension])){
                    $imageData = array(
                        'width' => 1500,
                        'height' => 1000,
                        'file' => 'images/' . $propertyID  . '/' . $this->_slug($filename) . '.' .$extension,
                        'sizes' => array(
                            'thumbnail' => array (
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-150x150.' .$extension,
                                'width' => 150,
                                'height' => 150,
                                'mime-type' => $extensions[$extension]
                            ),
                            'medium' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-300x200.' .$extension,
                                'width' => 300,
                                'height' => 200,
                                'mime-type' => $extensions[$extension],
                            ),
                            'medium_large' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-768x512.' .$extension,
                                'width' => 768,
                                'height' => 512,
                                'mime-type' => $extensions[$extension]
                            ),
                            'large' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-1024x683.' .$extension,
                                'width' => 1024,
                                'height' => 683,
                                'mime-type' => $extensions[$extension]
                            ),
                            'post-thumbnail' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-150x100.' .$extension,
                                'width' => 150,
                                'height' => 100,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-single-big-size' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-1170x600.' .$extension,
                                'width' => 1170,
                                'height' => 600,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-property-thumb-image' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-385x258.' .$extension,
                                'width' => 385,
                                'height' => 258,
                                'mime-type' => $extensions[$extension]
                            ),
    
                            'houzez-property-thumb-image-v2' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-380x280.' .$extension,
                                'width' => 380,
                                'height' => 280,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-image570_340' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-570x340.' .$extension,
                                'width' => 570,
                                'height' => 340,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-property-detail-gallery' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-810x430.' .$extension,
                                'width' => 810,
                                'height' => 430,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-imageSize1170_738' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-1170x738.' .$extension,
                                'width' => 1170,
                                'height' => 738,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-prop_image1440_610' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-1440x610.' .$extension,
                                'width' => 1440,
                                'height' => 610,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-image350_350' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-350x350.' .$extension,
                                'width' => 350,
                                'height' => 350,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-widget-prop' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-150x110.' .$extension,
                                'width' => 150,
                                'height' => 110,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-image_masonry' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-350x233.' .$extension,
                                'width' => 350,
                                'height' => 233,
                                'mime-type' => $extensions[$extension]
                            ),
                            'houzez-toparea-v5' => array(
                                'file' => 'images/' . $propertyID  . '/s/' . $this->_slug($filename) . '-720x480.' .$extension,
                                'width' => 720,
                                'height' => 480,
                                'mime-type' => $extensions[$extension]
                            )
                        ),
                        'image_meta' => array(
                            'aperture' => 0,
                            'credit' => '',
                            'camera' => '',
                            'caption' => '',
                            'created_timestamp' => 0,
                            'copyright' => '',
                            'focal_length' => 0,
                            'iso' => 0,
                            'shutter_speed' => 0,
                            'title' => '',
                            'orientation' => 0,
                            'keywords' => []
                        )
                    );
                    // debug_pre(file_exists($basepath . $imageData['file'])); die;
                    
                    // foreach($imageData['sizes'] as $size){
                    //     // debug_pre($basepath . $size['file']); die;
                    //     if(!file_exists($basepath . $size['file'])){
                    //         $this->image_resize(
                    //             $basepath . $imageData['file'],
                    //             $basepath . $size['file'],
                    //             $size['width'],
                    //             $size['height']
                    //         );
                    //     }
                    // }

                    $postID = 0;

                    $post = $this->_getRow('*', array(
                        'guid' => $this->siteurl . $propertyID  . '/' . $this->_slug($filename) . '.' .$extension,
                    ),
                    'wp_posts');
                    
                    if(!isset($post['ID'])){

                        $postdata = array(
                            'post_author'           => 1,
                            'post_date'             => date('Y-m-d H:i:s'),
                            'post_date_gmt'         => gmdate('Y-m-d H:i:s', time()),
                            'post_content'          => '',
                            'post_title'            => $filename . '.' .$extension,
                            'post_excerpt'          => '',
                            'post_status'           => 'inherit',
                            'comment_status'        => 'open',
                            'ping_status'           => 'closed',
                            'post_password'         => '',
                            'post_name'             => $this->_slug($filename) . '.' .$extension,
                            'to_ping'               => '',
                            'pinged'                => '',
                            'post_modified'         => date('Y-m-d H:i:s'),
                            'post_modified_gmt'     => gmdate('Y-m-d H:i:s', time()),
                            'post_content_filtered' => '',
                            'post_parent'           => $i == 0 ? $prop_post['ID'] : 0,
                            'guid'                  => $this->siteurl . $propertyID  . '/' . $this->_slug($filename) . '.' .$extension,
                            'menu_order'            => 0,
                            'post_type'             => 'attachment',
                            'post_mime_type'        => $extensions[$extension],
                            'comment_count'         => 0
                        );
                        $postID = $this->_insert($postdata, 'wp_posts');

                        $attchmeta = array(
                            '_api_property_image_url' => $remotePath,
                            '_api_sync_status' => 1,
                            '_wp_attachment_metadata' => serialize($imageData),
                            '_wp_attached_file' => $this->_slug($filename) . '.' .$extension
                        );

                        foreach($attchmeta as $key => $value){
                            $this->_insert(array(
                                'post_id'       => $postID,
                                'meta_key'      => $key,
                                'meta_value'    => $value
                            ), 'wp_postmeta');
                        }

                        $this->_insert(
                            array(
                                'post_id'       => $prop_post['ID'],
                                'meta_key'      => 'fave_property_images',
                                'meta_value'    => $postID
                            ),
                            'wp_postmeta'
                        );
    
                        if($i == 0){
                            $this->_insert(
                                array(
                                    'post_id'       => $prop_post['ID'],
                                    'meta_key'      => '_thumbnail_id',
                                    'meta_value'    => $postID
                                ),
                                'wp_postmeta'
                            );
                        }
                    }else{

                        $imagemeta = $this->_getRow('*', array(
                            'meta_key' => '_api_sync_status',
                            'post_id' => $post['ID']
                        ),
                        'wp_postmeta');

                        if(!isset($imagemeta['meta_id'])){
                            if(!isset($imagemeta['meta_id'])){
                                $postID = $post['ID'];
                                $this->_insert(
                                    array(
                                        'post_id'       => $postID,
                                        'meta_key'      => '_api_sync_status',
                                        'meta_value'    => 0
                                    ),
                                    'wp_postmeta'
                                );
                            }
                        }

                        $propmeta = $this->_getRow('*', array(
                            'meta_key' => 'fave_property_images',
                            'meta_value' => $post['ID'],
                            'post_id' => $prop_post['ID']
                        ),
                        'wp_postmeta');
                        if(!isset($propmeta['meta_id'])){
                            $postID = $post['ID'];
                            $this->_insert(
                                array(
                                    'post_id'       => $prop_post['ID'],
                                    'meta_key'      => 'fave_property_images',
                                    'meta_value'    => $postID
                                ),
                                'wp_postmeta'
                            );

                            if($i == 0){
                                $this->_insert(
                                    array(
                                        'post_id'       => $prop_post['ID'],
                                        'meta_key'      => '_thumbnail_id',
                                        'meta_value'    => $postID
                                    ),
                                    'wp_postmeta'
                                );
                            }
                        }
                    }

                    if($postID){
                        $images[] = $this->siteurl . $propertyID  . '/' . $this->_slug($filename) . '.' .$extension;
                    }
                }
            }
        }
        return $images;
    }

    /*
    * check string type JSON or not
    */
    function isJson($string) {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
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

        $params = http_build_query($data);
        $url = $url."?".$params;

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
            // curl_setopt($ch, CURLOPT_POST, TRUE);
            // curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            $curl_result = curl_exec($ch);

            // var_dump($url); die;

            $this->lastError = curl_errno($ch);
            if (!$this->lastError) {
                if(!$this->isJson($curl_result)){
                    // $result = gzdecode($curl_result);
                    $result = $curl_result;
                    debug_pre($result);
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

    function _insert($value, $table){
        $sql = "INSERT INTO $table ";
        if(is_array($value)){
            $columns = array_keys($value);
            $columns = implode(", ", $columns);
            $vals = [];
            foreach($value as $j){
                $vals[] = "'".$this->db->real_escape_string($j)."'";
            }
            $value = implode(", ", $vals);
            $sql .= "($columns) VALUES ($value)"; 
        }else{
            $sql .= $this->db->real_escape_string($value);
        }
        // print_r($sql); die;
        $result = $this->db->query($sql);

        if ($result) {
            return $this->db->insert_id;
        }
        return $this->retRes('502', 'Insert Error', array(
            'mysql' => mysqli_error($this->db),
            'sql' => $sql
        ));
    }

    function _update($key, $value, $table){
        $sql = "UPDATE $table SET ";
        if(is_array($value)){
            $upstmt = [];
            foreach($value as $i => $j){
                if(is_int($j)){
                    $upstmt[] = "$i = $j";
                }else{
                    $upstmt[] = "$i = '".$this->db->real_escape_string($j)."'";
                }
            }
            $upstmt = implode(", ", $upstmt);
            $sql .= "$upstmt "; 
        }else{
            $sql .= "'".$this->db->real_escape_string($value)."' ";
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

    function _slug($str){
        $str = strtolower($str);
        $str = str_replace("'", '', $str);
        $str = str_replace('"', '', $str);
        $str = str_replace('-', '', $str);
        $str = str_replace('  ', '-', $str);
        $str = str_replace(' ', '-', $str);
        $str = str_replace('(', '', $str);
        $str = str_replace(')', '', $str);
        $str = trim($str);
        $str = trim($str, '-');

        return $str;
    }

    function _eval($str){
       
        if(strlen(trim($str) > 1) && strstr($str, "+")){
            $stArr = explode('+', $str);
            $result = 0;
            foreach($stArr as $key => $value){
                if(strlen(trim($value)) == 1){
                    $result += $this->_eval(trim($value));
                }else{
                    $result ++;
                }
            }
            return $result;
        }
        if((int) $str != $str){
            return floatval($str);
        }
        return intval($str);
    }

    function retRes($status= '200', $message='',$data = []){
        switch ($status) {
            case '400': 
                header("HTTP/1.1 400 Bad Request");
                break;
            case '401': 
                header("HTTP/1.1 401 Unauthorized");
                break;
            case '403': 
                header("HTTP/1.1 403 Forbidden");
                break;
            case '404': 
                header("HTTP/1.1 404 No Found");
                break;
            case '500': 
                header("HTTP/1.1 500 Internal Server Error");
                break;
            case '502': 
                header("HTTP/1.1 502 Bad Request");
                break;
            case '503': 
                header("HTTP/1.1 503 Service Unavailable");
                break;
            case '202': 
                header("HTTP/1.1 202 Accepted");
                break;
            case '201': 
                header("HTTP/1.1 201 Created");
                break;
            case '200':
                header("HTTP/1.1 200 OK");
                break;
            default:
                header("HTTP/1.1 404 Not Found");
                exit;
                break;
        }
        header('Content-Type: application/json');
        echo json_encode(array(
            'status' => $status,
            'message' => $message,
            'data' => (object) $data
        ));
        exit;
    }
}

function debug_pre($data, $exit = true){
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    if($exit) die;
}

$cls = new Cron();
// debug_pre($cls->db_settings['dbname']); die;
// debug_pre(intval('3 +'));
// debug_pre($cls->_eval('3 + 3 + garage'));

if(isset($_GET['remove']) && $_GET['remove'] == 1){
    
    $properties =  $cls->property_unlist($page);
    $properties = json_decode($properties, true);
    // debug_pre($properties);

    if($properties['total_properties'] <= 0){
        $cls->retRes('200', 'Unlisted 0 Properties', array(
            'posts' => [],
            'properties' => []
        ));
    }
    // debug_pre($properties);
    // $terms =  $cls->get_terms();
    

    $res = [];
    foreach($properties['properties'] as $propertyID){
        $res[] = intval($propertyID);
    }
    // debug_pre($res);
    $cls->retRes('200', 'Unlisted '.count($res).' Properties', array(
        'posts' => $cls->unlist_properties($res),
        'properties' => $res
    ));
}else{

    $properties =  $cls->property_list();
    $properties = json_decode($properties, true);
    $terms =  $cls->get_terms();
    $count = 0;
    // debug_pre($properties);
    $res = [];
    // $property = reset($properties['hot_property']);
    // $property = $property['data'];
    // $res[] = $cls->save_property($property);
    foreach($properties['hot_property'] as $property){
        $property = $property['data'];
        $res[] = $cls->save_property($property);

        $count++;

        if($count >= 10){
            break;
        }
    }

    $cls->retRes('200', 'Saved '.count($res).' Properties', array(
        'posts' => $res,
        'properties' => $properties
    ));
}

// header('Content-Type: application/json');
// echo json_encode($properties);

?>