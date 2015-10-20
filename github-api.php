<?php
/**
Plugin Name: Github API
Description: Add Github project information using shortcode.
Version: 1.0
Author: Your Name
License: GPLv2 or later
Text Domain: github-api
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once 'vendor/autoload.php';

class MyGithub extends \Github\Client {};

function github_issues_func(  $atts, $gh=null ) {

    // Conditionally instantiate our class
    $gh = ( $gh ) ? $gh : new MyGithub();

    // Make the API call to get issues, passing in the Github owner and repository
    $issues = $gh->api("issue")->all(get_option("gh_org"), get_option("gh_repo"));

    // Handle the case when there are no issues
    if ( empty($issues) )
        return "<strong>" . __("No issues to show", 'githup-api') . "</strong>";
        
    // We're going to return a string. We open a list...
    $return = "<ul>";
    
    // Loop over the returned issues
    foreach( $issues as $issue ) {

        // Add a list item for each issue to the string.
        // (Feel free to get fancier here...
        // Maybe make each one a link to the issue issuing $issue['url] )
        $return .= "<li>{$issue['title']}</li>";

    }
    
    // Don't forget to close the list
    $return .= "</ul>";
    
    return $return;

}
add_shortcode( 'github_issues', 'github_issues_func' );


// Register the menu
add_action( "admin_menu", "gh_plugin_menu_func" );
function gh_plugin_menu_func() {
    add_submenu_page(   "options-general.php",  // Which menu parent
                        "Github",               // Page title
                        "Github",               // Menu title
                        "manage_options",       // Minimum capability (manage_options is an easy way to target Admins)
                        "github",               // Menu slug
                        "gh_plugin_options"     // Callback that prints the markup
                    );
}

// Print the markup for the page
function gh_plugin_options() {

    if ( !current_user_can( "manage_options" ) )  {
        wp_die( __( "You do not have sufficient permissions to access this page." ) );
    }

    if ( isset($_GET['status']) && $_GET['status']=='success') { 
    ?>
        <div id="message" class="updated notice is-dismissible">
            <p><?php _e("Settings updated!", "github-api"); ?></p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text"><?php _e("Dismiss this notice.", "github-api"); ?></span>
            </button>
        </div>
    <?php
    }

    ?>
    <form method="post" action="<?php echo admin_url( 'admin-post.php'); ?>">

        <input type="hidden" name="action" value="update_github_settings" />

        <h3><?php _e("Github Repository Info", "github-api"); ?></h3>
        <p>
        <label><?php _e("Github Organization:", "github-api"); ?></label>
        <input class="" type="text" name="gh_org" value="<?php echo get_option('gh_org'); ?>" />
        </p>

        <p>
        <label><?php _e("Github repository (slug):", "github-api"); ?></label>
        <input class="" type="text" name="gh_repo" value="<?php echo get_option('gh_repo'); ?>" />
        </p>

        <input class="button button-primary" type="submit" value="<?php _e("Save", "github-api"); ?>" />

    </form>

    <form method="post" action="<?php echo admin_url( 'admin-post.php'); ?>">

        <input type="hidden" name="action" value="oauth_submit" />
        
        <h3>Oauth 2.0</h3>

        <p>
            <label><?php _e("Github Application Client ID:", "github-api"); ?></label>
            <input class="" type="text" name="client_id" value="<?php echo get_option('client_id')?>" />
        </p>
        <p>
            <label><?php _e("Github Application Client Secret:", "github-api"); ?></label>
            <input class="" type="password" name="client_secret" value="<?php echo get_option('client_secret')?>" />
        </p>
            

        <input class="button button-primary" type="submit" value="<?php _e("Authorize", "github-api"); ?>" />

    </form>

<?php

}

add_action( 'admin_post_oauth_submit', 'handle_oauth' );

function handle_oauth() {

    // If the form was just submitted, save the values
    // (Step 1 above)
    if (    isset($_POST["client_id"]) && 
            isset($_POST["client_secret"])
        ) {
        
        update_option( "client_id", $_POST["client_id"], TRUE );
        update_option("client_secret", $_POST["client_secret"], TRUE);

    }

    // Get the saved application info
    $client_id = get_option("client_id");
    $client_secret = get_option("client_secret");

    if ($client_id && $client_secret)
    {
        $provider = new League\OAuth2\Client\Provider\Github([
            "clientId"          =>  $client_id,
            "clientSecret"      =>  $client_secret,
            "redirectUri"       => admin_url("options-general.php?page=github"),
        ]);

    }

    // If this is a form submission start the workflow...
    // (Step 2)
    if (!isset($_GET["code"]) && $_SERVER["REQUEST_METHOD"] === "POST") {

        // If we don"t have an authorization code then get one
        $authUrl = $provider->getAuthorizationUrl();
        $_SESSION["oauth2state"] = $provider->getState();
        header("Location: ".$authUrl);
        exit;

    // Check given state against previously stored one to mitigate CSRF attack
    // (Step 3 just happened and the user was redirected back)
    } elseif (empty($_GET["state"]) || ($_GET["state"] !== $_SESSION["oauth2state"])) {

        unset($_SESSION["oauth2state"]);
        exit("Invalid state");

    } else {

        // Try to get an access token (using the authorization code grant)
        // (Step 4)
        $token = $provider->getAccessToken("authorization_code", [
            "code" => $_GET["code"]
        ]);
        
        // Save the token for future use
        update_option( "github_token", $token->getToken(), TRUE );

    }

}

add_action( 'admin_post_update_github_settings', 'github_handle_save' );

function github_handle_save() {

    // Get the options that were sent
    $org = (!empty($_POST["gh_org"])) ? $_POST["gh_org"] : NULL;
    $repo = (!empty($_POST["gh_repo"])) ? $_POST["gh_repo"] : NULL;

    // Validation would go here

    // Update the values
    update_option( "gh_repo", $repo, TRUE );
    update_option("gh_org", $org, TRUE);

    // Redirect back to settings page
    // The ?page=github corresponds to the "slug" 
    // set in the fourth parameter of add_submenu_page() above.
    $redirect_url = get_bloginfo("url") . "/wp-admin/options-general.php?page=github&status=success";
    header("Location: ".$redirect_url);
    exit;
}