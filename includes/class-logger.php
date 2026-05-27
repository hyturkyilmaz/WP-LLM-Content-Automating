<?php
defined( 'ABSPATH' ) || exit;

class HYT_Logger {

    public static function info( string $context, string $message, mixed $data = null ): void {
        self::write( 'info', $context, $message, $data );
    }

    public static function warning( string $context, string $message, mixed $data = null ): void {
        self::write( 'warning', $context, $message, $data );
    }

    public static function error( string $context, string $message, mixed $data = null ): void {
        self::write( 'error', $context, $message, $data );
    }

    private static function write( string $level, string $context, string $message, mixed $data = null ): void {
        HYT_Database::insert_log( $level, $context, $message, $data );
    }
}
