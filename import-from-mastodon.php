<?php
/**
 * Plugin Name: Toots to Posts
 * Description: Pull toots off Mastodon and into WordPress.
 * Author: Jan Boddez
 * Author URI: https://janboddez.tech/
 * License: GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: import-from-mastodon
 * Version: 0.1
 *
 * @package Import_From_Mastodon
 */

namespace Import_From_Mastodon;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-mastodon-handler.php';
require_once dirname( __FILE__ ) . '/includes/class-import-from-mastodon.php';

new Import_From_Mastodon();
