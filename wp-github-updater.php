<?php
/**
 * Github updater
 *
 * @package     wp-github-updater
 * @author      Mehdi Lahlou
 * @copyright   2016 Mehdi Lahlou
 * @license     GPL-3.0+
 *
 * @wordpress-plugin
 * Plugin Name: Github updater
 * Plugin URI:  https://github.com/medfreeman/wp-github-updater
 * Description: Enables automatic updates of plugins and themes from github
 * Version:     1.1.4
 * Author:      Mehdi Lahlou
 * Author URI:  https://github.com/medfreeman
 * Text Domain: wp-github-updater
 * License:     GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

require __DIR__ . '/vendor/autoload.php';

/**
 * Main class.
 */
class WpGithubUpdater {

	const API_TOKEN_OPTION_KEY    = 'wp_github_updater_api_token';
	const API_NAMESPACE           = 'wp-github-updater/v1';
	const API_ENDPOINT            = '/update-check';

	/**
	 * Constructor
	 */
	function __construct() {
		register_activation_hook( __FILE__, array( $this, 'set_update_check_api_token' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'display_update_check_url' ), 10, 4 );
		add_action( 'admin_init', array( $this, 'handle_github_self_update' ) );
		add_action( 'rest_api_init', array( $this, 'add_update_check_api_route' ) );
	}

	/**
	 * Sets the api key.
	 */
	public function set_update_check_api_token() {
		if ( false !== get_option( self::API_TOKEN_OPTION_KEY ) ) {
			return;
		}

		$uuid_factory = new \Ramsey\Uuid\UuidFactory();
		$uuid_factory->setRandomGenerator( new \Ramsey\Uuid\Generator\RandomLibAdapter() );
		\Ramsey\Uuid\Uuid::setFactory( $uuid_factory );

		$uuid = \Ramsey\Uuid\Uuid::uuid4();

		update_option( self::API_TOKEN_OPTION_KEY, $uuid->toString(), 'no' );
	}

	/**
	 * Adds the update check url next to plugin buttons in plugins screen.
	 *
	 * @param array  $actions     An array of plugin action links.
	 * @param string $plugin_file Path to the plugin file.
	 * @param string $plugin_data An array of plugin data.
	 * @param string $context     The plugin context. Defaults are 'All', 'Active', 'Inactive', 'Recently Activated', 'Upgrade', 'Must-Use', 'Drop-ins', 'Search'.
	 */
	public function display_update_check_url( $actions, $plugin_file, $plugin_data, $context ) {
		$api_update_check_url = trailingslashit( get_home_url() ) . trailingslashit( 'wp-json/' ) . self::API_NAMESPACE . self::API_ENDPOINT;
		$api_update_check_url = add_query_arg( 'token', get_option( self::API_TOKEN_OPTION_KEY ), $api_update_check_url );

		$custom_actions = array(
			'update' => sprintf( '<a href="%s" target="_blank">%s</a>', $api_update_check_url, __( 'Api URL', 'wp-github-updater' ) ),
		);

		// Add the links to the front of the actions list.
		return array_merge( $actions, $custom_actions );
	}

	/**
	 * Handles this plugin's github update.
	 */
	public function handle_github_self_update() {
		new GitHubUpdater( 'plugin', __FILE__ );
	}

	/**
	 * Adds a route to wordpress REST Api v2
	 * to allow on-demand update checks.
	 */
	public function add_update_check_api_route() {
		register_rest_route( self::API_NAMESPACE, self::API_ENDPOINT, array(
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'handle_update_check' ),
			'args' => array(
				'token' => array(
					'required'          => true,
					'validate_callback' => function( $param, $request, $key ) {
						return ( get_option( self::API_TOKEN_OPTION_KEY ) === $param );
					},
				),
			),
		));
	}

	/**
	 * Force wordpress update check.
	 *
	 * @param  array $data Options for the function.
	 * @return string success.
	 */
	function handle_update_check( $data ) {
		wp_clean_update_cache();
		wp_update_plugins();
		return 'success';
	}
}

include_once( 'github-updater.class.php' );

$wp_github_updater = new WpGithubUpdater();
