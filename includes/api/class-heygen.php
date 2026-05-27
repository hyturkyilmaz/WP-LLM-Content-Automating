<?php
defined( 'ABSPATH' ) || exit;

class HYT_HeyGen {

    private $api_key;
    private $avatar_id;
    private $voice_id;
    const BASE_URL = 'https://api.heygen.com/v2';

    public function __construct() {
        $this->api_key   = get_option( 'hyt_heygen_api_key', '' );
        $this->avatar_id = get_option( 'hyt_heygen_avatar_id', '' );
        $this->voice_id  = get_option( 'hyt_heygen_voice_id', '' );
    }

    public function is_configured(): bool {
        return ! empty( $this->api_key ) && ! empty( $this->avatar_id ) && ! empty( $this->voice_id );
    }

    /* ---- Video Oluştur ---- */

    /**
     * Uzun YouTube videosu oluştur (16:9).
     */
    public function create_long_video( int $pipeline_id, string $script ): array|false {
        return $this->create_video( $pipeline_id, $script, '16:9', 'long' );
    }

    /**
     * Kısa dikey video oluştur (9:16) — Reels/Shorts/TikTok.
     */
    public function create_short_video( int $pipeline_id, string $script, int $index = 1 ): array|false {
        return $this->create_video( $pipeline_id, $script, '9:16', 'short_' . $index );
    }

    private function create_video( int $pipeline_id, string $script, string $ratio, string $type ): array|false {
        if ( ! $this->is_configured() ) {
            HYT_Logger::error( 'heygen', 'HeyGen yapılandırılmamış.' );
            return false;
        }

        $dimension = $ratio === '9:16'
            ? [ 'width' => 1080, 'height' => 1920 ]
            : [ 'width' => 1920, 'height' => 1080 ];

        $body = [
            'video_inputs' => [
                [
                    'character' => [
                        'type'      => 'avatar',
                        'avatar_id' => $this->avatar_id,
                        'avatar_style' => 'normal',
                    ],
                    'voice' => [
                        'type'     => 'text',
                        'input_text' => $script,
                        'voice_id'   => $this->voice_id,
                    ],
                    'background' => [
                        'type'  => 'color',
                        'value' => '#f8fafc',
                    ],
                ],
            ],
            'dimension'      => $dimension,
            'callback_id'    => "hyt_{$pipeline_id}_{$type}",
            'callback_url'   => rest_url( 'hyt/v1/heygen-callback' ),
        ];

        $response = $this->api_post( '/video/generate', $body );

        if ( ! $response || empty( $response['data']['video_id'] ) ) {
            HYT_Logger::error( 'heygen', "Video oluşturulamadı (type: {$type})", $response );
            return false;
        }

        $video_id = $response['data']['video_id'];
        HYT_Logger::info( 'heygen', "Video başlatıldı: {$video_id} (type: {$type}, pipeline: #{$pipeline_id})" );

        return [ 'video_id' => $video_id, 'type' => $type ];
    }

    /* ---- Video Durum Sorgula ---- */

    public function get_video_status( string $video_id ): ?array {
        $response = $this->api_get( "/video/{$video_id}" );
        return $response['data'] ?? null;
    }

    /* ---- Polling — tamamlanan videoları indir ---- */

    public function poll_pending_videos(): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}hyt_pipeline
             WHERE status = 'video_wait'
               AND heygen_video_id IS NOT NULL
             LIMIT 5"
        );

        foreach ( $rows as $row ) {
            $data = $this->get_video_status( $row->heygen_video_id );
            if ( ! $data ) continue;

            $video_status = $data['status'] ?? '';

            if ( $video_status === 'completed' ) {
                $video_url = $data['video_url'] ?? '';
                $this->finalize_video( (int) $row->id, (int) $row->post_id, $video_url );
            } elseif ( $video_status === 'failed' ) {
                HYT_Database::update_pipeline( (int) $row->id, [
                    'status'        => 'failed',
                    'error_message' => 'HeyGen video üretimi başarısız.',
                ] );
                HYT_Logger::error( 'heygen', "Video başarısız: {$row->heygen_video_id}" );
            }
        }
    }

    /**
     * Video tamamlandığında çağrılır — WP medyasına indir.
     */
    public function finalize_video( int $pipeline_id, int $post_id, string $video_url ): void {
        // WP medyasına indir
        $attachment_id = $this->download_to_media( $video_url, $pipeline_id );

        HYT_Database::update_pipeline( $pipeline_id, [
            'status'          => 'done',
            'flag_video'      => 1,
            'heygen_video_url' => $video_url,
            'step'            => 'video_ready',
        ] );

        HYT_Logger::info( 'heygen', "Video tamamlandı ve medyaya indirildi. Pipeline #{$pipeline_id}" );

        // Sosyal dağıtım planla (video kanalları)
        $delay = (int) get_option( 'hyt_social_delay_minutes', 30 );
        wp_schedule_single_event(
            time() + ( $delay * 60 ),
            'hyt_distribute_video',
            [ $pipeline_id ]
        );
    }

    /**
     * Video URL'sini WP medya kütüphanesine indirir.
     */
    private function download_to_media( string $url, int $pipeline_id ): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = HYT_Security::safe_download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            HYT_Logger::error( 'heygen', 'Video indirilemedi: ' . $tmp->get_error_message() );
            return 0;
        }

        $file_array = [
            'name'     => "hyt-video-{$pipeline_id}-" . time() . '.mp4',
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload( $file_array, 0 );
        @unlink( $tmp );

        if ( is_wp_error( $id ) ) {
            HYT_Logger::error( 'heygen', 'Medyaya eklenemedi: ' . $id->get_error_message() );
            return 0;
        }

        return $id;
    }

    /* ---- Webhook Callback (REST) ---- */

    public static function webhook_callback( WP_REST_Request $request ): WP_REST_Response {
        $body      = $request->get_json_params();
        $video_id  = sanitize_text_field( $body['video_id'] ?? '' );
        $status    = sanitize_text_field( $body['status']   ?? '' );
        $video_url = sanitize_text_field( $body['video_url'] ?? '' );
        $callback_id = sanitize_text_field( $body['callback_id'] ?? '' );

        HYT_Logger::info( 'heygen', "Webhook alındı: {$video_id} → {$status}", $body );

        if ( $status !== 'completed' || ! $video_url ) {
            return new WP_REST_Response( [ 'ok' => false ], 200 );
        }

        // callback_id: hyt_{pipeline_id}_{type}
        if ( preg_match( '/^hyt_(\d+)_/', $callback_id, $m ) ) {
            $pipeline_id = (int) $m[1];
            $row         = HYT_Database::get_pipeline( $pipeline_id );
            if ( $row ) {
                $heygen = new self();
                $heygen->finalize_video( $pipeline_id, (int) $row->post_id, $video_url );
            }
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /* ---- Avatar Listesi ---- */

    public function list_avatars(): array {
        $response = $this->api_get( '/avatars' );
        return $response['data']['avatars'] ?? [];
    }

    /* ---- API Helpers ---- */

    private function api_post( string $endpoint, array $body ): ?array {
        $response = HYT_Security::safe_remote_post( self::BASE_URL . $endpoint, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Api-Key'    => $this->api_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'heygen', 'POST hata: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            HYT_Logger::error( 'heygen', "API {$code}", [ 'body' => $data ] );
            return null;
        }

        return $data;
    }

    private function api_get( string $endpoint ): ?array {
        $response = HYT_Security::safe_remote_get( self::BASE_URL . $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Accept'    => 'application/json',
                'X-Api-Key' => $this->api_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'heygen', 'GET hata: ' . $response->get_error_message() );
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /* ---- Test ---- */
    public function test_connection(): array {
        if ( ! $this->api_key ) {
            return [ 'success' => false, 'message' => 'API key girilmemiş.' ];
        }
        $avatars = $this->list_avatars();
        if ( is_array( $avatars ) ) {
            return [ 'success' => true, 'message' => count( $avatars ) . ' avatar bulundu.' ];
        }
        return [ 'success' => false, 'message' => 'Bağlantı başarısız.' ];
    }
}

