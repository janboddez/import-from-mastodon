<?php
/**
 * Plugin Name: Import from Mastodon
 * Description: Import Mastodon statuses into WordPress.
 * Author:      Jan Boddez
 * Author URI:  https://jan.boddez.net/
 * License:     GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: import-from-mastodon
 * Version:     0.3.1
 *
 * @package Import_From_Mastodon
 */

namespace Import_From_Mastodon;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-import-from-mastodon.php';
require_once dirname( __FILE__ ) . '/includes/class-import-handler.php';
require_once dirname( __FILE__ ) . '/includes/class-options-handler.php';

$import_from_mastodon = Import_From_Mastodon::get_instance();
$import_from_mastodon->register();
