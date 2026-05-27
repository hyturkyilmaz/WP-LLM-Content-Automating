<?php
defined( 'ABSPATH' ) || exit;

class HYT_Distribution {

    private HYT_Social $social;
    private HYT_Claude $claude;

    public function __construct() {
        $this->social = new HYT_Social();
        $this->claude = new HYT_Claude();
    }

    /**
     * Blog yayÄ±nlandÄ±ktan sonra sosyal medya daÄŸÄ±tÄ±mÄ±nÄ± baÅŸlatÄ±r.
     * WP Cron tarafÄ±ndan +30 dk sonra tetiklenir.
     */
    public function distribute( int $pipeline_id ): void {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row || ! $row->post_id ) {
            HYT_Logger::warning( 'distribution', "DaÄŸÄ±tÄ±m: Pipeline veya post bulunamadÄ±. #{$pipeline_id}" );
            return;
        }

        $post_id = (int) $row->post_id;
        $title   = get_the_title( $post_id );
        $url     = get_permalink( $post_id );
        $excerpt = get_the_excerpt( $post_id );

        // flag_social = 2 ise zaten yayÄ±nlandÄ±
        if ( (int) $row->flag_social === 2 ) {
            HYT_Logger::info( 'distribution', "Zaten daÄŸÄ±tÄ±ldÄ±. Pipeline #{$pipeline_id}" );
            return;
        }

        // Sosyal medya metinleri al
        $social_texts = $this->get_social_texts( $row, $title, $excerpt, $url );

        if ( empty( $social_texts ) ) {
            HYT_Logger::warning( 'distribution', "Sosyal medya metni Ã¼retilemedi. Pipeline #{$pipeline_id}" );
            return;
        }

        // Metinleri payload'a kaydet, flag_social = 1 (metinler hazÄ±r)
        $payload = json_decode( $row->payload ?? '{}', true ) ?: [];
        $payload['social_texts'] = $social_texts;
        HYT_Database::update_pipeline( $pipeline_id, [
            'flag_social' => 1,
            'payload'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
        ] );

        // KanallarÄ± daÄŸÄ±t
        $distributed = 0;
        $channels    = $this->get_active_channels();

        foreach ( $channels as $channel ) {
            $result = $this->send_to_channel( $channel, $social_texts, $title, $url, $row );
            if ( $result ) {
                HYT_Database::insert_distribution( [
                    'pipeline_id' => $pipeline_id,
                    'channel'     => $channel,
                    'status'      => 'sent',
                    'channel_id'  => is_array( $result ) ? ( $result['id'] ?? '' ) : '',
                    'response'    => is_array( $result ) ? wp_json_encode( $result ) : '',
                ] );
                $distributed++;
            } else {
                HYT_Database::insert_distribution( [
                    'pipeline_id' => $pipeline_id,
                    'channel'     => $channel,
                    'status'      => 'failed',
                ] );
            }
        }

        // flag_social = 2 (yayÄ±nlandÄ±)
        if ( $distributed > 0 ) {
            HYT_Database::update_pipeline( $pipeline_id, [ 'flag_social' => 2 ] );
            HYT_Logger::info( 'distribution', "{$distributed} kanal iÃ§in daÄŸÄ±tÄ±m tamamlandÄ±. Pipeline #{$pipeline_id}" );
        } else {
            HYT_Logger::warning( 'distribution', "HiÃ§bir kanala daÄŸÄ±tÄ±lamadÄ±. Pipeline #{$pipeline_id}" );
        }
    }

    /**
     * Alias â€” scheduler "distribute_post" adÄ±yla Ã§aÄŸÄ±rÄ±r.
     */
    public function distribute_post( int $pipeline_id ): void {
        $this->distribute( $pipeline_id );
    }

    /**
     * Video tamamlandÄ±ktan sonra video kanallarÄ±na daÄŸÄ±tÄ±m (YouTube Shorts/Reels).
     */
    public function distribute_video( int $pipeline_id ): void {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row ) return;

        $video_url = $row->heygen_video_url ?? '';
        if ( ! $video_url ) {
            HYT_Logger::warning( 'distribution', "Video URL bulunamadÄ±. Pipeline #{$pipeline_id}" );
            return;
        }

        $post_id = (int) $row->post_id;
        $title   = $row->title ?? '';
        $payload = json_decode( $row->payload ?? '{}', true ) ?: [];
        $scripts = $payload['video_scripts'] ?? [];
        $yt_desc = $payload['social_texts']['youtube_desc'] ?? $title;

        $distributed = 0;

        // Instagram Reels
        if ( get_option( 'hyt_channel_instagram_reels', '0' ) && $this->social->is_instagram_configured() ) {
            $caption = $payload['social_texts']['instagram'] ?? $title;
            $result  = $this->social->post_instagram_reels( $caption, $video_url );
            HYT_Database::insert_distribution( [
                'pipeline_id' => $pipeline_id,
                'channel'     => 'instagram_reels',
                'status'      => $result ? 'sent' : 'failed',
                'channel_id'  => is_array( $result ) ? ( $result['id'] ?? '' ) : '',
            ] );
            if ( $result ) $distributed++;
        }

        // YouTube â€” video dosyasÄ±nÄ± indir, sonra yÃ¼kle
        if ( get_option( 'hyt_channel_youtube', '0' ) && $this->social->is_youtube_configured() ) {
            $tmp = download_url( $video_url );
            if ( ! is_wp_error( $tmp ) ) {
                $is_short   = str_contains( $video_url, 'short' ) || ! get_option( 'hyt_heygen_long_video_enabled', '0' );
                $yt_result  = $this->social->upload_to_youtube( $tmp, $title, $yt_desc, '', $is_short );
                @unlink( $tmp );
                HYT_Database::insert_distribution( [
                    'pipeline_id' => $pipeline_id,
                    'channel'     => 'youtube',
                    'status'      => $yt_result ? 'sent' : 'failed',
                    'channel_id'  => is_array( $yt_result ) ? ( $yt_result['id'] ?? '' ) : '',
                ] );
                if ( $yt_result ) $distributed++;
            }
        }

        if ( $distributed > 0 ) {
            HYT_Database::update_pipeline( $pipeline_id, [ 'flag_video_pub' => 1 ] );
            HYT_Logger::info( 'distribution', "Video {$distributed} kanala daÄŸÄ±tÄ±ldÄ±. Pipeline #{$pipeline_id}" );
        }
    }

    /* ---- Kanal GÃ¶nderimi ---- */

    private function send_to_channel( string $channel, array $texts, string $title, string $url, object $row ): mixed {
        switch ( $channel ) {
            case 'facebook':
                $msg = ( $texts['facebook'] ?? $title ) . "\n\n" . $url;
                return $this->social->post_to_facebook( $msg, $url );

            case 'instagram':
                $image_url = $this->get_featured_image_url( (int) $row->post_id );
                if ( ! $image_url ) return false;
                return $this->social->post_to_instagram(
                    $texts['instagram'] ?? $title,
                    $image_url
                );

            case 'linkedin':
                return $this->social->post_to_linkedin(
                    $texts['linkedin'] ?? $title,
                    $url
                );

            case 'youtube':
                // YouTube video yÃ¼klemesi video pipeline tarafÄ±ndan yapÄ±lÄ±r
                return null;
        }
        return false;
    }

    /* ---- Sosyal Medya Metinleri ---- */

    private function get_social_texts( object $row, string $title, string $excerpt, string $url ): array {
        // Daha Ã¶nce Ã¼retilmiÅŸse payload'dan al
        if ( $row->payload ) {
            $payload = json_decode( $row->payload, true );
            if ( ! empty( $payload['social_texts'] ) ) {
                return $payload['social_texts'];
            }
        }

        // Claude ile Ã¼ret
        if ( $this->claude->is_configured() ) {
            return $this->claude->generate_social_texts( $title, $excerpt, $url );
        }

        // Fallback: Basit metin
        return [
            'instagram'   => "âœ¨ {$title}\n\n{$excerpt}\n\nğŸ”— {$url}",
            'facebook'    => "{$title}\n\n{$excerpt}\n\n{$url}",
            'linkedin'    => "Yeni YazÄ±: {$title}\n\n{$excerpt}\n\n{$url}",
            'twitter'     => "{$title} {$url}",
            'youtube_desc' => "{$title}\n\n{$excerpt}",
        ];
    }

    /* ---- Aktif Kanallar ---- */

    private function get_active_channels(): array {
        $channels = [];
        if ( get_option( 'hyt_social_facebook_enabled', '0' ) && $this->social->is_facebook_configured() ) {
            $channels[] = 'facebook';
        }
        if ( get_option( 'hyt_social_instagram_enabled', '0' ) && $this->social->is_instagram_configured() ) {
            $channels[] = 'instagram';
        }
        if ( get_option( 'hyt_social_linkedin_enabled', '0' ) && $this->social->is_linkedin_configured() ) {
            $channels[] = 'linkedin';
        }
        return $channels;
    }

    /* ---- Featured Image URL ---- */

    private function get_featured_image_url( int $post_id ): string {
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumbnail_id ) return '';
        $src = wp_get_attachment_image_src( $thumbnail_id, 'large' );
        return $src ? $src[0] : '';
    }

    /* ---- WP Cron Hook ---- */

    public static function schedule_distribution( int $pipeline_id ): void {
        $delay = (int) get_option( 'hyt_social_delay_minutes', 30 );
        wp_schedule_single_event(
            time() + ( $delay * 60 ),
            'hyt_run_distribution',
            [ $pipeline_id ]
        );
        HYT_Logger::info( 'distribution', "DaÄŸÄ±tÄ±m {$delay} dk sonraya planlandÄ±. Pipeline #{$pipeline_id}" );
    }
}



