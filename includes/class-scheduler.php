<?php
defined( 'ABSPATH' ) || exit;

class HYT_Scheduler {

    const CRON_PIPELINE  = 'hyt_run_pipeline';
    const CRON_GDRIVE    = 'hyt_scan_gdrive';

    public static function register_crons(): void {
        if ( ! wp_next_scheduled( self::CRON_PIPELINE ) ) {
            wp_schedule_event( time(), 'hyt_every_5min', self::CRON_PIPELINE );
        }
        self::schedule_gdrive_scan();
    }

    public static function clear_crons(): void {
        wp_clear_scheduled_hook( self::CRON_PIPELINE );
        wp_clear_scheduled_hook( self::CRON_GDRIVE );
        wp_clear_scheduled_hook( 'hyt_run_heygen_poll' );
        wp_clear_scheduled_hook( 'hyt_run_distribution' );
        wp_clear_scheduled_hook( 'hyt_distribute_video' );
        wp_clear_scheduled_hook( 'hyt_generate_image' );
        wp_clear_scheduled_hook( 'hyt_start_video_pipeline' );
    }

    public static function init(): void {
        /* Özel cron aralýklarý */
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );

        /* Cron event handler'larý */
        add_action( self::CRON_PIPELINE,            [ __CLASS__, 'run_pipeline_queue' ] );
        add_action( self::CRON_GDRIVE,              [ __CLASS__, 'run_gdrive_scan' ] );
        add_action( 'hyt_run_heygen_poll',          [ __CLASS__, 'run_heygen_poll' ] );
        add_action( 'hyt_run_distribution',         [ __CLASS__, 'run_distribution' ] );
        add_action( 'hyt_distribute_video',         [ __CLASS__, 'run_distribute_video' ] );

        /* HeyGen polling her 15 dk */
        if ( ! wp_next_scheduled( 'hyt_run_heygen_poll' ) ) {
            wp_schedule_event( time(), 'hyt_every_15min', 'hyt_run_heygen_poll' );
        }
    }

    public static function add_cron_intervals( array $schedules ): array {
        $schedules['hyt_every_5min'] = [
            'interval' => 300,
            'display'  => __( 'Her 5 Dakika', 'hyt-content-automation' ),
        ];
        $schedules['hyt_every_15min'] = [
            'interval' => 900,
            'display'  => __( 'Her 15 Dakika', 'hyt-content-automation' ),
        ];
        $schedules['hyt_every_hour'] = [
            'interval' => 3600,
            'display'  => __( 'Her Saat', 'hyt-content-automation' ),
        ];
        $schedules['hyt_twice_daily_custom'] = [
            'interval' => 43200,
            'display'  => __( 'Günde 2 Kez', 'hyt-content-automation' ),
        ];
        return $schedules;
    }

    public static function schedule_gdrive_scan(): void {
        wp_clear_scheduled_hook( self::CRON_GDRIVE );
        $freq = get_option( 'hyt_gdrive_scan_frequency', 'hyt_every_15min' );
        if ( $freq !== 'disabled' ) {
            wp_schedule_event( time(), $freq, self::CRON_GDRIVE );
        }
    }

    /* ---- Pipeline Queue Runner ---- */
    public static function run_pipeline_queue(): void {
        global $wpdb;

        /* Her cron turunda max 3 içerik iþle */
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hyt_pipeline
              WHERE status = 'pending'
              ORDER BY created_at ASC
              LIMIT 3"
        );

        foreach ( $rows as $row ) {
            HYT_Database::update_pipeline( (int) $row->id, [ 'status' => 'processing', 'step' => 'started' ] );
            try {
                $pipeline = new HYT_Content_Pipeline( $row );
                $pipeline->run();
            } catch ( Throwable $e ) {
                HYT_Database::update_pipeline( (int) $row->id, [
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ] );
                HYT_Logger::error( 'scheduler', 'Pipeline çalýþýrken hata: ' . $e->getMessage(), [
                    'pipeline_id' => $row->id,
                    'trace'       => substr( $e->getTraceAsString(), 0, 500 ),
                ] );
            }
        }
    }

    /* ---- Yeni Cron Action Handler'larý ---- */

    public static function run_heygen_poll(): void {
        try {
            $heygen = new HYT_HeyGen();
            $heygen->poll_pending_videos();
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'heygen', 'Polling hatasý: ' . $e->getMessage() );
        }
    }

    public static function run_distribution( int $pipeline_id ): void {
        try {
            $dist = new HYT_Distribution();
            $dist->distribute( $pipeline_id );
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'distribution', 'Daðýtým hatasý: ' . $e->getMessage() );
        }
    }

    public static function run_distribute_video( int $pipeline_id ): void {
        try {
            $dist = new HYT_Distribution();
            $dist->distribute_video( $pipeline_id );
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'distribution', 'Video daðýtým hatasý: ' . $e->getMessage() );
        }
    }

    public static function run_generate_image( int $pipeline_id ): void {
        try {
            HYT_Image_Generator::generate_for_pipeline( $pipeline_id );
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'image', 'Görsel üretim hatasý: ' . $e->getMessage() );
        }
    }

    public static function run_video_pipeline( int $pipeline_id ): void {
        try {
            $vp = new HYT_Video_Pipeline();
            $vp->run( $pipeline_id );
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'heygen', 'Video pipeline hatasý: ' . $e->getMessage() );
        }
    }

    /* ---- Google Drive Scan ---- */
    public static function run_gdrive_scan(): void {
        if ( ! get_option( 'hyt_gdrive_client_id' ) ) {
            return;
        }
        try {
            $drive = new HYT_Google_Drive();
            $drive->scan_and_queue();
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'gdrive', 'Drive tarama hatasý: ' . $e->getMessage() );
        }
    }

    /**
     * Bir sonraki uygun yayýn tarihini hesapla.
     *
     * @param  string $from_date  Baþlangýç tarihi (Y-m-d), varsayýlan bugün.
     * @return string|null        Yayýn tarihi (Y-m-d H:i:s) veya null.
     */
    public static function next_available_slot( string $from_date = '' ): ?string {
        $publish_days = get_option( 'hyt_publish_days', [ 'monday', 'wednesday', 'friday' ] );
        $publish_time = get_option( 'hyt_publish_time', '08:45' );

        if ( empty( $publish_days ) ) {
            return null;
        }

        $day_map = [
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
            'sunday'    => 0,
        ];

        $allowed_dow = array_map(function($d) use ($day_map) { $key = strtolower($d); return isset($day_map[$key]) ? $day_map[$key] : -1; }, (array)$publish_days);

        $date = $from_date ? strtotime( $from_date ) : strtotime( 'today' );

        for ( $i = 0; $i < 90; $i++ ) {
            $check     = strtotime( "+$i days", $date );
            $date_str  = date( 'Y-m-d', $check );
            $dow       = (int) date( 'w', $check );

            if ( ! in_array( $dow, $allowed_dow, true ) ) {
                continue;
            }
            if ( HYT_Holidays::is_holiday( $date_str ) ) {
                continue;
            }
            /* O günde zaten planlanmýþ post var mý? */
            if ( self::slot_has_post( $date_str ) ) {
                continue;
            }

            return $date_str . ' ' . $publish_time . ':00';
        }

        return null;
    }

    /**
     * Verilen günde zaten planlanmýþ bir WP post ya da pipeline kaydý var mý?
     */
    private static function slot_has_post( string $date_str ): bool {
        global $wpdb;

        /* WP tarafýnda bu günde planlanmýþ post */
        $wp_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_status = 'future'
                AND DATE(post_date) = %s",
            $date_str
        ) );
        if ( $wp_count > 0 ) {
            return true;
        }

        /* Pipeline tarafýnda bu günde planlanmýþ kayýt */
        $pipe_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hyt_pipeline
              WHERE DATE(scheduled_at) = %s
                AND status NOT IN ('failed','cancelled')",
            $date_str
        ) );
        return $pipe_count > 0;
    }
}

