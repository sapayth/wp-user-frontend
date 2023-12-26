<?php
/*
Plugin Name: WP User Frontend
Plugin URI: https://wordpress.org/plugins/wp-user-frontend/
Description: Create, edit, delete, manages your post, pages or custom post types from frontend. Create registration forms, frontend profile and more...
Author: weDevs
Version: 3.6.9
Author URI: https://wedevs.com/?utm_source=WPUF_Author_URI
License: GPL2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-user-frontend
Domain Path: /languages
*/

// don't call the file directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $autoload ) ) {
    require_once $autoload;
} else {
	wp_die( __( 'There was a problem installing the plugin' ), __( 'Problem installing plugin' ) );
}

define( 'WPUF_VERSION', '3.6.9' );
define( 'WPUF_FILE', __FILE__ );
define( 'WPUF_ROOT', __DIR__ );
define( 'WPUF_ROOT_URI', plugins_url( '', __FILE__ ) );
define( 'WPUF_ASSET_URI', WPUF_ROOT_URI . '/assets' );
define( 'WPUF_INCLUDES', WPUF_ROOT . '/includes' );

use WeDevs\WpUtils\SingletonTrait;
use WeDevs\WpUtils\ContainerTrait;

/**
 * Main bootstrap class for WP User Frontend
 */

/*Marking a class with #[AllowDynamicProperties] is fully backwards-compatible with earlier PHP versions, because prior to PHP 8.0 this would be interpreted as a comment, and the use non-existent classes as attributes is not an error.*/
#[AllowDynamicProperties]
final class WP_User_Frontend {
    use SingletonTrait, ContainerTrait;

    /**
     * Form field value seperator
     *
     * @var string
     */
    public static $field_separator = '| ';

    /**
     * Pro plugin checkup
     *
     * @var bool
     */
    private $is_pro = false;

    /**
     * Minimum PHP version required
     *
     * @var string
     */
    private $min_php = '5.6';

    /**
     * Fire up the plugin
     */
    public function __construct() {
        if ( ! $this->is_supported_php() ) {
            add_action( 'admin_notices', [ $this, 'php_version_notice' ] );

            return;
        }

        register_activation_hook( __FILE__, [ $this, 'install' ] );
        register_deactivation_hook( __FILE__, [ $this, 'uninstall' ] );

        $this->includes();
        $this->init_hooks();

        // Insight class instantiate
        $this->container['tracker'] = new WeDevs\Wpuf\Lib\WeDevs_Insights( __FILE__ );

        do_action( 'wpuf_loaded' );
    }

    /**
     * Check if the PHP version is supported
     *
     * @return bool
     */
    public function is_supported_php( $min_php = null ) {
        $min_php = $min_php ? $min_php : $this->min_php;

        if ( version_compare( PHP_VERSION, $min_php, '<=' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Show notice about PHP version
     *
     * @return void
     */
    public function php_version_notice() {
        if ( $this->is_supported_php() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $error = __( 'Your installed PHP Version is: ', 'wp-user-frontend' ) . PHP_VERSION . '. ';
        $error .= __( 'The <strong>WP User Frontend</strong> plugin requires PHP version <strong>', 'wp-user-frontend' ) . $this->min_php . __( '</strong> or greater.', 'wp-user-frontend' ); ?>
        <div class="error">
            <p><?php printf( esc_html( $error ) ); ?></p>
        </div>
        <?php
    }

    /**
     * Initialize the hooks
     *
     * @since 2.5.4
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'plugins_loaded', [ $this, 'wpuf_loader' ] );
        add_action( 'plugins_loaded', [ $this, 'process_wpuf_pro_version' ], 11 );
        add_action( 'plugins_loaded', [ $this, 'plugin_upgrades' ] );
        add_action( 'plugins_loaded', [ $this, 'instantiate' ] );

        add_action( 'init', [ $this, 'load_textdomain' ] );

        // do plugin upgrades
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );

        add_action( 'widgets_init', [ $this, 'register_widgets' ] );
    }

    /**
     * Include the required files
     *
     * @return void
     */
    public function includes() {
        require_once __DIR__ . '/wpuf-functions.php';

        // add reCaptcha library if not found
        if ( ! function_exists( 'recaptcha_get_html' ) ) {
            require_once __DIR__ . '/lib/recaptchalib.php';
            require_once __DIR__ . '/lib/invisible_recaptcha.php';
        }
    }

    /**
     * Instantiate the classes
     *
     * @return void
     */
    public function instantiate() {
        $this->assets       = new WeDevs\Wpuf\Assets();
        $this->subscription = new WeDevs\Wpuf\Admin\Subscription();
        $this->fields       = new WeDevs\Wpuf\Admin\Forms\Field_Manager();
        $this->customize    = new WeDevs\Wpuf\Admin\Customizer_Options();
        $this->paypal       = new WeDevs\Wpuf\Lib\Gateway\Paypal();

        if ( is_admin() ) {
            $this->admin        = new WeDevs\Wpuf\Admin();
            $this->setup_wizard = new WeDevs\Wpuf\Setup_Wizard();
            $this->pro_upgrades = new WeDevs\Wpuf\Pro_Upgrades();
            $this->privacy      = new WeDevs\Wpuf\WPUF_Privacy();
        } else {
            $this->frontend = new WeDevs\Wpuf\Frontend();
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            $this->ajax = new WeDevs\Wpuf\Ajax();
        }
    }

    /**
     * Create tables on plugin activation
     *
     * @global object $wpdb
     */
    public function install() {
        $installer = new WeDevs\Wpuf\Installer();
        $installer->install();
    }

    /**
     * Do plugin upgrades
     *
     * @since 2.2
     *
     * @return void
     */
    public function plugin_upgrades() {
        if ( ! is_admin() && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->upgrades = new WeDevs\Wpuf\Admin\Upgrades();
    }

    /**
     * Check whether the version of wpuf pro is prior to the code restructure
     *
     * @since WPUF_FREE
     *
     * @return void
     */
    public function process_wpuf_pro_version() {
        // check whether the version of wpuf pro is prior to the code restructure
        if ( defined( 'WPUF_PRO_VERSION' ) && version_compare( WPUF_PRO_VERSION, '3.4.13', '<' ) ) {
            deactivate_plugins( WPUF_PRO_FILE );

            add_action( 'admin_notices', [ $this, 'wpuf_upgrade_notice' ] );
        }
    }

    /**
     * Show WordPress error notice if WP User Frontend not found
     *
     * @since 2.4.2
     */
    public function wpuf_upgrade_notice() {
        ?>
        <div class="notice error" id="wpuf-pro-installer-notice" style="padding: 1em; position: relative;">
            <h2><?php esc_html_e( 'Your WP User Frontend Pro is almost ready!', 'wpuf-pro' ); ?></h2>
            <p><?php esc_html_e( 'You just need to upgrade the Plugin version above 3.4.13 to make it functional.', 'wpuf-pro' ); ?></p>
        </div>
        <?php
    }

    /**
     * Load wpuf Free class if not pro
     *
     * @since 2.5.4
     */
    public function wpuf_loader() {
        $has_pro = class_exists( 'WP_User_Frontend_Pro' );

        if ( $has_pro ) {
            $this->is_pro = true;
            add_action( 'admin_notices', [ $this, 'wpuf_latest_pro_activation_notice' ] );
        } else {
            $this->free_loader= new WeDevs\Wpuf\Free\Free_Loader();
        }
    }

    /**
     * Latest Pro Activation Message
     *
     * @return void
     */
    public function wpuf_latest_pro_activation_notice() {
        if ( ! version_compare( WPUF_PRO_VERSION, '3.1.0', '<' ) ) {
            return;
        }

        $offer_msg = __(
            '<p style="font-size: 13px">
                            <strong class="highlight-text" style="font-size: 18px; display:block; margin-bottom:8px"> UPDATE REQUIRED </strong>
                            WP User Frontend Pro is not working because you are using an old version of WP User Frontend Pro. Please update <strong>WPUF Pro</strong> to >= <strong>v3.1.0</strong> to work with the latest version of WP User Frontend
                        </p>', 'wp-user-frontend'
        );
        ?>
            <div class="notice is-dismissible" id="wpuf-update-offer-notice">
                <table>
                    <tbody>
                        <tr>
                            <td class="image-container">
                                <img src="https://ps.w.org/wp-user-frontend/assets/icon-256x256.png" alt="">
                            </td>
                            <td class="message-container">
                                <?php echo esc_html( $offer_msg ); ?>
                            </td>
                            <td><a href="https://wedevs.com/account/downloads/" class="button button-primary promo-btn" target="_blank"><?php esc_html_e( 'Update WP User Frontend Pro Now', 'wp-user-frontend' ); ?></a></td>
                        </tr>
                    </tbody>
                </table>
                <!-- <a href="https://wedevs.com/account/downloads/" class="button button-primary promo-btn" target="_blank"><?php esc_html_e( 'Update WP User Frontend Pro NOW', 'wp-user-frontend' ); ?></a> -->
            </div><!-- #wpuf-update-offer-notice -->

            <style>
                #wpuf-update-offer-notice {
                    background-size: cover;
                    border: 0px;
                    padding: 10px;
                    opacity: 0;
                    border-left: 3px solid red;
                }

                .wrap > #wpuf-update-offer-notice {
                    opacity: 1;
                }

                #wpuf-update-offer-notice table {
                    border-collapse: collapse;
                    width: 70%;
                }

                #wpuf-update-offer-notice table td {
                    padding: 0;
                }

                #wpuf-update-offer-notice table td.image-container {
                    background-color: #fff;
                    vertical-align: middle;
                    width: 95px;
                }


                #wpuf-update-offer-notice img {
                    max-width: 100%;
                    max-height: 100px;
                    vertical-align: middle;
                    border-radius: 100%;
                }

                #wpuf-update-offer-notice table td.message-container {
                    padding: 0 10px;
                }

                #wpuf-update-offer-notice h2{
                    color: #000;
                    margin-bottom: 10px;
                    font-weight: normal;
                    margin: 16px 0 14px;
                    -webkit-text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                    -moz-text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                    -o-text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                    text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                }


                #wpuf-update-offer-notice h2 span {
                    position: relative;
                    top: 0;
                }

                #wpuf-update-offer-notice p{
                    color: #000;
                    font-size: 14px;
                    margin-bottom: 10px;
                    -webkit-text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                    -moz-text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                    -o-text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                    text-shadow: 0.1px 0.1px 0px rgba(250, 250, 250, 0.24);
                }

                #wpuf-update-offer-notice p strong.highlight-text{
                    color: #000;
                }

                #wpuf-update-offer-notice p a {
                    color: #000;
                }

                #wpuf-update-offer-notice .notice-dismiss:before {
                    color: #000;
                }

                #wpuf-update-offer-notice span.dashicons-megaphone {
                    position: absolute;
                    bottom: 46px;
                    right: 248px;
                    color: rgba(253, 253, 253, 0.29);
                    font-size: 96px;
                    transform: rotate(-21deg);
                }

                #wpuf-update-offer-notice a.promo-btn{
                    background: #0073aa;
                    /*border-color: #fafafa #fafafa #fafafa;*/
                    box-shadow: 0 1px 0 #fafafa;
                    color: #fff;
                    text-decoration: none;
                    text-shadow: none;
                    position: absolute;
                    top: 40px;
                    right: 26px;
                    height: 40px;
                    line-height: 40px;
                    width: 300px;
                    text-align: center;
                    font-weight: 600;
                }

            </style>
            <script type='text/javascript'>
                jQuery('body').on('click', '#wpuf-update-offer-notice .notice-dismiss', function(e) {
                    e.preventDefault();

                    wp.ajax.post('wpuf-dismiss-update-offer-notice', {
                        dismissed: true
                    });
                });
            </script>

        <?php
    }

    /**
     * Manage task on plugin deactivation
     *
     * @return void
     */
    public static function uninstall() {
        wp_clear_scheduled_hook( 'wpuf_remove_expired_post_hook' );
    }

    /**
     * Load the translation file for current language.
     *
     * @since version 0.7
     *
     * @author Tareq Hasan
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'wp-user-frontend', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * The main logging function
     *
     * @uses error_log
     *
     * @param string $type type of the error. e.g: debug, error, info
     * @param string $msg
     */
    public static function log( $type = '', $msg = '' ) {
        $msg = sprintf( "[%s][%s] %s\n", date( 'd.m.Y h:i:s' ), $type, $msg );
        error_log( $msg, 3, __DIR__ . '/log.txt' );
    }

    /**
     * Returns if the plugin is in PRO version
     *
     * @since 2.3.2
     *
     * @return bool
     */
    public function is_pro() {
        return $this->is_pro;
    }

    /**
     * Plugin action links
     *
     * @param array $links
     *
     * @since  2.3.3
     *
     * @return array
     */
    public function plugin_action_links( $links ) {
        if ( ! $this->is_pro() ) {
            $links[] = '<a href="' . WeDevs\Wpuf\Free\Pro_Prompt::get_pro_url() . '" target="_blank" style="color: red;">Get PRO</a>';
        }

        $links[] = '<a href="' . admin_url( 'admin.php?page=wpuf-settings' ) . '">Settings</a>';
        $links[] = '<a href="https://wedevs.com/docs/wp-user-frontend-pro/getting-started/how-to-install/" target="_blank">Documentation</a>';

        return $links;
    }

    /**
     * Register widgets
     *
     * @since WPUF_SINCE
     *
     * @return void
     */
    public function register_widgets() {
        $this->widgets = new WeDevs\Wpuf\Widgets\Manager();
    }
    public function license_expired() {
        echo '<div class="error">';
        echo '<p>Your <strong>WP User Frontend Pro</strong> License has been expired. Please <a href="https://wedevs.com/account/" target="_blank">renew your license</a>.</p>';
        echo '</div>';
    }

    /**
     * Get the global field seperator for WPUF
     *
     * @since WPUF_SINCE
     *
     * @return string
     */
    public function get_field_seperator() {
        return self::$field_separator;
    }
}

/**
 * Returns the singleton instance
 *
 * @return \WP_User_Frontend
 */
function wpuf() {
    return WP_User_Frontend::instance();
}

// kickoff
wpuf();
