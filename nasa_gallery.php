<?php
/*
Plugin Name: NASA Gallery
Plugin URI: https://github.com/alexeyryabovol/nasa_gallery_plugin
Description: Test plugin, which shows a gallery of NASA images.
Author: Alexey Ryabovol
Version: 1.0.0
Author URI: http://nasa.alexweb.icu/nasa-page/
*/

define( 'NASA_GALLERY_API_KEY', 'U117Eal147wwwrYc94AyLr36r1bHruVeyCRkZpdD' );
define( 'NASA_GALLERY_API_URL', 'https://api.nasa.gov/planetary/apod' );
/*You can change the quantity of gallery slides in this parameter.*/
define( 'NASA_GALLERY_QUANTITY_OF_SLIDES', 5 );

/*Start function*/
add_action( 'init', 'nasa_gallery_start' );
function nasa_gallery_start(){
	register_post_type('post-nasa-gallery', array() );
        add_image_size( 'nasa-gallery-500-300', 500, 300, true );
}

/*Plugin activation*/
register_activation_hook( __FILE__, 'nasa_gallery_activation' ); 
function nasa_gallery_activation(){
        nasa_gallery_start();
        nasa_gallery_update_transient();
        
        wp_clear_scheduled_hook( 'nasa_gallery_daily' );
        wp_schedule_event( time(), 'daily', 'nasa_gallery_daily' );
}

/*Plugin deactivation*/
register_deactivation_hook( __FILE__, 'nasa_gallery_deactivation' );
function nasa_gallery_deactivation() {
	$nasa_posts = get_posts( array(
                'numberposts' => -1,                
                'post_type'   => 'post-nasa-gallery'                
        ) );
        foreach ($nasa_posts as $nasa_post){ 
                $images = get_attached_media( 'image', $nasa_post->ID );
                $image = array_shift( $images );
                wp_delete_post($image->ID);
                wp_delete_post($nasa_post->ID);
        }
        delete_transient( 'nasa_gallery' );
        wp_clear_scheduled_hook( 'nasa_gallery_daily' );
}

/*Uploading new picture daily*/
add_action( 'nasa_gallery_daily', 'nasa_gallery_daily_upload' );
function nasa_gallery_daily_upload(){ 
        nasa_gallery_update_transient();        
}

/*Retrieving image object by API
* 
* @param     string   $date         The date of picture in 'Y-m-d' format
* @return    object   $result       Object with image data
*/  
function nasa_gallery_get_image_http($date = ''){
        if(empty($date)){
                $url = add_query_arg( array( 'api_key'=>NASA_GALLERY_API_KEY  ), NASA_GALLERY_API_URL );
        }else{
                $url = add_query_arg( array( 'api_key'=>NASA_GALLERY_API_KEY, 'date'=>$date  ), NASA_GALLERY_API_URL ); 
        }
        $response = wp_remote_get( $url );        
        $result = json_decode(wp_remote_retrieve_body( $response ));
        
        return $result;
}

/*Creating new post ('post-nasa-gallery' type)
* 
* @param     string   $date         The date of picture (Post title) in 'Y-m-d' format
* @return    int      $post_id      Post ID
*/  
function nasa_gallery_create_post($date){
        $obj_response = nasa_gallery_get_image_http($date);
        
        $post_data = array(
                'post_title'  => $obj_response->date,                
                'post_status' => 'publish',
                'post_type'   => 'post-nasa-gallery'                
        );
        
        $post_id = wp_insert_post( wp_slash($post_data) );
        
        if (!empty($post_id)){
                media_sideload_image( $obj_response->url, $post_id );
        }
        
        return $post_id;        
}

/*Updating the array of image urls in transient option 'nasa_gallery'
* 
* @return    array   $array_urls    Array of urls in 'nasa_gallery' transient option
*/      
function nasa_gallery_update_transient(){ 
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $date_now = new DateTime();
        $one_day_interval = new DateInterval('P1D');        
                
        $array_urls = array();
        for($i = 0; $i < NASA_GALLERY_QUANTITY_OF_SLIDES; $i++ ){
                $array_urls[] = nasa_gallery_get_or_update_gallery_post($date_now->format('Y-m-d'));
                $date_now->sub($one_day_interval);
        }
        
        set_transient( 'nasa_gallery', $array_urls, DAY_IN_SECONDS );
        
        return $array_urls;        
}

/*Updating post('post-nasa-gallery' type) or attached image(if needed)
* 
* @param     string   $date         The date of picture (Post title) in 'Y-m-d' format
* @return    string   $image_url    URL of attached image
*/         
function nasa_gallery_get_or_update_gallery_post($date){        
       $post_item = get_page_by_title( $date, OBJECT, 'post-nasa-gallery' );
       if(empty($post_item)){
                $post_id = nasa_gallery_create_post($date);                
       }else{
                $post_id = $post_item->ID;
       }
       $images = get_attached_media( 'image', $post_id );
       $image = array_shift( $images );
       if (empty($image)){                
                $obj_response = nasa_gallery_get_image_http($date);
                $image_id = media_sideload_image( $obj_response->url, $post_id, null, 'id');
       }else{
                $image_id = $image->ID;
       }
       $image_url = wp_get_attachment_image_url( $image_id, 'nasa-gallery-500-300' );
       
       return $image_url;       
}

/*Adding styles*/
add_action( 'wp_enqueue_scripts', 'nasa_gallery_enqueue_scripts' );
function nasa_gallery_enqueue_scripts() {
	wp_enqueue_style( 'slick', plugins_url('/nasa_gallery/css/slick.css') );
        wp_enqueue_style( 'slick-theme', plugins_url('/nasa_gallery/css/slick-theme.css') );
        wp_enqueue_style( 'nasa-gallery', plugins_url('/nasa_gallery/css/nasa-gallery.css') );
}

/*Creating shortcode to display the gallery*/
add_shortcode('nasa_gallery', 'nasa_gallery_shortcode');
function nasa_gallery_shortcode(){
        wp_enqueue_script( 'slick', plugins_url('/nasa_gallery/js/slick.min.js'), array('jquery') );
        wp_enqueue_script( 'nasa-gallery', plugins_url('/nasa_gallery/js/nasa-gallery.js'), array('slick') );
        
	$urls = get_transient( 'nasa_gallery' );
        if(empty($urls)){
                $urls = nasa_gallery_update_transient();
        }
               
        
        $output = '<div class="nasa-gallery">';
        for($i = 0; $i < NASA_GALLERY_QUANTITY_OF_SLIDES; $i++ ){
                $output .= '<img src="'.$urls[$i].'" alt="NASA GALLERY">';
        }
        $output .= '</div>';
        
        return $output;
}



