<?php
/**
 * Handles admin pages and the like.
 *
 * @package Import_From_Mastodon
 */

namespace Import_From_Mastodon;

/**
 * Options handler.
 */
class Options_Handler {
	/**
	 * Default plugin settings.
	 */
	const DEFAULT_SETTINGS = array(
		'mastodon_host'          => '',
		'mastodon_client_id'     => '',
		'mastodon_client_secret' => '',
		'mastodon_access_token'  => '',
		'post_status'            => 'publish',
		'post_format'            => '',
		'include_replies'        => false,
		'include_replies'        => false,
		'tags'                   => '',
		'denylist'               => '',
		'post_author'            => 0,
		'post_category'          => 0,
		'public_only'            => true,
	);

	/**
	 * Allowable post statuses.
	 *
	 * @var array POST_STATUSES Allowable post statuses.
	 */
	const POST_STATUSES = array(
		'publish',
		'draft',
		'pending',
		'private',
	);

	/**
	 * This plugins settings.
	 *
	 * @var array Plugin settings.
	 */
	private $options = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->options = get_option(
			'import_from_mastodon_settings',
			self::DEFAULT_SETTINGS
		);
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_import_from_mastodon', array( $this, 'admin_post' ) );

		add_action( 'import_from_mastodon_after_import', array( $this, 'set_latest_toot' ), 10, 2 );
	}

	/**
	 * Registers the plugin settings page.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Import From Mastodon', 'import-from-mastodon' ),
			__( 'Import From Mastodon', 'import-from-mastodon' ),
			'manage_options',
			'import-from-mastodon',
			array( $this, 'settings_page' )
		);
		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the actual options.
	 */
	public function add_settings() {
		register_setting(
			'import-from-mastodon-settings-group',
			'import_from_mastodon_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Handles submitted options.
	 *
	 * @param  array $settings Settings as submitted through WP Admin.
	 * @return array           Options to be stored.
	 */
	public function sanitize_settings( $settings ) {
		if ( isset( $settings['mastodon_host'] ) ) {
			$mastodon_host = untrailingslashit( trim( $settings['mastodon_host'] ) );

			if ( '' === $mastodon_host ) {
				// Removing the instance URL. Might be done to temporarily
				// disable crossposting. Let's not revoke access just yet.
				$this->options['mastodon_host'] = '';
			} else {
				if ( 0 !== strpos( $mastodon_host, 'https://' ) && 0 !== strpos( $mastodon_host, 'http://' ) ) {
					// Missing protocol. Try adding `https://`.
					$mastodon_host = 'https://' . $mastodon_host;
				}

				if ( wp_http_validate_url( $mastodon_host ) ) {
					if ( $mastodon_host !== $this->options['mastodon_host'] ) {
						// Updated URL.

						// (Try to) revoke access. Forget token regardless of the
						// outcome.
						$this->revoke_access();

						// Forget latest toot ID.
						$this->forget_latest_toot();

						// Then, save the new URL.
						$this->options['mastodon_host'] = untrailingslashit( $mastodon_host );

						// Forget client ID and secret. A new client ID and secret will
						// be requested next time the page is loaded.
						$this->options['mastodon_client_id']     = '';
						$this->options['mastodon_client_secret'] = '';
					}
				} else {
					// Invalid URL. Display error message.
					add_settings_error(
						'import-from-mastodon-mastodon-host',
						'invalid-url',
						esc_html__( 'Please provide a valid URL.', 'import-from-mastodon' )
					);
				}
			}
		}

		if ( isset( $settings['post_status'] ) && in_array( $settings['post_status'], self::POST_STATUSES, true ) ) {
			$this->options['post_status'] = $settings['post_status'];
		}

		if ( isset( $settings['post_category'] ) && term_exists( (int) $settings['post_category'], 'category' ) ) {
			$this->options['post_category'] = (int) $settings['post_category'];
		}

		if ( isset( $settings['post_author'] ) && false !== get_userdata( (int) $settings['post_author'] ) ) {
			$this->options['post_author'] = (int) $settings['post_author'];
		}

		// These can be either `"1"` or `true`.
		$this->options['include_reblogs'] = ! empty( $settings['include_reblogs'] );
		$this->options['include_replies'] = ! empty( $settings['include_replies'] );
		$this->options['public_only']     = ! empty( $settings['public_only'] );

		// Sanitizing text(area) fields is tricky, especially when data needs to
		// be kept intact. Anyhow, let's see how this works out.
		if ( isset( $settings['tags'] ) ) {
			$tags                  = preg_replace( '~,\s+~', ',', sanitize_text_field( $settings['tags'] ) );
			$this->options['tags'] = str_replace( '#', '', $tags );
		}

		if ( isset( $settings['denylist'] ) ) {
			// Normalize line endings.
			$denylist                  = preg_replace( '~\R~u', "\r\n", $settings['denylist'] );
			$this->options['denylist'] = trim( $denylist );
		}

		// Updated settings.
		return $this->options;
	}

	/**
	 * Echoes the plugin options form. Handles the OAuth flow, too, for now.
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import From Mastodon', 'import-from-mastodon' ); ?></h1>

			<h2><?php esc_html_e( 'Settings', 'import-from-mastodon' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				// Print nonces and such.
				settings_fields( 'import-from-mastodon-settings-group' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="import_from_mastodon_settings[mastodon_host]"><?php esc_html_e( 'Instance', 'import-from-mastodon' ); ?></label></th>
						<td><input type="url" id="import_from_mastodon_settings[mastodon_host]" name="import_from_mastodon_settings[mastodon_host]" style="min-width: 40%;" value="<?php echo esc_attr( $this->options['mastodon_host'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Your Mastodon instance&rsquo;s URL.', 'import-from-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Post Status', 'import-from-mastodon' ); ?></th>
						<td><ul style="list-style: none; margin-top: 4px;">
							<?php
							foreach ( self::POST_STATUSES as $post_status ) :
								?>
								<li><label><input type="radio" name="import_from_mastodon_settings[post_status]" value="<?php echo esc_attr( $post_status ); ?>" <?php checked( $post_status, $this->options['post_status'] ); ?>> <?php echo esc_html( ucfirst( $post_status ) ); ?></label></li>
								<?php
							endforeach;
							?>
						</ul>
						<p class="description"><?php esc_html_e( 'Post status for newly imported statuses.', 'import-from-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><label for="import_from_mastodon_settings[post_author]"><?php esc_html_e( 'Post Author', 'import-from-pixelfed' ); ?></label></th>
						<td>
							<?php
							$user = isset( $this->options['post_author'] ) ? get_userdata( $this->options['post_author'] ) : null;

							wp_dropdown_users(
								array(
									'show_option_none' => esc_attr__( 'Select author', 'import-from-pixelfed' ),
									'id'               => 'import_from_mastodon_settings[post_author]',
									'name'             => 'import_from_mastodon_settings[post_author]',
									'selected'         => ! empty( $user->ID ) ? $user->ID : -1, // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInTernaryCondition,Squiz.PHP.DisallowMultipleAssignments.Found
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The author for newly imported statuses.', 'import-from-pixelfed' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><label for="import_from_mastodon_settings[post_category]"><?php esc_html_e( 'Default Category', 'import-from-pixelfed' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'show_option_none' => esc_attr__( 'Select category', 'import-from-pixelfed' ),
									'id'               => 'import_from_mastodon_settings[post_category]',
									'name'             => 'import_from_mastodon_settings[post_category]',
									'selected'         => term_exists( $this->options['post_category'], 'category' ) ? $this->options['post_category'] : -1,
									'hide_empty'       => 0,
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Default category for newly imported toots.', 'import-from-pixelfed' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Public Only', 'import-from-mastodon' ); ?></th>
						<td><label><input type="checkbox" id="import_from_mastodon_settings[public_only]" name="import_from_mastodon_settings[public_only]" value="1" <?php checked( ! empty( $this->options['public_only'] ) ); ?>/> <?php esc_html_e( 'Public only?' ); ?></label>
						<p class="description"><?php esc_html_e( 'Import public toots only?', 'import-from-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Boosts', 'import-from-mastodon' ); ?></th>
						<td><label><input type="checkbox" id="import_from_mastodon_settings[include_reblogs]" name="import_from_mastodon_settings[include_reblogs]" value="1" <?php checked( ! empty( $this->options['include_reblogs'] ) ); ?>/> <?php esc_html_e( 'Include boosts?' ); ?></label>
						<p class="description"><?php esc_html_e( 'Import boosts (&ldquo;reblogs&rdquo;), too?', 'import-from-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Replies', 'import-from-mastodon' ); ?></th>
						<td><label><input type="checkbox" id="import_from_mastodon_settings[include_replies]" name="import_from_mastodon_settings[include_replies]" value="1" <?php checked( ! empty( $this->options['include_replies'] ) ); ?>/> <?php esc_html_e( 'Include replies?' ); ?></label>
						<p class="description"><?php esc_html_e( 'Import replies, too?', 'import-from-mastodon' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><label for="import_from_mastodon_settings[tags]"><?php esc_html_e( 'Tags', 'import-from-mastodon' ); ?></label></th>
						<td><input type="text" id="import_from_mastodon_settings[tags]" name="import_from_mastodon_settings[tags]" style="min-width: 40%;" value="<?php echo esc_attr( $this->options['tags'] ); ?>" />
						<p class="description"><?php _e( 'Import only statuses with <strong>any</strong> of these (comma-separated) tags. (Leave blank to import all statuses.)', 'import-from-mastodon' ); // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><label for="import_from_mastodon_settings[denylist]"><?php esc_html_e( 'Blocklist', 'import-from-mastodon' ); ?></label></th>
						<td><textarea id="import_from_mastodon_settings[denylist]" name="import_from_mastodon_settings[denylist]" style="min-width: 40%;" rows="5"><?php echo esc_html( $this->options['denylist'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Ignore statuses with these words (case-insensitive).', 'import-from-mastodon' ); ?></p></td>
					</tr>
				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Authorize Access', 'import-from-mastodon' ); ?></h2>
			<?php
			if ( ! empty( $this->options['mastodon_host'] ) ) {
				// A valid instance URL was set.
				if ( empty( $this->options['mastodon_client_id'] ) || empty( $this->options['mastodon_client_secret'] ) ) {
					// No app is currently registered. Let's try to fix that!
					$this->register_app();
				}

				if ( ! empty( $this->options['mastodon_client_id'] ) && ! empty( $this->options['mastodon_client_secret'] ) ) {
					// An app was successfully registered.
					if ( ! empty( $_GET['code'] ) && empty( $this->options['mastodon_access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						// Access token request.
						if ( $this->request_access_token( wp_unslash( $_GET['code'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
							?>
							<div class="notice notice-success is-dismissible">
								<p><?php esc_html_e( 'Access granted!', 'import-from-mastodon' ); ?></p>
							</div>
							<?php
						}
					}

					if ( empty( $this->options['mastodon_access_token'] ) ) {
						// No access token exists. Echo authorization link.
						$url = $this->options['mastodon_host'] . '/oauth/authorize?' . http_build_query(
							array(
								'response_type' => 'code',
								'client_id'     => $this->options['mastodon_client_id'],
								'client_secret' => $this->options['mastodon_client_secret'],
								'redirect_uri'  => add_query_arg(
									array(
										'page' => 'import-from-mastodon', // Redirect here after authorization.
									),
									admin_url( 'options-general.php' )
								),
								'scope'         => 'read:statuses read:accounts',
							)
						);
						?>
						<p><?php esc_html_e( 'Authorize WordPress to read from your Mastodon timeline. Nothing will ever be posted there.', 'import-from-mastodon' ); ?></p>
						<p style="margin-bottom: 2rem;"><?php printf( '<a href="%1$s" class="button">%2$s</a>', esc_url( $url ), esc_html__( 'Authorize Access', 'import-from-mastodon' ) ); ?>
						<?php
					} else {
						// An access token exists.
						?>
						<p><?php esc_html_e( 'You&rsquo;ve authorized WordPress to read from your Mastodon timeline.', 'import-from-mastodon' ); ?></p>
						<p style="margin-bottom: 2rem;">
							<?php
							printf(
								'<a href="%1$s" class="button">%2$s</a>',
								esc_url(
									add_query_arg(
										array(
											'action'   => 'import_from_mastodon',
											'revoke'   => 'true',
											'_wpnonce' => wp_create_nonce( 'import-from-mastodon-revoke' ),
										),
										admin_url( 'admin-post.php' )
									)
								),
								esc_html__( 'Revoke Access', 'import-from-mastodon' )
							);
							?>
						</p>
						<?php
					}
				} else {
					// Still couldn't register our app.
					?>
					<p><?php esc_html_e( 'Something went wrong contacting your Mastodon instance. Please reload this page to try again.', 'import-from-mastodon' ); ?></p>
					<?php
				}
			} else {
				// We can't do much without an instance URL.
				?>
				<p><?php esc_html_e( 'Please fill out and save your Mastodon instance&rsquo;s URL first.', 'import-from-mastodon' ); ?></p>
				<p style="margin-bottom: 2rem;"><?php printf( '<button class="button" disabled="disabled">%s</button>', esc_html__( 'Authorize Access', 'import-from-mastodon' ) ); ?>
				<?php
			}
			?>

			<h2><?php esc_html_e( 'Debugging', 'import-from-mastodon' ); ?></h2>
			<p><?php esc_html_e( 'Just in case, the button below lets you delete Import From Mastodon&rsquo;s settings. Note: This will not invalidate previously issued tokens! (You can, however, still invalidate them on your instance&rsquo;s &ldquo;Account &gt; Authorized apps&rdquo; page.)', 'import-from-mastodon' ); ?></p>
			<p style="margin-bottom: 2rem;">
				<?php
				printf(
					'<a href="%1$s" class="button button-reset-settings" style="color: #a00; border-color: #a00;">%2$s</a>',
					esc_url(
						add_query_arg(
							array(
								'action'   => 'import_from_mastodon',
								'reset'    => 'true',
								'_wpnonce' => wp_create_nonce( 'import-from-mastodon-reset' ),
							),
							admin_url( 'admin-post.php' )
						)
					),
					esc_html__( 'Reset Settings', 'import-from-mastodon' )
				);
				?>
			</p>
			<?php
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				?>
				<p><?php esc_html_e( 'The information below is not meant to be shared with anyone but may help when troubleshooting issues.', 'import-from-mastodon' ); ?></p>
				<p><textarea class="widefat" rows="5"><?php var_export( $this->options ); ?></textarea></p><?php // phpcs:ignore WordPress.PHP.DevelopmentFunctions ?>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Loads (admin) scripts.
	 *
	 * @param string $hook_suffix Current WP-Admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_import-from-mastodon' !== $hook_suffix ) {
			// Not the "Import From Mastodon" settings page.
			return;
		}

		// Enqueue JS.
		wp_enqueue_script( 'import-from-mastodon', plugins_url( '/assets/import-from-mastodon.js', dirname( __FILE__ ) ), array( 'jquery' ), '0.6.1', true );

		wp_localize_script(
			'import-from-mastodon',
			'import_from_mastodon_obj',
			array( 'message' => esc_attr__( 'Are you sure you want to reset all settings?', 'import-from-mastodon' ) ) // Confirmation message.
		);
	}

	/**
	 * Registers a new Mastodon app (client).
	 */
	private function register_app() {
		// Register a new app. Should probably only run once (per host).
		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] ) . '/api/v1/apps',
			array(
				'body' => array(
					'client_name'   => __( 'Import From Mastodon' ),
					'redirect_uris' => add_query_arg(
						array(
							'page' => 'import-from-mastodon',
						),
						admin_url(
							'options-general.php'
						)
					), // Allowed redirect URLs.
					'scopes'        => 'read:accounts read:statuses',
					'website'       => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Import From Mastodon] Could not register app: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$app = json_decode( $response['body'] );

		if ( isset( $app->client_id ) && isset( $app->client_secret ) ) {
			// After successfully registering the App, store its keys.
			$this->options['mastodon_client_id']     = $app->client_id;
			$this->options['mastodon_client_secret'] = $app->client_secret;
			update_option( 'import_from_mastodon_settings', $this->options );
		} else {
			// Log the entire response, for now.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
	}

	/**
	 * Requests a new access token.
	 *
	 * @param string $code Authorization code.
	 */
	private function request_access_token( $code ) {
		// Request an access token.
		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] ) . '/oauth/token',
			array(
				'body' => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => add_query_arg(
						array(
							'page' => 'import-from-mastodon',
						),
						admin_url( 'options-general.php' )
					), // Redirect here after authorization.
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Import From Mastodon] Access token request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$token = json_decode( $response['body'] );

		if ( isset( $token->access_token ) ) {
			// Success. Store access token.
			$this->options['mastodon_access_token'] = $token->access_token;

			update_option( 'import_from_mastodon_settings', $this->options );

			return true;
		} else {
			// Log the entire response, for now.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		return false;
	}

	/**
	 * Revokes WordPress's access to Mastodon.
	 *
	 * @return boolean Whether access was revoked.
	 */
	private function revoke_access() {
		if ( empty( $this->options['mastodon_host'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_client_id'] ) ) {
			return false;
		}

		if ( empty( $this->options['mastodon_client_secret'] ) ) {
			return false;
		}

		// Revoke access.
		$response = wp_remote_post(
			esc_url_raw( $this->options['mastodon_host'] . '/oauth/revoke' ),
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'client_id'     => $this->options['mastodon_client_id'],
					'client_secret' => $this->options['mastodon_client_secret'],
					'token'         => $this->options['mastodon_access_token'],
				),
				'timeout' => 11,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Import From Mastodon] Revoking access token failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Log the entire response, for now.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			return false;
		}

		unset( $this->options['mastodon_access_token'] );
		update_option( 'import_from_mastodon_settings', $this->options );

		error_log( '[Import From Mastodon] Access token revoked!' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return true;
	}

	/**
	 * Resets all plugin options.
	 */
	private function reset_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$this->options = self::DEFAULT_SETTINGS;

		update_option( 'import_from_mastodon_settings', $this->options );
	}

	/**
	 * `admin-post.php` callback.
	 */
	public function admin_post() {
		if ( isset( $_GET['revoke'] ) && 'true' === $_GET['revoke']
		  && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'import-from-mastodon-revoke' ) ) {
			// Revoke access. Forget access token regardless of the
			// outcome.
			$this->revoke_access();
		}

		if ( isset( $_GET['reset'] ) && 'true' === $_GET['reset']
		  && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'import-from-mastodon-reset' ) ) {
			// Reset all of this plugin's settings.
			$this->reset_options();
		}

		wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect
			esc_url_raw(
				add_query_arg(
					array(
						'page' => 'import-from-mastodon',
					),
					admin_url( 'options-general.php' )
				)
			)
		);
		exit;
	}

	/**
	 * Updates the most recently imported toot ID.
	 *
	 * @param int    $post_id ID of the most recently created post.
	 * @param stdObj $status  Corresponding Mastodon status.
	 */
	public function set_latest_toot( $post_id, $status ) {
		// Add (or update) latest toot ID.
		$this->options['latest_toot'] = $status->id;
		update_option( 'import_from_mastodon_settings', $this->options, false );
	}

	/**
	 * Forgets the most recently imported toot ID.
	 */
	public function forget_latest_toot() {
		unset( $this->options['latest_toot'] );
		update_option( 'import_from_mastodon_settings', $this->options, false );
	}

	/**
	 * Returns the plugin options.
	 *
	 * @return array Plugin options.
	 */
	public function get_options() {
		return $this->options;
	}
}
