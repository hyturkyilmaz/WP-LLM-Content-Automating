<?php
/**
 * HYT Content Automation — Global Helper Fonksiyonlar
 */
defined( 'ABSPATH' ) || exit;

/**
 * Pipeline durumuna göre renkli etiket döndürür.
 */
function hyt_status_label( string $status ): string {
    $labels = [
        'pending'        => [ 'class' => 'hyt-status-pending',        'icon' => '⏳', 'text' => 'Bekliyor' ],
        'processing'     => [ 'class' => 'hyt-status-processing',     'icon' => '🔄', 'text' => 'İşleniyor' ],
        'done'           => [ 'class' => 'hyt-status-done',           'icon' => '✅', 'text' => 'Yayında' ],
        'failed'         => [ 'class' => 'hyt-status-failed',         'icon' => '❌', 'text' => 'Başarısız' ],
        'cancelled'      => [ 'class' => 'hyt-status-cancelled',      'icon' => '✕',  'text' => 'İptal' ],
        'paused'         => [ 'class' => 'hyt-status-paused',         'icon' => '⏸',  'text' => 'Duraklatıldı' ],
        'duplicate'      => [ 'class' => 'hyt-status-duplicate',      'icon' => '⚠️', 'text' => 'Duplicate' ],
        'review_pending' => [ 'class' => 'hyt-status-review_pending', 'icon' => '🕐', 'text' => 'Onay Bekliyor' ],
        'video_wait'     => [ 'class' => 'hyt-status-paused',         'icon' => '🎬', 'text' => 'Video Bekliyor' ],
    ];

    $def = $labels[ $status ] ?? [ 'class' => 'hyt-status-pending', 'icon' => '?', 'text' => esc_html( $status ) ];
    return sprintf(
        '<span class="hyt-status %s">%s %s</span>',
        esc_attr( $def['class'] ),
        $def['icon'],
        esc_html( $def['text'] )
    );
}
