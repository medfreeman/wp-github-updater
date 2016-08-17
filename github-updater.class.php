<?php
/**
 * Github updater
 *
 * @package     wp-github-updater
 * @author      Mehdi Lahlou
 * @copyright   2016 Mehdi Lahlou
 * @license     GPL-3.0+
 */

require __DIR__ . '/vendor/autoload.php';

if ( ! class_exists( 'GitHubUpdater' ) ) {

	/**
	 * GitHubUpdater class
	 */
	class GitHubUpdater {

		/**
		 * __FILE__ of our plugin or __DIR__ of our theme.
		 *
		 * @var string $resource_path __FILE__ of our plugin or __DIR__ of our theme.
		 */
		private $resource_path;
		/**
		 * Wordpress plugin or theme data.
		 *
		 * @var array $resource_data Wordpress plugin or theme data.
		 */
		private $resource_data;
		/**
		 * Plugin or theme slug.
		 *
		 * @var string $slug Plugin or theme slug.
		 */
		private $slug;
		/**
		 * GitHub username.
		 *
		 * @var string $username GitHub username.
		 */
		private $username;
		/**
		 * GitHub repo name.
		 *
		 * @var string $repo GitHub repo name.
		 */
		private $repo;
		/**
		 * GitHub private repo token.
		 *
		 * @var string $access_token GitHub private repo token.
		 */
		private $access_token;
		/**
		 * Holds data from GitHub.
		 *
		 * @var array $github_api_result Holds data from GitHub.
		 */
		private $github_api_result;

		/**
		 * Constructor
		 *
		 * @param string $resource_type       The project type: 'theme' or 'plugin'.
		 * @param string $resource_path       The theme root folder, or plugin's php file.
		 * @param string $access_token        Optional github access token for private plugins.
		 */
		function __construct( $resource_type, $resource_path, $access_token = '' ) {
			$this->resource_path = $resource_path;
			$this->access_token = $access_token;

			if ( 'plugin' === $resource_type ) {
				$this->init_plugin_data();
				add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'plugin_set_transient' ) );
				add_filter( 'plugins_api', array( $this, 'resource_set_info' ), 10, 3 );
				add_filter( 'upgrader_pre_install', array( $this, 'plugin_pre_install' ), 10, 3 );
				add_filter( 'upgrader_post_install', array( $this, 'plugin_post_install' ), 10, 3 );
			} elseif ( 'theme' === $resource_type ) {
				$this->init_theme_data();
				add_filter( 'pre_set_site_transient_update_themes', array( $this, 'theme_set_transient' ) );
				add_filter( 'themes_api', array( $this, 'resource_set_info' ), 10, 3 );
				add_filter( 'upgrader_source_selection', array( $this, 'theme_upgrader_source_selection' ), 10, 4 );
				add_filter( 'upgrader_post_install', array( $this, 'theme_post_install' ), 10, 3 );
			}
		}

		/**
		 * Get information regarding our plugin from WordPress
		 */
		private function init_plugin_data() {
			$this->slug = plugin_basename( $this->resource_path );
			$this->resource_data = get_plugin_data( $this->resource_path );
			$this->resource_data['ResourceURI'] = $this->resource_data['PluginURI'];

			$this->get_github_credentials_from_url( $this->resource_data['PluginURI'] );
		}

		/**
		 * Get information regarding our theme from WordPress
		 */
		private function init_theme_data() {
			$this->slug = basename( $this->resource_path );
			$theme = wp_get_theme( $this->slug );

			$this->resource_data = array();
			$this->resource_data['ThemeURI'] = esc_html( $theme->get( 'ThemeURI' ) );
			$this->resource_data['ResourceURI'] = $this->resource_data['ThemeURI'];
			$this->resource_data['Name'] = $theme->get( 'Name' );
			$this->resource_data['AuthorName'] = $theme->get( 'Author' );
			$this->resource_data['Description'] = $theme->get( 'Description' );

			$this->get_github_credentials_from_url( $this->resource_data['ThemeURI'] );
		}

		/**
		 * Parse url, if it's a github url get username and repository and store them.
		 *
		 * @param string $url Github url to parse.
		 */
		private function get_github_credentials_from_url( $url ) {
			$url_components = wp_parse_url( $url );
			if ( false !== $url_components ) {
				if ( 'github.com' === $url_components['host'] ) {
					$github_properties = explode( '/', trim( $url_components['path'], '/' ) );
					if ( false !== $github_properties && 2 === count( $github_properties ) ) {
						$this->username = $github_properties[0];
						$this->repo = $github_properties[1];
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Get information regarding our plugin or theme from GitHub
		 */
		private function get_repo_release_info() {
			// Only do this once.
			if ( ! empty( $this->github_api_result ) ) {
				return;
			}

			// Query the GitHub API.
			$url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases";

			// We need the access token for private repos.
			if ( ! empty( $this->access_token ) ) {
				$url = add_query_arg( array( 'access_token' => $this->access_token ), $url );
			}

			// Get the results.
			$this->github_api_result = wp_remote_retrieve_body( wp_remote_get( $url ) );
			if ( ! empty( $this->github_api_result ) ) {
				$this->github_api_result = @json_decode( $this->github_api_result );
			}

			// Use only the latest release.
			if ( is_array( $this->github_api_result ) && ! empty( $this->github_api_result ) ) {
				$this->github_api_result = $this->github_api_result[0];
			}
		}

		/**
		 * Determine what file to download for the plugin or theme from GitHub
		 * If there's a release asset, return its url, if not return source code.
		 *
		 * @return string $url The url of plugin or theme file to download.
		 */
		private function get_download_url() {
			// Get plugin & GitHub release information.
			// $this->init_plugin_data();.
			$this->get_repo_release_info();

			if ( isset( $this->github_api_result->assets ) && ! empty( $this->github_api_result->assets ) ) {
				$url = $this->github_api_result->assets[0]->browser_download_url;
			} else {
				$url = $this->github_api_result->zipball_url;
			}

			return $url;
		}

		/**
		 * Push in plugin version information to get the update notification
		 *
		 * @param object $transient Wordpress transient object.
		 */
		public function plugin_set_transient( $transient ) {
			// If we have checked the plugin data before, don't re-check.
			if ( ! isset( $transient->checked ) || ! isset( $transient->checked[ $this->slug ] ) ) {
				return $transient;
			}

			// Get plugin & GitHub release information.
			// $this->init_plugin_data();.
			$this->get_repo_release_info();

			// If tag name is empty, return.
			if ( ! isset( $this->github_api_result->tag_name ) ) {
				return $transient;
			}

			// Check the versions if we need to do an update.
			$do_update = version_compare( $this->github_api_result->tag_name, $transient->checked[ $this->slug ] );

			// Update the transient to include our updated plugin data.
			if ( $do_update ) {
				$package = $this->get_download_url();

				// Include the access token for private GitHub repos.
				if ( ! empty( $this->access_token ) ) {
					$package = add_query_arg( array( 'access_token' => $this->access_token ), $package );
				}

				$obj = new stdClass();
				$obj->slug = $this->slug;
				$obj->new_version = $this->github_api_result->tag_name;
				$obj->url = $this->resource_data['PluginURI'];
				$obj->package = $package;

				$transient->response[ $this->slug ] = $obj;
			}

			return $transient;
		}

		/**
		 * Push in theme version information to get the update notification
		 *
		 * @param object $transient Wordpress transient object.
		 */
		public function theme_set_transient( $transient ) {
			// If we have checked the plugin data before, don't re-check.
			if ( ! isset( $transient->checked ) || ! isset( $transient->checked[ $this->slug ] ) ) {
				return $transient;
			}

			// Get plugin & GitHub release information.
			// $this->init_plugin_data();.
			$this->get_repo_release_info();

			// If tag name is empty, return.
			if ( ! isset( $this->github_api_result->tag_name ) ) {
				return $transient;
			}

			// Check the versions if we need to do an update.
			$do_update = version_compare( $this->github_api_result->tag_name, $transient->checked[ $this->slug ] );

			// Update the transient to include our updated plugin data.
			if ( $do_update ) {
				$package = $this->get_download_url();

				// Include the access token for private GitHub repos.
				if ( ! empty( $this->access_token ) ) {
					$package = add_query_arg( array( 'access_token' => $this->access_token ), $package );
				}

				$theme_array = array();
				$theme_array['new_version'] = $this->github_api_result->tag_name;
				$theme_array['url'] = $this->resource_data['ThemeURI'];
				$theme_array['package'] = $package;

				$transient->response[ $this->slug ] = $theme_array;
			}

			return $transient;
		}

		/**
		 * Push in plugin or theme version information to display in the details lightbox
		 *
		 * @param false|object|array $result The result object or array. Default false.
		 * @param string             $action The type of information being requested from the Plugin or Theme Install API.
		 * @param object             $args   Plugin or theme API arguments.
		 */
		public function resource_set_info( $result, $action, $args ) {
			// Get plugin & GitHub release information.
			// $this->init_plugin_data();.
			$this->get_repo_release_info();

			// If nothing is found, do nothing.
			if ( empty( $args->slug ) || $args->slug != $this->slug ) {
				return $result;
			}

			// Add our plugin information.
			$args->last_updated = $this->github_api_result->published_at;
			$args->slug = $this->slug;
			$args->name  = $this->resource_data['Name'];
			$args->version = $this->github_api_result->tag_name;
			$args->author = $this->resource_data['AuthorName'];
			$args->homepage = $this->resource_data['ResourceURI'];

			// This is our release download zip file.
			$download_link = $this->get_download_url();

			// Include the access token for private GitHub repos.
			if ( ! empty( $this->access_token ) ) {
				$download_link = add_query_arg(
					array( 'access_token' => $this->access_token ),
					$download_link
				);
			}
			$args->download_link = $download_link;

			// We're going to parse the GitHub markdown release notes, instantiate the parser.
			$converter = new CommonMarkConverter();
			$changelog = $converter->convertToHtml( $this->github_api_result->body );

			// Create tabs in the lightbox.
			$args->sections = array(
				'description' => $this->resource_data['Description'],
				'changelog' => $changelog,
			);

			// Gets the required version of WP if available.
			$matches = null;
			preg_match( '/requires:\s([\d\.]+)/i', $this->github_api_result->body, $matches );
			if ( ! empty( $matches ) ) {
				if ( is_array( $matches ) ) {
					if ( count( $matches ) > 1 ) {
						$args->requires = $matches[1];
					}
				}
			}

			// Gets the tested version of WP if available.
			$matches = null;
			preg_match( '/tested:\s([\d\.]+)/i', $this->github_api_result->body, $matches );
			if ( ! empty( $matches ) ) {
				if ( is_array( $matches ) ) {
					if ( count( $matches ) > 1 ) {
						$args->tested = $matches[1];
					}
				}
			}

			return $args;
		}

		/**
		 * Perform check before installation starts.
		 *
		 * @param  bool|WP_Error $response   Response.
		 * @param  array         $hook_extra Extra arguments passed to hooked filters.
		 */
		public function plugin_pre_install( $response, $hook_extra ) {
			// Check if the plugin was installed before...
			$this->plugin_activated = is_plugin_active( $this->slug );
		}

		/**
		 * Rename the zip folder to be the same as the existing repository folder.
		 * This method needed for correct updating/re-activation of current, active theme only.
		 *
		 * @global object $wp_filesystem
		 *
		 * @param string $source        File source location.
		 * @param string $remote_source Remote file source location.
		 * @param object $upgrader      WP_Upgrader instance.
		 * @param array  $hook_extra    Extra update information, from WP version 4.4.
		 *
		 * @return string $source|$corrected_source
		 */
		public function theme_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = null ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			global $wp_filesystem;
			$source_base = basename( $source );
			$active_theme = wp_get_theme()->stylesheet;

			/*
			 * Check for upgrade process, return if not correct upgrader.
			 */
			if ( ! ( $upgrader instanceof \Theme_Upgrader ) ) {
				return $source;
			}

			/*
			 * Set source for updating only for current active theme.
			 */
			if ( $active_theme === $upgrader->skin->theme_info->stylesheet ) {
				$corrected_source = str_replace( $source_base, $active_theme, $source );
			} else {
				return $source;
			}

			$upgrader->skin->feedback(
				sprintf(
					esc_html__( 'Renaming %1$s to %2$s', 'github-updater' ) . '&#8230;',
					'<span class="code">' . $source_base . '</span>',
					'<span class="code">' . basename( $corrected_source ) . '</span>'
				)
			);

			/*
			 * If we can rename, do so and return the new name.
			 */
			if ( $wp_filesystem->move( $source, $corrected_source, true ) ) {
				$upgrader->skin->feedback( esc_html__( 'Rename successful', 'wp-github-updater' ) . '&#8230;' );
				return $corrected_source;
			}

			/*
			 * Otherwise, return an error.
			 */
			$upgrader->skin->feedback( esc_html__( 'Unable to rename downloaded repository.', 'wp-github-updater' ) );
			return new \WP_Error();
		}

		/**
		 * Perform additional actions to successfully install our plugin
		 *
		 * @param bool  $response   Install response.
		 * @param array $hook_extra Extra arguments passed to hooked filters.
		 * @param array $result     Installation result data.
		 */
		public function plugin_post_install( $response, $hook_extra, $result ) {
			global $wp_filesystem;

			if ( ! isset( $hook_extra['plugin'] ) || $this->slug !== $hook_extra['plugin'] ) {
				return $result;
			}

			if ( $this->slug !== $result['destination_name'] ) {
				$temp_destination_name = $result['destination_name'];
				$temp_destination = $result['destination'];
				$result['destination_name'] = str_replace( $temp_destination_name, $this->slug, $result['destination_name'] );
				$result['destination'] = str_replace( $temp_destination_name, $this->slug, $result['destination'] );
				$result['remote_destination'] = str_replace( $temp_destination_name, $this->slug, $result['remote_destination'] );
				$wp_filesystem->move( $temp_destination, $result['destination'] );
			}

			// Re-activate plugin if needed.
			if ( $this->plugin_activated ) {
				$activate = activate_plugin( $this->slug );
			}

			return $result;
		}

		/**
		 * Perform additional actions to successfully install our theme
		 *
		 * @param bool  $response   Install response.
		 * @param array $hook_extra Extra arguments passed to hooked filters.
		 * @param array $result     Installation result data.
		 */
		public function theme_post_install( $response, $hook_extra, $result ) {
			global $wp_filesystem;

			if ( ! isset( $hook_extra['theme'] ) || $this->slug !== $hook_extra['theme'] ) {
				return $result;
			}

			if ( $this->slug !== $result['destination_name'] ) {
				$temp_destination_name = $result['destination_name'];
				$temp_destination = $result['destination'];
				$result['destination_name'] = str_replace( $temp_destination_name, $this->slug, $result['destination_name'] );
				$result['destination'] = str_replace( $temp_destination_name, $this->slug, $result['destination'] );
				$result['remote_destination'] = str_replace( $temp_destination_name, $this->slug, $result['remote_destination'] );
				$wp_filesystem->move( $temp_destination, $result['destination'] );
			}

			return $result;
		}
	}

}
