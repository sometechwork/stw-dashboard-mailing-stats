<?php
/**
 * Shared utility methods for the dashboard stats gateway.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_Utilities {
	private function rate( $numerator, $denominator ) {
		return $denominator > 0 ? round( ( $numerator / $denominator ) * 100, 2 ) : 0;
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

	private function first_existing_column( $table, array $columns ) {
		global $wpdb;
		foreach ( $columns as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
			if ( $found === $column ) {
				return $column;
			}
		}
		return '';
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
		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$key        = hash( 'sha256', AUTH_KEY, true );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : (string) $plain;
	}
}
