<?php
defined( 'ABSPATH' ) || exit;

class HYT_Ajax {

    public function __construct() {
        $actions = [
            'hyt_upload_files'          => 'handle_upload_files',
            'hyt_pipeline_action'       => 'handle_pipeline_action',
            'hyt_bulk_action'           => 'handle_bulk_action',
            'hyt_expand_content'        => 'handle_expand_content',
            'hyt_toggle_flag'           => 'handle_toggle_flag',
            'hyt_test_claude'           => 'handle_test_claude',
            'hyt_test_llm'              => 'handle_test_llm',
            'hyt_test_heygen'           => 'handle_test_heygen',
            'hyt_list_heygen_avatars'   => 'handle_list_heygen_avatars',
            'hyt_gdrive_scan_now'       => 'handle_gdrive_scan_now',
            'hyt_gdrive_disconnect'     => 'handle_gdrive_disconnect',
            'hyt_clear_logs'            => 'handle_clear_logs',
            'hyt_export_logs'           => 'handle_export_logs',
            'hyt_retry_all_failed'      => 'handle_retry_all_failed',
            'hyt_get_queue_counts'      => 'handle_get_queue_counts',
            'hyt_generate_image_now'    => 'handle_generate_image_now',
            'hyt_start_video_now'       => 'handle_start_video_now',
            'hyt_distribute_now'        => 'handle_distribute_now',
            'hyt_approve_review'        => 'handle_approve_review',
            'hyt_reject_review'         => 'handle_reject_review',
            'hyt_yt_disconnect'         => 'handle_yt_disconnect',
            'hyt_save_review_settings'  => 'handle_save_review_settings',
            'hyt_get_log_data'          => 'handle_get_log_data',
            'hyt_check_plugin_update'   => 'handle_check_plugin_update',
        ];
        foreach ( $actions as $action => $method ) {
            add_action( "wp_ajax_{$action}", [ $this, $method ] );
        }
    }

    private function verify( string $action = '' ): void {
        if ( ! check_ajax_referer( 'hyt_ajax', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Yetkisiz erisim.' ], 403 );
        }
        if ( $action && ! HYT_Security::check_rate_limit( $action ) ) {
            wp_send_json_error( [ 'message' => 'Rate limit asildi. Lutfen 5 saniye bekleyin.' ], 429 );
        }
    }

    public function handle_upload_files(): void {
        $this->verify( 'upload_files' );
        if ( empty( $_FILES['files'] ) ) {
            wp_send_json_error( [ 'message' => 'Dosya bulunamadi.' ] );
        }
        $files = $_FILES['files'];
        $queued = 0; $skipped = 0; $errors = [];
        $count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;
        for ( $i = 0; $i < $count; $i++ ) {
            $name = is_array( $files['name'] ) ? $files['name'][$i] : $files['name'];
            $tmp_path = is_array( $files['tmp_name'] ) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $error = is_array( $files['error'] ) ? $files['error'][$i] : $files['error'];
            if ( $error !== UPLOAD_ERR_OK ) { $errors[] = "{$name}: Yukleme hatasi ({$error})"; continue; }
            $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, [ 'docx', 'txt' ], true ) ) { $errors[] = "{$name}: Desteklenmeyen format."; continue; }
            if ( filesize( $tmp_path ) > 5 * 1024 * 1024 ) { $errors[] = "{$name}: Dosya cok buyuk."; continue; }
            $mime = mime_content_type( $tmp_path );
            $am = [ 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain' ];
            if ( $mime !== $am[ $ext ] ) { $errors[] = "{$name}: Gecersiz MIME type."; continue; }
            $content = $ext === 'docx' ? HYT_Google_Drive::parse_docx_file( $tmp_path ) : file_get_contents( $tmp_path );
            if ( empty( trim( $content ) ) ) { $errors[] = "{$name}: Dosya icerigi bos."; continue; }
            $title = $this->filename_to_title( $name );
            if ( HYT_Database::pipeline_exists_by_title( $title ) ) { $skipped++; continue; }
            HYT_Database::insert_pipeline([ 'file_name' => sanitize_text_field($name), 'title' => sanitize_text_field($title), 'raw_content' => $content, 'status' => 'pending', 'step' => 'uploaded' ]);
            $queued++;
        }
        wp_send_json_success([ 'message' => "{$queued} dosya kuyruga eklendi. {$skipped} atlandi.", 'queued' => $queued, 'skipped' => $skipped, 'errors' => $errors ]);
    }

    public function handle_pipeline_action(): void {
        $this->verify( 'pipeline_action' );
        $pipeline_id = (int)( $_POST['pipeline_id'] ?? 0 );
        $action = sanitize_key( $_POST['pipeline_action'] ?? '' );
        if ( ! $pipeline_id ) { wp_send_json_error([ 'message' => 'Pipeline ID gerekli.' ]); }
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row ) { wp_send_json_error([ 'message' => 'Pipeline bulunamadi.' ]); }
        switch ( $action ) {
            case 'retry':
                HYT_Database::update_pipeline($pipeline_id,['status'=>'pending','step'=>'retry','error_message'=>null]);
                wp_send_json_success(['message'=>'Pipeline yeniden kuyruga alindi.']);
                break;
            case 'pause':
                HYT_Database::update_pipeline($pipeline_id,['status'=>'paused']);
                wp_send_json_success(['message'=>'Pipeline duraklatildi.']);
                break;
            case 'resume':
                HYT_Database::update_pipeline($pipeline_id,['status'=>'pending','step'=>'resumed']);
                // Hemen isleme al — crontur bekleme
                try {
                    $pipeline = new HYT_Content_Pipeline( HYT_Database::get_pipeline( $pipeline_id ) );
                    $pipeline->run();
                } catch ( Throwable $e ) {
                    HYT_Database::update_pipeline( $pipeline_id, [ 'status' => 'failed', 'error_message' => $e->getMessage() ] );
                    HYT_Logger::error( 'pipeline', 'Devam ettirme hatasi: ' . $e->getMessage(), [ 'pipeline_id' => $pipeline_id ] );
                }
                wp_send_json_success(['message'=>'Pipeline devam ettiriliyor.']);
                break;
            case 'cancel':
                HYT_Database::update_pipeline($pipeline_id,['status'=>'cancelled']);
                wp_send_json_success(['message'=>'Pipeline iptal edildi.']);
                break;
            default:
                wp_send_json_error(['message'=>'Gecersiz islem.']);
        }
    }

    public function handle_bulk_action(): void {
        $this->verify( 'bulk_action' );
        $ids = array_map( 'intval', (array)( $_POST['ids'] ?? [] ) );
        $action = sanitize_key( $_POST['bulk_action'] ?? '' );
        if ( empty( $ids ) ) { wp_send_json_error([ 'message' => 'Secili kayit yok.' ]); }
        $count = 0;
        foreach ( $ids as $id ) {
            switch ( $action ) {
                case 'pause': HYT_Database::update_pipeline($id,['status'=>'paused']); $count++; break;
                case 'resume':
                    HYT_Database::update_pipeline($id,['status'=>'pending','step'=>'resumed']);
                    try {
                        $pipeline = new HYT_Content_Pipeline( HYT_Database::get_pipeline( $id ) );
                        $pipeline->run();
                    } catch ( Throwable $e ) {
                        HYT_Database::update_pipeline( $id, [ 'status' => 'failed', 'error_message' => $e->getMessage() ] );
                    }
                    $count++;
                    break;
                case 'retry': HYT_Database::update_pipeline($id,['status'=>'pending','step'=>'retry','error_message'=>null]); $count++; break;
                case 'cancel': HYT_Database::update_pipeline($id,['status'=>'cancelled']); $count++; break;
                case 'approve': HYT_Content_Pipeline::approve_and_publish($id); $count++; break;
                case 'reject': HYT_Content_Pipeline::reject($id); $count++; break;
            }
        }
        wp_send_json_success([ 'message' => "{$count} kayit guncellendi." ]);
    }

    public function handle_retry_all_failed(): void {
        $this->verify( 'retry_all_failed' );
        global $wpdb;
        $c = $wpdb->query("UPDATE {$wpdb->prefix}hyt_pipeline SET status='pending',step='retry',error_message=NULL,updated_at=NOW() WHERE status IN ('failed','cancelled')");
        wp_send_json_success([ 'message' => "{$c} basarisiz pipeline yeniden kuyruga alindi." ]);
    }

    public function handle_expand_content(): void {
        $this->verify( 'expand_content' );
        $pid = (int)( $_POST['pipeline_id'] ?? 0 );
        if ( ! $pid ) { wp_send_json_error([ 'message' => 'Pipeline ID gerekli.' ]); }
        $r = HYT_Content_Pipeline::expand_existing( $pid );
        if ( $r ) { wp_send_json_success([ 'message' => 'Icerik genisletildi ve SEO guncellendi.' ]); }
        else { wp_send_json_error([ 'message' => 'Genisletme basarisiz.' ]); }
    }

    public function handle_toggle_flag(): void {
        $this->verify( 'toggle_flag' );
        $pid = (int)( $_POST['pipeline_id'] ?? 0 );
        $flag = sanitize_key( $_POST['flag'] ?? '' );
        $allowed = [ 'flag_backlink', 'flag_seo', 'flag_img', 'flag_social', 'flag_video' ];
        if ( ! in_array( $flag, $allowed, true ) ) { wp_send_json_error([ 'message' => 'Gecersiz flag.' ]); }
        $row = HYT_Database::get_pipeline( $pid );
        if ( ! $row ) { wp_send_json_error([ 'message' => 'Pipeline bulunamadi.' ]); }
        $nv = $row->$flag ? 0 : 1;
        HYT_Database::update_pipeline( $pid, [ $flag => $nv ] );
        wp_send_json_success([ 'value' => $nv ]);
    }

    public function handle_test_claude(): void {
        $this->verify( 'test_claude' );
        $r = (new HYT_LLM())->test_connection();
        if ( $r['success'] ) { wp_send_json_success($r); } else { wp_send_json_error($r); }
    }

    public function handle_test_llm(): void {
        $this->verify( 'test_llm' );
        $p = sanitize_key( $_POST['provider'] ?? '' );
        $r = ($p ? new HYT_LLM($p) : new HYT_LLM())->test_connection();
        if ( $r['success'] ) { wp_send_json_success($r); } else { wp_send_json_error($r); }
    }

    public function handle_test_heygen(): void {
        $this->verify( 'test_heygen' );
        $r = (new HYT_HeyGen())->test_connection();
        if ( $r['success'] ) { wp_send_json_success($r); } else { wp_send_json_error($r); }
    }

    public function handle_list_heygen_avatars(): void {
        $this->verify( 'list_heygen_avatars' );
        $a = (new HYT_HeyGen())->list_avatars();
        if ( is_array($a) && !empty($a) ) { wp_send_json_success(['avatars'=>$a]); }
        else { wp_send_json_error(['message'=>'Avatar listesi alinamadi.']); }
    }

    public function handle_gdrive_scan_now(): void {
        $this->verify( 'gdrive_scan_now' );
        try {
            $q = (new HYT_Google_Drive())->scan_and_queue();
            wp_send_json_success([ 'message' => "{$q} yeni dosya kuyruga eklendi." ]);
        } catch ( Throwable $e ) { wp_send_json_error([ 'message' => $e->getMessage() ]); }
    }

    public function handle_gdrive_disconnect(): void {
        $this->verify( 'gdrive_disconnect' );
        (new HYT_Google_Drive())->disconnect();
        wp_send_json_success([ 'message' => 'Google Drive baglantisi kesildi.' ]);
    }

    public function handle_clear_logs(): void {
        $this->verify( 'clear_logs' );
        HYT_Database::clear_logs();
        wp_send_json_success([ 'message' => 'Tum loglar silindi.' ]);
    }

    public function handle_export_logs(): void {
        $this->verify( 'export_logs' );
        $l = sanitize_key( $_POST['level'] ?? '' );
        $c = sanitize_key( $_POST['context'] ?? '' );
        $logs = HYT_Database::get_logs([ 'level'=>$l, 'context'=>$c, 'per_page'=>5000, 'page'=>1 ]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hyt-logs-'.date('Y-m-d').'.csv"');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['ID','Seviye','Baglam','Mesaj','Veri','Tarih']);
        foreach($logs as $log){ fputcsv($out,[$log->id,$log->level,$log->context,$log->message,$log->data,$log->created_at]); }
        fclose($out); exit;
    }

    public function handle_get_queue_counts(): void {
        $this->verify( 'get_queue_counts' );
        wp_send_json_success( self::get_tab_counts() );
    }

    public static function get_tab_counts(): array {
        return [
            'all'=>HYT_Database::count_pipelines(), 'pending'=>HYT_Database::count_pipelines(['status'=>'pending']),
            'processing'=>HYT_Database::count_pipelines(['status'=>'processing']), 'paused'=>HYT_Database::count_pipelines(['status'=>'paused']),
            'review_pending'=>HYT_Database::count_pipelines(['status'=>'review_pending']), 'done'=>HYT_Database::count_pipelines(['status'=>'done']),
            'duplicate'=>HYT_Database::count_pipelines(['status'=>'duplicate']), 'failed'=>HYT_Database::count_pipelines(['status'=>'failed']),
            'cancelled'=>HYT_Database::count_pipelines(['status'=>'cancelled']), 'video_wait'=>HYT_Database::count_pipelines(['status'=>'video_wait']),
            'flag_seo'=>HYT_Database::count_pipelines(['flag_seo'=>1]), 'flag_img'=>HYT_Database::count_pipelines(['flag_img'=>1]),
            'flag_video'=>HYT_Database::count_pipelines(['flag_video'=>1]), 'flag_social'=>HYT_Database::count_pipelines(['flag_social'=>1]),
        ];
    }

    public function handle_generate_image_now(): void {
        $this->verify('generate_image_now');
        $pid=(int)($_POST['pipeline_id']??0);
        if(!$pid){wp_send_json_error(['message'=>'Pipeline ID gerekli.']);}
        $r=HYT_Image_Generator::generate_for_pipeline($pid);
        if($r){wp_send_json_success(['message'=>"Gorsel uretildi (Attachment #{$r})."]);}
        else{wp_send_json_error(['message'=>'Gorsel uretilemedi.']);}
    }

    public function handle_start_video_now(): void {
        $this->verify('start_video_now');
        $pid=(int)($_POST['pipeline_id']??0);
        if(!$pid){wp_send_json_error(['message'=>'Pipeline ID gerekli.']);}
        $r=(new HYT_Video_Pipeline())->run($pid);
        if($r){wp_send_json_success(['message'=>'Video uretimi HeyGen\'e gonderildi.']);}
        else{wp_send_json_error(['message'=>'Video baslatilamadi.']);}
    }

    public function handle_distribute_now(): void {
        $this->verify('distribute_now');
        $pid=(int)($_POST['pipeline_id']??0);
        if(!$pid){wp_send_json_error(['message'=>'Pipeline ID gerekli.']);}
        (new HYT_Distribution())->distribute($pid);
        wp_send_json_success(['message'=>'Sosyal medya dagitimi tamamlandi.']);
    }

    public function handle_approve_review(): void {
        $this->verify('approve_review');
        $pid=(int)($_POST['pipeline_id']??0);
        if(!$pid){wp_send_json_error(['message'=>'Pipeline ID gerekli.']);}
        $r=HYT_Content_Pipeline::approve_and_publish($pid);
        if($r){wp_send_json_success(['message'=>'Icerik onaylandi ve yayin takvimine eklendi.']);}
        else{wp_send_json_error(['message'=>'Onay islemi basarisiz.']);}
    }

    public function handle_reject_review(): void {
        $this->verify('reject_review');
        $pid=(int)($_POST['pipeline_id']??0);
        $note=sanitize_textarea_field($_POST['note']??'');
        if(!$pid){wp_send_json_error(['message'=>'Pipeline ID gerekli.']);}
        $r=HYT_Content_Pipeline::reject($pid,$note);
        if($r){wp_send_json_success(['message'=>'Icerik reddedildi.']);}
        else{wp_send_json_error(['message'=>'Reddetme islemi basarisiz.']);}
    }

    public function handle_yt_disconnect(): void {
        $this->verify('yt_disconnect');
        delete_option('hyt_youtube_access_token');
        delete_option('hyt_youtube_refresh_token');
        delete_option('hyt_youtube_token_expiry');
        HYT_Logger::info('social','YouTube baglantisi kesildi.');
        wp_send_json_success(['message'=>'YouTube baglantisi kesildi.']);
    }

    public function handle_save_review_settings(): void {
        $this->verify('save_review_settings');
        update_option('hyt_review_before_publish',(int)isset($_POST['hyt_review_before_publish']));
        wp_send_json_success(['message'=>'Onay akisi ayari kaydedildi.']);
    }

    public function handle_get_log_data(): void {
        $this->verify('get_log_data');
        $lid=(int)($_POST['log_id']??0);
        if(!$lid){wp_send_json_error(['message'=>'Log ID gerekli.']);}
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT data FROM {$wpdb->prefix}hyt_logs WHERE id=%d",$lid));
        if(!$row){wp_send_json_error(['message'=>'Log bulunamadi.']);}
        wp_send_json_success(['data'=>$row->data]);
    }

    public function handle_check_plugin_update(): void {
        $this->verify('check_plugin_update');
        $current=HYT_VERSION;
        $repo=apply_filters('hyt_update_github_repo','');
        if(empty($repo)){
            wp_send_json_success(['update_available'=>false,'current_version'=>$current,
                'message'=>'Guncelleme kaynagi tanimli degil.','note'=>'Ornek: hyturkyilmaz/WP-LLM-Content-Automating']);
            return;
        }
        $api="https://api.github.com/repos/{$repo}/releases/latest";
        $resp=wp_remote_get($api,['timeout'=>10,'headers'=>['User-Agent'=>'HYT-Plugin-Updater/'.$current]]);
        if(is_wp_error($resp)){wp_send_json_error(['message'=>'GitHub baglantisi basarisiz.']);return;}
        $body=json_decode(wp_remote_retrieve_body($resp),true);
        if(empty($body['tag_name'])){wp_send_json_error(['message'=>'Surum bilgisi alinamadi.']);return;}
        $latest=ltrim($body['tag_name'],'v');
        wp_send_json_success([
            'update_available'=>version_compare($latest,$current,'>'),
            'current_version'=>$current,'latest_version'=>$latest,
            'download_url'=>$body['zipball_url']??$body['html_url']??'',
            'changelog'=>wp_strip_all_tags(substr($body['body']??'',0,300)),
        ]);
    }

    private function filename_to_title( string $filename ): string {
        $n=pathinfo($filename,PATHINFO_FILENAME);
        $n=preg_replace('/^\d+[_\\-\\s]*/','',$n);
        $n=str_replace(['_','-'],' ',$n);
        return trim($n);
    }
}