<?php
defined( 'ABSPATH' ) || exit;

class HYT_Holidays {

    /**
     * Türkiye resmî tatil günleri (sabit, yıldan yıla değişmez).
     */
    public static function get_fixed_holidays( int $year ): array {
        return [
            "$year-01-01", // Yılbaşı
            "$year-04-23", // Ulusal Egemenlik ve Çocuk Bayramı
            "$year-05-01", // Emek ve Dayanışma Günü
            "$year-05-19", // Atatürk'ü Anma, Gençlik ve Spor Bayramı
            "$year-07-15", // Demokrasi ve Millî Birlik Günü
            "$year-08-30", // Zafer Bayramı
            "$year-10-29", // Cumhuriyet Bayramı
        ];
    }

    /**
     * İslami bayram günleri — ayarlardan okunur, kullanıcı tarafından güncellenir.
     * Format: ["2026-03-29","2026-03-30","2026-03-31","2026-06-05","2026-06-06","2026-06-07","2026-06-08","2026-06-09"]
     */
    public static function get_islamic_holidays(): array {
        $raw = get_option( 'hyt_islamic_holidays', '[]' );
        // get_option bazen zaten decode edilmiş array döndürebilir
        if ( is_array( $raw ) ) {
            return $raw;
        }
        $arr = json_decode( (string) $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            HYT_Logger::warning( 'holidays', 'Geçersiz tatil JSON: ' . json_last_error_msg(), [
                'raw_value' => substr( (string)$raw, 0, 200 ),
            ] );
            return [];
        }
        return is_array( $arr ) ? $arr : [];
    }

    /**
     * Verilen tarih tatil günü mü?
     */
    public static function is_holiday( string $date ): bool {
        $year    = (int) date( 'Y', strtotime( $date ) );
        $fixed   = self::get_fixed_holidays( $year );
        $islamic = self::get_islamic_holidays();
        return in_array( $date, $fixed, true ) || in_array( $date, $islamic, true );
    }

    /**
     * Bu yıl ve gelecek yılın tatil listesini döndürür (UI için).
     */
    public static function get_all_holidays_for_display(): array {
        $year    = (int) date( 'Y' );
        $fixed   = array_merge(
            self::get_fixed_holidays( $year ),
            self::get_fixed_holidays( $year + 1 )
        );
        $islamic = self::get_islamic_holidays();
        $all     = array_unique( array_merge( $fixed, $islamic ) );
        sort( $all );
        return $all;
    }
}
