<?php
if (! defined( 'ABSPATH' )) {
    exit; // Exit if accessed directly
}

require_once(ABSPATH . 'wp-admin/includes/taxonomy.php'); //for wp_create_category


class MDWH_Webhook
{
    static $mdwh_tmpPath;
    static $mdwh_tmpFile;
    static $mdwh_url;
    static $mdwh_username;
    static $mdwh_password;
    static $mdwh_tags;
    static $mdwh_token;

    static function checkActive()
    {
        if (!get_option('mdwh_active')) {
            die('error: plugin is deactive now');
        }
    }

    static function checkToken()
    {
 
        $mdwh_token = self:: $mdwh_token;
        $token = $_REQUEST['t']?$_REQUEST['t']:$_REQUEST['mdwh'];
        if ($token != $mdwh_token) {
            die('error: token incorrect');
        }
        echo '[token check success]';
        echo '<br>';
    }

    function downloadMDS()
    {
         $mdwh_tmpFile = self::$mdwh_tmpFile;
        $mdwh_url = self::$mdwh_url;
        $mdwh_username = self::$mdwh_username;
        $mdwh_password = self::$mdwh_password;
        if (!$mdwh_url) {
            die('error: please set your zip url');
        }
        $args = array();
        if ($mdwh_username && $mdwh_password && preg_match('/bitbucket/', $mdwh_url)) {
            $args['headers'] = array(
            'Authorization'=>'Basic '.base64_encode("$mdwh_username:$mdwh_password")
            );
        }
        $response = wp_remote_get($mdwh_url, $args);
        $temp = wp_remote_retrieve_body($response);
        if ($response->errors) {
            $errors = $response->errors;
            foreach ($errors as $key => $error) {
                echo "curl error:[$key]:{$error[0]}";
                echo '<br>';
            }
        }
        if (!$temp) {
            die('error: download zip file failed, please check your setting.');
        }


        file_put_contents($mdwh_tmpFile, $temp);
        $handle = finfo_open(FILEINFO_MIME_TYPE);
        $fileInfo = finfo_file($handle, $mdwh_tmpFile);
        finfo_close($handle);
        if (!preg_match('/zip/', $fileInfo)) {
            die("error: downloaded content should be zip file, now: $fileInfo");
        }
        echo '[download zip file success]';
        echo '<br>';
    }

    static function readZipFile()
    {
        $mdwh_tmpFile = self::$mdwh_tmpFile;
        $result = [];
        $zip = zip_open($mdwh_tmpFile);
        $auto_tag = get_option('mdwh_autocat');
        if ($zip) {
            while ($zip_entry = zip_read($zip)) {
                $name = zip_entry_name($zip_entry);
                if (strpos($name, '.md')==false) {
                    continue;
                }
                $post_name="";
                $catalog="";
                $contents = "";
                preg_match('/([^\/]*?)\.md/', $name, $matches);
                if ($matches && $matches[1]) {
                    $post_name = $matches[1];
                }
                if ($auto_tag) {
                    preg_match('/([^\/]*?)\/[^\/]*\.md/', $name, $matches);
                    if ($matches && $matches[1]) {
                        $catalog = $matches[1];
                    }
                }
                if (zip_entry_open($zip, $zip_entry)) {
                    $contents = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
                    zip_entry_close($zip_entry);
                }
                array_push($result, array("name"=>$post_name,"catalog"=>$catalog,"content"=> $contents));
            }
        }
        zip_close($zip);
        echo '[read zipfile success]';
        echo '<br>';
        return $result;
    }

    static function save($latest_posts)
    {
        $mdwh_tags = self::$mdwh_tags;
        $current_posts = get_posts(array('numberposts'=>-1));
        $auto_tag = get_option('mdwh_autocat');
        foreach ($latest_posts as $lpost) {
            $found = false;
            $catId = 0;
            $post = array(
                'post_content'=> $lpost['content'],
                'post_title'=> $lpost['name'],
                'post_status'=>'publish',
                'comment_status'=>'open'
            );
            if ($auto_tag) {
                $catId =  wp_create_category($lpost['catalog']);
                $post['post_category'] =  $auto_tag ? array($catId) : array();
            }
            foreach ($current_posts as $cpost) {
                if ($lpost['name']==$cpost->post_name) {
                    $post['post_date']= $cpost->post_date;
                    $post['ID']=$cpost->ID;
                    $found = true;
                    echo "[update post] $cpost->post_name / {$lpost['catalog']} / $cpost->ID ";
                    echo '<br>';
                    break;
                }
            }
            if (!$found) {
                echo "[new post] / {$lpost['name']}" ;
                echo '<br>';
            }
            $post['post_content'] =  $post['post_content'] . '<!---md-webhook--->';
            $post['post_content'] = preg_replace('/</', '&lt;', $post['post_content']);
            $post['post_content'] = preg_replace('/>/', '&gt;',  $post['post_content']);
            $content = $post['post_content'];
            if ($mdwh_tags) {
                preg_match_all("/($mdwh_tags)/i", $content, $matches);
                $tags_input = array_unique($matches[0]);
                $post['tags_input']= $tags_input;
            }
            wp_insert_post($post);
        }
        echo '[save post success]';
        echo '<br>';
    }

    static function run()
    {
        self::$mdwh_tmpPath = sys_get_temp_dir().'/md-master';
        self::$mdwh_tmpFile = self::$mdwh_tmpPath . '.zip';
        self::$mdwh_url= get_option('mdwh_zip_url') ;
        self::$mdwh_username = get_option('mdwh_username');
        self::$mdwh_password = get_option('mdwh_password');
        self::$mdwh_tags = get_option('mdwh_tags');
        self::$mdwh_token = get_option('mdwh_token');
    

        self::checkActive();
        self::checkToken();
        self::downloadMDS();
        $latest_posts =  self::readZipFile();
        self::save($latest_posts);
        echo 'all done';
    }
}

MDWH_Webhook::run();
