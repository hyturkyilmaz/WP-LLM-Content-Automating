<?php
defined( "ABSPATH" ) || exit;

/**
 * HYT Security Utils
 * Centralized security helpers: SSRF whitelist, rate limiting, encryption
 */
class HYT_Security {

    // Allowed domains for external requests (SSRF protection)
    private const ALLOWED_DOMAINS = [
        "accounts.google.com",
        "oauth2.googleapis.com",
        "www.googleapis.com",
        "generativelanguage.googleapis.com",
        "graph.facebook.com",
        "api.linkedin.com",
        "www.youtube.com",
        "youtube.googleapis.com",
        "api.github.com",
        "api.anthropic.com",
        "api.openai.com",
        "api.groq.com",
        "api.heygen.com",
    ];

    // Rate limit per user action (transient key pattern)
    private const RATE_LIMIT_SECONDS = 5;

    /**
     * Validate URL against SSRF whitelist
     */
    public static function validate_url( string $url ): bool {
        $parsed = parse_url( $url );
        if ( $parsed === false || empty( $parsed["host"] ) ) {
            return false;
        }
        $host = strtolower( $parsed["host"] );
        foreach ( self::ALLOWED_DOMAINS as $allowed ) {
            if ( $host === $allowed || str_ends_with( $host, ".".$allowed ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wrapped wp_remote_get with SSRF check
     */
    public static function safe_remote_get( string $url, array $args = [] ) {
        if ( ! self::validate_url( $url ) ) {
            HYT_Logger::error( "security", "SSRF blocked � disallowed URL", [ "url" => $url ] );
            return new WP_Error( "ssrf_blocked", "URL not allowed" );
        }
        return wp_remote_get( $url, $args );
    }

    /**
     * Wrapped wp_remote_post with SSRF check
     */
    public static function safe_remote_post( string $url, array $args = [] ) {
        if ( ! self::validate_url( $url ) ) {
            HYT_Logger::error( "security", "SSRF blocked � disallowed URL", [ "url" => $url ] );
            return new WP_Error( "ssrf_blocked", "URL not allowed" );
        }
        return wp_remote_post( $url, $args );
    }

    /**
     * Wrapped wp_remote_request with SSRF check (for PUT/DELETE etc.)
     */
    public static function safe_remote_request( string $url, array $args = [] ) {
        if ( ! self::validate_url( $url ) ) {
            HYT_Logger::error( "security", "SSRF blocked in remote_request", [ "url" => $url ] );
            return new WP_Error( "ssrf_blocked", "URL not allowed" );
        }
        return wp_remote_request( $url, $args );
    }

    /**
     * Wrapped download_url with SSRF check
     */
    public static function safe_download_url( string $url ) {
        if ( ! self::validate_url( $url ) ) {
            HYT_Logger::error( "security", "SSRF blocked in download_url", [ "url" => $url ] );
            return new WP_Error( "ssrf_blocked", "URL not allowed" );
        }
        return download_url( $url );
    }

    /**
     * Check rate limit for current user + action
     */
    public static function check_rate_limit( string $action ): bool {
        $key  = "hyt_rate_{$action}_" . get_current_user_id();
        $last = get_transient( $key );
        if ( $last && ( time() - (int) $last ) < self::RATE_LIMIT_SECONDS ) {
            return false; // Rate limited
        }
        set_transient( $key, time(), self::RATE_LIMIT_SECONDS );
        return true;
    }

    /**
     * Encrypt/decrypt using WordPress salts
     */
    public static function encrypt( string $value ): string {
        $method = "AES-256-CBC";
        $iv_len = openssl_cipher_iv_length( $method );
        $iv     = openssl_random_pseudo_bytes( $iv_len );
        $key    = self::get_encryption_key();
        $ct     = openssl_encrypt( $value, $method, $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $ct );
    }

    public static function decrypt( string $payload ) {
        $method = "AES-256-CBC";
        $data   = base64_decode( $payload, true );
        if ( $data === false ) return false;
        $iv_len = openssl_cipher_iv_length( $method );
        $iv     = substr( $data, 0, $iv_len );
        $ct     = substr( $data, $iv_len );
        $key    = self::get_encryption_key();
        return openssl_decrypt( $ct, $method, $key, OPENSSL_RAW_DATA, $iv );
    }

    private static function get_encryption_key() {
        // Derive key from WP salts (unique per site)
        $salt = AUTH_KEY . SECURE_AUTH_KEY;
        return hash( "sha256", $salt, true );
    }
}


