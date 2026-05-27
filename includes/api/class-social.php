<?php
defined( 'ABSPATH' ) || exit;

/**
 * HYT Social Media Distribution
 * Facebook · Instagram · LinkedIn · YouTube
 */
class HYT_Social {

    const OAUTH_STATE_TRANSIENT = 'hyt_oauth_state_';

    /* ================================================================
       FACEBOOK PAGE
    ================================================================ */
    public function post_to_facebook( string $message, string $link = '' ): array|false {
        $token   = get_option( 'hyt_meta_page_token', '' );
        $page_id = get_option( 'hyt_meta_page_id', '' );

        if ( ! $token || ! $page_id ) {
            HYT_Logger::warning( 'social', 'Facebook Page Token veya Page ID eksik.' );
            return false;
        }

        $body = [ 'message' => $message, 'access_token' => $token ];
        if ( $link ) $body['link'] = $link;

        $response = HYT_Security::safe_remote_post(
            "https://graph.facebook.com/v21.0/{$page_id}/feed",
            [ 'timeout' => 30, 'body' => $body ]
        );

        return $this->parse_meta_response( $response, 'facebook' );
    }

    /* ================================================================
       INSTAGRAM PHOTO
    ================================================================ */
    public function post_to_instagram( $caption, $image_url ) {
        $token   = get_option( 'hyt_meta_page_token', '' );
        $ig_id   = get_option( 'hyt_instagram_business_id', '' );

        if ( ! $token || ! $ig_id || ! $image_url ) {
            HYT_Logger::warning( 'social', 'Instagram yapılandırması eksik.' );
            return false;
        }

        // Adım 1: Media container oluştur
        $container = HYT_Security::safe_remote_post(
            "https://graph.facebook.com/v21.0/{$ig_id}/media",
            [
                'timeout' => 30,
                'body'    => [
                    'image_url'    => $image_url,
                    'caption'      => $caption,
                    'access_token' => $token,
                ],
            ]
        );

        $container_data = $this->parse_meta_response( $container, 'instagram_container' );
        if ( ! $container_data || empty( $container_data['id'] ) ) {
            return false;
        }

        // Adım 2: Yayınla
        $publish = HYT_Security::safe_remote_post(
            "https://graph.facebook.com/v21.0/{$ig_id}/media_publish",
            [
                'timeout' => 30,
                'body'    => [
                    'creation_id'  => $container_data['id'],
                    'access_token' => $token,
                ],
            ]
        );

        return $this->parse_meta_response( $publish, 'instagram' );
    }

    /* ================================================================
       INSTAGRAM REELS
    ================================================================ */
    public function post_instagram_reels( $caption, $video_url ) {
        $token = get_option( 'hyt_meta_page_token', '' );
        $ig_id = get_option( 'hyt_instagram_business_id', '' );

        if ( ! $token || ! $ig_id || ! $video_url ) {
            HYT_Logger::warning( 'social', 'Instagram Reels yapılandırması eksik.' );
            return false;
        }

        // Adım 1: Reels container
        $container = HYT_Security::safe_remote_post(
            "https://graph.facebook.com/v21.0/{$ig_id}/media",
            [
                'timeout' => 60,
                'body'    => [
                    'media_type'   => 'REELS',
                    'video_url'    => $video_url,
                    'caption'      => $caption,
                    'share_to_feed' => 'true',
                    'access_token' => $token,
                ],
            ]
        );

        $container_data = $this->parse_meta_response( $container, 'ig_reels_container' );
        if ( ! $container_data || empty( $container_data['id'] ) ) {
            return false;
        }

        // Adım 2: Durum bekle (polling, max 5 deneme)
        $creation_id = $container_data['id'];
        for ( $i = 0; $i < 5; $i++ ) {
            sleep( 10 );
            $status_resp = HYT_Security::safe_remote_get(
                "https://graph.facebook.com/v21.0/{$creation_id}?fields=status_code&access_token={$token}"
            );
            $status_data = json_decode( wp_remote_retrieve_body( $status_resp ), true );
            if ( ( $status_data['status_code'] ?? '' ) === 'FINISHED' ) break;
        }

        // Adım 3: Yayınla
        $publish = HYT_Security::safe_remote_post(
            "https://graph.facebook.com/v21.0/{$ig_id}/media_publish",
            [
                'timeout' => 30,
                'body'    => [
                    'creation_id'  => $creation_id,
                    'access_token' => $token,
                ],
            ]
        );

        return $this->parse_meta_response( $publish, 'instagram_reels' );
    }

    /* ================================================================
       LINKEDIN
    ================================================================ */
    public function post_to_linkedin( string $text, string $link = '' ): array|false {
        $token   = get_option( 'hyt_linkedin_access_token', '' );
        $person  = get_option( 'hyt_linkedin_person_id', '' ); // urn:li:person:XXX

        if ( ! $token || ! $person ) {
            HYT_Logger::warning( 'social', 'LinkedIn yapılandırması eksik.' );
            return false;
        }

        $author = "urn:li:person:{$person}";
        $share_content = [
            'shareCommentary'    => [ 'text' => $text ],
            'shareMediaCategory' => $link ? 'ARTICLE' : 'NONE',
        ];

        if ( $link ) {
            $share_content['media'] = [
                [
                    'status'         => 'READY',
                    'originalUrl'    => $link,
                    'description'    => [ 'text' => substr( $text, 0, 200 ) ],
                ],
            ];
        }

        $body = [
            'author'         => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => $share_content,
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = HYT_Security::safe_remote_post(
            'https://api.linkedin.com/v2/ugcPosts',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization'  => 'Bearer ' . $token,
                    'Content-Type'   => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                ],
                'body' => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'social', 'LinkedIn hata: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            HYT_Logger::error( 'social', "LinkedIn API {$code}", $data );
            return false;
        }

        HYT_Logger::info( 'social', 'LinkedIn paylaşımı yapıldı.' );
        return $data;
    }

    /* ================================================================
       YOUTUBE
    ================================================================ */

    public function upload_to_youtube( string $video_path, string $title, string $description, string $publish_at = '', bool $is_short = false ): array|false {
        $token = $this->get_valid_youtube_token();
        if ( ! $token ) {
            HYT_Logger::warning( 'social', 'YouTube token mevcut değil.' );
            return false;
        }

        if ( $is_short ) {
            $title = '#Shorts ' . $title;
        }

        // Metadata
        $metadata = [
            'snippet' => [
                'title'       => substr( $title, 0, 100 ),
                'description' => $description,
                'categoryId'  => '22', // People & Blogs
            ],
            'status'  => [
                'privacyStatus'           => $publish_at ? 'private' : 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        if ( $publish_at ) {
            $metadata['status']['publishAt'] = date( 'c', strtotime( $publish_at ) );
        }

        // Resumable upload başlat
        $init = HYT_Security::safe_remote_post(
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization'  => 'Bearer ' . $token,
                    'Content-Type'   => 'application/json',
                    'X-Upload-Content-Type' => 'video/mp4',
                ],
                'body' => wp_json_encode( $metadata ),
            ]
        );

        if ( is_wp_error( $init ) ) {
            HYT_Logger::error( 'social', 'YouTube upload başlatılamadı: ' . $init->get_error_message() );
            return false;
        }

        $upload_url = wp_remote_retrieve_header( $init, 'location' );
        if ( ! $upload_url ) {
            HYT_Logger::error( 'social', 'YouTube upload URL alınamadı.' );
            return false;
        }

        // Dosyayı yükle
        $video_data = file_get_contents( $video_path );
        $upload = wp_remote_request( $upload_url, [
            'method'  => 'PUT',
            'timeout' => 300,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'video/mp4',
            ],
            'body' => $video_data,
        ] );

        if ( is_wp_error( $upload ) ) {
            HYT_Logger::error( 'social', 'YouTube upload başarısız: ' . $upload->get_error_message() );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $upload ), true );
        if ( ! empty( $data['id'] ) ) {
            HYT_Logger::info( 'social', "YouTube'a yüklendi: {$data['id']}" );
            return $data;
        }

        HYT_Logger::error( 'social', 'YouTube upload tamamlanamadı.', $data );
        return false;
    }

    /* ---- YouTube OAuth ---- */

    public function get_youtube_auth_url(): string {
        $client_id    = get_option( 'hyt_youtube_client_id', '' );
        $redirect_uri = rest_url( 'hyt/v1/youtube-oauth-callback' );

        // CSRF protection: state token
        $state = wp_generate_password( 32, false );
        set_transient( self::OAUTH_STATE_TRANSIENT . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

        $params = http_build_query( [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ] );
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public static function youtube_oauth_callback( WP_REST_Request $request ): void {
        $code   = sanitize_text_field( $request->get_param( 'code' ) );
        $error  = sanitize_text_field( $request->get_param( 'error' ) );
        $state  = sanitize_text_field( $request->get_param( 'state' ) ); // CSRF state
        $user_id = get_current_user_id();

        if ( $error ) {
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=social&yt_oauth=error' ) );
            exit;
        }

        // Validate state
        $saved = get_transient( self::OAUTH_STATE_TRANSIENT . $user_id );
        if ( ! $state || ! $saved || ! hash_equals( $saved, $state ) ) {
            HYT_Logger::error( 'social', 'YouTube OAuth state validation failed', [ 'provided' => $state ] );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=social&yt_oauth=error' ) );
            exit;
        }
        delete_transient( self::OAUTH_STATE_TRANSIENT . $user_id );

        $social = new self();
        $response = HYT_Security::safe_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'hyt_youtube_client_id', '' ),
                'client_secret' => get_option( 'hyt_youtube_client_secret', '' ),
                'redirect_uri'  => rest_url( 'hyt/v1/youtube-oauth-callback' ),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['access_token'] ) ) {
            update_option( 'hyt_youtube_access_token',  $data['access_token'] );
            update_option( 'hyt_youtube_token_expiry',  time() + (int)( $data['expires_in'] ?? 3600 ) );
            if ( ! empty( $data['refresh_token'] ) ) {
                update_option( 'hyt_youtube_refresh_token', $data['refresh_token'] );
            }
            HYT_Logger::info( 'social', 'YouTube OAuth bağlantısı kuruldu.' );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=social&yt_oauth=success' ) );
        } else {
            HYT_Logger::error( 'social', 'YouTube OAuth token alınamadı.', [
                'error' => $data['error'] ?? 'Bilinmeyen hata',
                'full_response' => $data,
            ] );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=social&yt_oauth=error' ) );
        }
        exit;
    }

    private function get_valid_youtube_token(): ?string {
        $token   = get_option( 'hyt_youtube_access_token', '' );
        $expiry  = (int) get_option( 'hyt_youtube_token_expiry', 0 );
        $refresh = get_option( 'hyt_youtube_refresh_token', '' );

        if ( ! $token ) return null;
        if ( time() < $expiry - 60 ) return $token;
        if ( ! $refresh ) return null;

        $response = HYT_Security::safe_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => get_option( 'hyt_youtube_client_id', '' ),
                'client_secret' => get_option( 'hyt_youtube_client_secret', '' ),
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['access_token'] ) ) {
            update_option( 'hyt_youtube_access_token', $data['access_token'] );
            update_option( 'hyt_youtube_token_expiry', time() + (int)( $data['expires_in'] ?? 3600 ) );
            return $data['access_token'];
        }
        return null;
    }

    /* ================================================================
       HELPER
    ================================================================ */
    private function parse_meta_response( $response, string $channel ): array|false {
        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'social', "{$channel} hata: " . $response->get_error_message() );
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 || ! empty( $data['error'] ) ) {
            HYT_Logger::error( 'social', "{$channel} API {$code}", $data );
            return false;
        }
        HYT_Logger::info( 'social', "{$channel} paylaşımı başarılı." );
        return $data;
    }

    /* ================================================================
       STATUS CHECKS
    ================================================================ */
    public function is_facebook_configured(): bool {
        return ! empty( get_option( 'hyt_meta_page_token' ) ) && ! empty( get_option( 'hyt_meta_page_id' ) );
    }
    public function is_instagram_configured(): bool {
        return ! empty( get_option( 'hyt_meta_page_token' ) ) && ! empty( get_option( 'hyt_instagram_business_id' ) );
    }
    public function is_linkedin_configured(): bool {
        return ! empty( get_option( 'hyt_linkedin_access_token' ) ) && ! empty( get_option( 'hyt_linkedin_person_id' ) );
    }
    public function is_youtube_configured(): bool {
        return ! empty( get_option( 'hyt_youtube_access_token' ) );
    }
}

