<?php
defined( 'ABSPATH' ) || exit;

class HYT_Video_Pipeline {

    private HYT_Claude $claude;
    private HYT_HeyGen $heygen;

    public function __construct() {
        $this->claude = new HYT_Claude();
        $this->heygen = new HYT_HeyGen();
    }

    /**
     * Pipeline için video scriptleri üretir ve HeyGen'e gönderir.
     */
    public function run( int $pipeline_id ): bool {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row ) return false;

        if ( ! $this->heygen->is_configured() ) {
            HYT_Logger::warning( 'heygen', "HeyGen yapılandırılmamış. Pipeline #{$pipeline_id}" );
            return false;
        }

        $title   = $row->title ?? '';
        $content = $row->raw_content ?? '';

        // Video aktif mi?
        if ( ! get_option( 'hyt_heygen_long_video_enabled', '0' ) && ! get_option( 'hyt_heygen_short_video_enabled', '0' ) ) {
            HYT_Logger::info( 'heygen', "Video üretimi devre dışı. Pipeline #{$pipeline_id}" );
            return false;
        }

        HYT_Database::update_pipeline( $pipeline_id, [ 'status' => 'video_wait', 'step' => 'video_script' ] );

        // Script üret
        $scripts = $this->generate_video_scripts( $title, $content );
        if ( empty( $scripts ) ) {
            HYT_Database::update_pipeline( $pipeline_id, [
                'status'        => 'done',
                'error_message' => 'Video script üretilemedi.',
            ] );
            return false;
        }

        // Uzun video (YouTube)
        if ( get_option( 'hyt_heygen_long_video_enabled', '0' ) && ! empty( $scripts['long'] ) ) {
            $result = $this->heygen->create_long_video( $pipeline_id, $scripts['long'] );
            if ( $result ) {
                HYT_Database::update_pipeline( $pipeline_id, [
                    'heygen_video_id' => $result['video_id'],
                    'step'            => 'video_generating',
                ] );
            }
        }

        // Kısa videolar (3 adet)
        if ( get_option( 'hyt_heygen_short_video_enabled', '0' ) ) {
            for ( $i = 1; $i <= 3; $i++ ) {
                if ( ! empty( $scripts["short_{$i}"] ) ) {
                    $this->heygen->create_short_video( $pipeline_id, $scripts["short_{$i}"], $i );
                    sleep( 2 ); // Rate limit için bekle
                }
            }
        }

        // Script'i payload'a kaydet
        HYT_Database::update_pipeline( $pipeline_id, [
            'payload' => wp_json_encode( [
                'video_scripts' => $scripts,
            ], JSON_UNESCAPED_UNICODE ),
        ] );

        HYT_Logger::info( 'heygen', "Video işlemi başlatıldı. Pipeline #{$pipeline_id}" );
        return true;
    }

    /**
     * Claude ile video scriptleri üretir.
     */
    private function generate_video_scripts( string $title, string $content ): array {
        if ( ! $this->claude->is_configured() ) return [];

        $excerpt = strip_tags( mb_substr( $content, 0, 2000 ) );

        $prompt = <<<PROMPT
Sen Hasan Yasin Türkyılmaz'ın video script yazarısın. Türkçe konuşma dili kullan.

Blog başlığı: {$title}
İçerik özeti: {$excerpt}

Şu scriptleri yaz:

1. **Uzun YouTube Script** (8-12 dakika, ~1200-1800 kelime)
   - Güçlü intro hook (ilk 15 saniye izletmeli)
   - Ana içerik bölümleri
   - Doğal konuşma dili, Hasan'ın sesi
   - CTA: "Beğen, yorum yap, abone ol"

2. **Kısa Script 1** (30-60 sn, ~100 kelime) — Merak uyandıran hook
3. **Kısa Script 2** (30-60 sn, ~100 kelime) — İpucu/taktik odaklı
4. **Kısa Script 3** (30-60 sn, ~100 kelime) — CTA odaklı, aciliyet

Yanıtı SADECE JSON formatında ver:
{
  "long": "...",
  "short_1": "...",
  "short_2": "...",
  "short_3": "..."
}
PROMPT;

        $response = $this->claude->raw_request( $prompt );
        if ( ! $response ) return [];

        $parsed = $this->claude->parse_json( $response );
        return $parsed ?? [];
    }
}
