/**
 * HYT Content Automation — Admin JS v2.2.0
 * Düzeltmeler:
 *  - Upload zone ID'leri (#hyt-drop-zone, #hyt-file-input, label+button)
 *  - Dashboard scan/retry/pause/expand buton handler'ları eklendi
 *  - stat-num selector düzeltildi (.hyt-stat-num)
 *  - Log modal escape sorunu çözüldü (data-id ile server-side fetch)
 *  - Tüm selector/ID tutarsızlıkları giderildi
 */
(function ($) {
    'use strict';

    /* ============================================================
       GLOBALS
    ============================================================ */
    const ajaxUrl = HYT.ajax_url;
    const nonce   = HYT.nonce;

    let rejectTargetId = null;

    /* ============================================================
       HELPERS
    ============================================================ */
    function ajax(action, data, cb) {
        $.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, data), function (res) {
            if (cb) cb(res);
        });
    }

    function notify(msg, type) {
        type = type || 'info';
        const colors = {
            success : '#10b981',
            error   : '#ef4444',
            warning : '#f59e0b',
            info    : '#6366f1',
        };
        const $n = $('<div class="hyt-notify">')
            .css({
                background  : colors[type] || colors.info,
                color       : '#fff',
                padding     : '10px 16px',
                borderRadius: '6px',
                position    : 'fixed',
                top         : '32px',
                right       : '20px',
                zIndex      : 999999,
                fontSize    : '13px',
                fontWeight  : '600',
                boxShadow   : '0 4px 20px rgba(0,0,0,0.4)',
                maxWidth    : '380px',
                lineHeight  : '1.4',
                cursor      : 'pointer',
            })
            .text(msg)
            .appendTo('body')
            .click(function () { $(this).remove(); });
        setTimeout(function () { $n.fadeOut(400, function () { $(this).remove(); }); }, 5000);
    }

    function btnLoading($btn, loading) {
        if (loading) {
            $btn.data('orig', $btn.html())
                .html('<span class="hyt-spinner"></span> İşleniyor...')
                .prop('disabled', true);
        } else {
            $btn.html($btn.data('orig') || $btn.html()).prop('disabled', false);
        }
    }

    /* ============================================================
       UPLOAD ZONE — Dashboard
       HTML: #hyt-drop-zone, #hyt-file-input (type=file), label>Dosya Seç
       Yükleme, input change veya drag-drop ile tetiklenir.
       "Yükle" butonu yok — label tıklaması input'u tetikler,
       sonra otomatik yükleme başlar.
    ============================================================ */
    const $dropZone = $('#hyt-drop-zone');
    const $fileInput = $('#hyt-file-input');

    if ($dropZone.length) {

        /* Drag-over görsel geri bildirimi */
        $dropZone.on('dragover dragenter', function (e) {
            e.preventDefault();
            $(this).addClass('hyt-drag-over');
        });

        $dropZone.on('dragleave', function () {
            $(this).removeClass('hyt-drag-over');
        });

        /* Drop */
        $dropZone.on('drop', function (e) {
            e.preventDefault();
            $(this).removeClass('hyt-drag-over');
            const files = e.originalEvent.dataTransfer.files;
            if (files && files.length) uploadFiles(files);
        });

        /* Input change (Dosya Seç label butonu) */
        $fileInput.on('change', function () {
            if (this.files && this.files.length) uploadFiles(this.files);
        });

        function uploadFiles(files) {
            const $progress = $('#hyt-upload-progress');
            const $msg      = $('#hyt-upload-msg');
            const $result   = $('#hyt-upload-result');

            $progress.show();
            $result.hide().empty();
            $msg.text('Yükleniyor... (' + files.length + ' dosya)');

            const fd = new FormData();
            fd.append('action', 'hyt_upload_files');
            fd.append('nonce', nonce);
            Array.from(files).forEach(function (f) {
                fd.append('files[]', f, f.name);
            });

            $.ajax({
                url        : ajaxUrl,
                method     : 'POST',
                data       : fd,
                processData: false,
                contentType: false,
                success    : function (res) {
                    $progress.hide();
                    if (res.success) {
                        notify(res.data.message, 'success');
                        if (res.data.errors && res.data.errors.length) {
                            notify('Hatalar: ' + res.data.errors.join(' | '), 'warning');
                        }
                        $result.html('<p style="color:#10b981;font-weight:600">✅ ' + res.data.message + '</p>').show();
                        $fileInput.val('');
                        setTimeout(function () { location.reload(); }, 1800);
                    } else {
                        notify(res.data && res.data.message ? res.data.message : 'Yükleme başarısız.', 'error');
                        $result.html('<p style="color:#ef4444">❌ ' + (res.data && res.data.message ? res.data.message : 'Hata') + '</p>').show();
                    }
                },
                error: function () {
                    $progress.hide();
                    notify('Sunucu hatası oluştu.', 'error');
                },
            });
        }
    }

    /* ============================================================
       PIPELINE ACTIONS — queue.php satır butonları
       Selector: .hyt-pipeline-action[data-action="retry|pause|resume|cancel"]
    ============================================================ */
    $(document).on('click', '.hyt-pipeline-action', function () {
        const $btn   = $(this);
        const id     = $btn.data('id');
        const action = $btn.data('action');
        const labels = { retry: 'Yeniden dene', pause: 'Duraklat', resume: 'Devam et', cancel: 'İptal et' };

        if (!confirm((labels[action] || action) + '? Pipeline #' + id)) return;

        btnLoading($btn, true);
        ajax('hyt_pipeline_action', { pipeline_id: id, pipeline_action: action }, function (res) {
            btnLoading($btn, false);
            if (res.success) {
                notify(res.data.message, 'success');
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                notify(res.data && res.data.message ? res.data.message : 'Hata', 'error');
            }
        });
    });

    /* Dashboard mini-retry butonu */
    $(document).on('click', '.hyt-btn-retry', function () {
        const id = $(this).data('id');
        if (!confirm('Pipeline #' + id + ' yeniden kuyruğa alınsın mı?')) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_pipeline_action', { pipeline_id: id, pipeline_action: 'retry' }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : 'Hata', res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 1000);
        });
    });

    /* Dashboard mini-pause butonu */
    $(document).on('click', '.hyt-btn-pause', function () {
        const id = $(this).data('id');
        if (!confirm('Pipeline #' + id + ' duraklatılsın mı?')) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_pipeline_action', { pipeline_id: id, pipeline_action: 'pause' }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : 'Hata', res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 1000);
        });
    });

    /* Dashboard mini-expand butonu */
    $(document).on('click', '.hyt-btn-expand', function () {
        const id = $(this).data('id');
        if (!confirm('İçerik AI ile genişletilsin mi? Bu birkaç dakika sürebilir. Pipeline #' + id)) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_expand_content', { pipeline_id: id }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : (res.data && res.data.message ? res.data.message : 'Genişletme başarısız.'),
                   res.success ? 'success' : 'error');
        });
    });

    /* ============================================================
       REVIEW — APPROVE  (.hyt-review-approve)
    ============================================================ */
    $(document).on('click', '.hyt-review-approve', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        if (!confirm('İçeriği onayla ve yayın takvimine ekle? (#' + id + ')')) return;

        btnLoading($btn, true);
        ajax('hyt_approve_review', { pipeline_id: id }, function (res) {
            btnLoading($btn, false);
            if (res.success) {
                notify(res.data.message, 'success');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                notify(res.data && res.data.message ? res.data.message : 'Onay başarısız.', 'error');
            }
        });
    });

    /* ============================================================
       REVIEW — REJECT (.hyt-review-reject) → Modal açar
    ============================================================ */
    $(document).on('click', '.hyt-review-reject', function () {
        rejectTargetId = $(this).data('id');
        $('#hyt-reject-note').val('');
        $('#hyt-reject-modal').css('display', 'flex');
    });

    $(document).on('click', '#hyt-reject-cancel', function () {
        $('#hyt-reject-modal').hide();
        rejectTargetId = null;
    });

    $(document).on('click', '#hyt-reject-modal', function (e) {
        if ($(e.target).is('#hyt-reject-modal')) {
            $(this).hide();
            rejectTargetId = null;
        }
    });

    $(document).on('click', '#hyt-reject-confirm', function () {
        if (!rejectTargetId) return;
        const $btn = $(this);
        const note = $('#hyt-reject-note').val();

        btnLoading($btn, true);
        ajax('hyt_reject_review', { pipeline_id: rejectTargetId, note: note }, function (res) {
            btnLoading($btn, false);
            $('#hyt-reject-modal').hide();
            if (res.success) {
                notify(res.data.message, 'success');
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                notify(res.data && res.data.message ? res.data.message : 'Reddetme başarısız.', 'error');
            }
            rejectTargetId = null;
        });
    });

    /* ============================================================
       MANUAL ACTIONS — queue.php satır butonları
    ============================================================ */

    /* Görsel üret — .hyt-action-generate-img */
    $(document).on('click', '.hyt-action-generate-img', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        btnLoading($btn, true);
        ajax('hyt_generate_image_now', { pipeline_id: id }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : (res.data && res.data.message ? res.data.message : 'Görsel üretilemedi.'),
                   res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 1500);
        });
    });

    /* Video başlat — .hyt-action-start-video */
    $(document).on('click', '.hyt-action-start-video', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        btnLoading($btn, true);
        ajax('hyt_start_video_now', { pipeline_id: id }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : (res.data && res.data.message ? res.data.message : 'Video başlatılamadı.'),
                   res.success ? 'success' : 'error');
        });
    });

    /* Sosyal dağıt — .hyt-action-distribute */
    $(document).on('click', '.hyt-action-distribute', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        if (!confirm('Sosyal medyaya şimdi gönder? Pipeline #' + id)) return;
        btnLoading($btn, true);
        ajax('hyt_distribute_now', { pipeline_id: id }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : (res.data && res.data.message ? res.data.message : 'Dağıtım başarısız.'),
                   res.success ? 'success' : 'error');
        });
    });

    /* İçerik genişlet — .hyt-action-expand */
    $(document).on('click', '.hyt-action-expand', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        if (!confirm('İçeriği AI ile genişlet? Bu birkaç dakika sürebilir. Pipeline #' + id)) return;
        btnLoading($btn, true);
        ajax('hyt_expand_content', { pipeline_id: id }, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : (res.data && res.data.message ? res.data.message : 'Genişletme başarısız.'),
                   res.success ? 'success' : 'error');
        });
    });

    /* ============================================================
       FLAG TOGGLE — .hyt-btn-flag[data-flag][data-id]
    ============================================================ */
    $(document).on('click', '.hyt-btn-flag', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        const flag = $btn.data('flag');
        if (!id || !flag) return;

        btnLoading($btn, true);
        ajax('hyt_toggle_flag', { pipeline_id: id, flag: flag }, function (res) {
            btnLoading($btn, false);
            if (res.success) {
                notify(res.data && res.data.message ? res.data.message : 'Flag güncellendi.', 'success');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                notify(res.data && res.data.message ? res.data.message : 'Hata.', 'error');
            }
        });
    });

    /* ============================================================
       BULK ACTIONS
       HTML: <select id="hyt-bulk-action">, <button id="hyt-bulk-apply">
             <input type="checkbox" id="hyt-select-all">
             <input type="checkbox" class="hyt-row-cb" value="ID">
    ============================================================ */
    $(document).on('change', '#hyt-select-all', function () {
        const checked = $(this).is(':checked');
        $('.hyt-row-cb').prop('checked', checked);
        $('.hyt-row-cb').each(function () {
            $(this).closest('tr').toggleClass('hyt-row-selected', checked);
        });
        updateSelectedCount();
    });

    $(document).on('change', '.hyt-row-cb', function () {
        updateSelectedCount();
        if (!$(this).is(':checked')) {
            $('#hyt-select-all').prop('checked', false);
        } else {
            /* Hepsi seçiliyse select-all'ı işaretle */
            if ($('.hyt-row-cb').length === $('.hyt-row-cb:checked').length) {
                $('#hyt-select-all').prop('checked', true);
            }
        }
        $(this).closest('tr').toggleClass('hyt-row-selected', $(this).is(':checked'));
    });

    function updateSelectedCount() {
        const n = $('.hyt-row-cb:checked').length;
        $('.hyt-selected-count').text(n + ' seçili');
    }

    $(document).on('click', '#hyt-bulk-apply', function () {
        const action = $('#hyt-bulk-action').val();
        if (!action) { notify('Lütfen bir işlem seçin.', 'warning'); return; }

        const ids = $('.hyt-row-cb:checked').map(function () { return $(this).val(); }).get();
        if (!ids.length) { notify('Lütfen en az bir kayıt seçin.', 'warning'); return; }

        if (!confirm(ids.length + ' kayıt için "' + action + '" uygulanacak. Devam?')) return;

        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_bulk_action', { ids: ids, bulk_action: action }, function (res) {
            btnLoading($btn, false);
            if (res.success) {
                notify(res.data.message, 'success');
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                notify(res.data && res.data.message ? res.data.message : 'Hata', 'error');
            }
        });
    });

    /* Row selected style */
    $('<style>.hyt-row-selected td { background: rgba(99,102,241,0.07) !important; }</style>').appendTo('head');

    /* ============================================================
       RETRY ALL FAILED
       queue.php: <button id="hyt-retry-failed-btn">
       dashboard.php: <button id="hyt-retry-all">
    ============================================================ */
    $(document).on('click', '#hyt-retry-failed-btn, #hyt-retry-all', function () {
        if (!confirm('Tüm başarısız pipeline\'lar yeniden kuyruğa alınsın mı?')) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_retry_all_failed', {}, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : 'Hata oluştu.', res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 1200);
        });
    });

    /* ============================================================
       GOOGLE DRIVE — Scan / Disconnect
       dashboard.php: #hyt-scan-now
       settings.php:  #hyt-gdrive-scan-btn
    ============================================================ */
    $(document).on('click', '#hyt-scan-now, #hyt-gdrive-scan-btn', function () {
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_gdrive_scan_now', {}, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : (res.data && res.data.message ? res.data.message : 'Tarama başarısız.'),
                   res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 1500);
        });
    });

    $(document).on('click', '#hyt-gdrive-disconnect-btn', function () {
        if (!confirm('Google Drive bağlantısını kes?')) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_gdrive_disconnect', {}, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : 'Hata.', res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 800);
        });
    });

    /* ============================================================
       LLM / CLAUDE TEST
    ============================================================ */
    /* Genel LLM test butonu — #hyt-test-llm-btn */
    $(document).on('click', '#hyt-test-llm-btn', function () {
        const $btn = $(this);
        const $res = $('#hyt-llm-test-result');
        btnLoading($btn, true);
        $res.text('').removeClass('hyt-test-ok hyt-test-error');
        ajax('hyt_test_llm', {}, function (res) {
            btnLoading($btn, false);
            $res.text(res.data && res.data.message ? res.data.message : (res.success ? 'Bağlantı başarılı' : 'Bağlantı başarısız'))
                .addClass(res.success ? 'hyt-test-ok' : 'hyt-test-error');
        });
    });

    /* Provider bazlı test — .hyt-test-provider-btn veya #hyt-test-claude-btn */
    $(document).on('click', '.hyt-test-provider-btn, #hyt-test-claude-btn', function () {
        const $btn     = $(this);
        const provider = $btn.data('provider') || '';
        const $res     = $('#hyt-claude-test-result');
        btnLoading($btn, true);
        $res.text('').removeClass('hyt-test-ok hyt-test-error');
        ajax('hyt_test_llm', { provider: provider }, function (res) {
            btnLoading($btn, false);
            $res.text(res.data && res.data.message ? res.data.message : (res.success ? 'Bağlantı başarılı' : 'Bağlantı başarısız'))
                .addClass(res.success ? 'hyt-test-ok' : 'hyt-test-error');
        });
    });

    /* ============================================================
       LLM PROVIDER KARTLARI — interaktif seçim
    ============================================================ */
    $(document).on('change', '.hyt-provider-radio', function () {
        const selected = $(this).val();

        $('.hyt-provider-card').removeClass('hyt-provider-active');
        $(this).closest('.hyt-provider-card').addClass('hyt-provider-active');

        $('.hyt-provider-section').css('opacity', '0.55');
        $('#hyt-section-' + selected).css('opacity', '1');

        $('.hyt-provider-badge').remove();
        $(this).closest('.hyt-provider-card')
               .append('<span class="hyt-provider-badge">Aktif</span>');

        $('.hyt-provider-section h3 .hyt-active-note').remove();
        $('#hyt-section-' + selected + ' h3')
            .append('<span class="hyt-active-note" style="font-size:12px;font-weight:400;color:#10b981;margin-left:8px;">← Şu an aktif</span>');
    });

    /* ============================================================
       HEYGEN TEST
    ============================================================ */
    $(document).on('click', '#hyt-test-heygen-btn', function () {
        const $btn = $(this);
        const $res = $('#hyt-heygen-test-result');
        btnLoading($btn, true);
        $res.text('').removeClass('hyt-test-ok hyt-test-error');
        ajax('hyt_test_heygen', {}, function (res) {
            btnLoading($btn, false);
            $res.text(res.data && res.data.message ? res.data.message : (res.success ? 'Başarılı' : 'Başarısız'))
                .addClass(res.success ? 'hyt-test-ok' : 'hyt-test-error');
        });
    });

    /* ============================================================
       HEYGEN AVATAR LİSTELE
    ============================================================ */
    $(document).on('click', '#hyt-list-avatars-btn', function () {
        const $btn  = $(this);
        const $list = $('#hyt-avatar-list');
        btnLoading($btn, true);
        $list.hide().empty();
        ajax('hyt_list_heygen_avatars', {}, function (res) {
            btnLoading($btn, false);
            if (res.success && res.data.avatars && res.data.avatars.length) {
                const $grid = $('<div class="hyt-avatar-list-grid">');
                res.data.avatars.forEach(function (av) {
                    $('<div class="hyt-avatar-item">')
                        .html('🤖 <strong>' + av.avatar_name + '</strong> <span style="color:#94a3b8;font-size:10px">(' + av.avatar_id + ')</span>')
                        .on('click', function () {
                            $('#hyt_heygen_avatar_id').val(av.avatar_id);
                            notify('Avatar seçildi: ' + av.avatar_name, 'success');
                            $list.hide();
                        })
                        .appendTo($grid);
                });
                $list.append($grid).show();
            } else {
                notify(res.data ? res.data.message : 'Avatar listesi alınamadı.', 'error');
            }
        });
    });

    /* ============================================================
       YOUTUBE DISCONNECT
    ============================================================ */
    $(document).on('click', '#hyt-yt-disconnect-btn', function () {
        if (!confirm('YouTube bağlantısını kes?')) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_yt_disconnect', {}, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : 'Hata.', res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 800);
        });
    });

    /* ============================================================
       LOGS — Clear
    ============================================================ */
    $(document).on('click', '#hyt-clear-logs-btn', function () {
        if (!confirm('Tüm logları kalıcı olarak sil?')) return;
        const $btn = $(this);
        btnLoading($btn, true);
        ajax('hyt_clear_logs', {}, function (res) {
            btnLoading($btn, false);
            notify(res.success ? res.data.message : 'Hata.', res.success ? 'success' : 'error');
            if (res.success) setTimeout(function () { location.reload(); }, 800);
        });
    });

    /* ============================================================
       LOGS — Export CSV
    ============================================================ */
    $(document).on('click', '#hyt-export-logs-btn', function () {
        const $btn    = $(this);
        const level   = $btn.data('level')   || '';
        const context = $btn.data('context') || '';

        const form = $('<form method="post" action="' + ajaxUrl + '" style="display:none">')
            .append($('<input type="hidden" name="action"  value="hyt_export_logs">'))
            .append($('<input type="hidden" name="nonce"   value="' + nonce + '">'))
            .append($('<input type="hidden" name="level"   value="' + level + '">'))
            .append($('<input type="hidden" name="context" value="' + context + '">'));

        $('body').append(form);
        form[0].submit();
        setTimeout(function () { form.remove(); }, 2000);
    });

    /* ============================================================
       LOGS — Data Modal (Göster butonu)
       PHP artık data-log-id kullanır, veriyi AJAX ile çeker.
       Büyük JSON'da esc_attr bozulmasını önler.
    ============================================================ */
    $(document).on('click', '.hyt-log-expand-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();

        /* Önce data-data attribute dene (eski yöntem), yoksa AJAX fetch */
        const rawAttr = $(this).attr('data-data');
        const logId   = $(this).data('log-id');

        if (rawAttr && rawAttr.length < 4000) {
            /* Küçük veri — doğrudan göster */
            showLogModal(rawAttr);
        } else if (logId) {
            /* Büyük veri — AJAX ile çek */
            const $btn = $(this);
            btnLoading($btn, true);
            ajax('hyt_get_log_data', { log_id: logId }, function (res) {
                btnLoading($btn, false);
                if (res.success) {
                    showLogModal(res.data.data || '');
                } else {
                    notify('Log verisi alınamadı.', 'error');
                }
            });
        } else {
            showLogModal('(veri yok)');
        }
    });

    function showLogModal(raw) {
        let pretty;
        try {
            pretty = JSON.stringify(JSON.parse(raw), null, 2);
        } catch (err) {
            pretty = raw || '(veri yok)';
        }
        $('#hyt-log-data-pre').text(pretty);
        $('#hyt-log-data-modal').css('display', 'flex');
    }

    $(document).on('click', '#hyt-log-data-close', function () {
        $('#hyt-log-data-modal').hide();
    });

    $(document).on('click', '#hyt-log-data-modal', function (e) {
        if ($(e.target).is('#hyt-log-data-modal')) {
            $(this).hide();
        }
    });

    /* ESC tuşuyla tüm modalleri kapat */
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('#hyt-log-data-modal, #hyt-reject-modal').hide();
            rejectTargetId = null;
        }
    });

    /* ============================================================
       REVIEW TOGGLE — live status
    ============================================================ */
    $('input[name="hyt_review_before_publish"]').on('change', function () {
        $('#hyt-review-status-text').text($(this).is(':checked') ? '✅ Aktif' : '⭕ Pasif');
    });

    /* ============================================================
       SETTINGS FORM — API key format validation
    ============================================================ */
    $('.hyt-settings-form').on('submit', function () {
        const claudeKey    = $('#hyt_claude_api_key').val();
        const openaiLLMKey = $('#hyt_openai_llm_api_key').val();
        const geminiKey    = $('#hyt_gemini_api_key').val();
        const groqKey      = $('#hyt_groq_api_key').val();

        if (claudeKey    && !claudeKey.startsWith('sk-ant-'))  { notify('Claude API key "sk-ant-" ile başlamalıdır.', 'warning'); return false; }
        if (openaiLLMKey && !openaiLLMKey.startsWith('sk-'))   { notify('OpenAI API key "sk-" ile başlamalıdır.',    'warning'); return false; }
        if (geminiKey    && !geminiKey.startsWith('AIza'))      { notify('Gemini key "AIza" ile başlamalıdır.',       'warning'); return false; }
        if (groqKey      && !groqKey.startsWith('gsk_'))        { notify('Groq key "gsk_" ile başlamalıdır.',         'warning'); return false; }
        return true;
    });

    /* ============================================================
       AUTO-REFRESH — dashboard istatistikleri 60 saniyede bir güncelle
       HTML'deki selector: .hyt-stat-num (hyt-stat-number DEĞİL)
    ============================================================ */
    if ($('.hyt-stats-grid').length) {
        setInterval(function () {
            ajax('hyt_get_queue_counts', {}, function (res) {
                if (!res.success) return;
                const d = res.data;
                /* .hyt-stat-total .hyt-stat-num vb. */
                const map = {
                    '.hyt-stat-total .hyt-stat-num'      : d.all,
                    '.hyt-stat-done .hyt-stat-num'        : d.done,
                    '.hyt-stat-processing .hyt-stat-num'  : (d.processing || 0) + (d.pending || 0),
                    '.hyt-stat-failed .hyt-stat-num'      : d.failed,
                    '.hyt-stat-duplicate .hyt-stat-num'   : d.duplicate,
                };
                $.each(map, function (sel, val) {
                    if (val !== undefined) $(sel).text(val);
                });
            });
        }, 60000);
    }

    /* ============================================================
       OTOMATİK GÜNCELLEME (Auto Update) — #hyt-check-update-btn
    ============================================================ */
    $(document).on('click', '#hyt-check-update-btn', function () {
        const $btn = $(this);
        const $res = $('#hyt-update-result');
        btnLoading($btn, true);
        $res.html('').hide();
        ajax('hyt_check_plugin_update', {}, function (res) {
            btnLoading($btn, false);
            $res.show();
            if (res.success) {
                if (res.data.update_available) {
                    $res.html(
                        '<div style="background:#fef9c3;border:1px solid #fde047;padding:10px 14px;border-radius:6px;margin-top:8px;">' +
                        '🆕 <strong>Yeni sürüm mevcut: v' + res.data.latest_version + '</strong> ' +
                        '(mevcut: v' + res.data.current_version + ')<br>' +
                        '<a href="' + res.data.download_url + '" class="button button-primary" style="margin-top:8px" target="_blank">⬇ İndir</a>' +
                        (res.data.changelog ? '<p style="margin-top:8px;font-size:12px;color:#6b7280">' + res.data.changelog + '</p>' : '') +
                        '</div>'
                    );
                } else {
                    $res.html('<div style="background:#f0fdf4;border:1px solid #86efac;padding:8px 14px;border-radius:6px;color:#15803d;margin-top:8px;">✅ Eklenti güncel. (v' + res.data.current_version + ')</div>');
                }
            } else {
                $res.html('<div style="background:#fef2f2;border:1px solid #fca5a5;padding:8px 14px;border-radius:6px;color:#dc2626;margin-top:8px;">❌ ' + (res.data && res.data.message ? res.data.message : 'Güncelleme kontrolü başarısız.') + '</div>');
            }
        });
    });

})(jQuery);
