<?php

/*
Plugin Name: WPU Login As
Description: Login as another user
Version: 0.3.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPULoginAs {
    public function __construct() {
        add_action('wp_loaded', array(&$this,
            'launch_plugin'
        ), 1);
    }

    public function launch_plugin() {
        if (!is_user_logged_in()) {
            return;
        }
        load_plugin_textdomain('wpuloginas', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        add_action('wp_loaded', array(&$this,
            'redirecttouser'
        ));
        add_action('show_user_profile', array(&$this,
            'displaybutton'
        ));
        add_action('edit_user_profile', array(&$this,
            'displaybutton'
        ));
        add_action('admin_bar_menu', array(&$this,
            'go_back_link'
        ), 999);
        add_filter('user_row_actions', array(&$this,
            'user_action_link'
        ), 10, 2);
    }

    /* ----------------------------------------------------------
      Admin buttons
    ---------------------------------------------------------- */

    /**
     * Display backlink in admin bar
     */
    public function go_back_link($wp_admin_bar) {
        if (!isset($_SESSION['wpuloginas_originaluser'])) {
            return;
        }
        $user_id = $_SESSION['wpuloginas_originaluser'];
        if ($user_id == get_current_user_id()) {
            return;
        }
        $wp_admin_bar->add_node(array(
            'id' => 'wpuloginasback',
            'title' => $this->get_loginas_txt($user_id, true),
            'href' => $this->get_redirect_url($user_id)
        ));
    }

    public function user_action_link($actions, $user_obj) {
        /* Only for admins */
        if (!current_user_can('remove_users') || $user_obj->ID == get_current_user_id()) {
            return $actions;
        }

        /* Save the original user id (the first time we see the button) */
        if (!isset($_SESSION['wpuloginas_originaluser'])) {
            $_SESSION['wpuloginas_originaluser'] = get_current_user_id();
        }

        $actions['wpu_login_as'] = '<a href="' . $this->get_redirect_url($user_obj->ID) . '">' . $this->get_loginas_txt($user_obj->ID) . '</a>';

        return $actions;
    }

    /* ----------------------------------------------------------
      Button
    ---------------------------------------------------------- */

    /**
     * Display a button in user profile
     */
    public function displaybutton($user) {
        /* Only for admins */
        if (!current_user_can('remove_users') || $user->ID == get_current_user_id()) {
            return false;
        }

        /* Save the original user id (the first time we see the button) */
        if (!isset($_SESSION['wpuloginas_originaluser'])) {
            $_SESSION['wpuloginas_originaluser'] = get_current_user_id();
        }

        echo '<a href="' . $this->get_redirect_url($user->ID) . '" class="button">' . $this->get_loginas_txt($user->ID) . '</a>';
    }

    /* ----------------------------------------------------------
      Login AS
    ---------------------------------------------------------- */

    public function redirecttouser() {
        /* Only for loggedin user in admin */
        if (!is_admin() || !is_user_logged_in()) {
            return false;
        }

        /* Not going back and not admin */
        if (!isset($_SESSION['wpuloginas_originaluser']) && !current_user_can('remove_users')) {
            return false;
        }

        /* Check correct value */
        if (!isset($_GET['loginas']) || !is_numeric($_GET['loginas'])) {
            return false;
        }

        /* Check nonce */
        if (!isset($_GET['wpuloginas']) || !wp_verify_nonce($_GET['wpuloginas'], 'redirecttouser')) {
            return false;
        }

        $this->setuser($_GET['loginas']);

    }

    /**
     * Set current user and go back to the admin
     */
    public function setuser($user_id) {

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        /* Logout */
        wp_logout();

        /* Login as user */
        wp_set_current_user($user_id, $user->user_login);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user->user_login);

        /* Redirect to admin home */
        wp_redirect('/wp-admin/');

        die;
    }

    /* ----------------------------------------------------------
      Utils
    ---------------------------------------------------------- */

    /**
     * Get redirect url with nonce
     * @param  int $user_id
     * @return string
     */
    public function get_redirect_url($user_id) {
        return wp_nonce_url(admin_url('index.php?loginas=' . $user_id), 'redirecttouser', 'wpuloginas');
    }

    /**
     * Get login as text
     * @param  int $user_id
     * @return string
     */
    public function get_loginas_txt($user_id, $back = false) {
        $nick = esc_attr(get_user_meta($user_id, 'nickname', 1));
        $str = __('Login as "%s"', 'wpuloginas');
        if ($back) {
            $str = __('Back to user "%s"', 'wpuloginas');
        }
        return sprintf($str, $nick);
    }
}

$WPULoginAs = new WPULoginAs();