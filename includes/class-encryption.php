<?php
/**
 * HYT Encryption — WordPress option'larini AES-256-CBC ile sifreler/cozer.
 */
defined( 'ABSPATH' ) || exit;

class HYT_Encryption {

	public static function migrate_all(): void {
		$secrets = [
			'hyt_claude_api_key',
			'hyt_openai_api_key',
			'hyt_gdrive_client_secret',
			'hyt_gdrive_client_id',
			'hyt_heygen_api_key',
			'hyt_meta_page_token',
			'hyt_linkedin_access_token',
			'hyt_youtube_client_secret',
			'hyt_youtube_client_id',
			'hyt_youtube_access_token',
			'hyt_youtube_refresh_token',
		];

		foreach ( $secrets as $option_name ) {
			$value = get_option( $option_name, '' );
			if ( $value && ! self::is_encrypted( $value ) ) {
				$encrypted = self::encrypt( $value );
				update_option( $option_name, $encrypted );
			}
		}
	}

	private static function is_encrypted( string $value ): bool {
		$decoded = base64_decode( $value, true );
		if ( $decoded === false ) {
			return true;
		}
		if ( strlen( $decoded ) < 32 ) {
			return false;
		}
		return true;
	}

	public static function decrypt_option( $value ) {
		if ( ! is_string( $value ) || empty( $value ) ) {
			return $value;
		}
		$decrypted = self::decrypt( $value );
		return $decrypted !== false ? $decrypted : $value;
	}

	public static function encrypt_option( $value ) {
		if ( ! is_string( $value ) || empty( $value ) ) {
			return $value;
		}
		if ( self::is_encrypted( $value ) ) {
			return $value;
		}
		return self::encrypt( $value );
	}

	public static function encrypt( string $value ): string {
		$method = 'AES-256-CBC';
		$iv_len = openssl_cipher_iv_length( $method );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$key    = self::get_encryption_key();
		$ct     = openssl_encrypt( $value, $method, $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $ct );
	}

	public static function decrypt( string $payload ) {
		$method = 'AES-256-CBC';
		$data   = base64_decode( $payload, true );
		if ( $data === false ) {
			return false;
		}
		$iv_len = openssl_cipher_iv_length( $method );
		if ( strlen( $data ) < $iv_len ) {
			return false;
		}
		$iv  = substr( $data, 0, $iv_len );
		$ct  = substr( $data, $iv_len );
		$key = self::get_encryption_key();
		return openssl_decrypt( $ct, $method, $key, OPENSSL_RAW_DATA, $iv );
	}

	private static function get_encryption_key() {
		$salt = AUTH_KEY . SECURE_AUTH_KEY;
		return hash( 'sha256', $salt, true );
	}
}
