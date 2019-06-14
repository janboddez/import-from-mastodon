<?php
/**
 * Main plugin class.
 *
 * @package Import_From_Mastodon
 */

namespace Import_From_Mastodon;

/**
 * Main plugin class.
 */
class Import_From_Mastodon {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );

		register_activation_hook( dirname( dirname( __FILE__ ) ) . '/import-from-mastodon.php', array( $this, 'activate' ) );
		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/import-from-mastodon.php', array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Enable polling Mastodon for toots.
		new Mastodon_Handler();
	}

	/**
	 * Define a new WP Cron interval.
	 *
	 * @param array $schedules WP Cron schedules.
	 */
	public function add_cron_schedule( $schedules ) {
		$schedules['every_15_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Once every 15 minutes', 'import-from-mastodon' ),
		);

		return $schedules;
	}

	/**
	 * Schedules the Mastodon API call.
	 */
	public function activate() {
		if ( false === wp_next_scheduled( 'import_from_mastodon_get_toots' ) ) {
			wp_schedule_event( time() + 3600, 'every_15_minutes', 'import_from_mastodon_get_toots' );
		}
	}

	/**
	 * Unschedules any cron jobs.
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'import_from_mastodon_get_toots' );
	}

	/**
	 * Enables localization.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'import-from-mastodon', false, basename( dirname( dirname( __FILE__ ) ) ) . '/languages' );
	}
}
