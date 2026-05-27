<?php defined( 'ABSPATH' ) || exit;

/* ---- Filtre Sekmesi ---- */
$current_tab  = sanitize_key( $_GET['filter'] ?? 'all' );
$current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page     = 30;

$tab_filters = [
    'all'            => [ 'label' => 'Tümü',            'args' => [] ],
    'pending'        => [ 'label' => 'Bekliyor',         'args' => [ 'status' => 'pending' ] ],
    'processing'     => [ 'label' => 'İşleniyor',        'args' => [ 'status' => 'processing' ] ],
    'review_pending' => [ 'label' => 'Onay Bekliyor',    'args' => [ 'status' => 'review_pending' ] ],
    'paused'         => [ 'label' => 'Duraklatıldı',     'args' => [ 'status' => 'paused' ] ],
    'done'           => [ 'label' => 'Yayında',          'args' => [ 'status' => 'done' ] ],
    'video_wait'     => [ 'label' => 'Video Bekliyor',   'args' => [ 'status' => 'video_wait' ] ],
    'duplicate'      => [ 'label' => 'Duplicate',        'args' => [ 'status' => 'duplicate' ] ],
    'flag_seo'       => [ 'label' => 'SEO Tamam',        'args' => [ 'flag_seo' => 1 ] ],
    'flag_img'       => [ 'label' => 'Görsel Tamam',     'args' => [ 'flag_img' => 1 ] ],
    'flag_video'     => [ 'label' => 'Video Tamam',      'args' => [ 'flag_video' => 1 ] ],
    'flag_social'    => [ 'label' => 'Sosyal Paylaşıldı','args' => [ 'flag_social' => 1 ] ],
    'failed'         => [ 'label' => 'Başarısız',        'args' => [ 'status' => 'failed' ] ],
    'cancelled'      => [ 'label' => 'İptal',            'args' => [ 'status' => 'cancelled' ] ],
];

$args             = $tab_filters[ $current_tab ]['args'] ?? [];
$args['per_page'] = $per_page;
$args['page']     = $current_page;

$rows        = HYT_Database::get_pipelines( $args );
$total       = HYT_Database::count_pipelines( $args );
$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$counts      = HYT_Ajax::get_tab_counts();

/* Aktif kanallar */
$fb_enabled  = get_option( 'hyt_social_facebook_enabled', '0' ) === '1';
$ig_enabled  = get_option( 'hyt_social_instagram_enabled', '0' ) === '1';
$li_enabled  = get_option( 'hyt_social_linkedin_enabled', '0' ) === '1';
$yt_enabled  = get_option( 'hyt_social_youtube_enabled', '0' ) === '1';
$any_social  = $fb_enabled || $ig_enabled || $li_enabled || $yt_enabled;

$heygen_any  = get_option( 'hyt_heygen_long_video_enabled', '0' ) === '1'
            || get_option( 'hyt_heygen_short_video_enabled', '0' ) === '1';
?>
<div class="wrap hyt-wrap">
  <h1 class="hyt-page-title">
    <span class="dashicons dashicons-list-view"></span> İçerik Kuyruğu
    <?php if ( ($counts['review_pending'] ?? 0) > 0 ) : ?>
      <span class="hyt-badge hyt-badge-warning" style="font-size:13px;margin-left:8px">
        ⏳ <?php echo (int)$counts['review_pending']; ?> onay bekliyor
      </span>
    <?php endif; ?>
  </h1>

  <!-- SEKMELİ FİLTRELER -->
  <nav class="hyt-tab-nav" style="flex-wrap:wrap">
    <?php foreach ( $tab_filters as $key => $tab ) :
      $count  = $counts[ $key ] ?? HYT_Database::count_pipelines( $tab['args'] );
      $active = $current_tab === $key ? 'hyt-tab-active' : '';
      $url    = add_query_arg( [ 'page' => 'hyt-queue', 'filter' => $key ], admin_url( 'admin.php' ) );
      /* Onay sekmesini vurgula */
      $extra = ( $key === 'review_pending' && $count > 0 ) ? 'style="border-color:#f59e0b;color:#d97706"' : '';
    ?>
    <a href="<?php echo esc_url( $url ); ?>" class="hyt-tab <?php echo $active; ?>" <?php echo $extra; ?>>
      <?php echo esc_html( $tab['label'] ); ?>
      <?php if ( $count > 0 ) : ?><span class="hyt-tab-count"><?php echo (int)$count; ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- TOPLU İŞLEM BARI -->
  <div class="hyt-bulk-bar">
    <select id="hyt-bulk-action">
      <option value="">— Toplu İşlem Seç —</option>
      <option value="pause">⏸ Duraklat</option>
      <option value="resume">▶ Devam Ettir</option>
      <option value="retry">🔄 Tekrar Dene</option>
      <option value="cancel">✕ İptal Et</option>
      <?php if ( get_option( 'hyt_review_before_publish', '0' ) === '1' ) : ?>
      <option value="approve">✅ Toplu Onayla</option>
      <option value="reject">❌ Toplu Reddet</option>
      <?php endif; ?>
    </select>
    <button id="hyt-bulk-apply" class="hyt-btn hyt-btn-secondary">Uygula</button>
    <button id="hyt-retry-failed-btn" class="hyt-btn hyt-btn-warning" style="margin-left:auto">
      🔄 Tüm Başarısızları Tekrarla
      <?php if ( ($counts['failed'] ?? 0) > 0 ) echo '<span class="hyt-badge">' . (int)$counts['failed'] . '</span>'; ?>
    </button>
  </div>

  <!-- TABLO -->
  <div class="hyt-card hyt-p0">
    <?php if ( empty( $rows ) ) : ?>
      <p class="hyt-empty">Bu filtrede kayıt bulunamadı.</p>
    <?php else : ?>
    <table class="hyt-table hyt-queue-table">
      <thead><tr>
        <th><input type="checkbox" id="hyt-select-all"></th>
        <th>Başlık / Dosya</th>
        <th>Durum</th>
        <th>Adım</th>
        <th>Planlanma</th>
        <th>Göstergeler</th>
        <th>İşlemler</th>
      </tr></thead>
      <tbody>
      <?php foreach ( $rows as $row ) :
        $scheduled  = $row->scheduled_at ? date( 'd.m.Y H:i', strtotime( $row->scheduled_at ) ) : '—';
        $post_link  = $row->post_id ? get_edit_post_link( $row->post_id ) : '';
        $view_link  = $row->post_id ? get_permalink( $row->post_id ) : '';
        $title_disp = ( isset( $row->title )     && $row->title     !== '' ? $row->title     : null )
                  ?? ( isset( $row->file_name ) && $row->file_name !== '' ? $row->file_name : null )
                  ?? '(isimsiz)';
        $status_label = hyt_status_label( $row->status );
        $dup_data = ( $row->status === 'duplicate' && $row->payload ) ? json_decode( $row->payload, true ) : null;
        $seo_data_arr = ( $row->seo_data && is_string( $row->seo_data ) ) ? json_decode( $row->seo_data, true ) : [];
        $has_review_note = ! empty( $row->review_note );
      ?>
      <tr data-id="<?php echo (int)$row->id; ?>" class="hyt-row-status-<?php echo esc_attr( $row->status ); ?>">
        <td><input type="checkbox" class="hyt-row-cb" value="<?php echo (int)$row->id; ?>"></td>

        <!-- BAŞLIK / DOSYA -->
        <td class="hyt-col-title">
          <strong><?php echo esc_html( $title_disp ); ?></strong>
          <?php if ( isset( $row->file_name ) && $row->file_name && $row->file_name !== $title_disp ) : ?>
            <br><small class="hyt-muted"><?php echo esc_html( $row->file_name ); ?></small>
          <?php endif; ?>
          <?php if ( $dup_data ) : ?>
            <br><small class="hyt-text-warning">
              ⚠ <?php echo esc_html( $dup_data['message'] ?? 'Duplicate' ); ?>
              <?php if ( ! empty( $dup_data['link'] ) ) : ?>
                — <a href="<?php echo esc_url( $dup_data['link'] ); ?>" target="_blank">Postu Gör</a>
              <?php endif; ?>
            </small>
          <?php endif; ?>
          <?php if ( isset( $row->error_message ) && $row->error_message && $row->status === 'failed' ) : ?>
            <br><small class="hyt-text-error">❌ <?php echo esc_html( substr( $row->error_message, 0, 120 ) ); ?></small>
          <?php endif; ?>
          <?php if ( $has_review_note && isset( $row->review_note ) ) : ?>
            <br><small class="hyt-text-info">📝 <?php echo esc_html( substr( $row->review_note, 0, 100 ) ); ?></small>
          <?php endif; ?>
          <?php if ( $row->status === 'review_pending' && ! empty( $seo_data_arr['meta_description'] ) ) : ?>
            <br><small class="hyt-muted" style="font-style:italic">📄 <?php echo esc_html( substr( $seo_data_arr['meta_description'], 0, 100 ) ); ?></small>
          <?php endif; ?>
        </td>

        <!-- DURUM -->
        <td><?php echo $status_label; ?></td>

        <!-- ADIM -->
        <td><code><?php echo esc_html( $row->step ?? '—' ); ?></code></td>

        <!-- PLANLANMA / POST LİNKİ -->
        <td>
          <span><?php echo esc_html( $scheduled ); ?></span>
          <?php if ( $post_link ) : ?>
            <br><a href="<?php echo esc_url( $post_link ); ?>" target="_blank" class="hyt-link-sm">✏ Düzenle</a>
            <?php if ( $view_link ) : ?>
              &nbsp;<a href="<?php echo esc_url( $view_link ); ?>" target="_blank" class="hyt-link-sm">👁 Gör</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ( ! empty( $row->heygen_video_url ) ) : ?>
            <br><a href="<?php echo esc_url( $row->heygen_video_url ); ?>" target="_blank" class="hyt-link-sm">🎬 Video</a>
          <?php endif; ?>
        </td>

        <!-- GÖSTERGELER -->
        <td>
          <div class="hyt-chips">
            <span class="hyt-chip <?php echo $row->flag_seo   ? 'hyt-chip-blue'  : 'hyt-chip-gray'; ?>" title="SEO">
              <?php echo $row->flag_seo ? '📈 SEO✓' : '📈 SEO'; ?>
            </span>
            <span class="hyt-chip <?php echo $row->flag_img   ? 'hyt-chip-green' : 'hyt-chip-gray'; ?>" title="Görsel">
              <?php echo $row->flag_img ? '🖼 Görsel✓' : '🖼 Görsel'; ?>
            </span>
            <?php if ( $heygen_any ) : ?>
            <span class="hyt-chip <?php echo $row->flag_video ? 'hyt-chip-purple' : 'hyt-chip-gray'; ?>" title="Video">
              <?php echo $row->flag_video ? '🎬 Video✓' : '🎬 Video'; ?>
            </span>
            <?php endif; ?>
            <?php if ( $any_social ) : ?>
            <span class="hyt-chip <?php echo $row->flag_social ? 'hyt-chip-orange' : 'hyt-chip-gray'; ?>" title="Sosyal">
              <?php echo $row->flag_social ? '📣 Sosyal✓' : '📣 Sosyal'; ?>
            </span>
            <?php endif; ?>
            <span class="hyt-chip <?php echo $row->flag_backlink ? 'hyt-chip-teal' : 'hyt-chip-gray'; ?>" title="Backlink">
              <?php echo $row->flag_backlink ? '🔗 BL✓' : '🔗 BL'; ?>
            </span>
          </div>
        </td>

        <!-- İŞLEMLER -->
        <td class="hyt-row-actions">
          <!-- ONAY AKIŞI: Onayla / Reddet -->
          <?php if ( $row->status === 'review_pending' ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-success hyt-review-approve"
                    data-id="<?php echo (int)$row->id; ?>" title="Onayla ve Yayına Al">✅</button>
            <button class="hyt-btn hyt-btn-xs hyt-btn-danger hyt-review-reject"
                    data-id="<?php echo (int)$row->id; ?>" title="Reddet">❌</button>
          <?php endif; ?>

          <!-- Durum işlemleri -->
          <?php if ( in_array( $row->status, [ 'failed', 'cancelled' ], true ) ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-pipeline-action"
                    data-id="<?php echo (int)$row->id; ?>" data-action="retry" title="Tekrar Dene">🔄</button>
          <?php endif; ?>
          <?php if ( in_array( $row->status, [ 'pending', 'processing' ], true ) ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-pipeline-action"
                    data-id="<?php echo (int)$row->id; ?>" data-action="pause" title="Duraklat">⏸</button>
          <?php endif; ?>
          <?php if ( $row->status === 'paused' ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-pipeline-action"
                    data-id="<?php echo (int)$row->id; ?>" data-action="resume" title="Devam Ettir">▶</button>
          <?php endif; ?>
          <?php if ( ! in_array( $row->status, [ 'cancelled', 'done' ], true ) ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-pipeline-action"
                    data-id="<?php echo (int)$row->id; ?>" data-action="cancel" title="İptal Et">✕</button>
          <?php endif; ?>

          <!-- İçerik genişlet (post varsa) -->
          <?php if ( $row->post_id ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-action-expand"
                    data-id="<?php echo (int)$row->id; ?>" title="Claude ile Genişlet">📝</button>
          <?php endif; ?>

          <!-- Manuel Görsel üret -->
          <?php if ( $row->post_id && ! $row->flag_img ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-action-generate-img"
                    data-id="<?php echo (int)$row->id; ?>" title="Görsel Üret">🖼</button>
          <?php endif; ?>

          <!-- Manuel Video başlat -->
          <?php if ( $heygen_any && $row->post_id && ! $row->flag_video && $row->status !== 'video_wait' ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-action-start-video"
                    data-id="<?php echo (int)$row->id; ?>" title="HeyGen Video Başlat">🎬</button>
          <?php endif; ?>

          <!-- Manuel Sosyal dağıt -->
          <?php if ( $any_social && $row->post_id && ! $row->flag_social ) : ?>
            <button class="hyt-btn hyt-btn-xs hyt-btn-action hyt-action-distribute"
                    data-id="<?php echo (int)$row->id; ?>" title="Sosyal Medyaya Dağıt">📣</button>
          <?php endif; ?>

          <!-- Flag Toggles -->
          <button class="hyt-btn hyt-btn-xs hyt-btn-flag hyt-flag-backlink"
                  data-id="<?php echo (int)$row->id; ?>" data-flag="flag_backlink"
                  title="<?php echo $row->flag_backlink ? 'Backlink var (kaldır)' : 'Backlink ekle'; ?>">
            <?php echo $row->flag_backlink ? '🔗✓' : '🔗'; ?>
          </button>
          <button class="hyt-btn hyt-btn-xs hyt-btn-flag hyt-flag-seo"
                  data-id="<?php echo (int)$row->id; ?>" data-flag="flag_seo"
                  title="<?php echo $row->flag_seo ? 'SEO tamam (kaldır)' : 'SEO işaretle'; ?>">
            <?php echo $row->flag_seo ? '📈✓' : '📈'; ?>
          </button>
        </td>
      </tr>

      <!-- ONAY MOD: SEO Özeti Satırı -->
      <?php if ( $row->status === 'review_pending' && ! empty( $seo_data_arr ) ) : ?>
      <tr class="hyt-review-details-row" style="background:#fffbeb">
        <td></td>
        <td colspan="6">
          <div class="hyt-review-summary">
            <?php if ( ! empty( $seo_data_arr['focus_keyword'] ) ) : ?>
              <span>🔑 <strong>Anahtar:</strong> <?php echo esc_html( $seo_data_arr['focus_keyword'] ); ?></span>
            <?php endif; ?>
            <?php if ( ! empty( $seo_data_arr['slug'] ) ) : ?>
              <span>🔗 <strong>Slug:</strong> /<?php echo esc_html( $seo_data_arr['slug'] ); ?></span>
            <?php endif; ?>
            <?php if ( ! empty( $seo_data_arr['geo_summary'] ) ) : ?>
              <span>🌍 <strong>GEO:</strong> <?php echo esc_html( substr( $seo_data_arr['geo_summary'], 0, 120 ) ); ?></span>
            <?php endif; ?>
            <div class="hyt-review-actions">
              <button class="hyt-btn hyt-btn-success hyt-btn-sm hyt-review-approve"
                      data-id="<?php echo (int)$row->id; ?>">✅ Onayla ve Yayına Al</button>
              <button class="hyt-btn hyt-btn-danger hyt-btn-sm hyt-review-reject"
                      data-id="<?php echo (int)$row->id; ?>">❌ Reddet</button>
            </div>
          </div>
        </td>
      </tr>
      <?php endif; ?>

      <?php endforeach; ?>
      </tbody>
    </table>

    <!-- SAYFALAMA -->
    <?php if ( $total_pages > 1 ) : ?>
    <div class="hyt-pagination">
      <span class="hyt-pagination-info">
        Toplam <strong><?php echo (int)$total; ?></strong> kayıt — Sayfa <strong><?php echo $current_page; ?></strong> / <strong><?php echo $total_pages; ?></strong>
      </span>
      <div class="hyt-pagination-links">
        <?php if ( $current_page > 1 ) : ?>
          <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $current_page - 1 ] ) ); ?>" class="hyt-btn hyt-btn-xs">← Önceki</a>
        <?php endif; ?>
        <?php
        $start = max( 1, $current_page - 2 );
        $end   = min( $total_pages, $current_page + 2 );
        for ( $p = $start; $p <= $end; $p++ ) :
          $active = $p === $current_page ? 'hyt-btn-primary' : 'hyt-btn-outline';
        ?>
          <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $p ] ) ); ?>" class="hyt-btn hyt-btn-xs <?php echo $active; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <?php if ( $current_page < $total_pages ) : ?>
          <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $current_page + 1 ] ) ); ?>" class="hyt-btn hyt-btn-xs">Sonraki →</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty rows ?>
  </div><!-- /.hyt-card -->
</div><!-- /.hyt-wrap -->

<!-- REDDET MODAL — JS: .css('display','flex') ile açılır -->
<div id="hyt-reject-modal" class="hyt-modal">
  <div class="hyt-modal-content">
    <h3>❌ İçeriği Reddet</h3>
    <p style="color:#6b7280;margin-bottom:12px">Reddetme nedeni (opsiyonel):</p>
    <textarea id="hyt-reject-note" class="hyt-textarea" rows="4"
              placeholder="Reddetme nedeni buraya..."
              style="width:100%;box-sizing:border-box"></textarea>
    <div class="hyt-modal-actions">
      <button id="hyt-reject-cancel" class="hyt-btn hyt-btn-secondary">İptal</button>
      <button id="hyt-reject-confirm" class="hyt-btn hyt-btn-danger">❌ Reddet</button>
    </div>
  </div>
</div>
