<?php
/**
 * Plugin Name: STW Dashboard Stats Gateway
 * Description: Exposes WordPress editorial, MailPoet Premium, rasa.io, and Advanced Ads statistics for the publisher analytics dashboard.
 * Version: 0.1.0
 * Author: STW
 * Text Domain: stw-dashboard-mailing-stats
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class STW_Dashboard_Mailing_Stats {
	const OPTION_NAME = 'stw_dashboard_mailing_stats_options';
	const REST_NAMESPACE = 'stw-dashboard/v1';
	const CACHE_VERSION = '2026-07-15-editorial-v1';

	private $rasa_debug = array();

	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'admin_menu' ) );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
		add_action( 'rest_api_init', array( $instance, 'register_routes' ) );
	}

	public static function activate() {
		$options = get_option( self::OPTION_NAME );
		if ( ! is_array( $options ) ) {
			add_option(
				self::OPTION_NAME,
				array(
					'dashboard_api_key_hash' => '',
					'rasa_username'          => '',
					'rasa_password'          => '',
					'rasa_api_key'           => '',
					'rasa_base_url'          => 'https://api.rasa.io/v1',
					'cache_ttl'              => 600,
				),
				'',
				false
			);
		}
	}

	public function admin_menu() {
		add_options_page(
			__( 'Dashboard Stats Gateway', 'stw-dashboard-mailing-stats' ),
			__( 'Dashboard Stats Gateway', 'stw-dashboard-mailing-stats' ),
			'manage_options',
			'stw-dashboard-mailing-stats',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'stw_dashboard_mailing_stats',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'stw_dashboard_mailing_stats_access',
			__( 'Access', 'stw-dashboard-mailing-stats' ),
			'__return_false',
			'stw-dashboard-mailing-stats'
		);

		add_settings_field( 'dashboard_api_key', __( 'Dashboard API key', 'stw-dashboard-mailing-stats' ), array( $this, 'field_dashboard_api_key' ), 'stw-dashboard-mailing-stats', 'stw_dashboard_mailing_stats_access' );
		add_settings_field( 'cache_ttl', __( 'Cache TTL seconds', 'stw-dashboard-mailing-stats' ), array( $this, 'field_cache_ttl' ), 'stw-dashboard-mailing-stats', 'stw_dashboard_mailing_stats_access' );

		add_settings_section(
			'stw_dashboard_mailing_stats_rasa',
			__( 'rasa.io v1 credentials', 'stw-dashboard-mailing-stats' ),
			'__return_false',
			'stw-dashboard-mailing-stats'
		);

		add_settings_field( 'rasa_username', __( 'User ID / email', 'stw-dashboard-mailing-stats' ), array( $this, 'field_rasa_username' ), 'stw-dashboard-mailing-stats', 'stw_dashboard_mailing_stats_rasa' );
		add_settings_field( 'rasa_password', __( 'Password', 'stw-dashboard-mailing-stats' ), array( $this, 'field_rasa_password' ), 'stw-dashboard-mailing-stats', 'stw_dashboard_mailing_stats_rasa' );
		add_settings_field( 'rasa_api_key', __( 'API key', 'stw-dashboard-mailing-stats' ), array( $this, 'field_rasa_api_key' ), 'stw-dashboard-mailing-stats', 'stw_dashboard_mailing_stats_rasa' );
		add_settings_field( 'rasa_base_url', __( 'API base URL', 'stw-dashboard-mailing-stats' ), array( $this, 'field_rasa_base_url' ), 'stw-dashboard-mailing-stats', 'stw_dashboard_mailing_stats_rasa' );
	}

	public function sanitize_options( $input ) {
		$existing = $this->options();
		$input    = is_array( $input ) ? $input : array();

		$api_key = isset( $input['dashboard_api_key'] ) ? sanitize_text_field( wp_unslash( $input['dashboard_api_key'] ) ) : '';
		$password = isset( $input['rasa_password'] ) ? (string) wp_unslash( $input['rasa_password'] ) : '';

		return array(
			'dashboard_api_key_hash' => '' !== $api_key ? wp_hash_password( $api_key ) : $this->option( 'dashboard_api_key_hash', '' ),
			'rasa_username'          => isset( $input['rasa_username'] ) ? sanitize_text_field( wp_unslash( $input['rasa_username'] ) ) : $this->option( 'rasa_username', '' ),
			'rasa_password'          => '' !== $password ? $this->encrypt_secret( $password ) : $this->option( 'rasa_password', '' ),
			'rasa_api_key'           => isset( $input['rasa_api_key'] ) && '' !== (string) $input['rasa_api_key'] ? $this->encrypt_secret( sanitize_text_field( wp_unslash( $input['rasa_api_key'] ) ) ) : $this->option( 'rasa_api_key', '' ),
			'rasa_base_url'          => isset( $input['rasa_base_url'] ) ? esc_url_raw( wp_unslash( $input['rasa_base_url'] ) ) : $this->option( 'rasa_base_url', 'https://api.rasa.io/v1' ),
			'cache_ttl'              => max( 60, min( 3600, absint( $input['cache_ttl'] ?? $this->option( 'cache_ttl', 600 ) ) ) ),
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage mailing stats settings.', 'stw-dashboard-mailing-stats' ) );
		}
		$api_key_configured = '' !== $this->dashboard_api_key_constant() || '' !== $this->dashboard_api_key_hash();
		$rasa_username     = $this->credential_status( 'rasa_username', 'username', 'STW_RASA_USERNAME' );
		$rasa_password     = $this->credential_status( 'rasa_password', 'password', 'STW_RASA_PASSWORD' );
		$rasa_api_key      = $this->credential_status( 'rasa_api_key', 'api_key', 'STW_RASA_API_KEY' );
		?>
		<div class="wrap">
			<style>
				.stw-dashboard-settings { max-width: 1120px; }
				.stw-dashboard-hero { margin: 18px 0 22px; padding: 20px 22px; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / 4%); }
				.stw-dashboard-hero p { margin: 7px 0 0; max-width: 860px; color: #50575e; font-size: 14px; }
				.stw-dashboard-status-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0 0 20px; }
				.stw-dashboard-status-card { padding: 14px 15px; border: 1px solid #dcdcde; background: #fff; border-radius: 4px; }
				.stw-dashboard-status-label { display: block; color: #646970; font-size: 12px; font-weight: 600; text-transform: uppercase; }
				.stw-dashboard-status-value { display: flex; align-items: center; gap: 8px; margin-top: 8px; color: #1d2327; font-size: 14px; font-weight: 700; }
				.stw-dashboard-dot { width: 9px; height: 9px; border-radius: 999px; background: #d63638; }
				.stw-dashboard-dot.is-ready { background: #00a32a; }
				.stw-dashboard-source-note { margin-top: 6px; color: #646970; font-size: 12px; }
				.stw-dashboard-key-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
				.stw-dashboard-generated-token { max-width: 700px; margin-top: 9px; padding: 12px; border: 1px solid #c3c4c7; background: #f6f7f7; border-radius: 4px; }
				.stw-dashboard-field-note { margin: 6px 0 0; color: #646970; }
				.stw-dashboard-field-note strong { color: #1d2327; }
				.stw-dashboard-secret-state { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
				.stw-dashboard-secret-pill { display: inline-flex; align-items: center; gap: 7px; min-height: 34px; padding: 0 12px; border: 1px solid #8c8f94; border-radius: 4px; background: #fff; color: #1d2327; font-weight: 600; }
				.stw-dashboard-secret-pill::before { content: ""; width: 8px; height: 8px; border-radius: 999px; background: #00a32a; }
				.stw-dashboard-replace-wrap { margin-top: 8px; }
				@media (max-width: 960px) { .stw-dashboard-status-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
				@media (max-width: 600px) { .stw-dashboard-status-grid { grid-template-columns: 1fr; } }
			</style>
			<div class="stw-dashboard-settings">
			<h1><?php echo esc_html__( 'Dashboard Stats Gateway', 'stw-dashboard-mailing-stats' ); ?></h1>
			<div class="stw-dashboard-hero">
				<strong><?php echo esc_html__( 'Publisher dashboard API gateway', 'stw-dashboard-mailing-stats' ); ?></strong>
				<p><?php echo esc_html__( 'Endpoints: /wp-json/stw-dashboard/v1/stats, /editorial/posts, /mailing/stats, and /ads/{summary,timeseries,top,table}', 'stw-dashboard-mailing-stats' ); ?></p>
			</div>
			<div class="stw-dashboard-status-grid">
				<?php $this->render_status_card( __( 'Dashboard token', 'stw-dashboard-mailing-stats' ), $api_key_configured, $api_key_configured ? __( 'Configured', 'stw-dashboard-mailing-stats' ) : __( 'Missing', 'stw-dashboard-mailing-stats' ), '' !== $this->dashboard_api_key_constant() ? __( 'Using wp-config constant', 'stw-dashboard-mailing-stats' ) : __( 'Using saved setting when configured', 'stw-dashboard-mailing-stats' ) ); ?>
				<?php $this->render_status_card( __( 'Rasa username', 'stw-dashboard-mailing-stats' ), $rasa_username['configured'], $rasa_username['configured'] ? __( 'Configured', 'stw-dashboard-mailing-stats' ) : __( 'Missing', 'stw-dashboard-mailing-stats' ), $rasa_username['source_label'] ); ?>
				<?php $this->render_status_card( __( 'Rasa password', 'stw-dashboard-mailing-stats' ), $rasa_password['configured'], $rasa_password['configured'] ? __( 'Configured', 'stw-dashboard-mailing-stats' ) : __( 'Missing', 'stw-dashboard-mailing-stats' ), $rasa_password['source_label'] ); ?>
				<?php $this->render_status_card( __( 'Rasa API key', 'stw-dashboard-mailing-stats' ), $rasa_api_key['configured'], $rasa_api_key['configured'] ? __( 'Configured', 'stw-dashboard-mailing-stats' ) : __( 'Missing', 'stw-dashboard-mailing-stats' ), $rasa_api_key['source_label'] ); ?>
			</div>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'stw_dashboard_mailing_stats' );
				do_settings_sections( 'stw-dashboard-mailing-stats' );
				submit_button();
				?>
			</form>
			</div>
		</div>
		<?php
	}

	private function render_status_card( $label, $ready, $value, $note ) {
		printf(
			'<div class="stw-dashboard-status-card"><span class="stw-dashboard-status-label">%1$s</span><span class="stw-dashboard-status-value"><span class="stw-dashboard-dot %2$s"></span>%3$s</span>%4$s</div>',
			esc_html( $label ),
			$ready ? 'is-ready' : '',
			esc_html( $value ),
			'' !== $note ? '<div class="stw-dashboard-source-note">' . esc_html( $note ) . '</div>' : ''
		);
	}

	public function field_dashboard_api_key() {
		$has_key = '' !== $this->dashboard_api_key_hash();
		$has_constant = '' !== $this->dashboard_api_key_constant();
		$is_configured = $has_key || $has_constant;
		printf(
			'<div class="stw-dashboard-key-actions">
			%7$s
			<div id="stw-dashboard-api-key-wrap" %8$s>
			<input type="password" class="regular-text" id="stw-dashboard-api-key" name="%1$s[dashboard_api_key]" value="" autocomplete="new-password" placeholder="%9$s" />
			</div>
			%10$s
			<button type="button" class="button" id="stw-dashboard-generate-key">%3$s</button>
			<button type="button" class="button" id="stw-dashboard-copy-key" hidden>%4$s</button>
			</div>
			<p class="description">%2$s</p>
			<p class="description stw-dashboard-generated-token" id="stw-dashboard-generated-key-wrap" hidden>
				<label for="stw-dashboard-generated-key"><strong>%5$s</strong></label><br />
				<input type="text" class="large-text code" id="stw-dashboard-generated-key" readonly value="" />
				<br />%6$s
			</p>
			<script>
			(function () {
				var generateButton = document.getElementById("stw-dashboard-generate-key");
				var copyButton = document.getElementById("stw-dashboard-copy-key");
				var passwordInput = document.getElementById("stw-dashboard-api-key");
					var generatedWrap = document.getElementById("stw-dashboard-generated-key-wrap");
					var generatedInput = document.getElementById("stw-dashboard-generated-key");
					var passwordWrap = document.getElementById("stw-dashboard-api-key-wrap");
					var replaceButton = document.getElementById("stw-dashboard-replace-api-key");
					if (!generateButton || !copyButton || !passwordInput || !generatedWrap || !generatedInput || !passwordWrap) {
						return;
					}
					if (replaceButton) {
						replaceButton.addEventListener("click", function () {
							passwordWrap.hidden = false;
							replaceButton.hidden = true;
							passwordInput.focus();
						});
					}
					function randomToken() {
					var bytes = new Uint8Array(48);
					if (window.crypto && window.crypto.getRandomValues) {
						window.crypto.getRandomValues(bytes);
					} else {
						for (var index = 0; index < bytes.length; index += 1) {
							bytes[index] = Math.floor(Math.random() * 256);
						}
					}
					var binary = "";
					for (var byteIndex = 0; byteIndex < bytes.length; byteIndex += 1) {
						binary += String.fromCharCode(bytes[byteIndex]);
					}
					return window.btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
				}
				generateButton.addEventListener("click", function () {
					var token = randomToken();
					passwordWrap.hidden = false;
					if (replaceButton) {
						replaceButton.hidden = true;
					}
					passwordInput.value = token;
					generatedInput.value = token;
					generatedWrap.hidden = false;
					copyButton.hidden = false;
					generatedInput.focus();
					generatedInput.select();
				});
				copyButton.addEventListener("click", function () {
					generatedInput.select();
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(generatedInput.value);
					} else {
						document.execCommand("copy");
					}
				});
			}());
			</script>',
			esc_attr( self::OPTION_NAME ),
			esc_html( $has_constant ? __( 'A key is configured in wp-config.php. You can still save a setting here as a fallback.', 'stw-dashboard-mailing-stats' ) : ( $has_key ? __( 'A key is configured. Enter or generate a new value only to rotate it.', 'stw-dashboard-mailing-stats' ) : __( 'Set or generate the bearer token used by the dashboard.', 'stw-dashboard-mailing-stats' ) ) ),
			esc_html__( 'Generate token', 'stw-dashboard-mailing-stats' ),
			esc_html__( 'Copy token', 'stw-dashboard-mailing-stats' ),
			esc_html__( 'Generated token', 'stw-dashboard-mailing-stats' ),
			esc_html__( 'Copy this value into the matching WORDPRESS_DASHBOARD_API_KEY_* env var, then save changes. It will not be shown again after the page reloads.', 'stw-dashboard-mailing-stats' ),
			$is_configured ? '<span class="stw-dashboard-secret-pill">' . esc_html__( 'Configured', 'stw-dashboard-mailing-stats' ) . '</span>' : '',
			$is_configured ? 'hidden' : '',
			esc_attr__( 'Enter new token to replace current one', 'stw-dashboard-mailing-stats' ),
			$is_configured ? '<button type="button" class="button" id="stw-dashboard-replace-api-key">' . esc_html__( 'Replace value', 'stw-dashboard-mailing-stats' ) . '</button>' : ''
		);
	}

	public function field_cache_ttl() {
		printf(
			'<input type="number" min="60" max="3600" name="%1$s[cache_ttl]" value="%2$d" />',
			esc_attr( self::OPTION_NAME ),
			absint( $this->option( 'cache_ttl', 600 ) )
		);
	}

	public function field_rasa_username() {
		$status = $this->credential_status( 'rasa_username', 'username', 'STW_RASA_USERNAME' );
		printf(
			'<input type="text" class="regular-text" name="%1$s[rasa_username]" value="%2$s" autocomplete="off" />
			<p class="description stw-dashboard-field-note">%3$s</p>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $this->rasa_username() ),
			wp_kses_post( $this->credential_note( $status, __( 'Username', 'stw-dashboard-mailing-stats' ) ) )
		);
	}

	public function field_rasa_password() {
		$status = $this->credential_status( 'rasa_password', 'password', 'STW_RASA_PASSWORD' );
		$this->render_secret_field( 'rasa_password', __( 'Password', 'stw-dashboard-mailing-stats' ), $status );
	}

	public function field_rasa_api_key() {
		$status = $this->credential_status( 'rasa_api_key', 'api_key', 'STW_RASA_API_KEY' );
		$this->render_secret_field( 'rasa_api_key', __( 'API key', 'stw-dashboard-mailing-stats' ), $status );
	}

	private function render_secret_field( $key, $label, array $status ) {
		$field_id = 'stw-dashboard-' . str_replace( '_', '-', $key );
		if ( $status['configured'] ) {
			printf(
				'<div class="stw-dashboard-secret-state">
					<span class="stw-dashboard-secret-pill">%1$s</span>
					<button type="button" class="button stw-dashboard-replace-secret" data-target="%2$s">%3$s</button>
				</div>
				<div class="stw-dashboard-replace-wrap" id="%2$s-wrap" hidden>
					<input type="password" class="regular-text" id="%2$s" name="%4$s[%5$s]" value="" autocomplete="new-password" placeholder="%6$s" />
				</div>
				<p class="description stw-dashboard-field-note">%7$s</p>',
				esc_html__( 'Configured', 'stw-dashboard-mailing-stats' ),
				esc_attr( $field_id ),
				esc_html__( 'Replace value', 'stw-dashboard-mailing-stats' ),
				esc_attr( self::OPTION_NAME ),
				esc_attr( $key ),
				esc_attr__( 'Enter new value to replace current one', 'stw-dashboard-mailing-stats' ),
				wp_kses_post( $this->credential_note( $status, $label ) )
			);
			$this->render_replace_secret_script_once();
			return;
		}

		printf(
			'<input type="password" class="regular-text" id="%4$s" name="%1$s[%5$s]" value="" autocomplete="new-password" /> <p class="description stw-dashboard-field-note">%2$s</p>',
			esc_attr( self::OPTION_NAME ),
			wp_kses_post( $this->credential_note( $status, $label ) ),
			'',
			esc_attr( $field_id ),
			esc_attr( $key )
		);
	}

	private function render_replace_secret_script_once() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;
		?>
		<script>
		(function () {
			document.addEventListener("click", function (event) {
				var button = event.target.closest(".stw-dashboard-replace-secret");
				if (!button) {
					return;
				}
				var targetId = button.getAttribute("data-target");
				var wrap = document.getElementById(targetId + "-wrap");
				var input = document.getElementById(targetId);
				if (!wrap || !input) {
					return;
				}
				wrap.hidden = false;
				button.hidden = true;
				input.focus();
			});
		}());
		</script>
		<?php
	}

	public function field_rasa_base_url() {
		printf(
			'<input type="url" class="regular-text" name="%1$s[rasa_base_url]" value="%2$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $this->option( 'rasa_base_url', 'https://api.rasa.io/v1' ) )
		);
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/mailing/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_mailing_stats' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'startDate' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'sanitize_callback' => 'absint' ),
					'pageSize'  => array( 'sanitize_callback' => 'absint' ),
					'blogId'    => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/editorial/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_editorial_posts' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'startDate'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'       => array( 'sanitize_callback' => 'absint' ),
					'pageSize'   => array( 'sanitize_callback' => 'absint' ),
					'blogId'     => array( 'sanitize_callback' => 'absint' ),
					'search'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'authorId'   => array( 'sanitize_callback' => 'absint' ),
					'categoryId' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ads/(?P<view>summary|timeseries|top|table)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_ads_stats' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'view'      => array( 'sanitize_callback' => 'sanitize_key' ),
					'startDate' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'sanitize_callback' => 'absint' ),
					'pageSize'  => array( 'sanitize_callback' => 'absint' ),
					'blogId'    => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_all_stats' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'startDate' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'sanitize_callback' => 'absint' ),
					'pageSize'  => array( 'sanitize_callback' => 'absint' ),
					'blogId'    => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public function rest_permission( WP_REST_Request $request ) {
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;
		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$header = $request->get_header( 'authorization' );
		$token  = preg_replace( '/^Bearer\s+/i', '', (string) $header );
		$hash   = $this->dashboard_api_key_hash();

		$constant_key = $this->dashboard_api_key_constant();
		if ( '' !== $constant_key && hash_equals( $constant_key, $token ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return true;
		}

		if ( '' !== $hash && '' !== $token && wp_check_password( $token, $hash ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return true;
		}

		if ( $switched ) {
			restore_current_blog();
		}

		return new WP_Error( 'stw_dashboard_mailing_forbidden', __( 'Invalid mailing stats API token.', 'stw-dashboard-mailing-stats' ), array( 'status' => 401 ) );
	}

	public function rest_mailing_stats( WP_REST_Request $request ) {
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_mailing_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page_size );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return rest_ensure_response( $cached );
		}

		$payload = array(
			'providers' => array(
				$this->mailpoet_provider( $start, $end, $page_size ),
				$this->rasa_provider( $start, $end ),
			),
		);

		set_transient( $cache_key, $payload, absint( $this->option( 'cache_ttl', 600 ) ) );

		if ( $switched ) {
			restore_current_blog();
		}

		return rest_ensure_response( $payload );
	}

	public function rest_ads_stats( WP_REST_Request $request ) {
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_ads_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page . '|' . $page_size );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return rest_ensure_response( $cached );
		}

		$payload = $this->advanced_ads_payload( $start, $end, $page, $page_size );
		set_transient( $cache_key, $payload, absint( $this->option( 'cache_ttl', 600 ) ) );

		if ( $switched ) {
			restore_current_blog();
		}

		return rest_ensure_response( $payload );
	}

	public function rest_editorial_posts( WP_REST_Request $request ) {
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$author_id = absint( $request->get_param( 'authorId' ) );
		$category_id = absint( $request->get_param( 'categoryId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_editorial_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page . '|' . $page_size . '|' . $search . '|' . $author_id . '|' . $category_id );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return rest_ensure_response( $cached );
		}

		$payload = $this->editorial_posts_payload( $start, $end, $page, $page_size, $search, $author_id, $category_id );
		set_transient( $cache_key, $payload, absint( $this->option( 'cache_ttl', 600 ) ) );

		if ( $switched ) {
			restore_current_blog();
		}

		return rest_ensure_response( $payload );
	}

	public function rest_all_stats( WP_REST_Request $request ) {
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_all_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page . '|' . $page_size );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return rest_ensure_response( $cached );
		}

		$payload = array(
			'mailing' => array(
				'providers' => array(
					$this->mailpoet_provider( $start, $end, $page_size ),
					$this->rasa_provider( $start, $end ),
				),
			),
			'ads'     => $this->advanced_ads_payload( $start, $end, $page, $page_size ),
		);

		set_transient( $cache_key, $payload, absint( $this->option( 'cache_ttl', 600 ) ) );

		if ( $switched ) {
			restore_current_blog();
		}

		return rest_ensure_response( $payload );
	}

	private function editorial_posts_payload( $start, $end, $page, $page_size, $search, $author_id, $category_id ) {
		$query_args = $this->editorial_query_args( $start, $end, $search, $author_id, $category_id );
		$query = new WP_Query(
			array_merge(
				$query_args,
				array(
					'posts_per_page' => $page_size,
					'paged'          => $page,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);

		$summary_ids = $this->editorial_post_ids( $query_args );
		$trend_start = gmdate( 'Y-m-01', strtotime( $end . ' -5 months' ) );
		$trend_ids = $this->editorial_post_ids( $this->editorial_query_args( $trend_start, $end, $search, $author_id, $category_id ) );
		$summary = $this->editorial_summary( $summary_ids );

		return array(
			'metrics'    => array(
				array( 'label' => 'Published posts', 'value' => (float) $query->found_posts, 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Active authors', 'value' => (float) count( $summary['authors'] ), 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Categories covered', 'value' => (float) count( $summary['categories'] ), 'previous' => null, 'change' => null, 'format' => 'number' ),
			),
			'timeseries' => $this->editorial_publishing_trend( $trend_ids, $end ),
			'breakdown'  => $this->editorial_author_breakdown( $summary['authors'] ),
			'rows'       => array_map( array( $this, 'editorial_post_row' ), is_array( $query->posts ) ? $query->posts : array() ),
			'pagination' => array(
				'page'       => $page,
				'pageSize'   => $page_size,
				'total'      => (int) $query->found_posts,
				'totalPages' => (int) max( 1, $query->max_num_pages ),
			),
		);
	}

	private function editorial_query_args( $start, $end, $search = '', $author_id = 0, $category_id = 0 ) {
		$args = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'date_query'  => array(
				array(
					'after'     => $start . ' 00:00:00',
					'before'    => $end . ' 23:59:59',
					'inclusive' => true,
					'column'    => 'post_date_gmt',
				),
			),
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( $author_id > 0 ) {
			$args['author'] = $author_id;
		}
		if ( $category_id > 0 ) {
			$args['cat'] = $category_id;
		}
		return $args;
	}

	private function editorial_post_ids( array $base_args ) {
		$ids = array();
		$page = 1;
		do {
			$query = new WP_Query(
				array_merge(
					$base_args,
					array(
						'fields'         => 'ids',
						'posts_per_page' => 500,
						'paged'          => $page,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				)
			);
			$ids = array_merge( $ids, array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) );
			++$page;
		} while ( $page <= (int) $query->max_num_pages && $page <= 20 );

		return $ids;
	}

	private function editorial_summary( array $post_ids ) {
		$authors = array();
		$categories = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$author = $this->editorial_author_name( $post->post_author );
			$authors[ $author ] = ( $authors[ $author ] ?? 0 ) + 1;
			foreach ( $this->editorial_category_names( $post_id ) as $category ) {
				$categories[ $category ] = true;
			}
		}
		return array( 'authors' => $authors, 'categories' => $categories );
	}

	private function editorial_post_row( $post ) {
		$published = '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : $post->post_date;
		$modified = '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified;
		return array(
			'id'            => (int) $post->ID,
			'title'         => $this->clean_text( get_the_title( $post ) ),
			'slug'          => (string) $post->post_name,
			'status'        => (string) $post->post_status,
			'publishedDate' => $this->iso_date( $published ),
			'modifiedDate'  => $this->iso_date( $modified ),
			'authorId'      => (int) $post->post_author,
			'author'        => $this->editorial_author_name( $post->post_author ),
			'categories'    => implode( ', ', $this->editorial_category_names( $post->ID ) ),
			'tags'          => implode( ', ', $this->editorial_tag_names( $post->ID ) ),
			'url'           => get_permalink( $post ),
		);
	}

	private function editorial_author_name( $author_id ) {
		$user = get_userdata( absint( $author_id ) );
		if ( ! $user ) {
			return __( 'Unknown', 'stw-dashboard-mailing-stats' );
		}
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		}
		if ( '' === $name ) {
			$name = (string) $user->user_nicename;
		}
		return $this->clean_text( $name );
	}

	private function editorial_category_names( $post_id ) {
		$terms = get_the_category( $post_id );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map(
					function ( $term ) {
						return $this->clean_text( $term->name ?? '' );
					},
					$terms
				)
			)
		);
	}

	private function editorial_tag_names( $post_id ) {
		$terms = get_the_tags( $post_id );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map(
					function ( $term ) {
						return $this->clean_text( $term->name ?? '' );
					},
					$terms
				)
			)
		);
	}

	private function editorial_author_breakdown( array $authors ) {
		arsort( $authors );
		$breakdown = array();
		foreach ( $authors as $label => $value ) {
			$breakdown[] = array( 'label' => $label, 'value' => (float) $value );
		}
		return $breakdown;
	}

	private function editorial_publishing_trend( array $post_ids, $end_date ) {
		$end = strtotime( $end_date . ' 12:00:00' );
		$months = array();
		for ( $index = 5; $index >= 0; --$index ) {
			$timestamp = strtotime( '-' . $index . ' months', $end );
			$key = gmdate( 'Y-m', $timestamp );
			$months[ $key ] = array(
				'date'  => gmdate( 'M Y', $timestamp ),
				'value' => 0,
			);
		}

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$key = gmdate( 'Y-m', strtotime( $post->post_date_gmt ?: $post->post_date ) );
			if ( isset( $months[ $key ] ) ) {
				$months[ $key ]['value'] += 1;
			}
		}

		$points = array_values( $months );
		foreach ( $points as $index => $point ) {
			$points[ $index ]['previous'] = 0 === $index ? 0 : (float) $points[ $index - 1 ]['value'];
			$points[ $index ]['value'] = (float) $point['value'];
		}
		return $points;
	}

	private function mailpoet_provider( $start, $end, $limit ) {
		return array(
			'provider'    => 'MailPoet',
			'subscribers' => $this->mailpoet_subscriber_counts(),
			'lists'       => $this->mailpoet_lists(),
			'campaigns'   => $this->mailpoet_campaigns( $start, $end, $limit ),
		);
	}

	private function mailpoet_api() {
		if ( class_exists( '\MailPoet\API\API' ) ) {
			try {
				return \MailPoet\API\API::MP( 'v1' );
			} catch ( Exception $e ) {
				return null;
			}
		}
		return null;
	}

	private function mailpoet_subscriber_counts() {
		$api = $this->mailpoet_api();
		if ( ! $api ) {
			return array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'inactive' => 0 );
		}
		return array(
			'total'        => (int) $api->getSubscribersCount(),
			'subscribed'   => (int) $api->getSubscribersCount( array( 'status' => 'subscribed' ) ),
			'unsubscribed' => (int) $api->getSubscribersCount( array( 'status' => 'unsubscribed' ) ),
			'bounced'      => (int) $api->getSubscribersCount( array( 'status' => 'bounced' ) ),
			'inactive'     => (int) $api->getSubscribersCount( array( 'status' => 'inactive' ) ),
		);
	}

	private function mailpoet_lists() {
		$api = $this->mailpoet_api();
		if ( ! $api ) {
			return array();
		}
		$lists = array();
		foreach ( $api->getLists() as $list ) {
			if ( ! empty( $list['deleted_at'] ) ) {
				continue;
			}
			$id = absint( $list['id'] ?? 0 );
			$lists[] = array(
				'id'         => (string) $id,
				'name'       => sanitize_text_field( $list['name'] ?? '' ),
				'subscribed' => (int) $api->getSubscribersCount( array( 'status' => 'subscribed', 'listId' => $id ) ),
				'total'      => (int) $api->getSubscribersCount( array( 'listId' => $id ) ),
				'updatedAt'  => $this->iso_date( $list['updated_at'] ?? '' ),
			);
		}
		return $lists;
	}

	private function mailpoet_campaigns( $start, $end, $limit ) {
		global $wpdb;
		$tables = array(
			'newsletters' => $wpdb->prefix . 'mailpoet_newsletters',
			'sent'        => $wpdb->prefix . 'mailpoet_statistics_newsletters',
			'opens'       => $wpdb->prefix . 'mailpoet_statistics_opens',
			'clicks'      => $wpdb->prefix . 'mailpoet_statistics_clicks',
			'unsubs'      => $wpdb->prefix . 'mailpoet_statistics_unsubscribes',
			'bounces'     => $wpdb->prefix . 'mailpoet_statistics_bounces',
		);
		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				return array();
			}
		}

		$start_at = $start . ' 00:00:00';
		$end_at = $end . ' 23:59:59';
		$query = $wpdb->prepare(
			"SELECT n.id, n.subject, n.sent_at,
				(SELECT COUNT(DISTINCT s.subscriber_id) FROM {$tables['sent']} s WHERE s.newsletter_id = n.id) sent,
				(SELECT COUNT(DISTINCT o.subscriber_id) FROM {$tables['opens']} o WHERE o.newsletter_id = n.id AND o.user_agent_type = 0) opens,
				(SELECT COUNT(DISTINCT o.subscriber_id) FROM {$tables['opens']} o WHERE o.newsletter_id = n.id AND o.user_agent_type = 1) machine_opens,
				(SELECT COUNT(DISTINCT c.subscriber_id) FROM {$tables['clicks']} c WHERE c.newsletter_id = n.id) clicks,
				(SELECT COUNT(DISTINCT u.subscriber_id) FROM {$tables['unsubs']} u WHERE u.newsletter_id = n.id) unsubscribes,
				(SELECT COUNT(DISTINCT b.subscriber_id) FROM {$tables['bounces']} b WHERE b.newsletter_id = n.id) bounces
			FROM {$tables['newsletters']} n
			WHERE n.deleted_at IS NULL AND n.sent_at BETWEEN %s AND %s
			ORDER BY n.sent_at DESC
			LIMIT %d",
			$start_at,
			$end_at,
			$limit
		);
		$rows = $wpdb->get_results( $query, ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'id'            => (string) absint( $row['id'] ?? 0 ),
					'name'          => sanitize_text_field( $row['subject'] ?? __( 'MailPoet newsletter', 'stw-dashboard-mailing-stats' ) ),
					'sent'          => absint( $row['sent'] ?? 0 ),
					'opens'         => absint( $row['opens'] ?? 0 ),
					'machineOpens'  => absint( $row['machine_opens'] ?? 0 ),
					'clicks'        => absint( $row['clicks'] ?? 0 ),
					'unsubscribes'  => absint( $row['unsubscribes'] ?? 0 ),
					'bounces'       => absint( $row['bounces'] ?? 0 ),
					'sentAt'        => $this->iso_date( $row['sent_at'] ?? '' ),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	private function rasa_provider( $start, $end ) {
		$empty = array(
			'provider'    => 'rasa',
			'subscribers' => array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'inactive' => 0 ),
			'lists'       => array(),
			'campaigns'   => array(),
		);
		$token = $this->rasa_token();
		if ( ! $token ) {
			return $empty;
		}

		$people = $this->rasa_people_counts( $token );
		$total = absint( $people['total'] ?? 0 );
		$subscribed = absint( $people['subscribed'] ?? 0 );
		$not_receiving = absint( $people['unsubscribed'] ?? 0 );
		if ( 0 === $total ) {
			$total = $this->rasa_person_count( $token, array() );
			$subscribed = $this->rasa_person_count_any(
				$token,
				array(
					array( 'is_subscribed' => '1' ),
					array( 'is_subscribed' => 'true' ),
					array( 'is_receiving'  => '1' ),
					array( 'is_receiving'  => 'true' ),
				)
			);
			$not_receiving = $this->rasa_person_count_any(
				$token,
				array(
					array( 'is_subscribed' => '0' ),
					array( 'is_subscribed' => 'false' ),
					array( 'is_receiving'  => '0' ),
					array( 'is_receiving'  => 'false' ),
				)
			);
			if ( $total > 0 ) {
				if ( $subscribed <= 0 && $not_receiving > 0 ) {
					$subscribed = max( 0, $total - $not_receiving );
				}
				if ( $not_receiving <= 0 && $subscribed > 0 ) {
					$not_receiving = max( 0, $total - $subscribed );
				}
			}
			$total = max( $total, $subscribed + $not_receiving );
		}
		$activity = $this->rasa_activity( $token, $start, $end );

		return array(
			'provider'    => 'rasa',
			'subscribers' => array(
				'total'        => $total,
				'subscribed'   => $subscribed,
				'unsubscribed' => $not_receiving,
				'bounced'      => absint( $activity['bounces'] ?? 0 ),
				'inactive'     => 0,
			),
			'lists'       => array(
				array(
					'id'         => 'rasa-v1',
					'name'       => __( 'rasa active recipients', 'stw-dashboard-mailing-stats' ),
					'subscribed' => $subscribed,
					'total'      => $total,
					'updatedAt'  => gmdate( 'c' ),
				),
			),
			'campaigns'   => array(
				array(
					'id'           => 'rasa-' . $start . '-' . $end,
					'name'         => __( 'rasa activity analytics', 'stw-dashboard-mailing-stats' ),
					'sent'         => absint( $activity['delivered'] ?? 0 ),
					'opens'        => absint( $activity['opens'] ?? 0 ),
					'machineOpens' => 0,
					'clicks'       => absint( $activity['clicks'] ?? 0 ),
					'unsubscribes' => absint( $activity['unsubscribes'] ?? 0 ),
					'bounces'      => absint( $activity['bounces'] ?? 0 ),
					'sentAt'       => gmdate( 'c' ),
				),
			),
			'debug'       => $this->rasa_debug,
		);
	}

	private function advanced_ads_payload( $start, $end, $page, $page_size ) {
		global $wpdb;
		$impressions_table = $wpdb->get_blog_prefix() . 'advads_impressions';
		$clicks_table = $wpdb->get_blog_prefix() . 'advads_clicks';

		if ( ! $this->table_exists( $impressions_table ) ) {
			return array(
				'metrics'    => array(),
				'timeseries' => array(),
				'breakdown'  => array(),
				'rows'       => array(),
			);
		}

		$has_clicks = $this->table_exists( $clicks_table );
		$start_ts = $this->advanced_ads_timestamp( $start . ' 00:00:00' );
		$end_ts = $this->advanced_ads_timestamp( gmdate( 'Y-m-d 00:00:00', strtotime( $end . ' +1 day' ) ) );
		$offset = ( $page - 1 ) * $page_size;

		$click_select = $has_clicks
			? "(SELECT COALESCE(SUM(c.count), 0) FROM {$clicks_table} c WHERE c.ad_id = p.ID AND c.timestamp >= %d AND c.timestamp < %d)"
			: '0';
		$query = $wpdb->prepare(
			"SELECT p.ID id, p.post_title name, p.post_status status, p.post_modified_gmt last_updated,
				COALESCE(SUM(i.count), 0) impressions,
				{$click_select} clicks
			FROM {$wpdb->posts} p
			LEFT JOIN {$impressions_table} i ON i.ad_id = p.ID AND i.timestamp >= %d AND i.timestamp < %d
			WHERE p.post_type = %s
			GROUP BY p.ID
			HAVING impressions > 0 OR clicks > 0
			ORDER BY impressions DESC
			LIMIT %d OFFSET %d",
			array_merge(
				$has_clicks ? array( $start_ts, $end_ts ) : array(),
				array( $start_ts, $end_ts, $this->advanced_ads_post_type(), $page_size, $offset )
			)
		);
		$rows = $wpdb->get_results( $query, ARRAY_A );
		$rows = array_map( array( $this, 'advanced_ads_row' ), is_array( $rows ) ? $rows : array() );
		$totals = $this->advanced_ads_totals( $impressions_table, $has_clicks ? $clicks_table : '', $start_ts, $end_ts );
		$timeseries = $this->advanced_ads_timeseries( $impressions_table, $has_clicks ? $clicks_table : '', $start, $end, $start_ts, $end_ts );
		$breakdown = $this->advanced_ads_breakdown( $rows, (int) $totals['impressions'] );

		return array(
			'metrics'    => array(
				array( 'label' => 'Total ads', 'value' => (float) $this->advanced_ads_count(), 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Impressions', 'value' => (float) $totals['impressions'], 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Clicks', 'value' => (float) $totals['clicks'], 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'CTR', 'value' => (float) $this->rate( $totals['clicks'], $totals['impressions'] ), 'previous' => null, 'change' => null, 'format' => 'percent' ),
			),
			'timeseries' => $timeseries,
			'breakdown'  => $breakdown,
			'rows'       => $rows,
		);
	}

	private function advanced_ads_row( $row ) {
		$impressions = absint( $row['impressions'] ?? 0 );
		$clicks = absint( $row['clicks'] ?? 0 );
		return array(
			'id'          => absint( $row['id'] ?? 0 ),
			'name'        => sanitize_text_field( $row['name'] ?? __( 'Untitled ad', 'stw-dashboard-mailing-stats' ) ),
			'status'      => sanitize_key( $row['status'] ?? 'unknown' ),
			'impressions' => $impressions,
			'clicks'      => $clicks,
			'ctr'         => $this->rate( $clicks, $impressions ),
			'lastUpdated' => $this->iso_date( $row['last_updated'] ?? '' ),
		);
	}

	private function advanced_ads_totals( $impressions_table, $clicks_table, $start_ts, $end_ts ) {
		global $wpdb;
		$impressions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(count), 0) FROM {$impressions_table} WHERE timestamp >= %d AND timestamp < %d",
				$start_ts,
				$end_ts
			)
		);
		$clicks = 0;
		if ( $clicks_table ) {
			$clicks = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(count), 0) FROM {$clicks_table} WHERE timestamp >= %d AND timestamp < %d",
					$start_ts,
					$end_ts
				)
			);
		}
		return array( 'impressions' => $impressions, 'clicks' => $clicks );
	}

	private function advanced_ads_timeseries( $impressions_table, $clicks_table, $start, $end, $start_ts, $end_ts ) {
		global $wpdb;
		$impressions = $this->advanced_ads_daily_counts( $impressions_table, $start_ts, $end_ts );
		$clicks = $clicks_table ? $this->advanced_ads_daily_counts( $clicks_table, $start_ts, $end_ts ) : array();
		$points = array();
		$current = strtotime( $start . ' 00:00:00' );
		$last = strtotime( $end . ' 00:00:00' );

		while ( $current && $last && $current <= $last ) {
			$date = gmdate( 'Y-m-d', $current );
			$points[] = array(
				'date'      => $date,
				'value'     => (float) ( $impressions[ $date ] ?? 0 ),
				'secondary' => (float) ( $clicks[ $date ] ?? 0 ),
			);
			$current = strtotime( '+1 day', $current );
		}

		return $points;
	}

	private function advanced_ads_daily_counts( $table, $start_ts, $end_ts ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT (`timestamp` - (`timestamp` %% 100)) day_key, COALESCE(SUM(`count`), 0) total
				FROM {$table}
				WHERE `timestamp` >= %d AND `timestamp` < %d
				GROUP BY day_key
				ORDER BY day_key ASC",
				$start_ts,
				$end_ts
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$date = $this->advanced_ads_date_from_timestamp( $row['day_key'] ?? '' );
			if ( '' !== $date ) {
				$counts[ $date ] = absint( $row['total'] ?? 0 );
			}
		}
		return $counts;
	}

	private function advanced_ads_breakdown( array $rows, $total_impressions ) {
		$breakdown = array();
		foreach ( array_slice( $rows, 0, 8 ) as $row ) {
			$impressions = absint( $row['impressions'] ?? 0 );
			if ( $impressions <= 0 ) {
				continue;
			}
			$breakdown[] = array(
				'label' => sanitize_text_field( $row['name'] ?? __( 'Untitled ad', 'stw-dashboard-mailing-stats' ) ),
				'value' => (float) $impressions,
				'share' => (float) $this->rate( $impressions, $total_impressions ),
			);
		}
		return $breakdown;
	}

	private function advanced_ads_count() {
		$query = new WP_Query(
			array(
				'post_type'      => $this->advanced_ads_post_type(),
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'advanced_ads_expired' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $query->found_posts;
	}

	private function advanced_ads_post_type() {
		return defined( 'Advanced_Ads::POST_TYPE_SLUG' ) ? Advanced_Ads::POST_TYPE_SLUG : 'advanced_ads';
	}

	private function advanced_ads_timestamp( $datetime ) {
		$timestamp = strtotime( get_gmt_from_date( $datetime ) );
		$local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'ymWdH' );
		return absint( $local );
	}

	private function advanced_ads_date_from_timestamp( $timestamp ) {
		$value = str_pad( (string) absint( $timestamp ), 10, '0', STR_PAD_LEFT );
		$year = absint( substr( $value, 0, 2 ) );
		$month = absint( substr( $value, 2, 2 ) );
		$day = absint( substr( $value, 6, 2 ) );
		if ( $year <= 0 || $month <= 0 || $day <= 0 ) {
			return '';
		}
		return sprintf( '20%02d-%02d-%02d', $year, $month, $day );
	}

	private function rate( $numerator, $denominator ) {
		return $denominator > 0 ? round( ( $numerator / $denominator ) * 100, 2 ) : 0;
	}

	private function rasa_token() {
		$username = $this->rasa_username();
		$password = $this->rasa_password();
		$api_key = $this->rasa_api_key();
		if ( '' === $username || '' === $password || '' === $api_key ) {
			return '';
		}

		$response = wp_remote_post(
			$this->rasa_url( 'tokens' ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'key' => $api_key ) ),
			)
		);

		$body = $this->remote_json( $response );
		return (string) ( $body['results'][0]['token'] ?? $body['results'][0]['rasa-token'] ?? '' );
	}

	private function rasa_person_count( $token, array $query_args ) {
		$query_args = array_merge( $query_args, array( 'limit' => 1 ) );
		$response = wp_remote_get( add_query_arg( $query_args, $this->rasa_url( 'persons' ) ), $this->rasa_request_args( $token ) );
		$body = $this->remote_json( $response );
		$metadata = isset( $body['metadata'] ) && is_array( $body['metadata'] ) ? $body['metadata'] : array();
		return absint(
			$metadata['total_query_count']
			?? $metadata['record_count']
			?? $metadata['total_count']
			?? $metadata['total_records']
			?? $metadata['total']
			?? count( $body['results'] ?? array() )
		);
	}

	private function rasa_person_count_any( $token, array $queries ) {
		foreach ( $queries as $query_args ) {
			$count = $this->rasa_person_count( $token, $query_args );
			if ( $count > 0 ) {
				return $count;
			}
		}
		return 0;
	}

	private function rasa_people_counts( $token ) {
		$this->rasa_debug = array(
			'cacheVersion' => self::CACHE_VERSION,
			'queryCounts'  => null,
			'strategies'   => array(),
			'selected'     => null,
		);
		$query_counts = $this->rasa_people_query_counts( $token );
		$this->rasa_debug['queryCounts'] = $query_counts;
		if ( $this->rasa_people_counts_are_usable( $query_counts ) ) {
			$this->rasa_debug['selected'] = array_merge( array( 'source' => 'query-counts' ), $query_counts );
			return $query_counts;
		}

		$counts = $this->rasa_people_counts_from_pages( $token, absint( $query_counts['total'] ?? 0 ) );
		if ( $counts['total'] <= 0 ) {
			return $query_counts;
		}

		if ( $query_counts['total'] > $counts['total'] ) {
			$unknown = $query_counts['total'] - $counts['total'];
			$counts['total'] = $query_counts['total'];
			if ( $query_counts['subscribed'] > 0 && $query_counts['subscribed'] <= $counts['total'] ) {
				$counts['subscribed'] = $query_counts['subscribed'];
				$counts['unsubscribed'] = max( 0, $counts['total'] - $counts['subscribed'] );
			} else {
				$counts['subscribed'] += $unknown;
			}
		}

		$this->rasa_debug['selected'] = array_merge( array( 'source' => 'pagination' ), $counts );
		return $counts;
	}

	private function rasa_people_query_counts( $token ) {
		$total = $this->rasa_person_count( $token, array() );
		$subscribed = $this->rasa_person_count_any(
			$token,
			array(
				array( 'is_subscribed' => '1' ),
				array( 'is_subscribed' => 'true' ),
				array( 'is_receiving'  => '1' ),
				array( 'is_receiving'  => 'true' ),
				array( 'status'        => 'subscribed' ),
				array( 'subscription_status' => 'subscribed' ),
			)
		);
		$unsubscribed = $this->rasa_person_count_any(
			$token,
			array(
				array( 'is_subscribed' => '0' ),
				array( 'is_subscribed' => 'false' ),
				array( 'is_receiving'  => '0' ),
				array( 'is_receiving'  => 'false' ),
				array( 'status'        => 'unsubscribed' ),
				array( 'status'        => 'inactive' ),
				array( 'subscription_status' => 'unsubscribed' ),
				array( 'subscription_status' => 'inactive' ),
			)
		);

		return array(
			'total'        => $total,
			'subscribed'   => $subscribed,
			'unsubscribed' => $unsubscribed,
		);
	}

	private function rasa_people_counts_are_usable( array $counts ) {
		$total = absint( $counts['total'] ?? 0 );
		$subscribed = absint( $counts['subscribed'] ?? 0 );
		$unsubscribed = absint( $counts['unsubscribed'] ?? 0 );
		if ( $total <= 0 || $subscribed + $unsubscribed <= 0 ) {
			return false;
		}
		if ( 0 === $unsubscribed && 0 === $total % 1000 ) {
			return false;
		}

		return $subscribed + $unsubscribed <= $total;
	}

	private function rasa_people_counts_from_pages( $token, $expected_total ) {
		$limit = 1000;
		$best_counts = array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0 );
		foreach ( array( 'skip', 'offset', 'page', 'page_number' ) as $strategy ) {
			$counts = $this->rasa_people_counts_from_page_strategy( $token, $expected_total, $limit, $strategy );
			$this->rasa_debug['strategies'][] = array_merge( array( 'strategy' => $strategy ), $counts );
			if ( $counts['total'] > $best_counts['total'] ) {
				$best_counts = $counts;
			}
			if ( $counts['total'] > $limit ) {
				return $counts;
			}
		}
		return $best_counts;
	}

	private function rasa_people_counts_from_page_strategy( $token, $expected_total, $limit, $strategy ) {
		$counts = array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0 );
		$seen_first_signature = '';
		$metadata_total = absint( $expected_total );
		$pages_fetched = 0;
		$stop_reason = 'page-limit';

		for ( $page = 0; $page < 50; ++$page ) {
			$query_args = array( 'limit' => $limit );
			if ( 'skip' === $strategy ) {
				$query_args['skip'] = $page * $limit;
			} elseif ( 'offset' === $strategy ) {
				$query_args['offset'] = $page * $limit;
			} elseif ( 'page' === $strategy ) {
				$query_args['page'] = $page + 1;
			} else {
				$query_args['page_number'] = $page + 1;
			}

			$body = $this->remote_json( wp_remote_get( add_query_arg( $query_args, $this->rasa_url( 'persons' ) ), $this->rasa_request_args( $token ) ) );
			$results = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();
			if ( empty( $results ) ) {
				$stop_reason = 'empty-results';
				break;
			}
			++$pages_fetched;

			$page_metadata_total = $this->rasa_metadata_total( $body );
			$metadata_total = max( $metadata_total, $page_metadata_total );

			foreach ( $results as $item ) {
				$person = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : $item;
				if ( ! is_array( $person ) ) {
					continue;
				}
				++$counts['total'];
				if ( $this->rasa_person_is_subscribed( $person ) ) {
					++$counts['subscribed'];
				} else {
					++$counts['unsubscribed'];
				}
			}

			$first_person = isset( $results[0]['data'] ) && is_array( $results[0]['data'] ) ? $results[0]['data'] : $results[0];
			$signature = is_array( $first_person ) ? $this->rasa_person_signature( $first_person ) : '';
			if ( $page > 0 && '' !== $signature && $signature === $seen_first_signature ) {
				$counts['total'] -= count( $results );
				$counts = $this->rasa_recount_without_page( $counts, $results );
				$stop_reason = 'duplicate-first-record';
				break;
			}
			if ( 0 === $page ) {
				$seen_first_signature = $signature;
			}

			$next_offset = ( $page + 1 ) * $limit;
			$trust_metadata_total = $metadata_total > $limit || 0 !== $metadata_total % $limit;
			if ( count( $results ) < $limit || ( $trust_metadata_total && $next_offset >= $metadata_total ) ) {
				$stop_reason = count( $results ) < $limit ? 'short-page' : 'metadata-total-reached';
				break;
			}
		}

		if ( $metadata_total > $counts['total'] ) {
			$unknown = $metadata_total - $counts['total'];
			$counts['total'] = $metadata_total;
			if ( 0 === $counts['unsubscribed'] ) {
				$counts['subscribed'] += $unknown;
			}
		}

		return array_merge(
			$counts,
			array(
				'pagesFetched'  => $pages_fetched,
				'metadataTotal' => $metadata_total,
				'stopReason'    => $stop_reason,
			)
		);
	}

	private function rasa_recount_without_page( array $counts, array $results ) {
		foreach ( $results as $item ) {
			$person = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : $item;
			if ( ! is_array( $person ) ) {
				continue;
			}
			if ( $this->rasa_person_is_subscribed( $person ) ) {
				$counts['subscribed'] = max( 0, $counts['subscribed'] - 1 );
			} else {
				$counts['unsubscribed'] = max( 0, $counts['unsubscribed'] - 1 );
			}
		}
		return $counts;
	}

	private function rasa_metadata_total( array $body ) {
		$metadata = isset( $body['metadata'] ) && is_array( $body['metadata'] ) ? $body['metadata'] : array();
		return absint(
			$metadata['total_query_count']
			?? $metadata['record_count']
			?? $metadata['total_count']
			?? $metadata['total_records']
			?? $metadata['total']
			?? 0
		);
	}

	private function rasa_person_signature( array $person ) {
		foreach ( array( 'id', 'person_id', 'email', 'email_address' ) as $key ) {
			if ( isset( $person[ $key ] ) && '' !== (string) $person[ $key ] ) {
				return (string) $person[ $key ];
			}
		}
		return md5( wp_json_encode( $person ) );
	}

	private function rasa_person_is_subscribed( array $person ) {
		$status = $person['is_subscribed'] ?? $person['is_receiving'] ?? $person['status'] ?? $person['subscription_status'] ?? true;
		if ( is_bool( $status ) ) {
			return $status;
		}
		$status = strtolower( trim( (string) $status ) );
		return ! in_array( $status, array( '0', 'false', 'no', 'unsubscribed', 'inactive' ), true );
	}

	private function rasa_activity( $token, $start, $end ) {
		$response = wp_remote_post(
			$this->rasa_url( 'analytics/activities' ),
			array_merge(
				$this->rasa_request_args( $token ),
				array(
					'headers' => array_merge(
						$this->rasa_request_args( $token )['headers'],
						array( 'Content-Type' => 'application/json' )
					),
					'body'    => wp_json_encode(
						array(
							'date_range'    => array(
								'start_date' => $start . 'T00:00:00Z',
								'end_date'   => $end . 'T23:59:59Z',
							),
							'interval'      => 'day',
							'metrics'       => array( 'open', 'click', 'delivered', 'bounce', 'unsubscribe' ),
							'suspect_click' => 'real_clicks',
							'segment_code'  => 'All',
							'timezone'      => 'UTC',
							'limit'         => 10000,
						)
					),
				)
			)
		);
		$body = $this->remote_json( $response );
		$totals = array( 'opens' => 0, 'clicks' => 0, 'delivered' => 0, 'bounces' => 0, 'unsubscribes' => 0 );
		foreach ( $body['results'] ?? array() as $row ) {
			$totals['opens'] += $this->rasa_metric_value( $row, array( 'total_opens', 'opens', 'open', 'unique_opens', 'unique_open' ) );
			$totals['clicks'] += $this->rasa_metric_value( $row, array( 'total_clicks', 'clicks', 'click', 'unique_clicks', 'unique_click' ) );
			$totals['delivered'] += $this->rasa_metric_value( $row, array( 'total_delivered', 'delivered', 'deliveries', 'delivery' ) );
			$totals['bounces'] += $this->rasa_metric_value( $row, array( 'total_bounces', 'bounces', 'bounce', 'total_bounce' ) );
			$totals['unsubscribes'] += $this->rasa_metric_value( $row, array( 'total_unsubscribes', 'unsubscribes', 'unsubscribe', 'unsubscribed' ) );
		}
		return $totals;
	}

	private function rasa_metric_value( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) ) {
				return absint( $row[ $key ] );
			}
		}
		foreach ( array( 'data', 'metrics', 'totals', 'values' ) as $container_key ) {
			if ( isset( $row[ $container_key ] ) && is_array( $row[ $container_key ] ) ) {
				foreach ( $keys as $key ) {
					if ( isset( $row[ $container_key ][ $key ] ) ) {
						return absint( $row[ $container_key ][ $key ] );
					}
				}
			}
		}
		return 0;
	}

	private function rasa_request_args( $token ) {
		return array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->rasa_username() . ':' . $this->rasa_password() ),
				'rasa-token'    => $token,
			),
		);
	}

	private function remote_json( $response ) {
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : array();
	}

	private function table_exists( $table ) {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	private function date_arg( $value, $fallback ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $fallback;
	}

	private function iso_date( $value ) {
		$timestamp = strtotime( (string) $value );
		return $timestamp ? gmdate( 'c', $timestamp ) : '';
	}

	private function clean_text( $value ) {
		return trim( wp_strip_all_tags( wp_specialchars_decode( html_entity_decode( (string) $value, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) ) );
	}

	private function rasa_url( $path ) {
		return trailingslashit( $this->option( 'rasa_base_url', 'https://api.rasa.io/v1' ) ) . ltrim( $path, '/' );
	}

	private function options() {
		$options = get_option( self::OPTION_NAME, array() );
		return is_array( $options ) ? $options : array();
	}

	private function option( $key, $default = '' ) {
		$options = $this->options();
		return $options[ $key ] ?? $default;
	}

	private function dashboard_api_key_hash() {
		return (string) $this->option( 'dashboard_api_key_hash', '' );
	}

	private function dashboard_api_key_constant() {
		$generic = $this->constant_value( 'STW_DASHBOARD_API_KEY' );
		if ( '' !== $generic ) {
			return $generic;
		}
		return $this->constant_value( 'STW_DASHBOARD_MAILING_API_KEY' );
	}

	private function credential_status( $gateway_key, $sync_key, $constant_name ) {
		$constant = $this->constant_value( $constant_name );
		if ( '' !== $constant ) {
			return array(
				'configured'   => true,
				'source'       => 'constant',
				'source_label' => sprintf(
					/* translators: %s: constant name. */
					__( 'Using %s constant', 'stw-dashboard-mailing-stats' ),
					$this->constant_label( $constant_name )
				),
			);
		}

		$gateway_value = 'rasa_username' === $gateway_key
			? trim( (string) $this->option( $gateway_key, '' ) )
			: $this->decrypt_secret( (string) $this->option( $gateway_key, '' ) );
		if ( '' !== $gateway_value ) {
			return array(
				'configured'   => true,
				'source'       => 'gateway',
				'source_label' => __( 'Using Dashboard Stats Gateway setting', 'stw-dashboard-mailing-stats' ),
			);
		}

		if ( '' !== $this->sync_plugin_setting( $sync_key ) ) {
			return array(
				'configured'   => true,
				'source'       => 'sync',
				'source_label' => __( 'Using STW MailPoet Rasa Sync setting', 'stw-dashboard-mailing-stats' ),
			);
		}

		return array(
			'configured'   => false,
			'source'       => '',
			'source_label' => __( 'No value found', 'stw-dashboard-mailing-stats' ),
		);
	}

	private function credential_note( array $status, $label ) {
		if ( $status['configured'] ) {
			return sprintf(
				/* translators: 1: credential label, 2: source label. */
				__( '<strong>%1$s configured.</strong> %2$s. Leave blank to keep this value, or enter a new value to override it for this gateway.', 'stw-dashboard-mailing-stats' ),
				esc_html( $label ),
				esc_html( $status['source_label'] )
			);
		}

		return sprintf(
			/* translators: %s: credential label. */
			__( '<strong>%s missing.</strong> Add it here, define it in wp-config.php, or configure STW MailPoet Rasa Sync for this site.', 'stw-dashboard-mailing-stats' ),
			esc_html( $label )
		);
	}

	private function constant_label( $base_name ) {
		$blog_constant = $base_name . '_' . get_current_blog_id();
		return defined( $blog_constant ) ? $blog_constant : $base_name;
	}

	private function rasa_username() {
		$constant = $this->constant_value( 'STW_RASA_USERNAME' );
		if ( '' !== $constant ) {
			return $constant;
		}

		$gateway_value = trim( (string) $this->option( 'rasa_username', '' ) );
		if ( '' !== $gateway_value ) {
			return $gateway_value;
		}

		return $this->sync_plugin_setting( 'username' );
	}

	private function rasa_password() {
		$constant = $this->constant_value( 'STW_RASA_PASSWORD' );
		if ( '' !== $constant ) {
			return $constant;
		}

		$gateway_value = $this->decrypt_secret( (string) $this->option( 'rasa_password', '' ) );
		if ( '' !== $gateway_value ) {
			return $gateway_value;
		}

		return $this->sync_plugin_setting( 'password' );
	}

	private function rasa_api_key() {
		$constant = $this->constant_value( 'STW_RASA_API_KEY' );
		if ( '' !== $constant ) {
			return $constant;
		}

		$gateway_value = $this->decrypt_secret( (string) $this->option( 'rasa_api_key', '' ) );
		if ( '' !== $gateway_value ) {
			return $gateway_value;
		}

		return $this->sync_plugin_setting( 'api_key' );
	}

	private function sync_plugin_setting( $key ) {
		$settings = get_option( 'mailpoet_rasa_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings[ $key ] ) ) {
			return '';
		}

		return trim( (string) $settings[ $key ] );
	}

	private function constant_value( $base_name ) {
		$blog_constant = $base_name . '_' . get_current_blog_id();
		if ( defined( $blog_constant ) ) {
			return (string) constant( $blog_constant );
		}
		if ( defined( $base_name ) ) {
			return (string) constant( $base_name );
		}
		return '';
	}

	private function encrypt_secret( $value ) {
		if ( function_exists( 'openssl_encrypt' ) && defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$iv = random_bytes( 12 );
			$key = hash( 'sha256', AUTH_KEY, true );
			$ciphertext = openssl_encrypt( (string) $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ciphertext ) {
				return 'v1:' . base64_encode( $iv . $tag . $ciphertext );
			}
		}
		return 'plain:' . base64_encode( (string) $value );
	}

	private function decrypt_secret( $value ) {
		if ( 0 === strpos( $value, 'plain:' ) ) {
			return (string) base64_decode( substr( $value, 6 ), true );
		}
		if ( 0 !== strpos( $value, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) || ! AUTH_KEY ) {
			return '';
		}
		$decoded = base64_decode( substr( $value, 3 ), true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return '';
		}
		$iv = substr( $decoded, 0, 12 );
		$tag = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$key = hash( 'sha256', AUTH_KEY, true );
		$plain = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : (string) $plain;
	}
}

register_activation_hook( __FILE__, array( 'STW_Dashboard_Mailing_Stats', 'activate' ) );
add_action( 'plugins_loaded', array( 'STW_Dashboard_Mailing_Stats', 'init' ) );
