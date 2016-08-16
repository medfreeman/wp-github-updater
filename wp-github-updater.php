<?php
/**
Plugin Name: Github updater
Plugin URI: https://github.com/medfreeman/wp-github-updater
Description: Enables automatic updates of plugins and themes from github
Version: 1.0.0
Author: Mehdi Lahlou
Author URI: https://github.com/medfreeman
Author Email: mehdi.lahlou@free.fr
License: GPLv3

@package wp-github-updater

	Copyright 2014-2016 Mehdi Lahlou (mehdi.lahlou@free.fr)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Main class.
 */
class WpGithubUpdater {

	/**
	 * Constructor
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'handle_github_update' ) );
	}

	/**
	 * Handles this plugin's github update.
	 */
	function handle_github_update() {
		new GitHubUpdater( 'plugin', __FILE__ );
	}
}

include_once( 'github-updater.class.php' );

$wp_github_updater = new WpGithubUpdater();
