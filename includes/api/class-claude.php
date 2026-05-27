<?php
/**
 * HYT_Claude — Geriye dönük uyumluluk sarmalayıcısı.
 *
 * Tüm çağrılar HYT_LLM'e yönlendirilir.
 * Admin'de seçili provider (Claude / OpenAI / Gemini / Groq) otomatik kullanılır.
 * Eski kod new HYT_Claude() ile çalışmaya devam eder.
 */
defined( 'ABSPATH' ) || exit;

class HYT_Claude {

    private $llm;

    public function __construct() {
        $this->llm = new HYT_LLM();
    }

    /* ---- Uyumluluk Proxy Metodları ---- */

    public function is_configured(): bool {
        return $this->llm->is_configured();
    }

    public function optimize_seo_geo( string $title, string $content ): array {
        return $this->llm->optimize_seo_geo( $title, $content );
    }

    public function expand_content( string $title, string $content ): string {
        return $this->llm->expand_content( $title, $content );
    }

    public function humanize( string $content ): string {
        return $this->llm->humanize( $content );
    }

    public function generate_social_texts( string $title, string $excerpt, string $url ): array {
        return $this->llm->generate_social_texts( $title, $excerpt, $url );
    }

    public function generate_youtube_script( string $title, string $content ): string {
        return $this->llm->generate_youtube_script( $title, $content );
    }

    public function generate_short_script( string $title, string $content, int $index = 1 ): string {
        return $this->llm->generate_short_script( $title, $content, $index );
    }

    public function raw_request( string $prompt ): ?string {
        return $this->llm->raw_request( $prompt );
    }

    public function parse_json( string $text ): ?array {
        return $this->llm->parse_json( $text );
    }

    public function test_connection(): array {
        return $this->llm->test_connection();
    }

    /* ---- Ek Bilgi Metodları ---- */

    public function get_active_provider(): string {
        return $this->llm->get_provider();
    }

    public function get_active_model(): string {
        return $this->llm->get_model();
    }
}
