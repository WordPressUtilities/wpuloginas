<?php

/*
Plugin Name: WPU Login As
Description: Login as another user
Version: 0.7.2
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPULoginAs {

    /* Toggles */
    private $prevent_clean_logout = false;

    /* Initial settings */
    private $cookie_name = 'wpuloginas_originaluserid';
    private $cookie_name_hash = 'wpuloginas_originaluserhash';
    private $min_user_level = 'remove_users';

    public function __construct() {
        add_action('wp_loaded', array(&$this,
            'launch_plugin'
        ), 1);
    }

    public function launch_plugin() {
        if (!is_user_logged_in()) {
            return;
        }

        $this->cookie_name = apply_filters('wpuloginas__cookie_name', $this->cookie_name);
        $this->cookie_name_hash = apply_filters('wpuloginas__cookie_name_hash', $this->cookie_name_hash);
        $this->min_user_level = apply_filters('wpuloginas__min_user_level', $this->min_user_level);

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
        add_action('wp_footer', array(&$this,
            'go_back_link_footer'
        ), 999);
        add_action('wp_logout', array(&$this,
            'wp_logout'
        ), 999);
        add_filter('user_row_actions', array(&$this,
            'user_action_link'
        ), 10, 2);
        add_action('wp_loaded', array(&$this,
            'set_initial_cookie'
        ), 999);

        /* Add to multisite only if plugin is active on all websites */
        $active_plugins = (array) get_site_option('active_sitewide_plugins', array());
        if (array_key_exists(plugin_basename(__FILE__), $active_plugins)) {
            add_filter('ms_user_row_actions', array(&$this,
                'user_action_link'
            ), 10, 2);
        }
    }

    /* Set initial cookie
    -------------------------- */
    function set_initial_cookie(){
        /* Load user id in cookie */
        if (is_admin() && current_user_can($this->min_user_level)) {
            $this->set_user_id_in_cookie();
        }
    }


    /* ----------------------------------------------------------
      Admin buttons
    ---------------------------------------------------------- */

    /**
     * Display backlink in footer
     */
    public function go_back_link_footer() {
        $user_id = $this->get_user_id_from_cookie();
        if (!is_numeric($user_id)) {
            return;
        }
        echo '<footer id="wpuloginas-footer" style="padding:0.5em 1em;text-align: center;"><a href="' . $this->get_redirect_url($user_id) . '">' . $this->get_loginas_txt($user_id, true) . '</a></footer>';
    }

    /**
     * Display backlink in admin bar
     */
    public function go_back_link($wp_admin_bar) {
        $user_id = $this->get_user_id_from_cookie();
        if (!is_numeric($user_id)) {
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
        if (!current_user_can($this->min_user_level) || $user_obj->ID == get_current_user_id()) {
            return $actions;
        }

        /* Multisite view */
        if (current_filter() == 'ms_user_row_actions') {

            $redirect_url = $this->get_redirect_url($user_obj->ID);

            /* If user is not an admin, redirect to its first blog */
            if (!in_array('administrator', $user_obj->roles)) {
                $blogs = get_blogs_of_user($user_obj->ID);
                if (!is_array($blogs) || empty($blogs)) {
                    return;
                }
                $blog_id = false;
                foreach ($blogs as $blog_id_tmp => $blog) {
                    $blog_id = $blog_id_tmp;
                    break;
                }
                $redirect_url = $this->get_redirect_url($user_obj->ID, $blog_id_tmp) . '&wpuloginas_originaluser=' . get_current_user_id();
            }

            $actions['wpu_login_as'] = '<a href="' . $redirect_url . '">' . $this->get_loginas_txt($user_obj->ID) . '</a>';
        } else {
            $actions['wpu_login_as'] = '<a href="' . $this->get_redirect_url($user_obj->ID) . '">' . $this->get_loginas_txt($user_obj->ID) . '</a>';
        }

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
        if (!current_user_can($this->min_user_level) || $user->ID == get_current_user_id()) {
            return false;
        }

        $this->set_user_id_in_cookie();

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

        /* Check correct value */
        if (!isset($_GET['loginas']) || !is_numeric($_GET['loginas'])) {
            return false;
        }

        /* Check nonce */
        if (!isset($_GET['wpuloginas']) || !wp_verify_nonce($_GET['wpuloginas'], 'redirecttouser' . $_GET['loginas'])) {
            return false;
        }

        /* Save original user if it exists */
        if (isset($_GET['wpuloginas_originaluser']) && $_GET['wpuloginas_originaluser'] == get_current_user_id()) {
            $this->set_user_id_in_cookie($_GET['wpuloginas_originaluser']);
        }

        /* Not going back and not admin */
        if (!$this->has_original_user_to_go_back() && !current_user_can($this->min_user_level)) {
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
        $this->prevent_clean_logout = true;
        wp_logout();

        /* Login as user */
        wp_set_current_user($user_id, $user->user_login);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user->user_login);

        /* Redirect to admin home */
        wp_redirect('/wp-admin/');

        die;
    }

    public function wp_logout() {
        if ($this->prevent_clean_logout) {
            return;
        }
        $this->delete_cookies();
    }

    /* ----------------------------------------------------------
      Cookies utils
    ---------------------------------------------------------- */

    public function has_original_user_to_go_back() {
        return isset($_COOKIE[$this->cookie_name]) && $_COOKIE[$this->cookie_name] != get_current_user_id();
    }

    public function get_user_id_from_cookie() {
        /* Check if values exists */
        if (!isset($_COOKIE[$this->cookie_name]) || !isset($_COOKIE[$this->cookie_name_hash])) {
            return false;
        }
        /* Check if user is not current user */
        $user_id = $_COOKIE[$this->cookie_name];
        if ($user_id == get_current_user_id()) {
            return false;
        }
        /* Check if hash is correct */
        if ($this->get_user_hash($user_id) != $_COOKIE[$this->cookie_name_hash]) {
            return false;
        }

        return $user_id;
    }

    public function set_user_id_in_cookie($new_user_id = false) {
        /* Save the original user id (the first time we see the button) */
        if (!isset($_COOKIE[$this->cookie_name])) {
            if (!is_numeric($new_user_id)) {
                $new_user_id = get_current_user_id();
            }

            $expire_time = current_time('timestamp') + 3600;
            setcookie($this->cookie_name, $new_user_id, $expire_time, '/');
            setcookie($this->cookie_name_hash, $this->get_user_hash($new_user_id), $expire_time, '/');
        }
    }

    public function delete_cookies() {
        $expire_time = current_time('timestamp') - 86400;
        setcookie($this->cookie_name, '', $expire_time);
        setcookie($this->cookie_name_hash, '', $expire_time);
    }

    public function get_user_hash($user_id) {
        $cache_key = 'wpuloginas_userhash' . $user_id;
        $user_hash = wp_cache_get($cache_key);
        if ($user_hash === false) {
            /* Generate hash from fixed but non public user datas */
            $user = get_user_by('id', $user_id);
            $user_hash = md5($user->user_pass . $user->user_registered . $user->user_login . $user_id);
            wp_cache_set($cache_key, $user_hash, '', 600);
        }
        return $user_hash;
    }

    /* ----------------------------------------------------------
      Utils
    ---------------------------------------------------------- */

    /**
     * Get redirect url with nonce
     * @param  int $user_id
     * @return string
     */
    public function get_redirect_url($user_id, $blog_id = null) {
        return wp_nonce_url(get_admin_url($blog_id, 'index.php?loginas=' . $user_id), 'redirecttouser' . $user_id, 'wpuloginas');
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
