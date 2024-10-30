<?php
/*
Plugin Name: markdown-webhook
Description: sync md files to wp posts from webhook in github.com / bitbucket.com
Version: 1.1
Author: czl
Author URI: my-egg.me
License: GPL2
*/

if (! defined( 'ABSPATH' )) {
    exit; // Exit if accessed directly
}



register_activation_hook( __FILE__, 'MDWH::activation_hook' );
register_deactivation_hook( __FILE__, 'MDWH::deactivation_hook' );
register_uninstall_hook(__FILE__, 'MDWH::uninstall_hook');

remove_filter('the_content', 'wptexturize' );
remove_filter('the_content', 'wpautop' );
remove_filter('the_content', array($GLOBALS['wp_embed'], 'autoembed'), 8);
add_filter('plugin_row_meta', 'MDWH::add_meta_links', 10, 2 );
add_filter('plugin_action_links_' . plugin_basename( __FILE__ ), 'MDWH::action_links', 10, 2 );
add_filter('the_content', 'MDWH::the_content', 1);
add_filter('query_vars','MDWH::query_vars',10);
add_filter('pre_update_option_mdwh_password', 'MDWH::pre_update_option_mdwh_password', 10);
add_filter('option_mdwh_password', 'MDWH::option_mdwh_password', 10);
add_action('wp_head', 'MDWH::wp_head');
add_action('admin_init', 'MDWH::admin_init');
add_action('admin_menu', 'MDWH::admin_menu');
add_action('template_redirect', 'MDWH::template_redirect');

class MDWH
{
    static function activation_hook()
    {
        add_option('mdwh_active', true);
        update_option('mdwh_active', true);
    }
    static function deactivation_hook()
    {
        update_option('mdwh_active', false);
    }
    static function uninstall_hook()
    {
        delete_option('mdwh_active', false);
    }
    static function action_links($links, $file)
    {
        array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=mdwh' ) . '">' . 'Settings'. '</a>' );
        return $links;
    }
    static function add_meta_links($links, $file)
    {
        if ($file == plugin_basename( __FILE__ )) {
            //$links[] = '<a href="http://my-egg.me">my-egg.me</a>';
            //$rate_url = 'http://wordpress.org/support/view/plugin-reviews/markdown-webhook?rate=5#postform';
            //$links[]  = '<a href="'.$rate_url.'">Rate this plugin</a>';
        }
        return $links;
    }
    static function the_content($content)
    {
        if (preg_match('/---md-webhook---/', $content)) {
            $content = preg_replace('/</', '&lt;', $content);
            $content = preg_replace('/>/', '&gt;', $content);
            $content = preg_replace('/([\s\S]*)&lt;!---md-webhook---&gt;/', '$1<!---md-webhook--->', $content);
            return  '<div class="md-webhook-content" style="opacity:0.01;transition:opacity linear 0.1s;">'. $content . '</div>';
        }
        
        return $content;
    }

    static function query_vars($vars){
        $vars[] = 'mdwh';
        return $vars;
    }

    static function template_redirect(){
        if(get_query_var('mdwh')) {
            include 'webhook.php';
            exit;
        }
    }

    function wp_head()
    {
        global $post;
        if (get_option('mdwh_autokeyword')) {
            $terms = get_the_terms( null, 'post_tag' );
            if ($terms) {
                $terms = array_map(function ($term) {
                    return $term->name;
                }, $terms);
                $terms = join(",", $terms);
                echo  "<meta name=\"keywords\" content=\"$terms\" />";
            }
        }
        wp_enqueue_style('mdwh_highligthcss', plugins_url('assets/tomorrow.min.css', __FILE__ ), null, '9.12.0');
        wp_enqueue_script('mdwh_highlight', plugins_url('assets/highlight.min.js', __FILE__ ), null, '9.12.0', true);
        wp_enqueue_script('mdwh_markdown-it', plugins_url('assets/markdown-it.min.js', __FILE__ ), null, '8.4.0', true);
        wp_enqueue_script('mdwh_md-webhook', plugins_url('assets/md-webhook.js', __FILE__ ), null, "1.0.0", true);
    }
    function pre_update_option_mdwh_password($value, $old_value)
    {
        $token = get_option('mdwh_token');
        if (preg_match('/^MDWHMDWH/', $value)) {
            return $value;
        }
        return  'MDWHMDWH'.MDWH::encrypt($value, $token);
    }
    function option_mdwh_password($value)
    {
        $token = get_option('mdwh_token');
        if (!$value) {
            return $value;
        }
        if (preg_match('/^MDWHMDWH/', $value)) {
            return MDWH::decrypt(substr($value, 8), $token);
        }
        return $value;
    }
    static function admin_init()
    {
        register_setting('mdwh_options', 'mdwh_zip_url');
        register_setting('mdwh_options', 'mdwh_username');
        register_setting('mdwh_options', 'mdwh_password');
        register_setting('mdwh_options', 'mdwh_tags');
        register_setting('mdwh_options', 'mdwh_autocat');
        register_setting('mdwh_options', 'mdwh_autokeyword');
        if (!get_option('mdwh_token')) {
            update_option('mdwh_token', wp_generate_uuid4(), true);
        }
        add_settings_section('data_source', '', '', 'mdwh');
        add_settings_field('zip_url', 'ZipUrl', 'MDWH::field_html', 'mdwh', 'data_source', array(type=>'text',name=>'mdwh_zip_url',tip=>'Example:   https://bitbucket.org/yourname/yourrepo/get/master.zip'));
        add_settings_field('username', 'RepoUserName', 'MDWH::field_html', 'mdwh', 'data_source', array(type=>'text',name=>'mdwh_username',tip=>'Please input it, if the repo is privated'));
        add_settings_field('password', 'RepoPassWord', 'MDWH::field_html', 'mdwh', 'data_source', array(type=>'password',name=>'mdwh_password',tip=>'Be safe, your password will be saved encryted'));
        add_settings_field('autocat', 'AutoCategory', 'MDWH::field_html', 'mdwh', 'data_source', array(type=>'checkbox',name=>'mdwh_autocat',tip=>'Use folder name as category (beta)'));
        add_settings_field('autokeyword', 'AutoKeyword', 'MDWH::field_html', 'mdwh', 'data_source', array(type=>'checkbox',name=>'mdwh_autokeyword',tip=>'Use tags as SEO meta keywords (beta)'));
        add_settings_field('tags', 'AutoTags', 'MDWH::field_html', 'mdwh', 'data_source', array(type=>'textarea',name=>'mdwh_tags',tip=>'This Plugin will search the words in the post content as tags. Example: linux|php|javascript|node\.js|wordpress'));
    }
    static function admin_menu()
    {
        add_submenu_page(
            'edit.php',
            'Markdown Webhook Options',
            'Markdown Webhook',
            'manage_options',
            'mdwh',
            'MDWH::page_html'
        );
    }
    static function page_html()
    {
        ?>
        <div class="wrap">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>
            <form id="mdwhForm" action="options.php" method="post">
                <?php
                settings_fields( 'mdwh_options' );
                do_settings_sections('mdwh');
                submit_button('Save Settings');
                ?>
                <button id="mdwhPullBtn" class="button button-primary">Pull Now</button>
            </form>
            <hr>
            <h1>Something May Help</h1>
            <table>
                <tr><th class="row" style="text-align:left"><a href="https://www.markdowntutorial.com/">Markdown Tutorial</a></th></tr>
                <tr><th class="row"  style="text-align:left"><a href="https://developer.github.com/v3/repos/contents/#get-archive-link">Github Developer Guide #get-archive-link</a></th></tr>
                <tr><th class="row"  style="text-align:left"><a href="https://confluence.atlassian.com/bitbucketserver/download-an-archive-from-bitbucket-server-913477030.html">download-an-archive-from-bitbucket-server</a></th></tr>
                <tr><th class="row"  style="text-align:left">Github zip sample: https://github.com/username/reponame/archive/branchname.zip</th></tr>
                <tr><th class="row"  style="text-align:left">Bitbucket zip sample: https://bitbucket.org/username/reponame/get/branchname.zip</th></tr>
              
            </table>
          

            <script>
                (function(){
                    var $ = jQuery;
                    var hookUrl = "<?php echo home_url().'?mdwh='.get_option('mdwh_token') ?>"
                    $("[name=mdwh_zip_url]").css({width:'500px'});
                    $("[name=mdwh_tags]").css({width:'500px'});
                    $("[name=mdwh_username]").css({width:'500px'});
                    $("[name=mdwh_password]").css({width:'500px'});
                    $("#mdwhPullBtn").appendTo("#mdwhForm .submit").css({"margin-left":'15px'});
                    $("#mdwhPullBtn").on("click",function(e){
                        e.preventDefault();
                        window.open(hookUrl);
                    })
                    $("[name=mdwh_zip_url]").on("keydown keyup input change",function(e){
                        var value = $(this).val();
                        if(!/bitbucket/.test(value)){
                            $("[name=mdwh_username]").parents("tr").hide();
                            $("[name=mdwh_password]").parents("tr").hide();
                        }
                        else{
                            $("[name=mdwh_username]").parents("tr").show();
                            $("[name=mdwh_password]").parents("tr").show();
                        }
                    })
                    $("[name=mdwh_zip_url]").trigger("change");
                    $('<tr><th class="row">Your Webhook Url</th><td><a target="_blank" href="'+hookUrl+'">'+hookUrl+'</a></td></tr>').prependTo("#mdwhForm .form-table")
                    $("#mdwhForm").on('submit',function(e){
                        var zipUrl = $("[name=mdwh_zip_url]").val();
                        if(!/zip/.test(zipUrl)){
                            alert('only zip file is supported.');
                            e.preventDefault();
                            return;
                        }
                    });
                })()
            </script>
        </div>
        <?php
    }
    static function field_html($args)
    {
        $value = get_option($args['name']);
        if ($args['type']=='text') {
            ?>
                <input name="<?php echo $args['name']?>" type="text" value="<?php echo $value?>" placeholder="<?php echo $args['tip']?>">
            <?php
        }
        if ($args['type']=='password') {
            $token = get_option('mdwh_token');
            $pvalue = 'MDWHMDWH'.MDWH::encrypt($value, $token);
            ?>
                <input name="<?php echo $args['name']?>" type="password" value="<?php echo $pvalue?>" placeholder="<?php echo $args['tip']?>">
            <?php
        }
        if ($args['type']=='textarea') {
            ?>
                <textarea name="<?php echo $args['name']?>" resize="none" style="height:300px;resize:none" placeholder="<?php echo $args['tip']?>"><?php echo $value?></textarea>
            <?php
        }
        if ($args['type']=='checkbox') {
            ?>
                <input type="checkbox" name = "<?php echo $args['name']?>" <?php echo $value?'checked':'' ?>>
                <small><?php echo $args['tip']?></small>
            <?php
        }
    }
    static function encrypt($str, $key)
    {
        return openssl_encrypt($str, 'AES-128-CBC', $key, null, 'mbwhmbwhmbwhmbwh');
    }
          
    static function decrypt($str, $key)
    {
        return openssl_decrypt($str, 'AES-128-CBC', $key, null, 'mbwhmbwhmbwhmbwh');
    }
}

