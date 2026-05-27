<?php
defined( 'ABSPATH' ) || exit;

class HYT_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Menu registration                                                  */
    /* ------------------------------------------------------------------ */

    public function register_menu(): void {
        add_menu_page(
            'HYT Content Automation',
            'HYT Otomasyon',
            'manage_options',
            'hyt-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-media-document',
            25
        );

        add_submenu_page(
            'hyt-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'hyt-dashboard',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'hyt-dashboard',
            'İçerik Kuyruğu',
            'Kuyruk',
            'manage_options',
            'hyt-queue',
            [ $this, 'render_queue' ]
        );

        add_submenu_page(
            'hyt-dashboard',
            'Loglar',
            'Loglar',
            'manage_options',
            'hyt-logs',
            [ $this, 'render_logs' ]
        );

        add_submenu_page(
            'hyt-dashboard',
            'Ayarlar',
            'Ayarlar',
            'manage_options',
            'hyt-settings',
            [ $this, 'render_settings' ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Asset enqueue                                                      */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'hyt-' ) ) {
            return;
        }

        wp_enqueue_style(
            'hyt-admin-style',
            HYT_PLUGIN_URL . 'admin/css/style.css',
            [],
            HYT_VERSION
        );

        wp_enqueue_script(
            'hyt-admin-js',
            HYT_PLUGIN_URL . 'admin/js/main.js',
            [ 'jquery' ],
            HYT_VERSION,
            true
        );

        wp_localize_script( 'hyt-admin-js', 'HYT', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'hyt_ajax' ),
        ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Page renderers                                                     */
    /* ------------------------------------------------------------------ */

    public function render_dashboard(): void {
        require_once HYT_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function render_queue(): void {
        require_once HYT_PLUGIN_DIR . 'admin/views/queue.php';
    }

    public function render_logs(): void {
        require_once HYT_PLUGIN_DIR . 'admin/views/logs.php';
    }

    public function render_settings(): void {
        if ( isset( $_POST['hyt_save_settings'] ) && check_admin_referer( 'hyt_settings_save' ) ) {
            $this->save_settings();
        }
        require_once HYT_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /* ------------------------------------------------------------------ */
    /*  Settings save — dispatches by tab                                  */
    /* ------------------------------------------------------------------ */

    private function save_settings(): void {
        $tab = sanitize_key( $_POST['hyt_tab'] ?? 'api' );

        match ( $tab ) {
            'api'      => $this->save_tab_api(),
            'gdrive'   => $this->save_tab_gdrive(),
            'schedule' => $this->save_tab_schedule(),
            'heygen'   => $this->save_tab_heygen(),
            'social'   => $this->save_tab_social(),
            'image'    => $this->save_tab_image(),
            'review'   => $this->save_tab_review(),
            'holidays' => $this->save_tab_holidays(),
            default    => null,
        };

        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Ayarlar kaydedildi.</p></div>';
        } );
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: API (Claude)                                                  */
    /* ------------------------------------------------------------------ */

    private function save_tab_api(): void {
        // Geriye dönük uyumluluk — Claude doğrudan alanları
        update_option( 'hyt_claude_api_key', sanitize_text_field( $_POST['hyt_claude_api_key'] ?? '' ) );
        update_option( 'hyt_claude_model',   sanitize_text_field( $_POST['hyt_claude_model']   ?? 'claude-opus-4-5' ) );

        // Aktif LLM provider seçimi
        $allowed_providers = array_keys( HYT_LLM::get_provider_list() );
        $provider = sanitize_key( $_POST['hyt_llm_provider'] ?? 'claude' );
        if ( ! in_array( $provider, $allowed_providers, true ) ) {
            $provider = 'claude';
        }
        update_option( 'hyt_llm_provider', $provider );

        // Her provider'ın key + model ayarlarını kaydet
        foreach ( HYT_LLM::get_provider_list() as $prov_key => $prov_info ) {
            $key_option   = $prov_info['key_option'];
            $model_option = $prov_info['model_option'];

            // API key — sadece doldurulmuşsa üzerine yaz (boş bırakılırsa mevcut değeri koru)
            if ( isset( $_POST[ $key_option ] ) && $_POST[ $key_option ] !== '' ) {
                update_option( $key_option, sanitize_text_field( $_POST[ $key_option ] ) );
            }

            // Model seçimi
            if ( isset( $_POST[ $model_option ] ) ) {
                $allowed_models = array_keys( $prov_info['models'] );
                $selected_model = sanitize_text_field( $_POST[ $model_option ] );
                if ( in_array( $selected_model, $allowed_models, true ) ) {
                    update_option( $model_option, $selected_model );
                }
            }
        }

        // DALL-E checkbox
        update_option( 'hyt_use_dalle', (int) isset( $_POST['hyt_use_dalle'] ) );

        // OpenAI API key ayrıca image generation için de kaydedilir
        // (hyt_openai_llm_api_key → hyt_openai_api_key senkronizasyonu)
        $openai_llm_key = get_option( 'hyt_openai_llm_api_key', '' );
        if ( $openai_llm_key && ! get_option( 'hyt_openai_api_key', '' ) ) {
            update_option( 'hyt_openai_api_key', $openai_llm_key );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: Google Drive                                                  */
    /* ------------------------------------------------------------------ */

    private function save_tab_gdrive(): void {
        update_option( 'hyt_gdrive_client_id',      sanitize_text_field( $_POST['hyt_gdrive_client_id']      ?? '' ) );
        update_option( 'hyt_gdrive_client_secret',  sanitize_text_field( $_POST['hyt_gdrive_client_secret']  ?? '' ) );
        update_option( 'hyt_gdrive_folder_id',      sanitize_text_field( $_POST['hyt_gdrive_folder_id']      ?? '' ) );
        update_option( 'hyt_gdrive_scan_frequency', sanitize_text_field( $_POST['hyt_gdrive_scan_frequency'] ?? 'hyt_every_15min' ) );

        // Re-schedule the GDrive scan cron when frequency changes.
        HYT_Scheduler::schedule_gdrive_scan();
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: Publish schedule                                              */
    /* ------------------------------------------------------------------ */

    private function save_tab_schedule(): void {
        $days = array_map( 'sanitize_key', (array) ( $_POST['hyt_publish_days'] ?? [] ) );
        update_option( 'hyt_publish_days',            $days );
        update_option( 'hyt_publish_time',            sanitize_text_field( $_POST['hyt_publish_time']            ?? '08:45' ) );
        update_option( 'hyt_publish_author_id',       (int) ( $_POST['hyt_publish_author_id']        ?? get_current_user_id() ) );
        update_option( 'hyt_publish_category',        (int) ( $_POST['hyt_publish_category']         ?? 0 ) );
        update_option( 'hyt_direct_publish_fallback', (int) isset( $_POST['hyt_direct_publish_fallback'] ) );
        update_option( 'hyt_social_delay_minutes',    (int) ( $_POST['hyt_social_delay_minutes']     ?? 30 ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: HeyGen                                                        */
    /* ------------------------------------------------------------------ */

    private function save_tab_heygen(): void {
        update_option( 'hyt_heygen_api_key',             sanitize_text_field( $_POST['hyt_heygen_api_key']             ?? '' ) );
        update_option( 'hyt_heygen_avatar_id',           sanitize_text_field( $_POST['hyt_heygen_avatar_id']           ?? '' ) );
        update_option( 'hyt_heygen_voice_id',            sanitize_text_field( $_POST['hyt_heygen_voice_id']            ?? '' ) );
        update_option( 'hyt_heygen_long_video_enabled',  (int) isset( $_POST['hyt_heygen_long_video_enabled'] ) );
        update_option( 'hyt_heygen_short_video_enabled', (int) isset( $_POST['hyt_heygen_short_video_enabled'] ) );
        update_option( 'hyt_heygen_short_count',         (int) ( $_POST['hyt_heygen_short_count'] ?? 3 ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: Social media                                                  */
    /* ------------------------------------------------------------------ */

    private function save_tab_social(): void {
        // Meta (Facebook & Instagram) credentials
        update_option( 'hyt_meta_page_token',        sanitize_text_field( $_POST['hyt_meta_page_token']        ?? '' ) );
        update_option( 'hyt_meta_page_id',           sanitize_text_field( $_POST['hyt_meta_page_id']           ?? '' ) );
        update_option( 'hyt_instagram_business_id',  sanitize_text_field( $_POST['hyt_instagram_business_id']  ?? '' ) );

        // LinkedIn credentials
        update_option( 'hyt_linkedin_access_token',  sanitize_text_field( $_POST['hyt_linkedin_access_token']  ?? '' ) );
        update_option( 'hyt_linkedin_person_id',     sanitize_text_field( $_POST['hyt_linkedin_person_id']     ?? '' ) );

        // YouTube OAuth2 credentials
        update_option( 'hyt_youtube_client_id',      sanitize_text_field( $_POST['hyt_youtube_client_id']      ?? '' ) );
        update_option( 'hyt_youtube_client_secret',  sanitize_text_field( $_POST['hyt_youtube_client_secret']  ?? '' ) );

        // Channel enable/disable flags (new per-channel naming)
        update_option( 'hyt_channel_facebook',           (int) isset( $_POST['hyt_channel_facebook'] ) );
        update_option( 'hyt_channel_instagram',          (int) isset( $_POST['hyt_channel_instagram'] ) );
        update_option( 'hyt_channel_instagram_reels',    (int) isset( $_POST['hyt_channel_instagram_reels'] ) );
        update_option( 'hyt_channel_linkedin',           (int) isset( $_POST['hyt_channel_linkedin'] ) );
        update_option( 'hyt_channel_youtube',            (int) isset( $_POST['hyt_channel_youtube'] ) );

        // Keep legacy option names in sync so existing code (HYT_Distribution / HYT_Social)
        // that still reads hyt_social_*_enabled continues to work without changes.
        update_option( 'hyt_social_facebook_enabled',  get_option('hyt_channel_facebook',  0) );
        update_option( 'hyt_social_instagram_enabled', get_option('hyt_channel_instagram', 0) );
        update_option( 'hyt_social_linkedin_enabled',  get_option('hyt_channel_linkedin',  0) );
        update_option( 'hyt_social_youtube_enabled',   get_option('hyt_channel_youtube',   0) );
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: Image generation                                              */
    /* ------------------------------------------------------------------ */

    private function save_tab_image(): void {
        update_option( 'hyt_auto_generate_image', (int) isset( $_POST['hyt_auto_generate_image'] ) );
        update_option( 'hyt_openai_api_key',       sanitize_text_field( $_POST['hyt_openai_api_key'] ?? '' ) );
        update_option( 'hyt_use_dalle',            (int) isset( $_POST['hyt_use_dalle'] ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: Review / approval workflow                                    */
    /* ------------------------------------------------------------------ */

    private function save_tab_review(): void {
        update_option( 'hyt_review_before_publish', (int) isset( $_POST['hyt_review_before_publish'] ) );
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: Holidays                                                      */
    /* ------------------------------------------------------------------ */

    private function save_tab_holidays(): void {
        $json = sanitize_textarea_field( $_POST['hyt_islamic_holidays'] ?? '[]' );
        $arr  = json_decode( $json, true );
        if ( is_array( $arr ) ) {
            // Sanitize every date entry before storing.
            $arr = array_values( array_filter( array_map( 'sanitize_text_field', $arr ) ) );
            update_option( 'hyt_islamic_holidays', wp_json_encode( $arr ) );
        }
    }
}

