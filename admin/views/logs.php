<?php
defined( 'ABSPATH' ) || exit;

$level    = sanitize_key( $_GET['level']   ?? '' );
$context  = sanitize_key( $_GET['context'] ?? '' );
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 50;

$logs  = HYT_Database::get_logs( [ 'level' => $level, 'context' => $context, 'per_page' => $per_page, 'page' => $page_num ] );
$total = HYT_Database::count_logs( [ 'level' => $level, 'context' => $context ] );
$pages = ceil( $total / $per_page );
$all_contexts = HYT_Database::get_log_contexts();

$level_icons = [
	'info'    => 'Bilgi',
	'warning' => 'Uyari',
	'error'   => 'Hata',
];
?>
<div class="wrap hyt-wrap">
    <h1 class="hyt-page-title">
        <span class="dashicons dashicons-list-view"></span> Sistem Loglari
        <span class="hyt-total-badge"><?php echo $total; ?> kayit</span>
    </h1>

    <div class="hyt-filter-bar">
        <form method="get">
            <input type="hidden" name="page" value="hyt-logs">

            <select name="level" class="hyt-select" onchange="this.form.submit()">
                <option value="">Tum Seviyeler</option>
                <option value="info"    <?php selected( $level, 'info' ); ?>>Bilgi</option>
                <option value="warning" <?php selected( $level, 'warning' ); ?>>Uyari</option>
                <option value="error"   <?php selected( $level, 'error' ); ?>>Hata</option>
            </select>

            <select name="context" class="hyt-select" onchange="this.form.submit()">
                <option value="">Tum Baglamlar</option>
                <?php foreach ( $all_contexts as $ctx ) : ?>
                <option value="<?php echo esc_attr($ctx); ?>" <?php selected( $context, $ctx ); ?>>
                    <?php echo esc_html( $ctx ); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <?php if ( $level || $context ) : ?>
                <a href="<?php echo admin_url('admin.php?page=hyt-logs'); ?>" class="button">Filtreyi Temizle</a>
            <?php endif; ?>
        </form>

        <div class="hyt-filter-actions">
            <button id="hyt-export-logs-btn" class="button button-secondary"
                    data-level="<?php echo esc_attr($level); ?>"
                    data-context="<?php echo esc_attr($context); ?>">
                <span class="dashicons dashicons-download"></span> CSV Disa Aktar
            </button>
            <button id="hyt-clear-logs-btn" class="button" style="color:#ef4444;border-color:#ef4444;">
                <span class="dashicons dashicons-trash"></span> Tum Loglari Sil
            </button>
        </div>
    </div>

    <table class="hyt-table hyt-logs-table">
        <thead>
            <tr>
                <th class="hyt-col-log-id">ID</th>
                <th class="hyt-col-log-level">Seviye</th>
                <th class="hyt-col-log-ctx">Baglam</th>
                <th class="hyt-col-log-msg">Mesaj</th>
                <th class="hyt-col-log-data">Veri</th>
                <th class="hyt-col-log-time">Tarih</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $logs ) ) : ?>
            <tr><td colspan="6" class="hyt-empty-td">Log kaydi bulunamadi.</td></tr>
        <?php else : ?>
        <?php foreach ( $logs as $log ) : ?>
            <tr class="hyt-log-row-<?php echo esc_attr( $log->level ); ?>">
                <td class="hyt-muted"><?php echo (int) $log->id; ?></td>
                <td>
                    <span class="hyt-log-badge hyt-log-<?php echo esc_attr( $log->level ); ?>">
                        <?php echo $level_icons[ $log->level ] ?? ''; ?>
                        <?php echo esc_html( strtoupper( $log->level ) ); ?>
                    </span>
                </td>
                <td><span class="hyt-log-context"><?php echo esc_html( $log->context ); ?></span></td>
                <td class="hyt-log-message"><?php echo esc_html( $log->message ); ?></td>
                <td>
                    <?php if ( $log->data ) : ?>
                        <button class="button hyt-log-expand-btn"
                                data-log-id="<?php echo (int) $log->id; ?>"
                                data-data="<?php echo esc_js( mb_substr( $log->data, 0, 2000 ) ); ?>">
                            Goster
                        </button>
                    <?php else : ?>
                        <span class="hyt-muted">-</span>
                    <?php endif; ?>
                </td>
                <td class="hyt-log-time">
                    <?php echo esc_html( date( 'd.m.Y H:i:s', strtotime( $log->created_at ) ) ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $pages > 1 ) : ?>
    <div class="hyt-pagination">
        <?php for ( $p = 1; $p <= min( $pages, 20 ); $p++ ) :
            $href = add_query_arg( [ 'page' => 'hyt-logs', 'level' => $level, 'context' => $context, 'paged' => $p ], admin_url('admin.php') );
        ?>
        <a href="<?php echo esc_url($href); ?>" class="hyt-page-btn <?php echo $p === $page_num ? 'hyt-page-active' : ''; ?>">
            <?php echo $p; ?>
        </a>
        <?php endfor; ?>
        <?php if ( $pages > 20 ) : ?>
            <span class="hyt-muted">... (<?php echo $pages; ?> sayfa)</span>
        <?php endif; ?>
        <span class="hyt-page-info"><?php echo $total; ?> toplam kayit</span>
    </div>
    <?php endif; ?>
</div>

<div id="hyt-log-data-modal" class="hyt-modal">
    <div class="hyt-modal-content hyt-modal-wide">
        <h3>Log Verisi</h3>
        <pre id="hyt-log-data-pre" class="hyt-log-data-pre"></pre>
        <div class="hyt-modal-actions">
            <button id="hyt-log-data-close" class="button button-secondary">Kapat</button>
        </div>
    </div>
</div>