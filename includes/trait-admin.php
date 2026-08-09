<?php
/**
 * Admin settings UI for the dashboard stats gateway.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_Admin {
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
}
