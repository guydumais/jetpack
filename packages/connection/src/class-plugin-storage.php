<?php
/**
 * Storage for plugin connection information.
 *
 * @package automattic/jetpack-connection
 */

namespace Automattic\Jetpack\Connection;

use Automattic\Jetpack\Config;
use Jetpack_Options;
use WP_Error;

/**
 * The class serves a single purpose - to store the data that plugins use the connection, along with some auxiliary information.
 * Well, we don't really store all that. The information is provided on runtime,
 * so all we need to do is to save the data into the class property and retrieve it from there on demand.
 *
 * @todo Adapt for multisite installations.
 */
class Plugin_Storage {

	const ACTIVE_PLUGINS_OPTION_NAME = 'jetpack_connection_active_plugins';

	const DISCONNECTED_PLUGINS_OPTION_NAME = 'plugins_disconnected_user_initiated';

	/**
	 * Whether this class was configured for the first time or not.
	 *
	 * @var boolean
	 */
	private static $configured = false;

	/**
	 * Refresh list of connected plugins upon intialization.
	 *
	 * @var boolean
	 */
	private static $refresh_connected_plugins = false;

	/**
	 * Connected plugins.
	 *
	 * @var array
	 */
	private static $plugins = array();

	/**
	 * Add or update the plugin information in the storage.
	 *
	 * @param string $slug Plugin slug.
	 * @param array  $args Plugin arguments, optional.
	 *
	 * @return bool
	 */
	public static function upsert( $slug, array $args = array() ) {
		self::$plugins[ $slug ] = $args;

		// if plugin is not in the list of active plugins, refresh the list.
		if ( ! array_key_exists( $slug, get_option( self::ACTIVE_PLUGINS_OPTION_NAME, array() ) ) ) {
			self::$refresh_connected_plugins = true;
		}

		return true;
	}

	/**
	 * Retrieve the plugin information by slug.
	 * WARNING: the method cannot be called until Plugin_Storage::configure is called, which happens on plugins_loaded
	 * Even if you don't use Jetpack Config, it may be introduced later by other plugins,
	 * so please make sure not to run the method too early in the code.
	 *
	 * @param string $slug The plugin slug.
	 *
	 * @return array|null|WP_Error
	 */
	public static function get_one( $slug ) {
		$plugins = self::get_all();

		if ( $plugins instanceof WP_Error ) {
			return $plugins;
		}

		return empty( $plugins[ $slug ] ) ? null : $plugins[ $slug ];
	}

	/**
	 * Retrieve info for all plugins that use the connection.
	 * WARNING: the method cannot be called until Plugin_Storage::configure is called, which happens on plugins_loaded
	 * Even if you don't use Jetpack Config, it may be introduced later by other plugins,
	 * so please make sure not to run the method too early in the code.
	 *
	 * @param bool $connected_only Exclude plugins that were explicitly disconnected.
	 *
	 * @return array|WP_Error
	 */
	public static function get_all( $connected_only = false ) {
		$maybe_error = self::ensure_configured();

		if ( $maybe_error instanceof WP_Error ) {
			return $maybe_error;
		}

		return $connected_only ? array_diff_key( self::$plugins, array_flip( self::get_all_disconnected_user_initiated() ) ) : self::$plugins;
	}

	/**
	 * Remove the plugin connection info from Jetpack.
	 * WARNING: the method cannot be called until Plugin_Storage::configure is called, which happens on plugins_loaded
	 * Even if you don't use Jetpack Config, it may be introduced later by other plugins,
	 * so please make sure not to run the method too early in the code.
	 *
	 * @param string $slug The plugin slug.
	 *
	 * @return bool|WP_Error
	 */
	public static function delete( $slug ) {
		$maybe_error = self::ensure_configured();

		if ( $maybe_error instanceof WP_Error ) {
			return $maybe_error;
		}

		if ( array_key_exists( $slug, self::$plugins ) ) {
			unset( self::$plugins[ $slug ] );
		}

		return true;
	}

	/**
	 * The method makes sure that `Jetpack\Config` has finished, and it's now safe to retrieve the list of plugins.
	 *
	 * @return bool|WP_Error
	 */
	private static function ensure_configured() {
		if ( ! self::$configured ) {
			return new WP_Error( 'too_early', __( 'You cannot call this method until Jetpack Config is configured', 'jetpack' ) );
		}

		return true;
	}

	/**
	 * Called once to configure this class after plugins_loaded.
	 *
	 * @return void
	 */
	public static function configure() {

		if ( self::$configured ) {
			return;
		}

		// If a plugin was activated or deactivated.
		$number_of_plugins_differ = count( self::$plugins ) !== count( get_option( self::ACTIVE_PLUGINS_OPTION_NAME, array() ) );

		if ( $number_of_plugins_differ || true === self::$refresh_connected_plugins ) {
			self::update_active_plugins_option();
		}

		self::$configured = true;

	}

	/**
	 * Updates the active plugins option with current list of active plugins.
	 *
	 * @return void
	 */
	public static function update_active_plugins_option() {
		// Note: Since this options is synced to wpcom, if you change its structure, you have to update the sanitizer at wpcom side.
		update_option( self::ACTIVE_PLUGINS_OPTION_NAME, self::$plugins );
	}

	/**
	 * Add the plugin to the set of disconnected ones.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return bool
	 */
	public static function disconnect_user_initiated( $slug ) {
		$disconnects = self::get_all_disconnected_user_initiated();

		if ( ! in_array( $slug, $disconnects, true ) ) {
			$disconnects[] = $slug;
			Jetpack_Options::update_option( self::DISCONNECTED_PLUGINS_OPTION_NAME, $disconnects );
		}

		return true;
	}

	/**
	 * Remove the plugin from the set of disconnected ones.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return bool
	 */
	public static function reconnect_user_initiated( $slug ) {
		$disconnects = self::get_all_disconnected_user_initiated();

		$slug_index = array_search( $slug, $disconnects, true );
		if ( false !== $slug_index ) {
			unset( $disconnects[ $slug_index ] );
			Jetpack_Options::update_option( self::DISCONNECTED_PLUGINS_OPTION_NAME, $disconnects );
		}

		return true;
	}

	/**
	 * Get all plugins that were disconnected by user.
	 *
	 * @return array
	 */
	public static function get_all_disconnected_user_initiated() {
		return Jetpack_Options::get_option( self::DISCONNECTED_PLUGINS_OPTION_NAME, array() );
	}

}
