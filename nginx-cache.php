<?php
/*
Plugin Name: Nginx Cache
Plugin URI: http://wordpress.org/plugins/nginx-cache/
Description: Purge the Nginx cache (FastCGI, Proxy, uWSGI) automatically when content changes or manually within WordPress.
Version: 1.0.2
Text Domain: nginx-cache
Domain Path: /languages
Author: Till Krüss
Author URI: http://till.kruss.me/
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class NginxCache {

	private $screen = 'tools_page_nginx-cache';
	private $capability = 'manage_options';
	private $admin_page = 'tools.php?page=nginx-cache';

	public function __construct() {

		load_plugin_textdomain( 'nginx-cache', false, 'nginx-cache/languages' );

		add_filter( 'option_nginx_cache_path', 'sanitize_text_field' );
		add_filter( 'option_nginx_auto_purge', 'absint' );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_actions_links' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'load-' . $this->screen, array( $this, 'do_admin_actions' ) );
		add_action( 'load-' . $this->screen, array( $this, 'add_settings_notices' ) );

		// use `nginx_cache_purge_actions` filter to alter default purge actions
		$purge_actions = (array) apply_filters(
			'nginx_cache_purge_actions',
			array(
				'publish_phone', 'save_post', 'edit_post', 'delete_post', 'wp_trash_post', 'clean_post_cache',
				'trackback_post', 'pingback_post', 'comment_post', 'edit_comment', 'delete_comment', 'wp_set_comment_status',
				'switch_theme', 'wp_update_nav_menu', 'edit_user_profile_update'
			)
		);

		foreach ( $purge_actions as $action ) {
			add_action( $action, array( $this, 'purge_zone_once' ) );
		}

	}

	public function register_settings() {

		register_setting( 'nginx-cache', 'nginx_cache_path', 'sanitize_text_field' );
		register_setting( 'nginx-cache', 'nginx_auto_purge', 'absint' );

	}

	public function add_settings_notices() {

		$path_error = $this->is_valid_path();

		if ( isset( $_GET[ 'message' ] ) && ! isset( $_GET[ 'settings-updated' ] ) ) {

			// show cache purge success message
			if ( $_GET[ 'message' ] === 'cache-purged' ) {
				add_settings_error( '', 'nginx_cache_path', __( 'Cache purged.', 'nginx-cache' ), 'updated' );
			}

			// show cache purge failure message
			if ( $_GET[ 'message' ] === 'purge-cache-failed' ) {
				add_settings_error( '', 'nginx_cache_path', sprintf( __( 'Cache could not be purged. %s', 'nginx-cache' ), wptexturize( $path_error->get_error_message() ) ) );
			}

		} elseif ( is_wp_error( $path_error ) && $path_error->get_error_code() === 'fs' ) {

			// show cache path problem message
			add_settings_error( '', 'nginx_cache_path', wptexturize( $path_error->get_error_message( 'fs' ) ) );

		}

	}

	public function do_admin_actions() {

		// purge cache
		if ( isset( $_GET[ 'action' ] ) && $_GET[ 'action' ] === 'purge-cache' && wp_verify_nonce( $_GET[ '_wpnonce' ], 'purge-cache' ) ) {

			$result = $this->purge_zone();
			wp_safe_redirect( admin_url( add_query_arg( 'message', is_wp_error( $result ) ? 'purge-cache-failed' : 'cache-purged', $this->admin_page ) ) );
			exit;

		}

	}

	public function add_admin_bar_node( $wp_admin_bar ) {

		// verify user capability
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		// add "Nginx" node to admin-bar
		$wp_admin_bar->add_node( array(
			'id' => 'nginx-cache',
			'title' => __( 'Nginx', 'nginx-cache' ),
			'href' => admin_url( $this->admin_page )
		) );

		// add "Purge Cache" to "Nginx" node
		$wp_admin_bar->add_node( array(
			'parent' => 'nginx-cache',
			'id' => 'purge-cache',
			'title' => __( 'Purge Cache', 'nginx-cache' ),
			'href' => wp_nonce_url( admin_url( add_query_arg( 'action', 'purge-cache', $this->admin_page ) ), 'purge-cache' )
		) );

	}

	public function add_admin_menu_page() {

		// add "Tools" sub-page
		add_management_page(
			__( 'Nginx Cache', 'nginx-cache' ),
			__( 'Nginx', 'nginx-cache' ),
			$this->capability,
			'nginx-cache',
			array( $this, 'show_settings_page' )
		);

	}

	public function show_settings_page() {
		require_once plugin_dir_path( __FILE__ ) . '/includes/settings-page.php';
	}

	public function add_plugin_actions_links( $links ) {

		// add settings link to plugin actions
		return array_merge(
			array( '<a href="' . admin_url( $this->admin_page ) . '">Settings</a>' ),
			$links
		);

	}

	public function enqueue_admin_styles( $hook_suffix ) {

		if ( $hook_suffix === $this->screen ) {
			$plugin = get_plugin_data( __FILE__ );
			wp_enqueue_style( 'nginx-cache', plugin_dir_url( __FILE__ ) . 'includes/settings-page.css', null, $plugin[ 'Version' ] );
		}

	}

	private function is_valid_path() {

		global $wp_filesystem;

		$path = get_option( 'nginx_cache_path' );

		if ( empty( $path ) ) {
			return new WP_Error( 'empty', __( '"Cache Zone Path" is not set.', 'nginx-cache' ) );
		}

		if ( $this->initialize_filesystem() ) {

			if ( ! $wp_filesystem->exists( $path ) ) {
				return new WP_Error( 'fs', __( '"Cache Zone Path" does not exist.', 'nginx-cache' ) );
			}

			if ( ! $wp_filesystem->is_dir( $path ) ) {
				return new WP_Error( 'fs', __( '"Cache Zone Path" is not a directory.', 'nginx-cache' ) );
			}

			$list = $wp_filesystem->dirlist( $path, true, true );
			if ( ! $this->validate_dirlist( $list ) ) {
				return new WP_Error( 'fs', __( '"Cache Zone Path" does not appear to be a Nginx cache zone directory.', 'nginx-cache' ) );
			}

			if ( ! $wp_filesystem->is_writable( $path ) ) {
				return new WP_Error( 'fs', __( '"Cache Zone Path" is not writable.', 'nginx-cache' ) );
			}

			return true;

		}

		return new WP_Error( 'fs', __( 'Filesystem API could not be initialized.', 'nginx-cache' ) );

	}

	private function validate_dirlist( $list ) {

		foreach ( $list as $item ) {

			// abort if file is not a MD5 hash
			if ( $item[ 'type' ] === 'f' && ( strlen( $item[ 'name' ] ) !== 32 || ! ctype_xdigit( $item[ 'name' ] ) ) ) {
				return false;
			}

			// validate subdirectories recursively
			if ( $item[ 'type' ] === 'd' && ! $this->validate_dirlist( $item[ 'files' ] ) ) {
				return false;
			}

		}

		return true;

	}

	public function purge_zone_once() {

		static $completed = false;

		if ( ! $completed ) {
			$this->purge_zone();
			$completed = true;
		}

	}

	private function purge_zone() {

		global $wp_filesystem;

		$path = get_option( 'nginx_cache_path' );
		$path_error = $this->is_valid_path();

		// abort if cache zone path is not valid
		if ( is_wp_error( $path_error ) ) {
			return $path_error;
		}

		do_action( 'nginx_cache/before_purge' );

		// remove cache directory (recursively)
		$wp_filesystem->rmdir( $path, true );

		do_action( 'nginx_cache/after_purge' );

		return true;

	}

	private function initialize_filesystem() {

		$path = get_option( 'nginx_cache_path' );

		// load WordPress file API?
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		ob_start();
		$credentials = request_filesystem_credentials( '', '', false, $path, null, true );
		ob_end_clean();

		if ( $credentials === false ) {
			return false;
		}

		if ( ! WP_Filesystem( $credentials, $path, true ) ) {
			return false;
		}

		return true;

	}

}

new NginxCache;
