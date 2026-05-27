<?php
/**
 * HYT LLM Router — Claude · OpenAI GPT-4o · Google Gemini · Groq/Llama
 *
 * Kullanım:
 *   $llm = new HYT_LLM();          // admin'de seçili provider kullanılır
 *   $llm->optimize_seo_geo(...)     // HYT_Claude ile aynı arayüz
 *
 * Provider seçimi: Settings → API Anahtarları → "AI Sağlayıcı" dropdown
 * Her provider kendi API key + model seçimine sahiptir.
 */
defined( 'ABSPATH' ) || exit;

class HYT_LLM {

    /* Desteklenen provider listesi */
    const PROVIDERS = [
        'claude' => [
            'label'  => 'Anthropic Claude',
            'models' => [
                'claude-opus-4-5'    => 'Claude Opus 4.5 — En güçlü, blog/araştırma (~$20-50/ay)',
                'claude-sonnet-4-5'  => 'Claude Sonnet 4.5 — Dengeli, hızlı (~$5-15/ay)',
                'claude-haiku-4-5'   => 'Claude Haiku 4.5 — En hızlı, ucuz (~$1-3/ay)',
            ],
            'key_placeholder' => 'sk-ant-...',
            'key_option'      => 'hyt_claude_api_key',
            'model_option'    => 'hyt_claude_model',
            'default_model'   => 'claude-opus-4-5',
        ],
        'openai' => [
            'label'  => 'OpenAI GPT',
            'models' => [
                'gpt-4o'       => 'GPT-4o — Görsel + metin, güçlü (~$15-40/ay)',
                'gpt-4o-mini'  => 'GPT-4o Mini — Hızlı, ekonomik (~$2-8/ay)',
                'gpt-4.1'      => 'GPT-4.1 — Uzun bağlam, kod (~$20-50/ay)',
                'gpt-4.1-mini' => 'GPT-4.1 Mini — Ekonomik seçenek (~$3-10/ay)',
            ],
            'key_placeholder' => 'sk-...',
            'key_option'      => 'hyt_openai_llm_api_key',
            'model_option'    => 'hyt_openai_llm_model',
            'default_model'   => 'gpt-4o',
        ],
        'gemini' => [
            'label'  => 'Google Gemini',
            'models' => [
                'gemini-2.5-pro'         => 'Gemini 2.5 Pro — En güçlü, uzun bağlam (~$10-30/ay)',
                'gemini-2.5-flash'       => 'Gemini 2.5 Flash — Hızlı, ücretsiz kota (~$3-10/ay)',
                'gemini-2.0-flash'       => 'Gemini 2.0 Flash — Çok hızlı (~$2-6/ay)',
                'gemini-2.0-flash-lite'  => 'Gemini 2.0 Flash Lite — En ucuz (~$1-3/ay)',
            ],
            'key_placeholder' => 'AIza...',
            'key_option'      => 'hyt_gemini_api_key',
            'model_option'    => 'hyt_gemini_model',
            'default_model'   => 'gemini-2.5-flash',
        ],
        'groq' => [
            'label'  => 'Groq (Llama / Mixtral)',
            'models' => [
                'llama-3.3-70b-versatile'   => 'Llama 3.3 70B — Güçlü, ücretsiz kota',
                'llama-3.1-8b-instant'      => 'Llama 3.1 8B — Ultra hızlı, ücretsiz',
                'mixtral-8x7b-32768'        => 'Mixtral 8x7B — Çok dilli, geniş bağlam',
                'gemma2-9b-it'              => 'Gemma 2 9B — Google açık kaynak',
            ],
            'key_placeholder' => 'gsk_...',
            'key_option'      => 'hyt_groq_api_key',
            'model_option'    => 'hyt_groq_model',
            'default_model'   => 'llama-3.3-70b-versatile',
        ],
    ];

    private $provider;
    private $api_key;
    private $model;
    private int    $timeout = 180;

    public function __construct( ?string $provider = null ) {
        $this->provider = $provider ?? get_option( 'hyt_llm_provider', 'claude' );
        if ( ! array_key_exists( $this->provider, self::PROVIDERS ) ) {
            $this->provider = 'claude';
        }

        $cfg            = self::PROVIDERS[ $this->provider ];
        $this->api_key  = get_option( $cfg['key_option'], '' );
        $this->model    = get_option( $cfg['model_option'], $cfg['default_model'] );
    }

    /* ----------------------------------------------------------------
       PUBLIC — HYT_Claude ile birebir aynı arayüz (drop-in replacement)
    ---------------------------------------------------------------- */

    public function is_configured(): bool {
        if ( empty( $this->api_key ) ) return false;
        return match( $this->provider ) {
            'claude' => str_starts_with( $this->api_key, 'sk-ant-' ),
            'openai' => str_starts_with( $this->api_key, 'sk-' ),
            'gemini' => str_starts_with( $this->api_key, 'AIza' ),
            'groq'   => str_starts_with( $this->api_key, 'gsk_' ),
            default  => ! empty( $this->api_key ),
        };
    }

    public function get_provider(): string  { return $this->provider; }
    public function get_model(): string     { return $this->model; }
    public function get_provider_label(): string {
        return self::PROVIDERS[ $this->provider ]['label'] ?? $this->provider;
    }

    /**
     * SEO + GEO optimizasyonu — tüm provider'larda çalışır.
     */
    public function optimize_seo_geo( string $title, string $content ): array {
        $prompt = <<<PROMPT
Sen deneyimli bir SEO ve GEO (Generative Engine Optimization) uzmanısın. Türkçe içerik üretiyorsun.

Aşağıdaki blog yazısını analiz et ve şunları üret:

1. SEO Başlık (50-60 karakter, anahtar kelime içermeli)
2. URL Slug (Türkçe karaktersiz, tire ayraçlı, kısa)
3. Meta Description (150-160 karakter)
4. Focus Keyword (ana anahtar kelime)
5. Secondary Keywords (3-5 adet, virgülle ayrılmış)
6. FAQ Schema (JSON-LD formatında, 3-5 soru-cevap)
7. GEO Özeti (ChatGPT/Gemini/Perplexity için özet paragraf, 150-200 kelime, spesifik ve otoriter)
8. İçerik HTML (Gutenberg uyumlu H2/H3/p/ul/li yapısı)

Başlık: {$title}

İçerik:
{$content}

Yanıtı SADECE aşağıdaki JSON formatında ver, başka hiçbir şey ekleme:
{"title":"...","slug":"...","meta_description":"...","focus_keyword":"...","secondary_keywords":"...","faq_schema":{},"geo_summary":"...","content_html":"..."}
PROMPT;

        $response = $this->send_request( $prompt );
        if ( ! $response ) return [];
        return $this->parse_json_response( $response ) ?? [];
    }

    /**
     * İçerik genişletme — 1000 kelime → 1500+ kelime.
     */
    public function expand_content( string $title, string $content ): string {
        $word_count = str_word_count( strip_tags( $content ) );
        $author_voice = get_option( 'hyt_author_voice', 'Hasan Yasin Türkyılmaz' );

        $prompt = <<<PROMPT
Sen {$author_voice} adına içerik yazıyorsun. Onun samimi, pratik, deneyim bazlı Türkçe sesini koru.

Mevcut kelime sayısı: {$word_count} — Hedef: En az 1500 kelime.

Yapman gerekenler:
- Gerçek istatistikler ve örnekler ekle
- Konuyu derinleştir, alt başlıklar ekle
- "belirtmek gerekir ki", "önemli olan şudur ki" gibi AI kalıplarını KULLANMA
- Gutenberg uyumlu HTML formatında yaz (H2, H3, p, ul, li)

Başlık: {$title}

Mevcut İçerik:
{$content}

Sadece genişletilmiş HTML içeriği döndür.
PROMPT;

        return $this->send_request( $prompt ) ?? $content;
    }

    /**
     * İçerik insanlaştırma.
     */
    public function humanize( string $content ): string {
        $author_voice = get_option( 'hyt_author_voice', 'Hasan Yasin Türkyılmaz' );
        $prompt = <<<PROMPT
Aşağıdaki metni doğal, insan sesi ile yeniden yaz. {$author_voice}'ın samimi, pratik sesini koru.

Kurallar:
- AI kalıplarını sil ("belirtmek gerekir ki", "son derece" vb.)
- Cümleleri kısalt, doğrudan konuya gir
- HTML yapısını koru (H2, H3, p, ul, li taglerini değiştirme)
- Sadece düzenlenmiş HTML döndür

{$content}
PROMPT;
        return $this->send_request( $prompt ) ?? $content;
    }

    /**
     * Sosyal medya metinleri üretimi.
     */
    public function generate_social_texts( string $title, string $excerpt, string $url ): array {
        $prompt = <<<PROMPT
Aşağıdaki blog yazısı için her platform için ayrı sosyal medya metni yaz.

Blog Başlığı: {$title}
Özet: {$excerpt}
URL: {$url}

Her platform için platforma özel ton ve format kullan:
- instagram: Hook cümlesi + hikaye + 5-8 hashtag (max 2200 karakter)
- facebook: Uzun form, hikaye odaklı, link dahil
- linkedin: Profesyonel ton, içgörü odaklı, 1200-1500 karakter
- twitter: Max 250 karakter, güçlü hook + link
- youtube_desc: 500-800 karakter, anahtar kelimeler, timestamps placeholder

Yanıtı SADECE JSON formatında ver:
{"instagram":"...","facebook":"...","linkedin":"...","twitter":"...","youtube_desc":"..."}
PROMPT;

        $response = $this->send_request( $prompt );
        if ( ! $response ) return [];
        return $this->parse_json_response( $response ) ?? [];
    }

    /**
     * YouTube scripti üretimi (uzun video).
     */
    public function generate_youtube_script( string $title, string $content ): string {
        $prompt = <<<PROMPT
Aşağıdaki blog yazısından 8-12 dakikalık YouTube video scripti yaz.

Başlık: {$title}

İçerik Özeti:
{$content}

Script formatı:
[INTRO — 30 saniye] Hook cümlesi + ne öğreneceklerini söyle
[BÖLÜM 1 — başlık] İçerik
[BÖLÜM 2 — başlık] İçerik
...
[OUTRO — 30 saniye] Abone ol + bir sonraki video teaser

Sadece scripti döndür, JSON veya açıklama ekleme.
PROMPT;
        return $this->send_request( $prompt ) ?? '';
    }

    /**
     * Kısa video scripti (Reels/Shorts — 60 saniye).
     */
    public function generate_short_script( string $title, string $content, int $index = 1 ): string {
        $angles = [
            1 => 'Problemin/çözümün kısa özeti',
            2 => 'Şaşırtıcı istatistik veya gerçek',
            3 => 'Adım adım mini rehber (3 adım)',
        ];
        $angle = $angles[ $index ] ?? 'Blog yazısının ana mesajı';

        $prompt = <<<PROMPT
Aşağıdaki blog yazısından 60 saniyelik dikey video scripti yaz. Açı: {$angle}

Blog: {$title}
Özet: {$content}

Format:
[HOOK — 3 saniye]: Dikkat çekici cümle
[ANA İÇERİK — 50 saniye]: Kısa, net noktalar
[CTA — 7 saniye]: İzleyiciyi yönlendir

Sadece scripti döndür.
PROMPT;
        return $this->send_request( $prompt ) ?? '';
    }

    /**
     * Dış erişim için ham istek.
     */
    public function raw_request( string $prompt ): ?string {
        return $this->send_request( $prompt );
    }

    /**
     * Dış erişim için JSON parse.
     */
    public function parse_json( string $text ): ?array {
        return $this->parse_json_response( $text );
    }

    /**
     * Bağlantı testi.
     */
    public function test_connection(): array {
        if ( ! $this->is_configured() ) {
            return [ 'success' => false, 'message' => "API key eksik veya hatalı format. (Provider: {$this->provider})" ];
        }
        $result = $this->send_request( 'Merhaba, bağlantı testi. Sadece "OK" yaz.' );
        if ( $result ) {
            return [ 'success' => true, 'message' => "Bağlantı başarılı. Provider: {$this->get_provider_label()} | Model: {$this->model}" ];
        }
        return [ 'success' => false, 'message' => "API bağlantısı başarısız. Loglara bakın. (Provider: {$this->provider})" ];
    }

    /* ================================================================
       PRIVATE — Provider-spesifik API çağrıları
    ================================================================ */

    private function send_request( string $prompt ): ?string {
        if ( ! $this->is_configured() ) {
            HYT_Logger::error( 'llm', "API key yapılandırılmamış. Provider: {$this->provider}" );
            return null;
        }

        try {
            return match( $this->provider ) {
                'claude' => $this->call_claude( $prompt ),
                'openai' => $this->call_openai( $prompt ),
                'gemini' => $this->call_gemini( $prompt ),
                'groq'   => $this->call_groq( $prompt ),
                default  => null,
            };
        } catch ( Throwable $e ) {
            HYT_Logger::error( 'llm', "Provider {$this->provider} hata: " . $e->getMessage() );
            return null;
        }
    }

    /* ---- Anthropic Claude ---- */
    private function call_claude( string $prompt ): ?string {
        $response = HYT_Security::safe_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => $this->model,
                'max_tokens' => 8192,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'llm', 'Claude HTTP hata: ' . $response->get_error_message() );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            HYT_Logger::error( 'llm', "Claude API {$code}", [ 'error' => $data['error'] ?? [] ] );
            return null;
        }
        return $data['content'][0]['text'] ?? null;
    }

    /* ---- OpenAI GPT ---- */
    private function call_openai( string $prompt ): ?string {
        $response = HYT_Security::safe_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => wp_json_encode( [
                'model'       => $this->model,
                'max_tokens'  => 8192,
                'temperature' => 0.7,
                'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'llm', 'OpenAI HTTP hata: ' . $response->get_error_message() );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            HYT_Logger::error( 'llm', "OpenAI API {$code}", [ 'error' => $data['error'] ?? [] ] );
            return null;
        }
        return $data['choices'][0]['message']['content'] ?? null;
    }

    /* ---- Google Gemini ---- */
    private function call_gemini( string $prompt ): ?string {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";

        $response = HYT_Security::safe_remote_post( $endpoint, [
            'timeout' => $this->timeout,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'contents' => [
                    [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 8192,
                    'temperature'     => 0.7,
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'llm', 'Gemini HTTP hata: ' . $response->get_error_message() );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            HYT_Logger::error( 'llm', "Gemini API {$code}", [ 'error' => $data['error'] ?? [] ] );
            return null;
        }
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /* ---- Groq (OpenAI-compatible) ---- */
    private function call_groq( string $prompt ): ?string {
        $response = HYT_Security::safe_remote_post( 'https://api.groq.com/openai/v1/chat/completions', [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body' => wp_json_encode( [
                'model'       => $this->model,
                'max_tokens'  => 8192,
                'temperature' => 0.7,
                'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            HYT_Logger::error( 'llm', 'Groq HTTP hata: ' . $response->get_error_message() );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            HYT_Logger::error( 'llm', "Groq API {$code}", [ 'error' => $data['error'] ?? [] ] );
            return null;
        }
        return $data['choices'][0]['message']['content'] ?? null;
    }

    /* ---- JSON Parse (tüm provider'larda ortak) ---- */
    private function parse_json_response( string $text ): ?array {
        // ```json ... ``` bloğunu çıkar
        if ( preg_match( '/```json\s*(.*?)\s*```/s', $text, $m ) ) {
            $text = $m[1];
        } elseif ( preg_match( '/```\s*(.*?)\s*```/s', $text, $m ) ) {
            $text = $m[1];
        }
        // Düz JSON objesi bul
        if ( preg_match( '/(\{.*\})/s', $text, $m ) ) {
            $text = $m[1];
        }

        $decoded = json_decode( $text, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            HYT_Logger::error( 'llm', 'JSON parse hatası', [
                'provider' => $this->provider,
                'raw'      => substr( $text, 0, 500 ),
                'error'    => json_last_error_msg(),
            ] );
            return null;
        }
        return $decoded;
    }

    /* ----------------------------------------------------------------
       STATIC HELPER — provider listesi, admin formu için
    ---------------------------------------------------------------- */
    public static function get_provider_list(): array {
        return self::PROVIDERS;
    }

    public static function get_active_provider(): string {
        return get_option( 'hyt_llm_provider', 'claude' );
    }
}


