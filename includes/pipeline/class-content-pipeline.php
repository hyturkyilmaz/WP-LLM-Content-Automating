<?php
defined( 'ABSPATH' ) || exit;

class HYT_Content_Pipeline {

    private $row;
    private $claude;

    public function __construct( $row ) {
        $this->row    = $row;
        $this->claude = new HYT_Claude();
    }

    public function run() {
        $id      = $this->row->id;
        $title   = $this->row->title ?? '';
        $content = $this->row->raw_content ?? '';

        HYT_Logger::info( 'pipeline', "Pipeline başladı: #{$id} - {$title}" );

        /* ---- ADIM 1: Duplicate kontrolü ---- */
        $this->step( $id, 'duplicate_check' );
        $duplicate = $this->check_duplicate( $title );
        if ( $duplicate ) {
            HYT_Database::update_pipeline( $id, [
                'status'        => 'duplicate',
                'step'          => 'duplicate_found',
                'error_message' => $duplicate['message'],
                'payload'       => wp_json_encode( $duplicate ),
            ] );
            HYT_Logger::warning( 'pipeline', "Duplicate bulundu: {$title}", $duplicate );
            return;
        }

        /* ---- ADIM 2: SEO / GEO optimizasyonu ---- */
        $this->step( $id, 'seo_geo' );
        $seo_data = [];

        if ( $this->claude->is_configured() ) {
            $seo_data = $this->claude->optimize_seo_geo( $title, $content );
            if ( ! empty( $seo_data ) ) {
                HYT_Database::update_pipeline( $id, [
                    'flag_seo' => 1,
                    'seo_data' => wp_json_encode( $seo_data, JSON_UNESCAPED_UNICODE ),
                ] );
                if ( ! empty( $seo_data['title'] ) ) {
                    $title = $seo_data['title'];
                    HYT_Database::update_pipeline( $id, [ 'title' => sanitize_text_field( $title ) ] );
                }
                HYT_Logger::info( 'pipeline', "SEO/GEO tamamlandı: #{$id}" );
            }
        } else {
            $direct = get_option( 'hyt_direct_publish_fallback', '0' );
            if ( ! $direct ) {
                HYT_Database::update_pipeline( $id, [
                    'status'        => 'failed',
                    'step'          => 'seo_geo',
                    'error_message' => 'Claude API key yapılandırılmamış.',
                ] );
                HYT_Logger::error( 'pipeline', "Claude yapılandırılmamış, pipeline durdu: #{$id}" );
                return;
            }
            HYT_Logger::warning( 'pipeline', "Claude yok — direkt yayın modunda devam ediliyor: #{$id}" );
        }

        /* ---- ADIM 3: Manuel Onay modu mu? ---- */
        if ( get_option( 'hyt_review_before_publish', '0' ) === '1' ) {
            HYT_Database::update_pipeline( $id, [
                'status'        => 'review_pending',
                'step'          => 'awaiting_review',
                'seo_data'      => wp_json_encode( $seo_data, JSON_UNESCAPED_UNICODE ),
            ] );
            HYT_Logger::info( 'pipeline', "Onay bekleniyor: #{$id}" );
            return; // Kullanıcı onaylayana kadar dur
        }

        /* ---- ADIM 4: WordPress Post oluştur ---- */
        $this->step( $id, 'create_post' );
        $post_id = $this->create_wordpress_post( $title, $content, $seo_data );

        if ( ! $post_id ) {
            HYT_Database::update_pipeline( $id, [
                'status'        => 'failed',
                'step'          => 'create_post',
                'error_message' => 'WordPress post oluşturulamadı.',
            ] );
            return;
        }

        HYT_Database::update_pipeline( $id, [
            'post_id' => $post_id,
            'status'  => 'done',
            'step'    => 'post_created',
        ] );

        /* ---- ADIM 5: Görsel Üretimi ---- */
        if ( get_option( 'hyt_auto_generate_image', '0' ) === '1' ) {
            $this->step( $id, 'image_generation' );
            HYT_Image_Generator::generate_for_pipeline( $id );
        }

        /* ---- ADIM 6: Video Pipeline ---- */
        if ( get_option( 'hyt_heygen_long_video_enabled', '0' ) === '1'
             || get_option( 'hyt_heygen_short_video_enabled', '0' ) === '1' ) {
            $this->step( $id, 'video_queue' );
            $video_pipeline = new HYT_Video_Pipeline();
            $video_pipeline->run( $id );
        }

        /* ---- ADIM 7: Sosyal medya dağıtımını planla ---- */
        HYT_Distribution::schedule_distribution( $id );

        HYT_Logger::info( 'pipeline', "Pipeline tamamlandı: #{$id} → Post #{$post_id}" );
    }

    /* ---- Onaylanmış Pipeline'ı Yayınla ---- */
    public static function approve_and_publish( $pipeline_id ) {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row || $row->status !== 'review_pending' ) return false;

        $seo_data = $row->seo_data ? json_decode( $row->seo_data, true ) : [];
        $title    = $row->title ?? '';
        $content  = $row->raw_content ?? '';

        HYT_Database::update_pipeline( $pipeline_id, [
            'status'        => 'processing',
            'step'          => 'approved',
            'review_status' => 'approved',
        ] );

        $pipeline = new self( HYT_Database::get_pipeline( $pipeline_id ) );
        $post_id  = $pipeline->create_wordpress_post( $title, $content, $seo_data );

        if ( ! $post_id ) {
            HYT_Database::update_pipeline( $pipeline_id, [
                'status'        => 'failed',
                'error_message' => 'Onay sonrası post oluşturulamadı.',
            ] );
            return false;
        }

        HYT_Database::update_pipeline( $pipeline_id, [
            'post_id' => $post_id,
            'status'  => 'done',
            'step'    => 'approved_published',
        ] );

        // Görsel
        if ( get_option( 'hyt_auto_generate_image', '0' ) === '1' ) {
            HYT_Image_Generator::generate_for_pipeline( $pipeline_id );
        }

        // Video
        if ( get_option( 'hyt_heygen_long_video_enabled', '0' ) === '1'
             || get_option( 'hyt_heygen_short_video_enabled', '0' ) === '1' ) {
            $vp = new HYT_Video_Pipeline();
            $vp->run( $pipeline_id );
        }

        // Sosyal dağıtım
        HYT_Distribution::schedule_distribution( $pipeline_id );

        HYT_Logger::info( 'pipeline', "Onaylandı ve yayınlandı: #{$pipeline_id} → Post #{$post_id}" );
        return true;
    }

    /* ---- Reddet ---- */
    public static function reject( $pipeline_id, $note = "" ) {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row ) return false;

        HYT_Database::update_pipeline( $pipeline_id, [
            'status'        => 'cancelled',
            'step'          => 'rejected',
            'review_status' => 'rejected',
            'review_note'   => sanitize_textarea_field( $note ),
        ] );

        HYT_Logger::info( 'pipeline', "Reddedildi: #{$pipeline_id}. Not: {$note}" );
        return true;
    }

    /* ---- Duplicate Check ---- */
    private function check_duplicate( $title ) {
        $existing_posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'publish', 'future', 'draft', 'pending' ],
            'title'          => $title,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );

        if ( ! empty( $existing_posts ) ) {
            $post_id     = $existing_posts[0];
            $post_status = get_post_status( $post_id );
            return [
                'type'    => 'wordpress_post',
                'post_id' => $post_id,
                'status'  => $post_status,
                'link'    => get_permalink( $post_id ),
                'message' => "Bu başlıkla WordPress'te '{$post_status}' durumunda bir post zaten var. ID: {$post_id}",
            ];
        }

        $slug        = sanitize_title( $title );
        global $wpdb;
        $slug_check  = $wpdb->get_row( $wpdb->prepare(
            "SELECT ID, post_status FROM {$wpdb->posts}
             WHERE post_name = %s AND post_type = 'post'
               AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
            $slug
        ) );

        if ( $slug_check ) {
            return [
                'type'    => 'slug_conflict',
                'post_id' => $slug_check->ID,
                'status'  => $slug_check->post_status,
                'link'    => get_permalink( $slug_check->ID ),
                'message' => "Bu slug ({$slug}) ile WordPress'te zaten bir post mevcut. ID: {$slug_check->ID}",
            ];
        }

        $pipeline_dup = HYT_Database::pipeline_exists_by_title( $title );
        if ( $pipeline_dup && $pipeline_dup->id !== $this->row->id ) {
            return [
                'type'        => 'pipeline_duplicate',
                'pipeline_id' => $pipeline_dup->id,
                'status'      => $pipeline_dup->status,
                'message'     => "Bu başlık pipeline'da zaten mevcut. Pipeline ID: {$pipeline_dup->id}, Durum: {$pipeline_dup->status}",
            ];
        }

        return null;
    }

    /* ---- WordPress Post Creation ---- */
    public function create_wordpress_post( $title, $content, $seo_data ) {
        $post_content = ! empty( $seo_data['content_html'] ) ? $seo_data['content_html'] : wpautop( $content );

        if ( ! empty( $seo_data['geo_summary'] ) ) {
            $geo_block    = '<div class="hyt-geo-summary" style="background:#f0f4ff;border-left:4px solid #3b82f6;padding:1rem 1.25rem;margin-bottom:1.5rem;border-radius:4px;">'
                          . '<strong>Özet:</strong> ' . esc_html( $seo_data['geo_summary'] )
                          . '</div>';
            $post_content = $geo_block . $post_content;
        }

        if ( ! empty( $seo_data['faq_schema'] ) ) {
            $schema_json  = is_array( $seo_data['faq_schema'] )
                            ? wp_json_encode( $seo_data['faq_schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
                            : $seo_data['faq_schema'];
            $post_content .= "\n\n<!-- HYT FAQ Schema -->\n<script type=\"application/ld+json\">\n" . $schema_json . "\n</script>";
        }

        $scheduled_at = HYT_Scheduler::next_available_slot();
        $post_status  = 'future';

        if ( ! $scheduled_at ) {
            $post_status  = 'draft';
            $scheduled_at = current_time( 'mysql' );
            HYT_Logger::warning( 'pipeline', '90 gün içinde uygun slot bulunamadı, taslak olarak kaydedildi.' );
        }

        $today     = current_time( 'Y-m-d' );
        $slot_date = substr( $scheduled_at, 0, 10 );
        if ( $slot_date === $today ) {
            $post_status = 'publish';
        }

        $author_id  = get_option( 'hyt_publish_author_id', get_current_user_id() );
        $category   = get_option( 'hyt_publish_category', 0 );
        $post_title = ! empty( $seo_data['title'] ) ? $seo_data['title'] : $title;

        $post_data = [
            'post_title'   => sanitize_text_field( $post_title ),
            'post_content' => $post_content,
            'post_status'  => $post_status,
            'post_author'  => $author_id,
            'post_type'    => 'post',
        ];

        if ( $post_status === 'future' ) {
            $post_data['post_date']     = $scheduled_at;
            $post_data['post_date_gmt'] = get_gmt_from_date( $scheduled_at );
        }

        if ( ! empty( $seo_data['slug'] ) ) {
            $post_data['post_name'] = sanitize_title( $seo_data['slug'] );
        }

        if ( $category > 0 ) {
            $post_data['post_category'] = [ $category ];
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            HYT_Logger::error( 'pipeline', 'Post oluşturulamadı: ' . $post_id->get_error_message() );
            return false;
        }

        if ( ! empty( $seo_data['meta_description'] ) ) {
            update_post_meta( $post_id, '_hyt_meta_description', sanitize_textarea_field( $seo_data['meta_description'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $seo_data['meta_description'] ) );
        }
        if ( ! empty( $seo_data['focus_keyword'] ) ) {
            update_post_meta( $post_id, '_hyt_focus_keyword',   sanitize_text_field( $seo_data['focus_keyword'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $seo_data['focus_keyword'] ) );
        }
        if ( ! empty( $seo_data['secondary_keywords'] ) ) {
            update_post_meta( $post_id, '_hyt_secondary_keywords', sanitize_text_field( $seo_data['secondary_keywords'] ) );
        }
        if ( ! empty( $seo_data['geo_summary'] ) ) {
            update_post_meta( $post_id, '_hyt_geo_summary', sanitize_textarea_field( $seo_data['geo_summary'] ) );
        }

        HYT_Database::update_pipeline( $this->row->id, [ 'scheduled_at' => $scheduled_at ] );
        HYT_Logger::info( 'pipeline', "Post oluşturuldu: #{$post_id} — {$post_status} — {$scheduled_at}" );
        return $post_id;
    }
    public static function expand_existing( $pipeline_id ): bool {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row ) return false;

        $claude = new HYT_Claude();
        if ( ! $claude->is_configured() ) return false;

        $content  = $row->raw_content ?? '';
        $title    = $row->title ?? '';
        $expanded = $claude->expand_content( $title, $content );
        $seo_data = $claude->optimize_seo_geo( $title, $expanded );

        if ( empty( $seo_data ) ) return false;

        if ( $row->post_id ) {
            wp_update_post( [
                'ID'           => $row->post_id,
                'post_title'   => sanitize_text_field( $seo_data['title'] ?? $title ),
                'post_content' => $seo_data['content_html'] ?? $expanded,
                'post_name'    => sanitize_title( $seo_data['slug'] ?? '' ),
            ] );
            if ( ! empty( $seo_data['meta_description'] ) ) {
                update_post_meta( $row->post_id, '_hyt_meta_description', $seo_data['meta_description'] );
                update_post_meta( $row->post_id, '_yoast_wpseo_metadesc', $seo_data['meta_description'] );
            }
            if ( ! empty( $seo_data['focus_keyword'] ) ) {
                update_post_meta( $row->post_id, '_yoast_wpseo_focuskw', $seo_data['focus_keyword'] );
            }
        }

        HYT_Database::update_pipeline( $pipeline_id, [
            'flag_seo' => 1,
            'seo_data' => wp_json_encode( $seo_data, JSON_UNESCAPED_UNICODE ),
            'title'    => sanitize_text_field( $seo_data['title'] ?? $title ),
            'step'     => 'expanded',
        ] );

        HYT_Logger::info( 'pipeline', "İçerik genişletildi: #{$pipeline_id}" );
        return true;
    }

    private function step( $id, $step ) {
        HYT_Database::update_pipeline( $id, [ 'step' => $step ] );
    }
}

