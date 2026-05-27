<?php defined( 'ABSPATH' ) || exit;

$counts     = HYT_Ajax::get_tab_counts();
$claude     = new HYT_Claude();
$drive      = new HYT_Google_Drive();
$heygen     = new HYT_HeyGen();
$social     = new HYT_Social();
$today      = current_time( 'Y-m-d' );
$is_holiday = HYT_Holidays::is_holiday( $today );
$next_slot  = HYT_Scheduler::next_available_slot();

// Son 8 pipeline
$recent = HYT_Database::get_pipelines( [ 'per_page' => 8, 'page' => 1 ] );

// Servis durumları
$services = [
    [ 'name' => 'Claude API',     'ok' => $claude->is_configured(),  'link' => admin_url( 'admin.php?page=hyt-settings&tab=api' ),     'icon' => '🤖' ],
    [ 'name' => 'Google Drive',   'ok' => $drive->is_connected(),    'link' => admin_url( 'admin.php?page=hyt-settings&tab=gdrive' ),  'icon' => '📁' ],
    [ 'name' => 'HeyGen Video',   'ok' => $heygen->is_configured(),  'link' => admin_url( 'admin.php?page=hyt-settings&tab=heygen' ), 'icon' => '🎬' ],
    [ 'name' => 'Facebook/IG',    'ok' => $social->is_facebook_configured(), 'link' => admin_url( 'admin.php?page=hyt-settings&tab=social' ), 'icon' => '📘' ],
    [ 'name' => 'LinkedIn',       'ok' => $social->is_linkedin_configured(), 'link' => admin_url( 'admin.php?page=hyt-settings&tab=social' ), 'icon' => '💼' ],
    [ 'name' => 'YouTube',        'ok' => $social->is_youtube_configured(),  'link' => admin_url( 'admin.php?page=hyt-settings&tab=social' ), 'icon' => '▶️' ],
];
$configured_count = count( array_filter( $services, fn($s) => $s['ok'] ) );
?>
<div class="wrap hyt-wrap">
  <h1 class="hyt-page-title">
    <span class="dashicons dashicons-media-document"></span>
    HYT Content Automation <span class="hyt-version">v<?php echo HYT_VERSION; ?></span>
  </h1>

  <?php if ( $is_holiday ) : ?>
  <div class="hyt-notice hyt-notice--warning">
    <span class="dashicons dashicons-flag"></span>
    <strong>Bugün tatil günü!</strong> (<?php echo esc_html( $today ); ?>) — İçerik planlaması bu güne yapılmayacak.
  </div>
  <?php endif; ?>

  <?php if ( ($counts['review_pending'] ?? 0) > 0 ) : ?>
  <div class="hyt-notice hyt-notice--warning" style="display:flex;align-items:center;gap:12px">
    <span style="font-size:20px">⏳</span>
    <div>
      <strong><?php echo (int)$counts['review_pending']; ?> içerik onayınızı bekliyor.</strong>
      <a href="<?php echo admin_url( 'admin.php?page=hyt-queue&filter=review_pending' ); ?>"
         class="hyt-btn hyt-btn-warning hyt-btn-sm" style="margin-left:12px">Onay Kuyruğuna Git</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="hyt-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
    <div class="hyt-stat-card hyt-stat-total">
      <div class="hyt-stat-icon"><span class="dashicons dashicons-list-view"></span></div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num"><?php echo (int)$counts['all']; ?></span>
        <span class="hyt-stat-label">Toplam</span>
      </div>
    </div>
    <div class="hyt-stat-card hyt-stat-processing">
      <div class="hyt-stat-icon"><span class="dashicons dashicons-update-alt"></span></div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num"><?php echo (int)$counts['processing'] + (int)$counts['pending']; ?></span>
        <span class="hyt-stat-label">Kuyrukta</span>
      </div>
    </div>
    <?php if ( ($counts['review_pending'] ?? 0) > 0 ) : ?>
    <div class="hyt-stat-card" style="border-top:4px solid #f59e0b">
      <div class="hyt-stat-icon" style="color:#f59e0b">⏳</div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num" style="color:#d97706"><?php echo (int)$counts['review_pending']; ?></span>
        <span class="hyt-stat-label">Onay Bekliyor</span>
      </div>
    </div>
    <?php endif; ?>
    <div class="hyt-stat-card hyt-stat-done">
      <div class="hyt-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num"><?php echo (int)$counts['done']; ?></span>
        <span class="hyt-stat-label">Yayında</span>
      </div>
    </div>
    <?php if ( ($counts['video_wait'] ?? 0) > 0 ) : ?>
    <div class="hyt-stat-card" style="border-top:4px solid #8b5cf6">
      <div class="hyt-stat-icon" style="color:#8b5cf6">🎬</div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num" style="color:#7c3aed"><?php echo (int)$counts['video_wait']; ?></span>
        <span class="hyt-stat-label">Video Bekliyor</span>
      </div>
    </div>
    <?php endif; ?>
    <div class="hyt-stat-card hyt-stat-failed">
      <div class="hyt-stat-icon"><span class="dashicons dashicons-warning"></span></div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num"><?php echo (int)$counts['failed']; ?></span>
        <span class="hyt-stat-label">Başarısız</span>
      </div>
    </div>
    <div class="hyt-stat-card hyt-stat-duplicate">
      <div class="hyt-stat-icon"><span class="dashicons dashicons-admin-page"></span></div>
      <div class="hyt-stat-body">
        <span class="hyt-stat-num"><?php echo (int)$counts['duplicate']; ?></span>
        <span class="hyt-stat-label">Duplicate</span>
      </div>
    </div>
  </div>

  <div class="hyt-dashboard-cols">

    <!-- SOL KOLON -->
    <div class="hyt-col-main">

      <!-- UPLOAD ZONE -->
      <div class="hyt-card">
        <div class="hyt-card-header">
          <span class="dashicons dashicons-upload"></span> Dosya Yükle
        </div>
        <div class="hyt-card-body">
          <div id="hyt-drop-zone" class="hyt-drop-zone">
            <span class="dashicons dashicons-cloud-upload hyt-drop-icon"></span>
            <p>Dosyaları buraya sürükle &amp; bırak veya</p>
            <label class="hyt-btn hyt-btn-primary" for="hyt-file-input">Dosya Seç</label>
            <input type="file" id="hyt-file-input" name="files[]" multiple accept=".docx,.txt" style="display:none">
            <p class="hyt-drop-hint">.docx ve .txt dosyaları desteklenir — aynı anda birden fazla dosya yükleyebilirsiniz</p>
          </div>
          <div id="hyt-upload-progress" style="display:none">
            <div class="hyt-progress-bar"><div class="hyt-progress-fill"></div></div>
            <p id="hyt-upload-msg">Yükleniyor...</p>
          </div>
          <div id="hyt-upload-result" style="display:none"></div>
        </div>
      </div>

      <!-- SON İÇERİKLER -->
      <div class="hyt-card">
        <div class="hyt-card-header">
          <span class="dashicons dashicons-clock"></span> Son İşlemler
          <a href="<?php echo admin_url( 'admin.php?page=hyt-queue' ); ?>" class="hyt-card-link">Tümünü Gör →</a>
        </div>
        <div class="hyt-card-body hyt-p0">
          <?php if ( empty( $recent ) ) : ?>
            <p class="hyt-empty">Henüz içerik yok. Dosya yükleyerek başlayın.</p>
          <?php else : ?>
          <table class="hyt-table">
            <thead><tr>
              <th>Başlık</th><th>Durum</th><th>Adım</th><th>Planlandı</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $recent as $row ) :
              $status_label = hyt_status_label( $row->status );
              $scheduled    = $row->scheduled_at ? date( 'd.m.Y H:i', strtotime( $row->scheduled_at ) ) : '—';
              $post_link    = $row->post_id ? get_edit_post_link( $row->post_id ) : '';
            ?>
            <tr>
              <td class="hyt-col-title">
                <?php if ( $post_link ) : ?>
                  <a href="<?php echo esc_url( $post_link ); ?>" target="_blank"><?php echo esc_html( $row->title ?? $row->file_name ); ?></a>
                <?php else : ?>
                  <?php echo esc_html( $row->title ?? $row->file_name ); ?>
                <?php endif; ?>
              </td>
              <td><?php echo $status_label; ?></td>
              <td><code><?php echo esc_html( $row->step ?? '—' ); ?></code></td>
              <td><?php echo esc_html( $scheduled ); ?></td>
              <td class="hyt-row-actions">
                <?php if ( $row->status === 'review_pending' ) : ?>
                  <a href="<?php echo admin_url( 'admin.php?page=hyt-queue&filter=review_pending' ); ?>"
                     class="hyt-btn hyt-btn-xs hyt-btn-warning" title="Onay Bekliyor">⏳</a>
                <?php elseif ( $row->status === 'failed' || $row->status === 'cancelled' ) : ?>
                  <button class="hyt-btn hyt-btn-xs hyt-btn-retry" data-id="<?php echo (int)$row->id; ?>">🔄</button>
                <?php elseif ( $row->status === 'processing' || $row->status === 'pending' ) : ?>
                  <button class="hyt-btn hyt-btn-xs hyt-btn-pause" data-id="<?php echo (int)$row->id; ?>">⏸</button>
                <?php endif; ?>
                <?php if ( $row->post_id ) : ?>
                  <button class="hyt-btn hyt-btn-xs hyt-btn-expand" data-id="<?php echo (int)$row->id; ?>" title="İçeriği Genişlet">📝</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.hyt-col-main -->

    <!-- SAĞ KOLON -->
    <div class="hyt-col-side">

      <!-- SERVİS DURUMU -->
      <div class="hyt-card">
        <div class="hyt-card-header">
          <span class="dashicons dashicons-admin-plugins"></span> Servis Durumu
          <span class="hyt-card-badge"><?php echo $configured_count; ?>/<?php echo count($services); ?></span>
        </div>
        <div class="hyt-card-body hyt-service-list">
          <?php foreach ( $services as $svc ) :
            $dot   = $svc['ok'] ? '<span class="hyt-dot hyt-dot-green"></span>' : '<span class="hyt-dot hyt-dot-red"></span>';
            $label = $svc['ok'] ? 'Bağlı' : 'Bağlı Değil';
          ?>
          <div class="hyt-service-row">
            <?php echo $dot; ?>
            <span><?php echo $svc['icon']; ?> <?php echo esc_html( $svc['name'] ); ?></span>
            <?php if ( ! $svc['ok'] ) : ?>
              <a href="<?php echo esc_url( $svc['link'] ); ?>" class="hyt-service-config">Yapılandır →</a>
            <?php endif; ?>
            <span class="hyt-service-status <?php echo $svc['ok'] ? 'hyt-service-ok' : 'hyt-service-err'; ?>"><?php echo $label; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- YAYIN TAKVİMİ -->
      <div class="hyt-card">
        <div class="hyt-card-header"><span class="dashicons dashicons-calendar-alt"></span> Yayın Planı</div>
        <div class="hyt-card-body">
          <?php
          $days      = (array) get_option( 'hyt_publish_days', [ 'monday', 'wednesday', 'friday' ] );
          $day_labels = [
            'monday' => 'Pazartesi', 'tuesday' => 'Salı', 'wednesday' => 'Çarşamba',
            'thursday' => 'Perşembe', 'friday' => 'Cuma', 'saturday' => 'Cumartesi', 'sunday' => 'Pazar',
          ];
          $day_names = array_map( fn($d) => $day_labels[$d] ?? $d, $days );
          $time      = get_option( 'hyt_publish_time', '08:45' );
          $review_on = get_option( 'hyt_review_before_publish', '0' ) === '1';
          ?>
          <p><strong>Günler:</strong> <?php echo esc_html( implode( ', ', $day_names ) ); ?></p>
          <p><strong>Saat:</strong> <?php echo esc_html( $time ); ?></p>
          <p><strong>Onay Akışı:</strong>
            <?php if ( $review_on ) : ?>
              <span style="color:#f59e0b;font-weight:600">✅ Aktif (manuel onay)</span>
            <?php else : ?>
              <span style="color:#6b7280">Devre dışı (otomatik)</span>
            <?php endif; ?>
          </p>
          <p><strong>Sonraki Slot:</strong>
            <?php echo $next_slot
              ? '<strong style="color:#16a34a">' . esc_html( date( 'd.m.Y H:i', strtotime( $next_slot ) ) ) . '</strong>'
              : '<span style="color:#dc2626">Slot bulunamadı</span>'; ?>
          </p>
          <a href="<?php echo admin_url( 'admin.php?page=hyt-settings&tab=schedule' ); ?>"
             class="hyt-btn hyt-btn-secondary hyt-btn-sm">Planı Düzenle</a>
        </div>
      </div>

      <!-- AKTİF KANALLAR -->
      <?php
      $channels = [];
      if ( get_option('hyt_social_facebook_enabled','0') === '1'  && $social->is_facebook_configured()  ) $channels[] = '📘 Facebook';
      if ( get_option('hyt_social_instagram_enabled','0') === '1' && $social->is_instagram_configured() ) $channels[] = '📷 Instagram';
      if ( get_option('hyt_social_linkedin_enabled','0') === '1'  && $social->is_linkedin_configured()  ) $channels[] = '💼 LinkedIn';
      if ( get_option('hyt_social_youtube_enabled','0') === '1'   && $social->is_youtube_configured()   ) $channels[] = '▶️ YouTube';
      if ( get_option('hyt_heygen_long_video_enabled','0') === '1' || get_option('hyt_heygen_short_video_enabled','0') === '1' ) {
        if ( $heygen->is_configured() ) $channels[] = '🎬 HeyGen Video';
      }
      ?>
      <div class="hyt-card">
        <div class="hyt-card-header"><span class="dashicons dashicons-share"></span> Aktif Kanallar</div>
        <div class="hyt-card-body">
          <?php if ( empty( $channels ) ) : ?>
            <p class="hyt-muted">Henüz kanal yapılandırılmamış.</p>
            <a href="<?php echo admin_url( 'admin.php?page=hyt-settings&tab=social' ); ?>"
               class="hyt-btn hyt-btn-secondary hyt-btn-sm">Kanalları Yapılandır</a>
          <?php else : ?>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
              <?php foreach ( $channels as $ch ) : ?>
              <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600">
                <?php echo esc_html($ch); ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php $delay = (int)get_option('hyt_social_delay_minutes', 30); ?>
            <p class="hyt-muted" style="font-size:12px">Yayından <?php echo $delay; ?> dakika sonra dağıtılır.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- HIZLI İŞLEMLER -->
      <div class="hyt-card">
        <div class="hyt-card-header"><span class="dashicons dashicons-controls-play"></span> Hızlı İşlemler</div>
        <div class="hyt-card-body hyt-quick-actions">
          <?php if ( ($counts['review_pending'] ?? 0) > 0 ) : ?>
          <a href="<?php echo admin_url( 'admin.php?page=hyt-queue&filter=review_pending' ); ?>"
             class="hyt-btn hyt-btn-warning hyt-btn-full">
            ⏳ Onay Bekleyenler (<?php echo (int)$counts['review_pending']; ?>)
          </a>
          <?php endif; ?>
          <?php if ( $drive->is_connected() ) : ?>
          <button id="hyt-scan-now" class="hyt-btn hyt-btn-secondary hyt-btn-full">
            <span class="dashicons dashicons-search"></span> Drive'ı Şimdi Tara
          </button>
          <?php endif; ?>
          <?php if ( ($counts['failed'] ?? 0) > 0 ) : ?>
          <button id="hyt-retry-all" class="hyt-btn hyt-btn-warning hyt-btn-full">
            <span class="dashicons dashicons-update"></span> Başarısızları Tekrarla (<?php echo (int)$counts['failed']; ?>)
          </button>
          <?php endif; ?>
          <a href="<?php echo admin_url( 'admin.php?page=hyt-queue' ); ?>" class="hyt-btn hyt-btn-outline hyt-btn-full">
            <span class="dashicons dashicons-list-view"></span> Kuyruğu Görüntüle
          </a>
          <a href="<?php echo admin_url( 'admin.php?page=hyt-logs' ); ?>" class="hyt-btn hyt-btn-outline hyt-btn-full">
            <span class="dashicons dashicons-text-page"></span> Logları İncele
          </a>
          <a href="<?php echo admin_url( 'admin.php?page=hyt-settings' ); ?>" class="hyt-btn hyt-btn-outline hyt-btn-full">
            <span class="dashicons dashicons-admin-settings"></span> Ayarlar
          </a>
        </div>
      </div>

    </div><!-- /.hyt-col-side -->
  </div><!-- /.hyt-dashboard-cols -->
</div><!-- /.hyt-wrap -->

