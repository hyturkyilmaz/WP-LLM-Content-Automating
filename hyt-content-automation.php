<?php
/**
 * Plugin Name: HYT Content Automation
 * Plugin URI:  https://hyturkyilmaz.com
 * Description: Google Drive > WordPress > SEO/GEO > Gorsel > Video > Sosyal Medya tam otomasyonu.
 * Version:     2.2.2
 * Author:      Hasan Yasin Turkyilmaz
 * Author URI:  https://hyturkyilmaz.com
 * Text Domain: hyt-content-automation
 * Requires at least: 6.2
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'HYT_VERSION',     '2.2.2' );
define( 'HYT_PLUGIN_FILE', __FILE__ );
define( 'HYT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'HYT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/* ---------- Helpers ---------- */
require_once __DIR__ . '/includes/helpers.php';

/* ---------- Autoloader ---------- */
spl_autoload_register( function ( string $class ) {
    $classes = [
        'HYT_Database'          => 'includes/class-database.php',
        'HYT_Scheduler'         => 'includes/class-scheduler.php',
        'HYT_Holidays'          => 'includes/class-holidays.php',
        'HYT_Logger'            => 'includes/class-logger.php',
        'HYT_Security'          => 'includes/class-security.php',
        'HYT_LLM'               => 'includes/api/class-llm.php',
        'HYT_Claude'            => 'includes/api/class-claude.php',
        'HYT_Google_Drive'      => 'includes/api/class-google-drive.php',
        'HYT_HeyGen'            => 'includes/api/class-heygen.php',
        'HYT_Social'            => 'includes/api/class-social.php',
        'HYT_Image_Generator'   => 'includes/api/class-image-generator.php',
        'HYT_Content_Pipeline'  => 'includes/pipeline/class-content-pipeline.php',
        'HYT_Video_Pipeline'    => 'includes/pipeline/class-video-pipeline.php',
        'HYT_Distribution'      => 'includes/pipeline/class-distribution.php',
        'HYT_Admin_Menu'        => 'includes/admin/class-admin-menu.php',
        'HYT_Ajax'              => 'includes/admin/class-ajax.php',
    ];
    if ( isset( $classes[ $class ] ) ) {
        require_once HYT_PLUGIN_DIR . $classes[ $class ];
    }
} );

/* ---------- Encryption & Secret Option Filters ---------- */
require_once HYT_PLUGIN_DIR . 'includes/class-encryption.php';

$secret_options = [
    'hyt_claude_api_key',
    'hyt_openai_api_key',
    'hyt_gdrive_client_secret',
    'hyt_gdrive_client_id',
    'hyt_heygen_api_key',
    'hyt_meta_page_token',
    'hyt_linkedin_access_token',
    'hyt_youtube_client_secret',
    'hyt_youtube_client_id',
    'hyt_youtube_access_token',
    'hyt_youtube_refresh_token',
];

foreach ( $secret_options as $option_name ) {
    add_filter( 'option_' . $option_name, [ 'HYT_Encryption', 'decrypt_option' ] );
    add_filter( 'pre_update_option_' . $option_name, [ 'HYT_Encryption', 'encrypt_option' ] );
}

/* ---------- Activation / Deactivation ---------- */
register_activation_hook( __FILE__, function () {
    if ( class_exists( 'HYT_Encryption' ) ) {
        HYT_Encryption::migrate_all();
    }
    HYT_Database::install();
    HYT_Scheduler::register_crons();
} );

register_deactivation_hook( __FILE__, function () {
    HYT_Scheduler::clear_crons();
} );

/* ---------- Auto-upgrade for existing installs ---------- */
add_action( 'plugins_loaded', function () {
    $db_version = get_option( 'hyt_db_version', '0' );
    if ( version_compare( $db_version, HYT_VERSION, '<' ) ) {
        HYT_Database::install();
    }
} );

/* ---------- Boot ---------- */
add_action( 'plugins_loaded', function () {
    /* Textdomain */
    load_plugin_textdomain( 'hyt-content-automation', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    new HYT_Admin_Menu();
    new HYT_Ajax();
    HYT_Scheduler::init();

    /* REST endpoints */
    add_action( 'rest_api_init', function () {
        register_rest_route( 'hyt/v1', '/google-oauth-callback', [
            'methods'             => 'GET',
            'callback'            => [ 'HYT_Google_Drive', 'oauth_callback' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'hyt/v1', '/heygen-callback', [
            'methods'             => 'POST',
            'callback'            => [ 'HYT_HeyGen', 'webhook_callback' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'hyt/v1', '/youtube-oauth-callback', [
            'methods'             => 'GET',
            'callback'            => [ 'HYT_Social', 'youtube_oauth_callback' ],
            'permission_callback' => '__return_true',
        ] );
    } );
} );
