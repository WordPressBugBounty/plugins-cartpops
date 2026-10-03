<?php

/**
 * Plugin Name: CartPops
 * Plugin URI:  https://cartpops.com
 * Description: The #1 WooCommerce cart drawer plugin. Boost conversions with a beautiful slide-out cart, product recommendations, and free shipping meter.
 * Version:     2.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author:      CartPops
 * Author URI:  https://cartpops.com
 * License:     GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: cartpops
 * Domain Path: /languages
 * WC requires at least: 9.0
 * WC tested up to: 11.0
 *
 *
 * @package CartPops
 */
declare (strict_types = 1);
defined( 'ABSPATH' ) || exit;
if ( !function_exists( 'cartpops_editions_are_simultaneously_active' ) ) {
    /** Detect the two exact CartPops product basenames without mutating either. */
    function cartpops_editions_are_simultaneously_active() : bool {
        $site_plugins = ( function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array() );
        $site_plugins = ( is_array( $site_plugins ) ? $site_plugins : array() );
        $network_plugins = ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ? get_site_option( 'active_sitewide_plugins', array() ) : array() );
        $network_plugins = ( is_array( $network_plugins ) ? array_keys( $network_plugins ) : array() );
        $active_basenames = array_merge( $site_plugins, $network_plugins );
        return in_array( 'cartpops/cartpops.php', $active_basenames, true ) && in_array( 'cartpops-pro/cartpops.php', $active_basenames, true );
    }

}
if ( !function_exists( 'cartpops_render_edition_collision_notice' ) ) {
    /** Render an actionable value-free duplicate-edition notice. */
    function cartpops_render_edition_collision_notice() : void {
        if ( !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_network_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html__( 'CartPops detected both the Free and Pro editions as active. CartPops was paused; deactivate one edition and reload this page.', 'cartpops' ) . '</p></div>';
    }

}
if ( !function_exists( 'cartpops_register_edition_collision_notice' ) ) {
    /** Register the duplicate-edition notice at most once in this request. */
    function cartpops_register_edition_collision_notice() : void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        add_action( 'admin_notices', 'cartpops_render_edition_collision_notice' );
        if ( is_multisite() ) {
            add_action( 'network_admin_notices', 'cartpops_render_edition_collision_notice' );
        }
        $registered = true;
    }

}
// Loading two CartPops editions in one request would make global constants,
// lifecycle hooks, and the Freemius product singleton ambiguous. Keep the
// edition that WordPress loaded first and stop this second bootstrap without
// deactivating either plugin or touching customer data.
if ( defined( 'CARTPOPS_VERSION' ) || defined( 'CARTPOPS_FILE' ) || defined( 'CARTPOPS_PATH' ) || defined( 'CARTPOPS_URL' ) || function_exists( 'fs_cartpops' ) || cartpops_editions_are_simultaneously_active() ) {
    cartpops_register_edition_collision_notice();
    return;
}
define( 'CARTPOPS_VERSION', '2.0.2' );
define( 'CARTPOPS_FILE', __FILE__ );
define( 'CARTPOPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CARTPOPS_URL', plugin_dir_url( __FILE__ ) );
$GLOBALS['cartpops_freemius_bootstrap_ready'] = false;
// Register the source fallback first so immutable V1 evidence can be captured
// before Composer loads Freemius' files autoload entry. Once Composer is ready,
// unregister the fallback so it remains the ordinary package authority.
$cartpops_source_autoloader = static function ( string $class_name ) : void {
    $prefix = 'CartPops\\';
    if ( !str_starts_with( $class_name, $prefix ) ) {
        return;
    }
    $relative = substr( $class_name, strlen( $prefix ) );
    $file = CARTPOPS_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
};
spl_autoload_register( $cartpops_source_autoloader, true, true );
$cartpops_pre_sdk_legacy_install_evidence = null;
$cartpops_pre_sdk_legacy_install_evidence_ready = false;
try {
    $cartpops_pre_sdk_legacy_install_evidence = \CartPops\Migration\PreSdkLegacyInstallEvidence::capture();
    $cartpops_pre_sdk_legacy_install_evidence_ready = $cartpops_pre_sdk_legacy_install_evidence->capture_was_available();
} catch ( \Throwable ) {
    $GLOBALS['cartpops_freemius_bootstrap_error'] = 'pre_sdk_legacy_evidence_unavailable';
    if ( class_exists( \CartPops\Migration\PreSdkLegacyInstallEvidence::class, false ) ) {
        try {
            $cartpops_pre_sdk_legacy_install_evidence = \CartPops\Migration\PreSdkLegacyInstallEvidence::unavailable();
        } catch ( \Throwable ) {
            $cartpops_pre_sdk_legacy_install_evidence = null;
            $cartpops_pre_sdk_legacy_install_evidence_ready = false;
        }
    }
}
if ( !$cartpops_pre_sdk_legacy_install_evidence_ready ) {
    $GLOBALS['cartpops_freemius_bootstrap_error'] = 'pre_sdk_legacy_evidence_unavailable';
    spl_autoload_unregister( $cartpops_source_autoloader );
    unset($cartpops_source_autoloader);
    if ( !function_exists( 'cartpops_pre_sdk_legacy_evidence_notice' ) ) {
        /** Render a value-free pre-SDK failure notice to capable operators. */
        function cartpops_pre_sdk_legacy_evidence_notice() : void {
            if ( !function_exists( 'current_user_can' ) || !function_exists( 'esc_html__' ) || !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_network_plugins' ) ) {
                return;
            }
            echo '<div class="notice notice-error"><p>' . esc_html__( 'CartPops could not safely verify upgrade state. CartPops was paused before licensing initialization; reload this page or contact support.', 'cartpops' ) . '</p></div>';
        }

    }
    if ( function_exists( 'add_action' ) ) {
        add_action( 'admin_notices', 'cartpops_pre_sdk_legacy_evidence_notice' );
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            add_action( 'network_admin_notices', 'cartpops_pre_sdk_legacy_evidence_notice' );
        }
    }
    return;
}
$cartpops_composer_loaded = false;
if ( is_readable( CARTPOPS_PATH . 'vendor/autoload.php' ) ) {
    try {
        require_once CARTPOPS_PATH . 'vendor/autoload.php';
        $cartpops_composer_loaded = true;
    } catch ( \Throwable ) {
        $GLOBALS['cartpops_freemius_bootstrap_error'] = 'composer_runtime_failure';
    }
}
if ( $cartpops_composer_loaded ) {
    spl_autoload_unregister( $cartpops_source_autoloader );
}
if ( !function_exists( 'cartpops_freemius_runtime_version_is_supported' ) ) {
    /**
     * Accept only one bounded canonical Freemius runtime version at the reviewed floor.
     *
     * @param mixed $version External runtime version constant.
     */
    function cartpops_freemius_runtime_version_is_supported(  mixed $version  ) : bool {
        if ( !is_string( $version ) || '' === $version || strlen( $version ) > 32 ) {
            return false;
        }
        $canonical = 1 === preg_match( '/\\A(?:0|[1-9][0-9]{0,5})(?:\\.(?:0|[1-9][0-9]{0,5})){2,3}(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?\\z/D', $version );
        return $canonical && version_compare( $version, '2.13.4', '>=' );
    }

}
if ( !function_exists( 'cartpops_freemius_registration_authority_for' ) ) {
    /**
     * Capture one immutable registration authority for an exact SDK candidate.
     *
     * @param mixed $candidate External Freemius SDK candidate.
     */
    function cartpops_freemius_registration_authority_for(  mixed $candidate  ) : ?\CartPops\Licensing\FreemiusRuntime {
        if ( !is_object( $candidate ) || !defined( 'WP_FS__SDK_VERSION' ) || !cartpops_freemius_runtime_version_is_supported( WP_FS__SDK_VERSION ) ) {
            return null;
        }
        try {
            return ( new \CartPops\Licensing\FreemiusRuntime($candidate) )->registration_authority( 7061 );
        } catch ( \Throwable ) {
            return null;
        }
    }

}
if ( !function_exists( 'cartpops_freemius_registration_is_current' ) ) {
    /**
     * Require the captured candidate through the global and both public accessors.
     *
     * @param \CartPops\Licensing\FreemiusRuntime $authority Captured authority.
     */
    function cartpops_freemius_registration_is_current(  \CartPops\Licensing\FreemiusRuntime $authority  ) : bool {
        global $fs_cartpops, $cartpops_freemius_registration_authority;
        $candidate = $authority->registration_candidate();
        if ( $cartpops_freemius_registration_authority !== $authority || !isset( $fs_cartpops ) || $fs_cartpops !== $candidate || !$authority->registration_is_current( $fs_cartpops ) ) {
            return false;
        }
        return fs_cartpops() === $candidate && cartpops_fs() === $candidate;
    }

}
// Composer records immutable package provenance; this explicit exact path is
// the reviewed production SDK boundary. Composer may already have loaded it
// through its files autoload, so require_once is intentionally idempotent.
$cartpops_freemius_sdk_start = CARTPOPS_PATH . 'vendor/freemius/wordpress-sdk/start.php';
if ( !is_readable( $cartpops_freemius_sdk_start ) ) {
    $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_package_unavailable';
} else {
    try {
        require_once $cartpops_freemius_sdk_start;
        if ( !function_exists( 'fs_dynamic_init' ) || !defined( 'WP_FS__SDK_VERSION' ) || !cartpops_freemius_runtime_version_is_supported( WP_FS__SDK_VERSION ) ) {
            $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_runtime_unavailable';
        }
    } catch ( \Throwable ) {
        $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_runtime_failure';
    }
}
if ( !function_exists( 'fs_cartpops' ) ) {
    /**
     * Return the canonical CartPops Freemius SDK instance.
     *
     * The legacy accessor name is public compatibility surface. Initialization
     * is attempted once, returns only the exact product runtime, and never logs
     * SDK/customer state or accepts a product secret.
     *
     * @return object|null
     */
    function fs_cartpops() : ?object {
        global $fs_cartpops, $cartpops_freemius_bootstrap_attempted, $cartpops_freemius_registration_authority;
        if ( isset( $GLOBALS['cartpops_freemius_bootstrap_error'] ) ) {
            return null;
        }
        if ( isset( $fs_cartpops ) && is_object( $fs_cartpops ) ) {
            $cartpops_freemius_bootstrap_attempted = true;
            $candidate = $fs_cartpops;
            if ( isset( $cartpops_freemius_registration_authority ) ) {
                if ( !$cartpops_freemius_registration_authority instanceof \CartPops\Licensing\FreemiusRuntime || $fs_cartpops !== $candidate || !$cartpops_freemius_registration_authority->registration_is_current( $candidate ) || $fs_cartpops !== $candidate ) {
                    $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_identity_mismatch';
                    return null;
                }
                return $candidate;
            }
            $authority = cartpops_freemius_registration_authority_for( $candidate );
            if ( !$authority instanceof \CartPops\Licensing\FreemiusRuntime || $fs_cartpops !== $candidate ) {
                $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_identity_mismatch';
                return null;
            }
            $cartpops_freemius_registration_authority = $authority;
            return $candidate;
        }
        if ( isset( $cartpops_freemius_bootstrap_attempted ) && true === $cartpops_freemius_bootstrap_attempted ) {
            return null;
        }
        $cartpops_freemius_bootstrap_attempted = true;
        if ( !function_exists( 'fs_dynamic_init' ) ) {
            $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_runtime_unavailable';
            return null;
        }
        try {
            // Freemius must receive the literal array to transform Free edition identity.
            $candidate = fs_dynamic_init( array(
                'id'               => '7061',
                'slug'             => 'cartpops',
                'premium_slug'     => 'cartpops-pro',
                'type'             => 'plugin',
                'public_key'       => 'pk_f71eea687152e554f27b743874cd0',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'trial'            => array(
                    'days'               => 14,
                    'is_require_payment' => true,
                ),
                'has_affiliation'  => 'selected',
                'menu'             => array(
                    'slug'    => 'cartpops',
                    'parent'  => 'woocommerce',
                    'contact' => false,
                    'support' => false,
                ),
                'is_live'          => true,
                'is_org_compliant' => true,
            ) );
            $authority = cartpops_freemius_registration_authority_for( $candidate );
            if ( !$authority instanceof \CartPops\Licensing\FreemiusRuntime ) {
                $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_identity_mismatch';
                return null;
            }
        } catch ( \Throwable ) {
            $GLOBALS['cartpops_freemius_bootstrap_error'] = 'sdk_runtime_failure';
            return null;
        }
        $fs_cartpops = $candidate;
        $cartpops_freemius_registration_authority = $authority;
        return $fs_cartpops;
    }

}
if ( !function_exists( 'cartpops_fs' ) ) {
    /** Internal release-contract alias for the canonical V1-compatible accessor. */
    function cartpops_fs() : ?object {
        return fs_cartpops();
    }

}
if ( !function_exists( 'cartpops_after_uninstall' ) ) {
    /** Preserve customer and Freemius data while removing executable schedules. */
    function cartpops_after_uninstall() : void {
        try {
            ( new \CartPops\Setup\UninstallCleanup() )->run( false );
        } catch ( \Throwable $cartpops_cleanup_failure ) {
            // Freemius owns uninstall reporting. Cleanup remains fail-safe and
            // deliberately emits no customer, licensing, or exception data.
            unset($cartpops_cleanup_failure);
        }
    }

}
if ( !function_exists( 'cartpops_edition_authority' ) ) {
    /** Return one stateless current-blog edition authority for this request. */
    function cartpops_edition_authority() : \CartPops\Licensing\EditionAuthority {
        static $authority = null;
        static $bound_sdk = null;
        $sdk = ( true === ($GLOBALS['cartpops_freemius_bootstrap_ready'] ?? false) ? fs_cartpops() : null );
        if ( !$authority instanceof \CartPops\Licensing\EditionAuthority || $sdk !== $bound_sdk ) {
            $authority = \CartPops\Licensing\EditionAuthority::from_sdk( $sdk );
            $bound_sdk = $sdk;
        }
        return $authority;
    }

}
if ( !function_exists( 'cartpops_freemius_bootstrap_notice' ) ) {
    /** Render one value-free SDK/bootstrap diagnostic to capable operators. */
    function cartpops_freemius_bootstrap_notice() : void {
        if ( !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_network_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html__( 'CartPops licensing could not initialize. Shared cart features remain available, but paid features are paused. Reinstall the complete CartPops package or contact support.', 'cartpops' ) . '</p></div>';
    }

}
// `cartpops_fs()` is the one reviewed initializer call graph used by release
// validation. `fs_cartpops()` remains the canonical public compatibility API.
// WordPress includes newly activated plugins from function scope, so bind the
// captured authority to its exact global before performing registration.
global $cartpops_freemius_registration_authority;
$cartpops_fs = cartpops_fs();
if ( is_object( $cartpops_fs ) ) {
    $cartpops_registration_error = null;
    try {
        if ( !$cartpops_freemius_registration_authority instanceof \CartPops\Licensing\FreemiusRuntime || $cartpops_freemius_registration_authority->registration_candidate() !== $cartpops_fs || !cartpops_freemius_registration_is_current( $cartpops_freemius_registration_authority ) ) {
            $cartpops_registration_error = 'sdk_registration_initial_drift';
            throw new \RuntimeException('Freemius registration authority drifted.');
        }
        $cartpops_freemius_registration_authority->set_registration_basename( __FILE__ );
        if ( !cartpops_freemius_registration_is_current( $cartpops_freemius_registration_authority ) ) {
            $cartpops_registration_error = 'sdk_registration_basename_drift';
            throw new \RuntimeException('Freemius registration authority drifted.');
        }
        $cartpops_freemius_registration_authority->register_captured_after_uninstall( 'cartpops_after_uninstall' );
        if ( !cartpops_freemius_registration_is_current( $cartpops_freemius_registration_authority ) ) {
            $cartpops_registration_error = 'sdk_registration_after_uninstall_drift';
            throw new \RuntimeException('Freemius registration authority drifted.');
        }
        $GLOBALS['cartpops_freemius_bootstrap_ready'] = true;
        do_action( 'fs_cartpops_loaded' );
    } catch ( \Throwable ) {
        $GLOBALS['cartpops_freemius_bootstrap_ready'] = false;
        $GLOBALS['cartpops_freemius_bootstrap_error'] = $cartpops_registration_error ?? 'sdk_registration_failure';
    }
}
if ( isset( $GLOBALS['cartpops_freemius_bootstrap_error'] ) ) {
    add_action( 'admin_notices', 'cartpops_freemius_bootstrap_notice' );
    if ( is_multisite() ) {
        add_action( 'network_admin_notices', 'cartpops_freemius_bootstrap_notice' );
    }
}
// Declare WooCommerce compatibility.
add_action( 'before_woocommerce_init', static function () : void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
// Activation is deliberately side-effect free. The ordinary Plugin bootstrap
// delegates edition-aware schema and cron convergence to the single Upgrader
// authority on the next normal request.
register_activation_hook( __FILE__, static function () : void {
    // Intentionally empty; activation must not bypass upgrade convergence.
} );
// Deactivation: stop scheduled execution without purging customer data.
if ( !function_exists( 'cartpops_deactivate' ) ) {
    /**
     * Stop CartPops-owned execution without deleting customer or licensing data.
     *
     * @param bool $network_wide Whether WordPress is deactivating across the network.
     */
    function cartpops_deactivate(  bool $network_wide = false  ) : void {
        try {
            $cleanup = new \CartPops\Setup\UninstallCleanup();
            if ( $network_wide && is_multisite() ) {
                $cleanup->run( false );
                return;
            }
            $cleanup->cleanup_current_site( false );
        } catch ( \Throwable $cartpops_cleanup_failure ) {
            // WordPress must be able to finish deactivation even when a scheduler
            // store is unavailable. Never expose customer or licensing state.
            unset($cartpops_cleanup_failure);
        }
    }

}
register_deactivation_hook( __FILE__, 'cartpops_deactivate' );
// Boot the plugin.
add_action( 'plugins_loaded', static function () use($cartpops_pre_sdk_legacy_install_evidence, $cartpops_pre_sdk_legacy_install_evidence_ready) : void {
    if ( !$cartpops_pre_sdk_legacy_install_evidence_ready ) {
        return;
    }
    // Recheck after activation changes: WordPress writes active_plugins only
    // after it has successfully included a newly activated plugin.
    if ( cartpops_editions_are_simultaneously_active() ) {
        cartpops_register_edition_collision_notice();
        return;
    }
    if ( !class_exists( 'WooCommerce' ) ) {
        return;
    }
    \CartPops\Plugin::instance( null, cartpops_edition_authority(), $cartpops_pre_sdk_legacy_install_evidence );
    do_action( 'cartpops_loaded' );
} );