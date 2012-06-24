<?php
/*
Plugin Name: Add Tweet as Comment
Plugin URI: https://github.com/swaroopch/wordpress_add_tweet_as_comment/
Description: Add any random tweet as a comment to your blog post, to save tweet replies along with your blog comments.
Version: 1.2
Author: Swaroop C H
Author URI: http://www.swaroopch.com
License: GPL2
Extracted From: http://wordpress.org/extend/plugins/social/
*/

add_action('admin_menu', 'add_tweet_as_comment_menu');
add_action('init', 'add_tweet_as_comment_request_handler');


function add_tweet_as_comment_menu() {
    add_options_page(
        'Add Tweet as Comment',
        'Add Tweet as Comment',
        'moderate_comments',
        basename(__FILE__),
        'show_tweet_as_comment_form'
    );
}


function show_tweet_as_comment_form() {
    if (is_admin()) {
        if (isset($_GET['saved_tweet_as_comment'])) {
?>
<p>
    <strong>
        Saved tweet!
    </strong>
</p>
<p>
    <a href="<?php echo admin_url(); ?>/options-general.php?page=add_tweet_as_comment.php">Add another one</a>
</p>
<?php
        } else {
?>
<form id="add_tweet_as_comment" method="post" action="<?php echo admin_url(); ?>">
<?php wp_nonce_field('add_tweet_as_comment'); ?>
<h2>Add Tweet</h2>

<input type="hidden" name="add_tweet_as_comment_action" value="true"/>

<p>
    <label for="tweet_url">Tweet URL:</label>
    <br />
    <input id="tweet_url" name="tweet_url" type="text" size="100"></input>
</p>

<p>
    <label for="which_post">Post:</label>
    <br />
    <select id="which_post" name="which_post">
        <?php $args = array( 'numberposts' => 200 ); ?>
        <?php foreach (get_posts($args) as $post) { ?>
            <option value="<?php echo $post->ID; ?>"><?php echo $post->post_title; ?>&nbsp;&nbsp;</option>
        <?php } ?>
    </select>
</p>

<input type="submit" value="Submit"></input>

</form>
<?php
        }
    }
}


function add_tweet_as_comment_request_handler()
{
    if (is_admin() ) {
        if (isset($_POST['add_tweet_as_comment_action'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'], 'add_tweet_as_comment')) {
                wp_die('Oops, please try again.');
                exit(1);
            }

            $post_id  = $_POST['which_post'];
            $url      = $_POST['tweet_url'];
            import_tweet($post_id, $url);

            wp_redirect(admin_url("edit-comments.php?comment_status=moderated"));
            exit;
            //wp_redirect(admin_url("options-general.php?page=add_tweet_as_comment.php&saved_tweet_as_comment=1"));
            //exit;
        }
    }
}


function import_tweet($post_id, $url) {
    $post = get_post($post_id);

    $url_exploded = explode('/', $url);
    $tweet_id = end($url_exploded);

    if (empty($tweet_id)) {
        wp_die("Invalid tweet ID -> |$tweet_id|", "Invalid tweet ID");
        exit(1);
    }

    // https://dev.twitter.com/docs/api/1/get/statuses/show/%3Aid
    $api_url = "http://api.twitter.com/1/statuses/show/$tweet_id.json";
    $request = wp_remote_get($api_url);
    if (is_wp_error($request)) {
        wp_die("Unable to fetch data from Twitter : " . $request->get_error_message());
        exit(1);
    }

    $response = json_decode($request['body']);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_die("Unable to decode JSON : <pre> " . var_export($request['body'], true) . "</pre>");
        exit(1);
    }

    $replies = array(
        (object) array(
            'from_user_id'      => $response->user->id,
            'from_user'         => '@' . $response->user->screen_name,
            'profile_image_url' => $response->user->profile_image_url,
            'id'                => $response->id,
            'text'              => $response->text,
            'created_at'        => $response->created_at,
            'tweet_url'         => $url,
        )
    );
    save_replies($post_id, $replies);
}


function save_replies($post_id, array $replies) {
    foreach($replies as $reply) {
        // TODO Replace t.co urls with entities/urls/expanded_url using entities/urls/indices in the JSON
        $commentdata = array(
            'comment_post_ID'      => $post_id,
            'comment_type'         => 'comment',
            'comment_author'       => $reply->from_user,
            'comment_author_email' => 'twitter.' . $reply->id . '@example.com',
            'comment_author_url'   => $reply->tweet_url,
            'comment_content'      => $reply->text,
            'comment_date'         => gmdate('Y-m-d H:i:s', strtotime($reply->created_at)),
            'comment_date_gmt'     => gmdate('Y-m-d H:i:s', strtotime($reply->created_at)),
            'comment_author_IP'    => $_SERVER['SERVER_ADDR'],
            'comment_agent'        => 'Add Tweet as Comment Wordpress Plugin'
        );
        $commentdata['comment_approved'] = wp_allow_comment($commentdata);
        $comment_id = wp_insert_comment($commentdata);
        update_comment_meta($comment_id, 'add_tweet_as_comment_account_id',         $reply->from_user_id);
        update_comment_meta($comment_id, 'add_tweet_as_comment_profile_image_url',  $reply->profile_image_url);
        update_comment_meta($comment_id, 'add_tweet_as_comment_status_id',          $reply->id);

        if ('spam' !== $commentdata['comment_approved']) {
            if ('0' == $commentdata['comment_approved']) {
                wp_notify_moderator($comment_id);
            }
        }

        $post = &get_post($commentdata['comment_post_ID']); // Don't notify if it's your own comment
        if (get_option('comments_notify') and $commentdata['comment_approved'] and (!isset($commentdata['user_id']) or $post->post_author != $commentdata['user_id'])) {
            wp_notify_postauthor($commentid, isset($commentdata['comment_type']) ? $commentdata['comment_type'] : '');
        }
    }
}

?>
