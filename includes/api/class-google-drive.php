<?php
defined( 'ABSPATH' ) || exit;

class HYT_Google_Drive {

    private $client_id;
    private $client_secret;
    private $folder_id;
    private $redirect_uri;

    const OPTION_ACCESS_TOKEN  = 'hyt_gdrive_access_token';
    const OPTION_REFRESH_TOKEN = 'hyt_gdrive_refresh_token';
    const OPTION_TOKEN_EXPIRY  = 'hyt_gdrive_token_expiry';
    const OPTION_SCANNED_FILES = 'hyt_gdrive_scanned_files';
    const OAUTH_STATE_TRANSIENT = 'hyt_oauth_state_';

    public function __construct() {
        $this->client_id     = get_option( 'hyt_gdrive_client_id', '' );
        $this->client_secret = get_option( 'hyt_gdrive_client_secret', '' );
        $this->folder_id     = get_option( 'hyt_gdrive_folder_id', '' );
        $this->redirect_uri  = rest_url( 'hyt/v1/google-oauth-callback' );
    }

    public function is_configured(): bool {
        return ! empty( $this->client_id ) && ! empty( $this->client_secret );
    }

    public function is_connected(): bool {
        return ! empty( get_option( self::OPTION_ACCESS_TOKEN ) );
    }

    /* ---- OAuth2 ---- */

    public function get_auth_url(): string {
        // CSRF protection: generate and store state token
        $state = wp_generate_password( 32, false );
        set_transient( self::OAUTH_STATE_TRANSIENT . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

        $params = http_build_query( [
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state, // CSRF token
        ] );
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public static function oauth_callback( WP_REST_Request $request ): WP_REST_Response {
        $code   = sanitize_text_field( $request->get_param( 'code' ) );
        $error  = sanitize_text_field( $request->get_param( 'error' ) );
        $state  = sanitize_text_field( $request->get_param( 'state' ) ); // CSRF state
        $user_id = get_current_user_id();

        if ( $error ) {
            return new WP_REST_Response( [ 'error' => $error ], 400 );
        }

        // Validate state parameter
        $saved = get_transient( self::OAUTH_STATE_TRANSIENT . $user_id );
        if ( ! $state || ! $saved || ! hash_equals( $saved, $state ) ) {
            HYT_Logger::error( 'gdrive', 'OAuth state validation failed', [ 'provided' => $state ] );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=gdrive&oauth=error' ) );
            exit;
        }
        // One-time use: delete it
        delete_transient( self::OAUTH_STATE_TRANSIENT . $user_id );

        $drive    = new self();
        $response = HYT_Security::safe_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $drive->client_id,
                'client_secret' => $drive->client_secret,
                'redirect_uri'  => $drive->redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'gdrive', 'Token alınamadı: ' . $response->get_error_message() );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=gdrive&oauth=error' ) );
            exit;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['access_token'] ) ) {
            update_option( self::OPTION_ACCESS_TOKEN,  $data['access_token'] );
            update_option( self::OPTION_TOKEN_EXPIRY,  time() + (int) ( $data['expires_in'] ?? 3600 ) );
            if ( ! empty( $data['refresh_token'] ) ) {
                update_option( self::OPTION_REFRESH_TOKEN, $data['refresh_token'] );
            }
            HYT_Logger::info( 'gdrive', 'OAuth2 bağlantısı başarıyla kuruldu.' );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=gdrive&oauth=success' ) );
        } else {
            HYT_Logger::error( 'gdrive', 'OAuth2 token alınamadı.', [
                'error' => $data['error'] ?? 'Bilinmeyen hata',
                'full_response' => $data,
            ] );
            wp_redirect( admin_url( 'admin.php?page=hyt-settings&tab=gdrive&oauth=error' ) );
        }
        exit;
    }

    public function disconnect(): void {
        delete_option( self::OPTION_ACCESS_TOKEN );
        delete_option( self::OPTION_REFRESH_TOKEN );
        delete_option( self::OPTION_TOKEN_EXPIRY );
        HYT_Logger::info( 'gdrive', 'Google Drive bağlantısı kesildi.' );
    }

    /* ---- Token Refresh ---- */

    private function get_valid_token(): ?string {
        $token   = get_option( self::OPTION_ACCESS_TOKEN );
        $expiry  = (int) get_option( self::OPTION_TOKEN_EXPIRY, 0 );
        $refresh = get_option( self::OPTION_REFRESH_TOKEN );

        if ( ! $token ) {
            return null;
        }

        if ( time() < ( $expiry - 60 ) ) {
            return $token;
        }

        if ( ! $refresh ) {
            HYT_Logger::warning( 'gdrive', 'Refresh token yok, yeniden bağlanın.' );
            return null;
        }

        $response = HYT_Security::safe_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'gdrive', 'Token yenileme hatası: ' . $response->get_error_message() );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['access_token'] ) ) {
            update_option( self::OPTION_ACCESS_TOKEN, $data['access_token'] );
            update_option( self::OPTION_TOKEN_EXPIRY, time() + (int) ( $data['expires_in'] ?? 3600 ) );
            return $data['access_token'];
        }

        HYT_Logger::error( 'gdrive', 'Token yenilenemedi.', $data );
        return null;
    }

    /* ---- Drive API ---- */

    private function api_get( string $url, array $query = [] ): ?array {
        $token = $this->get_valid_token();
        if ( ! $token ) {
            return null;
        }

        if ( $query ) {
            $url .= '?' . http_build_query( $query );
        }

        $response = HYT_Security::safe_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'gdrive', 'API isteği başarısız: ' . $response->get_error_message() );
            return null;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /* ---- Scan & Queue ---- */

    public function scan_and_queue(): int {
        if ( ! $this->is_connected() || empty( $this->folder_id ) ) {
            return 0;
        }

        $scanned  = get_option( self::OPTION_SCANNED_FILES, [] );
        $queued   = 0;
        $page_token = null;

        do {
            $query_params = [
                'q'          => "'{$this->folder_id}' in parents and trashed = false and (mimeType='application/vnd.openxmlformats-officedocument.wordprocessingml.document' or mimeType='text/plain' or mimeType='application/vnd.google-apps.document')",
                'fields'     => 'nextPageToken,files(id,name,mimeType,modifiedTime)',
                'pageSize'   => 100,
            ];
            if ( $page_token ) {
                $query_params['pageToken'] = $page_token;
            }

            $data = $this->api_get( 'https://www.googleapis.com/drive/v3/files', $query_params );

            if ( ! $data || empty( $data['files'] ) ) {
                break;
            }

            foreach ( $data['files'] as $file ) {
                $file_id = $file['id'];

                if ( in_array( $file_id, $scanned, true ) ) {
                    continue;
                }

                if ( HYT_Database::find_by_drive_file_id( $file_id ) ) {
                    $scanned[] = $file_id;
                    continue;
                }

                $content = $this->read_file( $file );
                if ( empty( $content ) ) {
                    continue;
                }

                $title = $this->filename_to_title( $file['name'] );

                HYT_Database::insert_pipeline( [
                    'drive_file_id' => $file_id,
                    'file_name'     => sanitize_text_field( $file['name'] ),
                    'title'         => sanitize_text_field( $title ),
                    'raw_content'   => $content,
                    'status'        => 'pending',
                    'step'          => 'queued_from_drive',
                ] );

                HYT_Logger::info( 'gdrive', "Dosya kuyruğa eklendi: {$file['name']}", [ 'file_id' => $file_id ] );
                $scanned[] = $file_id;
                $queued++;
            }

            $page_token = $data['nextPageToken'] ?? null;

        } while ( $page_token );

        update_option( self::OPTION_SCANNED_FILES, array_unique( $scanned ) );
        return $queued;
    }

    /* ---- File Reading ---- */

    public function read_file( array $file ): string {
        $mime = $file['mimeType'];

        if ( $mime === 'application/vnd.google-apps.document' ) {
            return $this->export_google_doc( $file['id'] );
        }

        if ( $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ) {
            return $this->download_and_parse_docx( $file['id'] );
        }

        if ( $mime === 'text/plain' ) {
            return $this->download_file_content( $file['id'] );
        }

        return '';
    }

    private function export_google_doc( string $file_id ): string {
        $token = $this->get_valid_token();
        if ( ! $token ) return '';

        $url      = "https://www.googleapis.com/drive/v3/files/{$file_id}/export?mimeType=text/plain";
        $response = HYT_Security::safe_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) ) return '';
        return wp_remote_retrieve_body( $response );
    }

    private function download_file_content( string $file_id ): string {
        $token = $this->get_valid_token();
        if ( ! $token ) return '';

        $url      = "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media";
        $response = HYT_Security::safe_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) ) return '';
        return wp_remote_retrieve_body( $response );
    }

    private function download_and_parse_docx( string $file_id ): string {
        $token = $this->get_valid_token();
        if ( ! $token ) return '';

        $url      = "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media";
        $response = HYT_Security::safe_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) ) return '';

        $tmp_file = wp_tempnam( 'hyt_docx_' );
        file_put_contents( $tmp_file, wp_remote_retrieve_body( $response ) );

        $text = self::parse_docx_file( $tmp_file );
        @unlink( $tmp_file );
        return $text;
    }

    /* ---- DOCX Parser (PHP ZipArchive) ---- */

    public static function parse_docx_file( string $file_path ): string {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return '';
        }

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return '';
        }

        // Zip bomb protection: limit number of files inside archive
        if ( $zip->numFiles > 1000 ) {
            error_log( "HYT: Zip bomb detected — {$zip->numFiles} files in archive" );
            $zip->close();
            return '';
        }

        $xml_content = $zip->getFromName( 'word/document.xml' );
        $zip->close();

        if ( ! $xml_content ) {
            return '';
        }

        /* Satır sonlarını koru */
        $xml_content = str_replace( '</w:p>', "\n", $xml_content );
        $xml_content = str_replace( '<w:br/>', "\n", $xml_content );

        return wp_strip_all_tags( $xml_content );
    }

    /* ---- Helpers ---- */

    private function filename_to_title( string $filename ): string {
        $name = pathinfo( $filename, PATHINFO_FILENAME );
        /* 01_Konu_Basligi → Konu Basligi */
        $name = preg_replace( '/^\d+[_\-\s]*/', '', $name );
        $name = str_replace( [ '_', '-' ], ' ', $name );
        return trim( $name );
    }

    public function get_folder_info(): ?array {
        if ( empty( $this->folder_id ) ) return null;
        return $this->api_get( "https://www.googleapis.com/drive/v3/files/{$this->folder_id}", [
            'fields' => 'id,name,mimeType',
        ] );
    }
}

