<?php
defined( 'ABSPATH' ) || exit;

$active_tab = sanitize_key( $_GET['tab'] ?? 'api' );
$drive      = new HYT_Google_Drive();
$social     = new HYT_Social();

$tabs = [
    'api'      => [ 'icon' => '🧠', 'label' => 'AI / LLM' ],
    'gdrive'   => [ 'icon' => '📁', 'label' => 'Google Drive' ],
    'schedule' => [ 'icon' => '📅', 'label' => 'Yayın Takvimi' ],
    'heygen'   => [ 'icon' => '🎬', 'label' => 'HeyGen Video' ],
    'social'   => [ 'icon' => '📱', 'label' => 'Sosyal Medya' ],
    'image'    => [ 'icon' => '🖼', 'label' => 'Görsel Üretim' ],
    'review'   => [ 'icon' => '👁', 'label' => 'Onay Akışı' ],
    'holidays' => [ 'icon' => '🗓', 'label' => 'Tatil Günleri' ],
    'update'   => [ 'icon' => '🔄', 'label' => 'Güncelleme' ],
];

// OAuth mesajları
$oauth_msg = sanitize_key( $_GET['oauth'] ?? '' );
$yt_oauth  = sanitize_key( $_GET['yt_oauth'] ?? '' );
?>
<div class="wrap hyt-wrap">
    <h1 class="hyt-page-title">
        <span class="dashicons dashicons-admin-settings"></span> Ayarlar
    </h1>

    <?php if ( $oauth_msg === 'success' ) : ?>
    <div class="notice notice-success is-dismissible"><p>✅ Google Drive OAuth2 bağlantısı başarıyla kuruldu.</p></div>
    <?php elseif ( $oauth_msg === 'error' ) : ?>
    <div class="notice notice-error is-dismissible"><p>❌ Google Drive OAuth2 bağlantısı başarısız. Loglara bakın.</p></div>
    <?php endif; ?>

    <?php if ( $yt_oauth === 'success' ) : ?>
    <div class="notice notice-success is-dismissible"><p>✅ YouTube OAuth2 bağlantısı başarıyla kuruldu.</p></div>
    <?php elseif ( $yt_oauth === 'error' ) : ?>
    <div class="notice notice-error is-dismissible"><p>❌ YouTube OAuth2 bağlantısı başarısız. Loglara bakın.</p></div>
    <?php endif; ?>

    <!-- Sekme Navigasyonu -->
    <div class="hyt-settings-tabs">
        <?php foreach ( $tabs as $tab_key => $tab_info ) :
            $href   = add_query_arg( [ 'page' => 'hyt-settings', 'tab' => $tab_key ], admin_url('admin.php') );
            $active = $tab_key === $active_tab ? 'hyt-settings-tab-active' : '';
        ?>
        <a href="<?php echo esc_url($href); ?>" class="hyt-settings-tab <?php echo $active; ?>">
            <?php echo $tab_info['icon']; ?> <?php echo esc_html( $tab_info['label'] ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="post" action="" class="hyt-settings-form">
        <?php wp_nonce_field( 'hyt_settings_save' ); ?>
        <input type="hidden" name="hyt_save_settings" value="1">
        <input type="hidden" name="hyt_tab" value="<?php echo esc_attr( $active_tab ); ?>">

        <?php /* ============================================================ API TAB ============================================================ */ ?>
        <?php if ( $active_tab === 'api' ) : ?>
        <?php
        $providers    = HYT_LLM::get_provider_list();
        $active_prov  = HYT_LLM::get_active_provider();
        $provider_links = [
            'claude' => [ 'url' => 'https://console.anthropic.com/', 'label' => 'console.anthropic.com' ],
            'openai' => [ 'url' => 'https://platform.openai.com/api-keys', 'label' => 'platform.openai.com' ],
            'gemini' => [ 'url' => 'https://aistudio.google.com/app/apikey', 'label' => 'aistudio.google.com' ],
            'groq'   => [ 'url' => 'https://console.groq.com/keys', 'label' => 'console.groq.com' ],
        ];
        ?>
        <div class="hyt-settings-panel">

            <!-- ── PROVIDER SEÇİCİ ─────────────────────────────────────── -->
            <div class="hyt-settings-section">
                <h3>🧠 AI Sağlayıcı Seçimi</h3>
                <p class="description">
                    İçerik üretimi, SEO/GEO optimizasyonu ve sosyal medya metinleri için kullanılacak LLM sağlayıcısını seçin.
                    Her sağlayıcı için kendi API anahtarını ve modelini ayarlayabilirsiniz.
                </p>

                <div class="hyt-provider-cards">
                    <?php foreach ( $providers as $prov_key => $prov_info ) :
                        $key_opt   = get_option( $prov_info['key_option'], '' );
                        $is_set    = ! empty( $key_opt );
                        $is_active = ( $prov_key === $active_prov );
                    ?>
                    <label class="hyt-provider-card <?php echo $is_active ? 'hyt-provider-active' : ''; ?>">
                        <input type="radio" name="hyt_llm_provider" value="<?php echo esc_attr($prov_key); ?>"
                               <?php checked( $active_prov, $prov_key ); ?>
                               class="hyt-provider-radio" data-provider="<?php echo esc_attr($prov_key); ?>">
                        <span class="hyt-provider-icon">
                            <?php echo match($prov_key) {
                                'claude' => '🟣',
                                'openai' => '🟢',
                                'gemini' => '🔵',
                                'groq'   => '🟠',
                                default  => '⚙️',
                            }; ?>
                        </span>
                        <span class="hyt-provider-name"><?php echo esc_html($prov_info['label']); ?></span>
                        <span class="hyt-provider-status <?php echo $is_set ? 'hyt-key-set' : 'hyt-key-empty'; ?>">
                            <?php echo $is_set ? '✅ Key var' : '⭕ Key yok'; ?>
                        </span>
                        <?php if ( $is_active ) : ?>
                        <span class="hyt-provider-badge">Aktif</span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="hyt-test-connection" style="margin-top:16px;">
                    <button type="button" id="hyt-test-llm-btn" class="button button-secondary">
                        <span class="dashicons dashicons-networking"></span> Aktif LLM Bağlantısını Test Et
                    </button>
                    <span id="hyt-llm-test-result" class="hyt-test-result"></span>
                </div>
            </div>

            <!-- ── PROVIDER KONFIGÜRASYONLARI ─────────────────────────── -->
            <?php foreach ( $providers as $prov_key => $prov_info ) :
                $key_option   = $prov_info['key_option'];
                $model_option = $prov_info['model_option'];
                $sel_model    = get_option( $model_option, $prov_info['default_model'] );
                $link         = $provider_links[ $prov_key ] ?? null;
            ?>
            <div class="hyt-settings-section hyt-provider-section" id="hyt-section-<?php echo esc_attr($prov_key); ?>"
                 style="<?php echo $prov_key !== $active_prov ? 'opacity:0.55;' : ''; ?>">
                <h3>
                    <?php echo match($prov_key) {
                        'claude' => '🟣', 'openai' => '🟢', 'gemini' => '🔵', 'groq' => '🟠', default => '⚙️',
                    }; ?>
                    <?php echo esc_html($prov_info['label']); ?> Yapılandırması
                    <?php if ( $prov_key === $active_prov ) : ?>
                    <span style="font-size:12px;font-weight:400;color:#10b981;margin-left:8px;">← Şu an aktif</span>
                    <?php endif; ?>
                </h3>

                <table class="hyt-settings-table">
                    <tr>
                        <th><label for="<?php echo esc_attr($key_option); ?>">API Key</label></th>
                        <td>
                            <input type="password"
                                   name="<?php echo esc_attr($key_option); ?>"
                                   id="<?php echo esc_attr($key_option); ?>"
                                   value="<?php echo esc_attr( get_option($key_option,'') ); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($prov_info['key_placeholder']); ?>"
                                   autocomplete="off">
                            <?php if ( $link ) : ?>
                            <p class="description">
                                API anahtarı için:
                                <a href="<?php echo esc_url($link['url']); ?>" target="_blank">
                                    <?php echo esc_html($link['label']); ?>
                                </a>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo esc_attr($model_option); ?>">Model</label></th>
                        <td>
                            <select name="<?php echo esc_attr($model_option); ?>"
                                    id="<?php echo esc_attr($model_option); ?>"
                                    class="hyt-select-lg">
                                <?php foreach ( $prov_info['models'] as $model_val => $model_label ) : ?>
                                <option value="<?php echo esc_attr($model_val); ?>"
                                        <?php selected( $sel_model, $model_val ); ?>>
                                    <?php echo esc_html($model_label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php if ( $prov_key === $active_prov ) : ?>
                <div class="hyt-test-connection">
                    <button type="button" class="button button-secondary hyt-test-provider-btn"
                            data-provider="<?php echo esc_attr($prov_key); ?>"
                            id="hyt-test-claude-btn">
                        <span class="dashicons dashicons-networking"></span> Bağlantıyı Test Et
                    </button>
                    <span id="hyt-claude-test-result" class="hyt-test-result"></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- ── DALL·E 3 / GÖRSEL ─────────────────────────────────── -->
            <div class="hyt-settings-section">
                <h3>🎨 DALL·E 3 — Görsel Üretim (İsteğe Bağlı)</h3>
                <p class="description">
                    OpenAI DALL·E 3 görsel üretimi için. Öncelik sırası: DALL·E 3 → Python Pillow → PHP GD.<br>
                    <strong>Not:</strong> DALL·E 3 kullanmak için yukarıda OpenAI API anahtarı girilmiş olmalıdır.
                </p>
                <table class="hyt-settings-table">
                    <tr>
                        <th>DALL·E 3 Kullan</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_use_dalle" value="1"
                                       <?php checked( get_option('hyt_use_dalle','0'), '1' ); ?>>
                                DALL·E 3 ile görsel üret (Python Pillow ve GD'den önce dene)
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ── NOT KUTUSU ─────────────────────────────────────────── -->
            <div class="hyt-info-box" style="margin:0 0 20px;">
                <strong>💡 LLM Sağlayıcı Karşılaştırması:</strong>
                <table style="width:100%;margin-top:8px;font-size:12px;border-collapse:collapse;">
                    <thead><tr style="border-bottom:1px solid rgba(255,255,255,0.1);">
                        <th style="text-align:left;padding:4px 8px;">Sağlayıcı</th>
                        <th style="text-align:left;padding:4px 8px;">Güçlü Yanı</th>
                        <th style="text-align:left;padding:4px 8px;">Tahmini Maliyet/ay</th>
                        <th style="text-align:left;padding:4px 8px;">Ücretsiz Kota</th>
                    </tr></thead>
                    <tbody>
                        <tr><td style="padding:4px 8px;">🟣 Claude Opus 4.5</td><td style="padding:4px 8px;">Uzun içerik, analiz</td><td style="padding:4px 8px;">$20-50</td><td style="padding:4px 8px;">Hayır</td></tr>
                        <tr><td style="padding:4px 8px;">🟢 GPT-4o</td><td style="padding:4px 8px;">Genel amaçlı, hızlı</td><td style="padding:4px 8px;">$15-40</td><td style="padding:4px 8px;">Hayır</td></tr>
                        <tr><td style="padding:4px 8px;">🔵 Gemini 2.5 Flash</td><td style="padding:4px 8px;">Hızlı, ücretsiz kota</td><td style="padding:4px 8px;">$3-10</td><td style="padding:4px 8px;">✅ 1M token/gün</td></tr>
                        <tr><td style="padding:4px 8px;">🟠 Groq Llama 3.3</td><td style="padding:4px 8px;">Ultra hızlı, açık kaynak</td><td style="padding:4px 8px;">$0-5</td><td style="padding:4px 8px;">✅ Ücretsiz tier</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="hyt-settings-actions">
                <?php submit_button( 'Kaydet', 'primary', 'submit', false ); ?>
            </div>
        </div>

        <?php /* ============================================================ GDRIVE TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'gdrive' ) : ?>
        <div class="hyt-settings-panel">
            <div class="hyt-settings-section">
                <h3>📁 Google Drive OAuth2</h3>

                <?php if ( $drive->is_connected() ) : ?>
                <div class="hyt-connection-status hyt-connected">
                    ✅ Google Drive'a bağlı.
                    <button type="button" id="hyt-gdrive-disconnect-btn" class="button hyt-btn-danger-sm">Bağlantıyı Kes</button>
                </div>
                <?php else : ?>
                <div class="hyt-connection-status hyt-not-connected">
                    ❌ Google Drive bağlı değil. API bilgilerini girin ve bağlanın.
                </div>
                <?php endif; ?>

                <table class="hyt-settings-table">
                    <tr>
                        <th><label for="hyt_gdrive_client_id">OAuth2 Client ID</label></th>
                        <td>
                            <input type="text" name="hyt_gdrive_client_id" id="hyt_gdrive_client_id"
                                   value="<?php echo esc_attr( get_option('hyt_gdrive_client_id','') ); ?>"
                                   class="regular-text" placeholder="xxx.apps.googleusercontent.com">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_gdrive_client_secret">OAuth2 Client Secret</label></th>
                        <td>
                            <input type="password" name="hyt_gdrive_client_secret" id="hyt_gdrive_client_secret"
                                   value="<?php echo esc_attr( get_option('hyt_gdrive_client_secret','') ); ?>"
                                   class="regular-text" placeholder="GOCSPX-..." autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_gdrive_folder_id">Taranacak Klasör ID</label></th>
                        <td>
                            <input type="text" name="hyt_gdrive_folder_id" id="hyt_gdrive_folder_id"
                                   value="<?php echo esc_attr( get_option('hyt_gdrive_folder_id','') ); ?>"
                                   class="regular-text" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
                            <p class="description">Drive URL'sinden klasör ID'sini kopyalayın.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_gdrive_scan_frequency">Tarama Sıklığı</label></th>
                        <td>
                            <select name="hyt_gdrive_scan_frequency" id="hyt_gdrive_scan_frequency" class="hyt-select-lg">
                                <?php
                                $freq_options = [
                                    'disabled'          => 'Devre Dışı',
                                    'hyt_every_5min'    => 'Her 5 Dakika',
                                    'hyt_every_15min'   => 'Her 15 Dakika',
                                    'hyt_every_hour'    => 'Her Saat',
                                    'hyt_twice_daily_custom' => 'Günde 2 Kez',
                                ];
                                $sel = get_option( 'hyt_gdrive_scan_frequency', 'hyt_every_15min' );
                                foreach ( $freq_options as $val => $label ) :
                                ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected( $sel, $val ); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <div class="hyt-settings-actions-row">
                    <?php submit_button( 'Kaydet', 'secondary', 'submit', false ); ?>
                    <?php if ( $drive->is_configured() && ! $drive->is_connected() ) :
                        $auth_url = $drive->get_auth_url();
                    ?>
                    <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">
                        <span class="dashicons dashicons-admin-links"></span> Google Drive'a Bağlan
                    </a>
                    <?php elseif ( $drive->is_connected() ) : ?>
                    <button type="button" id="hyt-gdrive-scan-btn" class="button button-primary">
                        <span class="dashicons dashicons-search"></span> Şimdi Tara
                    </button>
                    <?php endif; ?>
                </div>

                <div class="hyt-info-box" style="margin-top:16px;">
                    <strong>Yönlendirme URI (Google Console'a ekleyin):</strong><br>
                    <code><?php echo esc_html( rest_url('hyt/v1/google-oauth-callback') ); ?></code>
                </div>
            </div>
        </div>

        <?php /* ============================================================ SCHEDULE TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'schedule' ) : ?>
        <div class="hyt-settings-panel">
            <div class="hyt-settings-section">
                <h3>📅 Yayın Takvimi</h3>
                <p class="description">İçerikler belirlenen günlerde, belirtilen saatte yayınlanır. Tatil günleri otomatik atlanır.</p>

                <table class="hyt-settings-table">
                    <tr>
                        <th>Yayın Günleri</th>
                        <td>
                            <?php
                            $selected_days = (array) get_option('hyt_publish_days', ['monday','wednesday','friday']);
                            $all_days = [
                                'monday'    => 'Pazartesi',
                                'tuesday'   => 'Salı',
                                'wednesday' => 'Çarşamba',
                                'thursday'  => 'Perşembe',
                                'friday'    => 'Cuma',
                                'saturday'  => 'Cumartesi',
                                'sunday'    => 'Pazar',
                            ];
                            foreach ( $all_days as $val => $label ) :
                            ?>
                            <label class="hyt-day-check">
                                <input type="checkbox" name="hyt_publish_days[]"
                                       value="<?php echo esc_attr($val); ?>"
                                       <?php checked( in_array($val, $selected_days, true) ); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_publish_time">Yayın Saati</label></th>
                        <td>
                            <input type="time" name="hyt_publish_time" id="hyt_publish_time"
                                   value="<?php echo esc_attr( get_option('hyt_publish_time','08:45') ); ?>">
                            <p class="description">Sunucu saat dilimine göre. WordPress zaman dilimi ayarını kontrol edin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_publish_author_id">Yazar</label></th>
                        <td>
                            <select name="hyt_publish_author_id" id="hyt_publish_author_id" class="hyt-select-lg">
                                <?php
                                $users = get_users( [ 'role__in' => [ 'administrator', 'editor', 'author' ] ] );
                                $sel_author = (int) get_option('hyt_publish_author_id', get_current_user_id());
                                foreach ( $users as $u ) :
                                ?>
                                <option value="<?php echo $u->ID; ?>" <?php selected($sel_author, $u->ID); ?>>
                                    <?php echo esc_html($u->display_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_publish_category">Varsayılan Kategori</label></th>
                        <td>
                            <?php
                            $cats = get_categories(['hide_empty' => false]);
                            $sel_cat = (int) get_option('hyt_publish_category', 0);
                            ?>
                            <select name="hyt_publish_category" id="hyt_publish_category" class="hyt-select-lg">
                                <option value="0">— Kategori Seçme —</option>
                                <?php foreach ( $cats as $cat ) : ?>
                                <option value="<?php echo $cat->term_id; ?>" <?php selected($sel_cat, $cat->term_id); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Direkt Yayın Modu</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_direct_publish_fallback" value="1"
                                       <?php checked( get_option('hyt_direct_publish_fallback','0'), '1' ); ?>>
                                Claude yapılandırılmamış olsa dahi direkt yayınla (SEO olmadan)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Sosyal Medya Gecikmesi</th>
                        <td>
                            <input type="number" name="hyt_social_delay_minutes" min="5" max="1440"
                                   value="<?php echo (int) get_option('hyt_social_delay_minutes', 30); ?>"
                                   class="small-text"> dakika
                            <p class="description">Yayın sonrası sosyal medyaya ne kadar süre sonra gönderilsin.</p>
                        </td>
                    </tr>
                </table>

                <div class="hyt-settings-actions">
                    <?php submit_button( 'Takvimi Kaydet', 'primary', 'submit', false ); ?>
                    <?php
                    $next = HYT_Scheduler::next_available_slot();
                    if ( $next ) : ?>
                    <div class="hyt-info-box" style="display:inline-block;margin-left:12px;">
                        📅 Sonraki uygun slot: <strong><?php echo esc_html( date('d.m.Y H:i', strtotime($next)) ); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php /* ============================================================ HEYGEN TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'heygen' ) : ?>
        <div class="hyt-settings-panel">
            <div class="hyt-settings-section">
                <h3>🎬 HeyGen Avatar Video Üretimi</h3>
                <p class="description">Blog yazısı için otomatik YouTube (16:9) ve Reels/Shorts (9:16) avatar videoları üretilir.</p>

                <table class="hyt-settings-table">
                    <tr>
                        <th><label for="hyt_heygen_api_key">HeyGen API Key</label></th>
                        <td>
                            <input type="password" name="hyt_heygen_api_key" id="hyt_heygen_api_key"
                                   value="<?php echo esc_attr( get_option('hyt_heygen_api_key','') ); ?>"
                                   class="regular-text" placeholder="NjY4...=" autocomplete="off">
                            <p class="description"><a href="https://app.heygen.com/api-token" target="_blank">app.heygen.com</a>'dan edinin</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_heygen_avatar_id">Avatar ID</label></th>
                        <td>
                            <input type="text" name="hyt_heygen_avatar_id" id="hyt_heygen_avatar_id"
                                   value="<?php echo esc_attr( get_option('hyt_heygen_avatar_id','') ); ?>"
                                   class="regular-text" placeholder="avatar_id_buraya">
                            <button type="button" id="hyt-list-avatars-btn" class="button" style="margin-left:8px;">Avatar Listele</button>
                            <div id="hyt-avatar-list" style="margin-top:8px;display:none;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_heygen_voice_id">Voice ID</label></th>
                        <td>
                            <input type="text" name="hyt_heygen_voice_id" id="hyt_heygen_voice_id"
                                   value="<?php echo esc_attr( get_option('hyt_heygen_voice_id','') ); ?>"
                                   class="regular-text" placeholder="voice_id_buraya">
                            <p class="description">HeyGen panelinden ses ID'sini kopyalayın.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Uzun Video (YouTube 16:9)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_heygen_long_video_enabled" value="1"
                                       <?php checked( get_option('hyt_heygen_long_video_enabled','0'), '1' ); ?>>
                                8-12 dakikalık YouTube videosu üret
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Kısa Videolar (9:16)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_heygen_short_video_enabled" value="1"
                                       <?php checked( get_option('hyt_heygen_short_video_enabled','0'), '1' ); ?>>
                                3 adet Reels/Shorts/TikTok videosu üret (30-60 sn)
                            </label>
                        </td>
                    </tr>
                </table>

                <div class="hyt-test-connection">
                    <button type="button" id="hyt-test-heygen-btn" class="button button-secondary">
                        <span class="dashicons dashicons-networking"></span> Bağlantıyı Test Et
                    </button>
                    <span id="hyt-heygen-test-result" class="hyt-test-result"></span>
                </div>

                <div class="hyt-settings-actions">
                    <?php submit_button( 'HeyGen Ayarlarını Kaydet', 'primary', 'submit', false ); ?>
                </div>
            </div>
        </div>

        <?php /* ============================================================ SOCIAL TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'social' ) : ?>
        <div class="hyt-settings-panel">

            <!-- Facebook & Instagram (Meta) -->
            <div class="hyt-settings-section">
                <h3>📘 Facebook & Instagram (Meta Graph API)</h3>
                <p class="description">Facebook Sayfası ve Instagram Business hesabı için Meta uzun ömürlü Page Access Token kullanılır.</p>

                <?php if ( $social->is_facebook_configured() ) : ?>
                <div class="hyt-connection-status hyt-connected">✅ Facebook yapılandırılmış.</div>
                <?php endif; ?>
                <?php if ( $social->is_instagram_configured() ) : ?>
                <div class="hyt-connection-status hyt-connected">✅ Instagram yapılandırılmış.</div>
                <?php endif; ?>

                <table class="hyt-settings-table">
                    <tr>
                        <th><label for="hyt_meta_page_token">Page Access Token</label></th>
                        <td>
                            <input type="password" name="hyt_meta_page_token" id="hyt_meta_page_token"
                                   value="<?php echo esc_attr( get_option('hyt_meta_page_token','') ); ?>"
                                   class="regular-text" autocomplete="off">
                            <p class="description">Meta Business Suite → Araçlar → Graph API Explorer'dan alın. Uzun ömürlü token (60 gün) kullanın.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_meta_page_id">Facebook Page ID</label></th>
                        <td>
                            <input type="text" name="hyt_meta_page_id" id="hyt_meta_page_id"
                                   value="<?php echo esc_attr( get_option('hyt_meta_page_id','') ); ?>"
                                   class="regular-text" placeholder="123456789012345">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_instagram_business_id">Instagram Business Account ID</label></th>
                        <td>
                            <input type="text" name="hyt_instagram_business_id" id="hyt_instagram_business_id"
                                   value="<?php echo esc_attr( get_option('hyt_instagram_business_id','') ); ?>"
                                   class="regular-text" placeholder="17841400000000000">
                        </td>
                    </tr>
                    <tr>
                        <th>Facebook Paylaşım</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_channel_facebook" value="1"
                                       <?php checked( get_option('hyt_channel_facebook','0'), '1' ); ?>>
                                Yeni içerikleri Facebook'a otomatik gönder
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Instagram Paylaşım</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_channel_instagram" value="1"
                                       <?php checked( get_option('hyt_channel_instagram','0'), '1' ); ?>>
                                Yeni içerikleri Instagram'a otomatik gönder
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Instagram Reels</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_channel_instagram_reels" value="1"
                                       <?php checked( get_option('hyt_channel_instagram_reels','0'), '1' ); ?>>
                                HeyGen videolarını Instagram Reels olarak yayınla
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- LinkedIn -->
            <div class="hyt-settings-section">
                <h3>💼 LinkedIn</h3>

                <?php if ( $social->is_linkedin_configured() ) : ?>
                <div class="hyt-connection-status hyt-connected">✅ LinkedIn yapılandırılmış.</div>
                <?php endif; ?>

                <table class="hyt-settings-table">
                    <tr>
                        <th><label for="hyt_linkedin_access_token">LinkedIn Access Token</label></th>
                        <td>
                            <input type="password" name="hyt_linkedin_access_token" id="hyt_linkedin_access_token"
                                   value="<?php echo esc_attr( get_option('hyt_linkedin_access_token','') ); ?>"
                                   class="regular-text" autocomplete="off">
                            <p class="description">LinkedIn Developer → OAuth 2.0 Token.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_linkedin_person_id">LinkedIn Person ID</label></th>
                        <td>
                            <input type="text" name="hyt_linkedin_person_id" id="hyt_linkedin_person_id"
                                   value="<?php echo esc_attr( get_option('hyt_linkedin_person_id','') ); ?>"
                                   class="regular-text" placeholder="abcdefghij">
                            <p class="description">API ile <code>GET /v2/me</code> isteği yaparak alın.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>LinkedIn Paylaşım</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_channel_linkedin" value="1"
                                       <?php checked( get_option('hyt_channel_linkedin','0'), '1' ); ?>>
                                Yeni içerikleri LinkedIn'e otomatik gönder
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- YouTube -->
            <div class="hyt-settings-section">
                <h3>▶️ YouTube</h3>

                <?php if ( $social->is_youtube_configured() ) : ?>
                <div class="hyt-connection-status hyt-connected">
                    ✅ YouTube bağlı.
                    <button type="button" id="hyt-yt-disconnect-btn" class="button hyt-btn-danger-sm">Bağlantıyı Kes</button>
                </div>
                <?php else : ?>
                <div class="hyt-connection-status hyt-not-connected">❌ YouTube bağlı değil.</div>
                <?php endif; ?>

                <table class="hyt-settings-table">
                    <tr>
                        <th><label for="hyt_youtube_client_id">YouTube Client ID</label></th>
                        <td>
                            <input type="text" name="hyt_youtube_client_id" id="hyt_youtube_client_id"
                                   value="<?php echo esc_attr( get_option('hyt_youtube_client_id','') ); ?>"
                                   class="regular-text" placeholder="xxx.apps.googleusercontent.com">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hyt_youtube_client_secret">YouTube Client Secret</label></th>
                        <td>
                            <input type="password" name="hyt_youtube_client_secret" id="hyt_youtube_client_secret"
                                   value="<?php echo esc_attr( get_option('hyt_youtube_client_secret','') ); ?>"
                                   class="regular-text" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th>YouTube Video Yükleme</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_channel_youtube" value="1"
                                       <?php checked( get_option('hyt_channel_youtube','0'), '1' ); ?>>
                                HeyGen videolarını YouTube'a otomatik yükle
                            </label>
                        </td>
                    </tr>
                </table>

                <?php if ( get_option('hyt_youtube_client_id') && ! $social->is_youtube_configured() ) : ?>
                <?php $yt_auth = $social->get_youtube_auth_url(); ?>
                <a href="<?php echo esc_url($yt_auth); ?>" class="button button-primary">
                    <span class="dashicons dashicons-admin-links"></span> YouTube'a Bağlan (OAuth2)
                </a>
                <?php endif; ?>

                <div class="hyt-info-box" style="margin-top:12px;">
                    <strong>Yönlendirme URI:</strong><br>
                    <code><?php echo esc_html( rest_url('hyt/v1/youtube-oauth-callback') ); ?></code>
                </div>
            </div>

            <div class="hyt-settings-actions">
                <?php submit_button( 'Sosyal Medya Ayarlarını Kaydet', 'primary', 'submit', false ); ?>
            </div>
        </div>

        <?php /* ============================================================ IMAGE TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'image' ) : ?>
        <div class="hyt-settings-panel">
            <div class="hyt-settings-section">
                <h3>🖼 Canvas Design Engine — Görsel Üretimi</h3>
                <p class="description">
                    İçerikler için otomatik featured image üretimi. Öncelik sırası:
                    <strong>DALL·E 3</strong> (etkinse) → <strong>Python Pillow</strong> → <strong>PHP GD</strong>
                </p>

                <table class="hyt-settings-table">
                    <tr>
                        <th>Otomatik Görsel Üretim</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hyt_auto_generate_image" value="1"
                                       <?php checked( get_option('hyt_auto_generate_image','0'), '1' ); ?>>
                                Pipeline tamamlandığında otomatik featured image üret
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- Önizleme Renk Paletle -->
                <h4>Kategori Renk Paletle</h4>
                <div class="hyt-palette-grid">
                    <?php
                    $palettes = [
                        'dijital-pazarlama' => [ 'bg' => '#0f1117', 'accent' => '#6366f1', 'label' => 'Dijital Pazarlama' ],
                        'seo'               => [ 'bg' => '#040d21', 'accent' => '#3b82f6', 'label' => 'SEO' ],
                        'e-ticaret'         => [ 'bg' => '#061411', 'accent' => '#059669', 'label' => 'E-Ticaret' ],
                        'girisimcilik'      => [ 'bg' => '#13100a', 'accent' => '#d97706', 'label' => 'Girişimcilik' ],
                        'sosyal-medya'      => [ 'bg' => '#0d0510', 'accent' => '#c026d3', 'label' => 'Sosyal Medya' ],
                        'default'           => [ 'bg' => '#0f1117', 'accent' => '#6366f1', 'label' => 'Varsayılan' ],
                    ];
                    foreach ( $palettes as $key => $p ) : ?>
                    <div class="hyt-palette-item" style="background:<?php echo esc_attr($p['bg']); ?>;border:2px solid <?php echo esc_attr($p['accent']); ?>">
                        <span class="hyt-palette-label" style="color:<?php echo esc_attr($p['accent']); ?>"><?php echo esc_html($p['label']); ?></span>
                        <span class="hyt-palette-dots">
                            <span style="background:<?php echo esc_attr($p['bg']); ?>"></span>
                            <span style="background:<?php echo esc_attr($p['accent']); ?>"></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="hyt-info-box" style="margin-top:16px;">
                    <strong>Python Pillow Durumu:</strong>
                    <?php
                    $script = HYT_PLUGIN_DIR . 'includes/api/generate_image.py';
                    if ( file_exists( $script ) ) {
                        echo '<span style="color:#10b981">✓ Script mevcut</span>';
                    } else {
                        echo '<span style="color:#f59e0b">⚠ Script oluşturulacak (ilk çalıştırmada otomatik)</span>';
                    }
                    ?>
                    &nbsp;|&nbsp;
                    <strong>PHP GD Durumu:</strong>
                    <?php echo extension_loaded('gd')
                        ? '<span style="color:#10b981">✓ Aktif</span>'
                        : '<span style="color:#ef4444">✗ Yüklü değil</span>'; ?>
                </div>
            </div>

            <div class="hyt-settings-actions">
                <?php submit_button( 'Görsel Ayarlarını Kaydet', 'primary', 'submit', false ); ?>
            </div>
        </div>

        <?php /* ============================================================ REVIEW TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'review' ) : ?>
        <div class="hyt-settings-panel">
            <div class="hyt-settings-section">
                <h3>👁 Manuel İçerik Onay Akışı</h3>
                <p class="description">
                    Etkinleştirildiğinde, SEO optimizasyonu yapılan içerikler yayınlanmadan önce sizin onayınızı bekler.
                    Dashboard ve Kuyruk sayfasında "Onay Bekliyor" sekmesinden içerikleri inceleyebilirsiniz.
                </p>

                <div class="hyt-review-toggle-card">
                    <div class="hyt-review-toggle-icon">👁</div>
                    <div class="hyt-review-toggle-info">
                        <h4>İçerik Onay Akışı</h4>
                        <p>SEO optimizasyonundan sonra içerik WordPress'e yüklenmeden önce duraklatılır.</p>
                    </div>
                    <div class="hyt-review-toggle-control">
                        <label class="hyt-toggle-switch">
                            <input type="checkbox" name="hyt_review_before_publish" value="1"
                                   <?php checked( get_option('hyt_review_before_publish','0'), '1' ); ?>>
                            <span class="hyt-toggle-slider"></span>
                        </label>
                        <span id="hyt-review-status-text" class="hyt-review-status">
                            <?php echo get_option('hyt_review_before_publish','0') === '1' ? '✅ Aktif' : '⭕ Pasif'; ?>
                        </span>
                    </div>
                </div>

                <div class="hyt-review-workflow">
                    <h4>Onay Akışı Adımları:</h4>
                    <ol class="hyt-workflow-steps">
                        <li>📥 Dosya Google Drive'dan veya manuel yüklenerek kuyruğa alınır</li>
                        <li>🤖 Claude API ile SEO/GEO optimizasyonu yapılır</li>
                        <li class="hyt-step-highlight">👁 <strong>İçerik "Onay Bekliyor" durumuna alınır — siz buraya bakıyorsunuz</strong></li>
                        <li>✅ Onayla → Yayın takvimine eklenir</li>
                        <li>❌ Reddet → "İptal" durumuna geçer, istenirse yeniden işlenebilir</li>
                    </ol>
                </div>

                <?php $review_count = HYT_Database::count_pipelines(['status' => 'review_pending']); ?>
                <?php if ( $review_count > 0 ) : ?>
                <div class="hyt-info-box hyt-info-warning">
                    ⚠️ <strong><?php echo $review_count; ?> içerik</strong> şu anda onayınızı bekliyor.
                    <a href="<?php echo admin_url('admin.php?page=hyt-queue&tab=review_pending'); ?>">Şimdi İncele →</a>
                </div>
                <?php endif; ?>
            </div>

            <div class="hyt-settings-actions">
                <?php submit_button( 'Onay Ayarlarını Kaydet', 'primary', 'submit', false ); ?>
            </div>
        </div>

        <?php /* ============================================================ HOLIDAYS TAB ============================================================ */ ?>
        <?php elseif ( $active_tab === 'holidays' ) : ?>
        <div class="hyt-settings-panel">
            <div class="hyt-settings-section">
                <h3>🗓 Tatil Günleri</h3>

                <h4>Sabit Türkiye Resmî Tatilleri (Otomatik)</h4>
                <div class="hyt-fixed-holidays">
                    <?php
                    $year = (int) date('Y');
                    $fixed = HYT_Holidays::get_fixed_holidays($year);
                    $fixed_labels = [
                        '01-01' => 'Yılbaşı',
                        '04-23' => 'Ulusal Egemenlik ve Çocuk Bayramı',
                        '05-01' => 'Emek ve Dayanışma Günü',
                        '05-19' => 'Atatürk\'ü Anma, Gençlik ve Spor Bayramı',
                        '07-15' => 'Demokrasi ve Millî Birlik Günü',
                        '08-30' => 'Zafer Bayramı',
                        '10-29' => 'Cumhuriyet Bayramı',
                    ];
                    foreach ( $fixed as $fd ) :
                        $md = substr($fd, 5);
                        $label = $fixed_labels[$md] ?? '';
                    ?>
                    <span class="hyt-holiday-tag">
                        📅 <?php echo esc_html( date('d F', strtotime($fd)) ); ?>
                        <?php if ($label) : ?><em><?php echo esc_html($label); ?></em><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                </div>

                <h4>İslami Bayram Günleri (Manuel Giriş)</h4>
                <p class="description">Her yıl değişen İslami bayram tarihlerini JSON dizisi olarak girin. Format: <code>["2026-03-29","2026-03-30"]</code></p>
                <textarea name="hyt_islamic_holidays" rows="6" class="widefat hyt-code-area"
                          placeholder='["2026-03-29","2026-03-30","2026-03-31","2026-06-05","2026-06-06","2026-06-07","2026-06-08","2026-06-09"]'
                ><?php
                    $raw = get_option('hyt_islamic_holidays', '[]');
                    $arr = is_array($raw) ? $raw : (json_decode($raw, true) ?: []);
                    echo esc_textarea( wp_json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) );
                ?></textarea>

                <h4>Tüm Tatil Takvimi (Bu yıl + Sonraki yıl)</h4>
                <?php $all = HYT_Holidays::get_all_holidays_for_display(); ?>
                <div class="hyt-all-holidays-grid">
                    <?php foreach ( $all as $hd ) : ?>
                    <span class="hyt-holiday-tag <?php echo $hd < date('Y-m-d') ? 'hyt-past' : ''; ?>">
                        <?php echo esc_html( date('d F Y', strtotime($hd)) ); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="hyt-settings-actions">
                <?php submit_button( 'Tatil Listesini Kaydet', 'primary', 'submit', false ); ?>
            </div>
        </div>
        <?php endif; ?>

    </form>

    <?php /* ============================================================ UPDATE TAB (form dışında) ============================================================ */ ?>
    <?php if ( $active_tab === 'update' ) : ?>
    <div class="hyt-settings-panel">
        <div class="hyt-settings-section">
            <h3>🔄 Eklenti Güncellemesi</h3>
            <p class="description">
                Mevcut sürüm: <strong>v<?php echo HYT_VERSION; ?></strong><br>
                GitHub üzerinden yayınlanan yeni sürümü kontrol edebilir ve indirme bağlantısına ulaşabilirsiniz.
            </p>

            <div style="margin:16px 0;">
                <button type="button" id="hyt-check-update-btn" class="button button-primary">
                    <span class="dashicons dashicons-update"></span> Güncelleme Kontrol Et
                </button>
                <div id="hyt-update-result"></div>
            </div>

            <div class="hyt-info-box" style="margin-top:16px;">
                <strong>📋 Manuel Güncelleme Adımları:</strong>
                <ol style="margin:8px 0 0 16px;font-size:13px;">
                    <li>Yukarıdaki butona tıklayarak yeni sürümü kontrol edin.</li>
                    <li>Yeni sürüm varsa "İndir" butonuyla ZIP dosyasını indirin.</li>
                    <li>WordPress yönetim panelinde <strong>Eklentiler → Eklenti Ekle → Eklenti Yükle</strong> yolunu izleyin.</li>
                    <li>İndirdiğiniz ZIP dosyasını yükleyin — WordPress mevcut eklentinin üzerine yazar.</li>
                    <li>Aktivasyon gerekirse "Etkinleştir" butonuna tıklayın.</li>
                </ol>
                <p style="margin-top:8px;font-size:12px;color:#6b7280;">
                    💡 <strong>İpucu:</strong> GitHub deposuna erişim için
                    <code>hyt_update_github_repo</code> filtresiyle depo adını tanımlamanız gerekir.
                </p>
            </div>

            <div class="hyt-info-box" style="margin-top:12px;">
                <strong>📦 GitHub Deposu Tanımlama:</strong><br>
                <code style="display:block;margin-top:6px;padding:8px;background:rgba(0,0,0,0.2);border-radius:4px;font-size:12px;line-height:1.8;">
                    // wp-config.php veya bir mu-plugin'e ekleyin:<br>
                    add_filter( 'hyt_update_github_repo', function() {<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;return 'hyturkyilmaz/WP-LLM-Content-Automating';<br>
                    } );
                </code>
                <p style="margin-top:8px;font-size:12px;">
                    Tanımlandığında "Güncelleme Kontrol Et" butonu GitHub Releases API'sini sorgular ve yeni sürüm varsa indirme bağlantısı gösterir.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

