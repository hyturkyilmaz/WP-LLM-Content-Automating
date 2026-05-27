<?php
defined( 'ABSPATH' ) || exit;

/**
 * HYT Canvas Design Engine — Görsel Üretimi
 * 1. Python Pillow (tercihli)
 * 2. PHP GD (fallback)
 * 3. DALL-E 3 (override, OpenAI key gerekli)
 */
class HYT_Image_Generator {

    /* Kategori renk paleti */
    private static array $palettes = [
        'dijital-pazarlama' => [ 'bg' => '#0f1117', 'accent' => '#6366f1', 'glow' => '#8b5cf6' ],
        'seo'               => [ 'bg' => '#040d21', 'accent' => '#3b82f6', 'glow' => '#60a5fa' ],
        'e-ticaret'         => [ 'bg' => '#061411', 'accent' => '#059669', 'glow' => '#34d399' ],
        'girisimcilik'      => [ 'bg' => '#13100a', 'accent' => '#d97706', 'glow' => '#fbbf24' ],
        'sosyal-medya'      => [ 'bg' => '#0d0510', 'accent' => '#c026d3', 'glow' => '#e879f9' ],
        'default'           => [ 'bg' => '#0f1117', 'accent' => '#6366f1', 'glow' => '#8b5cf6' ],
    ];

    /**
     * Ana üretim fonksiyonu — pipeline_id için featured image üretir.
     */
    public static function generate_for_pipeline( int $pipeline_id ): int|false {
        $row = HYT_Database::get_pipeline( $pipeline_id );
        if ( ! $row ) return false;

        $title    = $row->title ?? 'İçerik';
        $category = self::detect_category( $title, $row->raw_content ?? '' );
        $palette  = self::$palettes[ $category ] ?? self::$palettes['default'];

        // DALL-E 3 override
        if ( get_option( 'hyt_openai_api_key' ) && get_option( 'hyt_use_dalle', '0' ) === '1' ) {
            $attachment_id = self::generate_dalle( $title, $palette );
            if ( $attachment_id ) return self::attach_to_pipeline( $pipeline_id, (int) $row->post_id, $attachment_id );
        }

        // Python Pillow
        $attachment_id = self::generate_pillow( $title, $palette, $pipeline_id );
        if ( $attachment_id ) return self::attach_to_pipeline( $pipeline_id, (int) $row->post_id, $attachment_id );

        // PHP GD Fallback
        $attachment_id = self::generate_gd( $title, $palette, $pipeline_id );
        if ( $attachment_id ) return self::attach_to_pipeline( $pipeline_id, (int) $row->post_id, $attachment_id );

        HYT_Logger::error( 'image', "Görsel üretilemedi: #{$pipeline_id}" );
        return false;
    }

    /* ================================================================
       PYTHON PILLOW
    ================================================================ */
    private static function generate_pillow( string $title, array $palette, int $pipeline_id ): int {
        $script_path = HYT_PLUGIN_DIR . 'includes/api/generate_image.py';
        if ( ! file_exists( $script_path ) ) {
            self::create_python_script( $script_path );
        }

        $tmp_out  = wp_tempnam( 'hyt_img_' ) . '.png';
        $title_esc = escapeshellarg( $title );
        $bg_esc    = escapeshellarg( $palette['bg'] );
        $accent_esc = escapeshellarg( $palette['accent'] );
        $glow_esc  = escapeshellarg( $palette['glow'] );
        $out_esc   = escapeshellarg( $tmp_out );

        $cmd    = "python3 {$script_path} --title {$title_esc} --bg {$bg_esc} --accent {$accent_esc} --glow {$glow_esc} --output {$out_esc} 2>&1";
        $output = shell_exec( $cmd );

        if ( file_exists( $tmp_out ) && filesize( $tmp_out ) > 1000 ) {
            $id = self::upload_image_file( $tmp_out, "hyt-featured-{$pipeline_id}.png" );
            @unlink( $tmp_out );
            return $id;
        }

        HYT_Logger::warning( 'image', 'Python Pillow başarısız, GD fallback deneniyor.', [ 'output' => $output ] );
        return 0;
    }

    /* ================================================================
       PHP GD FALLBACK
    ================================================================ */
    private static function generate_gd( string $title, array $palette, int $pipeline_id ): int {
        if ( ! extension_loaded( 'gd' ) ) {
            HYT_Logger::error( 'image', 'PHP GD extension yüklü değil.' );
            return 0;
        }

        $width  = 1200;
        $height = 630;
        $im     = imagecreatetruecolor( $width, $height );

        // Arka plan rengi
        $bg = self::hex_to_rgb( $palette['bg'] );
        $bg_color = imagecolorallocate( $im, $bg[0], $bg[1], $bg[2] );
        imagefill( $im, 0, 0, $bg_color );

        // Gradient benzeri overlay (sağ üst glow)
        $accent = self::hex_to_rgb( $palette['accent'] );
        $glow   = self::hex_to_rgb( $palette['glow'] );

        // Radyal daireler (glow simülasyonu)
        for ( $r = 300; $r > 0; $r -= 10 ) {
            $alpha = (int) ( 120 - ( $r * 0.4 ) );
            $alpha = max( 0, min( 127, $alpha ) );
            $c = imagecolorallocatealpha( $im, $glow[0], $glow[1], $glow[2], $alpha );
            imagefilledellipse( $im, $width - 200, 150, $r * 2, $r * 2, $c );
        }

        // İnce yatay çizgiler (veri akışı)
        $line_color = imagecolorallocatealpha( $im, $accent[0], $accent[1], $accent[2], 100 );
        for ( $y = 0; $y < $height; $y += 40 ) {
            imageline( $im, 0, $y, $width, $y, $line_color );
        }

        // Sol alt aksan blok
        $accent_color = imagecolorallocate( $im, $accent[0], $accent[1], $accent[2] );
        imagefilledrectangle( $im, 60, $height - 100, 200, $height - 95, $accent_color );

        // Başlık metni
        $white    = imagecolorallocate( $im, 255, 255, 255 );
        $gray     = imagecolorallocate( $im, 160, 160, 180 );
        $font_dir = HYT_PLUGIN_DIR . 'admin/fonts/';

        // Font varsa TTF kullan, yoksa built-in
        $bold_font   = $font_dir . 'Inter-Bold.ttf';
        $regular_font = $font_dir . 'Inter-Regular.ttf';

        if ( file_exists( $bold_font ) ) {
            // Başlık — sözcüklere böl, satır sar
            $lines      = self::wrap_text_ttf( $title, $bold_font, 42, $width - 180 );
            $line_h     = 56;
            $start_y    = max( 200, ( $height / 2 ) - ( count( $lines ) * $line_h / 2 ) );
            foreach ( $lines as $i => $line ) {
                imagettftext( $im, 42, 0, 80, $start_y + $i * $line_h, $white, $bold_font, $line );
            }
            // Alt bilgi
            imagettftext( $im, 16, 0, 80, $height - 65, $gray, $regular_font ?: $bold_font, 'hyturkyilmaz.com' );
        } else {
            // GD built-in font (ASCII only)
            $short = mb_substr( $title, 0, 60 );
            imagestring( $im, 5, 80, 250, $short, $white );
            imagestring( $im, 3, 80, $height - 60, 'hyturkyilmaz.com', $gray );
        }

        // Vignette kenar karartma
        for ( $i = 0; $i < 80; $i++ ) {
            $alpha = (int) ( $i * 1.5 );
            $alpha = min( 127, $alpha );
            $c = imagecolorallocatealpha( $im, 0, 0, 0, 127 - $alpha );
            imagerectangle( $im, $i, $i, $width - $i, $height - $i, $c );
        }

        $tmp_out = wp_tempnam( 'hyt_gd_' ) . '.png';
        imagepng( $im, $tmp_out, 8 );
        imagedestroy( $im );

        if ( file_exists( $tmp_out ) && filesize( $tmp_out ) > 500 ) {
            $id = self::upload_image_file( $tmp_out, "hyt-featured-{$pipeline_id}.png" );
            @unlink( $tmp_out );
            return $id;
        }
        return 0;
    }

    /* ================================================================
       DALL-E 3
    ================================================================ */
    private static function generate_dalle( string $title, array $palette ): int {
        $api_key = get_option( 'hyt_openai_api_key', '' );
        if ( ! $api_key ) return 0;

        $prompt = "Blog yazısı için profesyonel featured image. Başlık: \"{$title}\". "
            . "Karanlık tema, {$palette['bg']} arka plan, {$palette['accent']} aksan rengi. "
            . "Minimalist, data/tech estetiği, radyal ışık efekti, metin içermesin. "
            . "16:9 oran, yüksek kalite.";

        $response = HYT_Security::safe_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'   => 'dall-e-3',
                'prompt'  => $prompt,
                'n'       => 1,
                'size'    => '1792x1024',
                'quality' => 'standard',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return 0;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $url  = $data['data'][0]['url'] ?? '';
        if ( ! $url ) return 0;

        // URL'den WP medyasına indir
        $tmp = HYT_Security::safe_download_url( $url );
        if ( is_wp_error( $tmp ) ) return 0;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_handle_sideload( [ 'name' => 'hyt-dalle.png', 'tmp_name' => $tmp ], 0 );
        @unlink( $tmp );
        return is_wp_error( $id ) ? 0 : $id;
    }

    /* ================================================================
       PYTHON SCRIPT OLUŞTUR (ilk çalıştırmada)
    ================================================================ */
    private static function create_python_script( string $path ): void {
        $dir = dirname( $path );
        if ( ! is_writable( $dir ) ) {
            HYT_Logger::error( 'image', "Python script dizini yazılabilir değil: {$dir}" );
            return;
        }

        $code = <<<'PYTHON'
#!/usr/bin/env python3
"""HYT Canvas Design Engine — Veri Akışı felsefesiyle featured image üretir."""
import argparse, math, random, sys
try:
    from PIL import Image, ImageDraw, ImageFont, ImageFilter
except ImportError:
    sys.exit(1)

def hex_to_rgb(h):
    h = h.lstrip('#')
    return tuple(int(h[i:i+2], 16) for i in (0, 2, 4))

def draw_image(title, bg, accent, glow, output):
    W, H = 1200, 630
    im = Image.new('RGB', (W, H), hex_to_rgb(bg))
    draw = ImageDraw.Draw(im, 'RGBA')

    # Radyal glow (sağ üst)
    glow_rgb = hex_to_rgb(glow)
    for r in range(350, 0, -8):
        alpha = max(0, int(80 - r * 0.22))
        draw.ellipse([W-200-r, 80-r, W-200+r, 80+r],
                     fill=(*glow_rgb, alpha))

    # İkincil glow (sol alt)
    accent_rgb = hex_to_rgb(accent)
    for r in range(180, 0, -8):
        alpha = max(0, int(40 - r * 0.2))
        draw.ellipse([80-r, H-80-r, 80+r, H-80+r],
                     fill=(*accent_rgb, alpha))

    # Radyal ray sistemi
    cx, cy = W - 150, 80
    for angle in range(0, 360, 18):
        rad = math.radians(angle)
        x2 = cx + int(math.cos(rad) * 500)
        y2 = cy + int(math.sin(rad) * 500)
        draw.line([(cx, cy), (x2, y2)], fill=(*accent_rgb, 12), width=1)

    # Mikro-mark grid
    for gx in range(0, W, 60):
        for gy in range(0, H, 60):
            draw.rectangle([gx-1, gy-1, gx+1, gy+1],
                            fill=(*accent_rgb, 20))

    # Broken signal lines
    random.seed(42)
    for _ in range(12):
        y = random.randint(40, H-40)
        x1 = random.randint(0, W//3)
        x2 = x1 + random.randint(40, 200)
        draw.line([(x1, y), (x2, y)], fill=(*accent_rgb, 40), width=1)

    # Sol aksan bar
    draw.rectangle([60, H-90, 220, H-84], fill=(*accent_rgb, 220))

    # Vignette
    vig = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    vd  = ImageDraw.Draw(vig)
    for i in range(100):
        a = int(i * 1.4)
        vd.rectangle([i, i, W-i, H-i], outline=(0, 0, 0, min(255, a)))
    im.paste(Image.alpha_composite(im.convert('RGBA'), vig).convert('RGB'))

    # Font yükle
    try:
        font_bold = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 46)
        font_sm   = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 18)
    except:
        font_bold = ImageFont.load_default()
        font_sm   = font_bold

    draw2 = ImageDraw.Draw(im)

    # Başlığı satırlara böl (max ~38 karakter/satır)
    words = title.split()
    lines, cur = [], ''
    for w in words:
        test = (cur + ' ' + w).strip()
        if len(test) <= 38:
            cur = test
        else:
            if cur: lines.append(cur)
            cur = w
    if cur: lines.append(cur)
    lines = lines[:4]

    total_h = len(lines) * 60
    start_y = max(160, (H - total_h) // 2 - 20)

    for i, line in enumerate(lines):
        draw2.text((80, start_y + i * 60), line,
                   fill=(255, 255, 255, 240), font=font_bold)

    draw2.text((80, H - 60), 'hyturkyilmaz.com',
               fill=(160, 160, 180, 200), font=font_sm)

    im.save(output, 'PNG', optimize=True)

if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--title',  required=True)
    p.add_argument('--bg',     required=True)
    p.add_argument('--accent', required=True)
    p.add_argument('--glow',   required=True)
    p.add_argument('--output', required=True)
    args = p.parse_args()
    draw_image(args.title, args.bg, args.accent, args.glow, args.output)
PYTHON;
        $written = file_put_contents( $path, $code );
        if ( $written === false ) {
            HYT_Logger::error( 'image', "Python script yazılamadı: {$path}" );
            return;
        }
        @chmod( $path, 0755 );
    }

    /* ================================================================
       HELPERS
    ================================================================ */
    private static function detect_category( string $title, string $content ): string {
        $text  = strtolower( $title . ' ' . mb_substr( $content, 0, 500 ) );
        $rules = [
            'seo'               => [ 'seo', 'arama motoru', 'google', 'anahtar kelime', 'backlink', 'serp' ],
            'e-ticaret'         => [ 'e-ticaret', 'e-commerce', 'shopify', 'woocommerce', 'satış', 'mağaza' ],
            'girisimcilik'      => [ 'girişim', 'startup', 'iş modeli', 'yatırım', 'founder', 'ürün' ],
            'sosyal-medya'      => [ 'instagram', 'facebook', 'tiktok', 'linkedin', 'twitter', 'sosyal medya' ],
            'dijital-pazarlama' => [ 'pazarlama', 'marketing', 'reklam', 'kampanya', 'strateji' ],
        ];
        foreach ( $rules as $cat => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( str_contains( $text, $kw ) ) return $cat;
            }
        }
        return 'default';
    }

    private static function upload_image_file( string $file_path, string $filename ): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_upload_bits( $filename, null, file_get_contents( $file_path ) );
        if ( $upload['error'] ) {
            HYT_Logger::error( 'image', 'Dosya yüklenemedi: ' . $upload['error'] );
            return 0;
        }

        $wp_filetype  = wp_check_filetype( $filename, null );
        $attachment   = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name( $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( ! $id ) return 0;

        $attach_data = wp_generate_attachment_metadata( $id, $upload['file'] );
        wp_update_attachment_metadata( $id, $attach_data );
        return $id;
    }

    private static function attach_to_pipeline( int $pipeline_id, int $post_id, int $attachment_id ): int {
        if ( $post_id ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
        HYT_Database::update_pipeline( $pipeline_id, [ 'flag_img' => 1 ] );
        HYT_Logger::info( 'image', "Featured image atandı. Pipeline #{$pipeline_id}, Attachment #{$attachment_id}" );
        return $attachment_id;
    }

    private static function hex_to_rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );
        return [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];
    }

    private static function wrap_text_ttf( string $text, string $font, int $size, int $max_width ): array {
        $words = explode( ' ', $text );
        $lines = [];
        $cur   = '';
        foreach ( $words as $word ) {
            $test = trim( $cur . ' ' . $word );
            $box  = imagettfbbox( $size, 0, $font, $test );
            $w    = abs( $box[4] - $box[0] );
            if ( $w > $max_width && $cur ) {
                $lines[] = $cur;
                $cur     = $word;
            } else {
                $cur = $test;
            }
        }
        if ( $cur ) $lines[] = $cur;
        return array_slice( $lines, 0, 4 );
    }
}

