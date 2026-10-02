<?php
/**
 * Main plugin orchestrator.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops;

use CartPops\Admin\AdminPage;
use CartPops\Admin\SettingsController;
use CartPops\Admin\SettingsRepository;
use CartPops\Blocks\CartDrawer\DrawerRenderExtensionRegistry;
use CartPops\Cart\CartTotalsObserver;
use CartPops\Cart\DrawerRenderer;
use CartPops\Cart\StalePaidCartArtifactSanitizer;
use CartPops\Compatibility\ClassicThemeCompat;
use CartPops\I18n\Translator;
use CartPops\Compatibility\BlocksyIntegration;
use CartPops\Compatibility\MiniCartIntegration;
use CartPops\Licensing\EditionAuthority;
use CartPops\Licensing\PaidRuntimeGuard;
use CartPops\Edition\PhysicalEditionAuthority;
use CartPops\Frontend\FrontendRuntimePolicy;
use CartPops\Migration\PreSdkLegacyInstallEvidence;
use CartPops\Pro\ServiceGraph;
use CartPops\Recommendations\RecommendationEngine;
use CartPops\Recommendations\RecommendationsController;
use CartPops\REST\DrawerDataController;
use CartPops\Setup\Upgrader;
use CartPops\Setup\UpgradeOutcome;
use CartPops\StoreAPI\BlockIntegration;
use CartPops\StoreAPI\CartExtension;

/**
 * Main plugin orchestrator.
 */
final class Plugin {
	private const DRAWER_STYLE_HANDLE     = 'cartpops-cart-drawer-style';
	private const CUSTOM_CSS_STYLE_HANDLE = 'cartpops-drawer-custom-css';

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Upgrade boundary retained for the one permitted BUSY retry.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Customer-facing services may boot at most once.
	 *
	 * @var bool
	 */
	private bool $services_booted = false;

	/**
	 * BUSY may schedule exactly one bounded retry.
	 *
	 * @var bool
	 */
	private bool $upgrade_retry_scheduled = false;

	/**
	 * Value-free diagnostic code for a fail-closed boot or missing build.
	 *
	 * @var string
	 */
	private string $boot_diagnostic = '';

	/**
	 * Avoid duplicate admin notice hooks across a retry.
	 *
	 * @var bool
	 */
	private bool $boot_notice_registered = false;

	/**
	 * Block build root. An explicit path is an injectable filesystem seam for
	 * verification; ordinary runtime construction uses the packaged build.
	 *
	 * @var string
	 */
	private string $block_build_root;

	/**
	 * Current-blog authority; it resolves afresh and never caches entitlement.
	 *
	 * @var EditionAuthority
	 */
	private EditionAuthority $edition_authority;

	/**
	 * Per-callback current-blog guard and idempotent paid prerequisite boundary.
	 *
	 * @var PaidRuntimeGuard
	 */
	private PaidRuntimeGuard $paid_runtime_guard;

	/**
	 * Immutable package source identity, independent of SDK/site state.
	 *
	 * @var PhysicalEditionAuthority
	 */
	private PhysicalEditionAuthority $physical_edition_authority;

	/**
	 * Value-free V1 identity captured before the SDK may update its version row.
	 *
	 * @var PreSdkLegacyInstallEvidence
	 */
	private PreSdkLegacyInstallEvidence $pre_sdk_legacy_evidence;

	/**
	 * Physically removable owner of every paid factory and hook.
	 *
	 * @var ServiceGraph|null
	 */
	private ?ServiceGraph $paid_service_graph = null;

	/**
	 * Whether this request registered the paid service graph.
	 *
	 * @var bool
	 */
	private bool $paid_services_registered = false;

	/**
	 * Whether every paid hook and shared service extension was attached.
	 *
	 * @var bool
	 */
	private bool $paid_services_booted = false;

	/**
	 * Whether the paid REST edition guard was attached.
	 *
	 * @var bool
	 */
	private bool $paid_rest_guard_registered = false;

	/**
	 * Whether the paid drawer supplement was attached.
	 *
	 * @var bool
	 */
	private bool $paid_drawer_provider_registered = false;

	/**
	 * Prevent recursive activation while an entitlement seam changes context.
	 *
	 * @var bool
	 */
	private bool $paid_reconciliation_running = false;

	/**
	 * Whether an expected paid package failed its closed runtime manifest.
	 *
	 * @var bool
	 */
	private bool $paid_source_graph_invalid = false;

	/**
	 * Get the singleton plugin instance.
	 *
	 * @param string|null                      $block_build_root  Optional explicit block build root.
	 * @param EditionAuthority|null            $edition_authority Optional injected current-blog authority.
	 * @param PreSdkLegacyInstallEvidence|null $pre_sdk_legacy_evidence Optional pre-SDK V1 identity.
	 * @return self
	 */
	public static function instance( ?string $block_build_root = null, ?EditionAuthority $edition_authority = null, ?PreSdkLegacyInstallEvidence $pre_sdk_legacy_evidence = null ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $block_build_root, $edition_authority, $pre_sdk_legacy_evidence );
		}
		return self::$instance;
	}

	/**
	 * Return the already-booted instance without initializing plugin state.
	 */
	public static function current_instance(): ?self {
		return self::$instance;
	}

	/**
	 * Constructor. Wires up and boots the plugin services.
	 *
	 * @param string|null                      $block_build_root  Optional explicit block build root.
	 * @param EditionAuthority|null            $edition_authority Optional injected current-blog authority.
	 * @param PreSdkLegacyInstallEvidence|null $pre_sdk_legacy_evidence Optional pre-SDK V1 identity.
	 */
	private function __construct( ?string $block_build_root = null, ?EditionAuthority $edition_authority = null, ?PreSdkLegacyInstallEvidence $pre_sdk_legacy_evidence = null ) {
		$this->block_build_root           = null === $block_build_root
			? CARTPOPS_PATH . 'assets/build/'
			: trailingslashit( $block_build_root );
		$this->edition_authority          = $edition_authority ?? EditionAuthority::from_sdk( null );
		$this->pre_sdk_legacy_evidence    = $pre_sdk_legacy_evidence ?? PreSdkLegacyInstallEvidence::unknown();
		$this->physical_edition_authority = PhysicalEditionAuthority::from_package();
		$this->paid_runtime_guard         = new PaidRuntimeGuard(
			$this->edition_authority,
			fn(): bool => $this->paid_prerequisites_are_ready(),
			fn(): bool => $this->paid_services_booted
		);
		$this->container                  = new Container();
		$this->register_services();
		$this->boot();
	}

	/**
	 * Register all services in the container.
	 */
	private function register_services(): void {
		$this->container->singleton(
			EditionAuthority::class,
			fn() => $this->edition_authority
		);
		$this->container->singleton(
			PaidRuntimeGuard::class,
			fn() => $this->paid_runtime_guard
		);

		$this->container->singleton(
			SettingsRepository::class,
			static fn() => new SettingsRepository()
		);
		$this->container->singleton(
			FrontendRuntimePolicy::class,
			fn() => new FrontendRuntimePolicy(
				$this->container->get( SettingsRepository::class )
			)
		);

		$this->container->singleton(
			CartTotalsObserver::class,
			static fn() => new CartTotalsObserver()
		);

		$this->container->singleton(
			REST\CartStateController::class,
			fn() => new REST\CartStateController(
				$this->container->get( CartTotalsObserver::class ),
				$this->container->has( Cart\CartItemAuthoritativePresentationProjector::class )
					? $this->container->get( Cart\CartItemAuthoritativePresentationProjector::class )
					: null,
			)
		);

		$this->container->singleton(
			REST\RateLimiter::class,
			static fn() => new REST\RateLimiter()
		);

		$this->container->singleton(
			REST\NamespaceRateLimitMiddleware::class,
			fn() => new REST\NamespaceRateLimitMiddleware(
				$this->container->get( REST\RateLimiter::class )
			)
		);

		$this->container->singleton(
			SettingsController::class,
			fn() => new SettingsController(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( EditionAuthority::class )
			)
		);

		$this->container->singleton(
			AdminPage::class,
			fn() => new AdminPage(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( EditionAuthority::class )
			)
		);

		$this->container->singleton(
			CartExtension::class,
			fn() => new CartExtension(
				$this->container->get( SettingsRepository::class ),
				$this->container->has( Cart\CartItemAuthoritativePresentationProjector::class )
					? $this->container->get( Cart\CartItemAuthoritativePresentationProjector::class )
					: null,
			)
		);

		$this->container->singleton(
			BlockIntegration::class,
			static fn() => new BlockIntegration()
		);

		$this->container->singleton(
			DrawerRenderer::class,
			fn() => new DrawerRenderer(
				$this->container->get( SettingsRepository::class )
			)
		);

		$this->container->singleton(
			ClassicThemeCompat::class,
			fn() => new ClassicThemeCompat(
				$this->container->get( DrawerRenderer::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( FrontendRuntimePolicy::class )
			)
		);

		$this->container->singleton(
			Translator::class,
			static fn() => new Translator()
		);

		$this->container->singleton(
			RecommendationEngine::class,
			fn() => new RecommendationEngine(
				$this->container->get( SettingsRepository::class )
			)
		);

		$this->container->singleton(
			RecommendationsController::class,
			fn() => new RecommendationsController(
				$this->container->get( RecommendationEngine::class )
			)
		);

		$this->container->singleton(
			REST\BasicRecommendationsDrawerDataProvider::class,
			fn() => new REST\BasicRecommendationsDrawerDataProvider(
				$this->container->get( RecommendationEngine::class )
			)
		);

		$this->container->singleton(
			MiniCartIntegration::class,
			fn() => new MiniCartIntegration(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( FrontendRuntimePolicy::class )
			)
		);

		$this->container->singleton(
			BlocksyIntegration::class,
			fn() => new BlocksyIntegration(
				$this->container->get( SettingsRepository::class ),
				$this->container->get( FrontendRuntimePolicy::class )
			)
		);

		$this->container->singleton(
			DrawerDataController::class,
			fn() => new DrawerDataController(
				$this->drawer_data_providers()
			)
		);
		$this->container->singleton(
			DrawerRenderExtensionRegistry::class,
			static fn() => new DrawerRenderExtensionRegistry()
		);
		$this->container->singleton(
			Upgrader::class,
			fn() => new Upgrader( null, null, null, null, $this->pre_sdk_legacy_evidence )
		);

		$physical_paid = $this->physical_edition_authority->has_paid_source();
		if ( $physical_paid ) {
			add_action( 'switch_blog', array( $this, 'reconcile_paid_runtime_after_blog_switch' ), 1, 3 );
			$this->reconcile_paid_runtime();
		} elseif ( $this->physical_edition_authority->is_verified_free() ) {
			$this->container->singleton(
				StalePaidCartArtifactSanitizer::class,
				static fn() => new StalePaidCartArtifactSanitizer()
			);
		} else {
			$this->mark_paid_source_graph_invalid();
		}
	}

	/**
	 * Reconcile lazy paid activation after WordPress changes current-blog context.
	 *
	 * @param int    $new_blog_id      New current blog identifier.
	 * @param int    $previous_blog_id Previous current blog identifier.
	 * @param string $context          WordPress switch or restore context.
	 */
	public function reconcile_paid_runtime_after_blog_switch( int $new_blog_id, int $previous_blog_id, string $context ): void {
		unset( $new_blog_id, $previous_blog_id, $context );
		$this->reconcile_paid_runtime();
	}

	/** Load and register the exact paid graph only for a freshly entitled blog. */
	private function reconcile_paid_runtime(): bool {
		if ( $this->paid_services_registered ) {
			return $this->boot_paid_services_if_ready();
		}
		if ( $this->paid_reconciliation_running || ! $this->physical_edition_authority->has_paid_source() ) {
			return false;
		}

		try {
			$paid_entitled = $this->edition_authority->allows_paid_code();
		} catch ( \Throwable ) {
			$paid_entitled = false;
		}
		if ( ! $paid_entitled ) {
			return false;
		}
		if ( $this->paid_activation_lifecycle_was_missed() ) {
			$this->boot_diagnostic = 'paid_activation_lifecycle_missed';
			$this->register_boot_notice();
			return false;
		}

		$this->paid_reconciliation_running = true;
		try {
			if ( ! $this->paid_service_graph_is_complete() ) {
				$this->mark_paid_source_graph_invalid();
				return false;
			}

			$graph = new ServiceGraph(
				$this->container,
				$this->edition_authority,
				$this->paid_runtime_guard,
				$this->pre_sdk_legacy_evidence
			);
			$graph->register();
			$this->paid_service_graph       = $graph;
			$this->paid_services_registered = true;
			if ( isset( $this->upgrader ) ) {
				$this->upgrader = $this->container->get( Upgrader::class );
			}

			return $this->boot_paid_services_if_ready();
		} catch ( \Throwable ) {
			$this->mark_paid_source_graph_invalid();
			return false;
		} finally {
			$this->paid_reconciliation_running = false;
		}
	}

	/** Relevant WordPress lifecycle actions cannot be replayed safely mid-request. */
	private function paid_activation_lifecycle_was_missed(): bool {
		return did_action( 'init' ) > 0 || did_action( 'rest_api_init' ) > 0;
	}

	/** Record one bounded, value-free paid package failure. */
	private function mark_paid_source_graph_invalid(): void {
		$this->paid_source_graph_invalid = true;
		$this->boot_diagnostic           = 'paid_source_graph_invalid';
		$this->register_boot_notice();
	}

	/** Fail closed around graph discovery, parsing, and exact manifest validation. */
	private function paid_service_graph_is_complete(): bool {
		if ( ! $this->physical_edition_authority->has_paid_source() || ! $this->paid_service_graph_class_is_exact() ) {
			return false;
		}

		try {
			return ServiceGraph::runtime_manifest_is_valid();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/** Establish the exact package graph class before invoking its runtime API. */
	private function paid_service_graph_class_is_exact(): bool {
		try {
			$package = realpath( CARTPOPS_PATH );
			if ( false === $package || is_link( CARTPOPS_PATH ) ) {
				return false;
			}
			$package_root = rtrim( $package, '/\\' ) . DIRECTORY_SEPARATOR;
			if ( CARTPOPS_PATH !== $package_root ) {
				return false;
			}
			$path = $package_root . 'src' . DIRECTORY_SEPARATOR . 'Pro' . DIRECTORY_SEPARATOR . 'ServiceGraph.php';
			if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) || realpath( $path ) !== $path ) {
				return false;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A package-file race must fail closed without leaking its path.
			$before_stat = @stat( $path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A package-file race must fail closed without leaking its path.
			$before_lstat = @lstat( $path );
			if ( ! is_array( $before_stat ) || ! is_array( $before_lstat ) || ! self::same_file_identity( $before_stat, $before_lstat ) ) {
				return false;
			}

			$symbol_loaded = class_exists( ServiceGraph::class, false )
				|| interface_exists( ServiceGraph::class, false )
				|| trait_exists( ServiceGraph::class, false )
				|| enum_exists( ServiceGraph::class, false );
			if ( ! $symbol_loaded ) {
				require_once $path;
			}
			if ( ! class_exists( ServiceGraph::class, false ) ) {
				return false;
			}

			$reflection = new \ReflectionClass( ServiceGraph::class );
			$origin     = $reflection->getFileName();
			if (
				ServiceGraph::class !== $reflection->getName()
				|| ! $reflection->isFinal()
				|| $reflection->isInterface()
				|| $reflection->isTrait()
				|| $reflection->isEnum()
				|| $path !== $origin
			) {
				return false;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A package-file race must fail closed without leaking its path.
			$origin_stat = @stat( $origin );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A package-file race must fail closed without leaking its path.
			$after_stat = @stat( $path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A package-file race must fail closed without leaking its path.
			$after_lstat = @lstat( $path );
			return is_array( $origin_stat )
				&& is_array( $after_stat )
				&& is_array( $after_lstat )
				&& self::same_file_identity( $before_stat, $origin_stat )
				&& self::same_file_identity( $before_stat, $after_stat )
				&& self::same_file_identity( $after_stat, $after_lstat );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Verify two stat records identify the same exact file.
	 *
	 * @param array $left  First stat record.
	 * @param array $right Second stat record.
	 */
	private static function same_file_identity( array $left, array $right ): bool {
		return isset( $left['dev'], $left['ino'], $right['dev'], $right['ino'] )
			&& $left['dev'] === $right['dev']
			&& $left['ino'] === $right['ino'];
	}
	/**
	 * Compose the always-present shared drawer provider.
	 *
	 * Freemius removes the complete paid graph from a Free artifact. The shared
	 * route starts with the basic provider; paid activation appends its guarded
	 * supplement to the already-resolved controller.
	 *
	 * @return REST\DrawerDataProvider[] Edition-appropriate drawer providers.
	 */
	private function drawer_data_providers(): array {
		return array(
			$this->container->get( REST\BasicRecommendationsDrawerDataProvider::class ),
		);
	}

	/**
	 * Hook into WordPress and WooCommerce.
	 */
	private function boot(): void {
		// Migrate the current site's V1 settings before any frontend or admin
		// service can resolve SettingsRepository and observe V2 defaults.
		$this->upgrader = $this->container->get( Upgrader::class );
		$this->handle_upgrade_outcome( $this->upgrader->maybe_upgrade(), true );
	}

	/** One init callback is the only automatic retry allowed after BUSY. */
	public function retry_upgrade_and_boot(): void {
		if ( $this->services_booted ) {
			return;
		}
		$this->handle_upgrade_outcome( $this->upgrader->maybe_upgrade(), false );
	}

	/**
	 * Fail closed unless the complete per-site upgrade has a terminal outcome.
	 *
	 * @param UpgradeOutcome $outcome     Typed current-site upgrade result.
	 * @param bool           $allow_retry Whether BUSY may register one retry.
	 */
	private function handle_upgrade_outcome( UpgradeOutcome $outcome, bool $allow_retry ): void {
		if ( in_array( $outcome, array( UpgradeOutcome::COMPLETE, UpgradeOutcome::NOT_APPLICABLE ), true ) ) {
			$this->boot_diagnostic = $this->paid_source_graph_invalid ? 'paid_source_graph_invalid' : '';
			$this->upgrader->register( false, true );
			$this->boot_services();
			return;
		}

		$this->upgrader->register( false, false );
		if ( UpgradeOutcome::BUSY === $outcome && $allow_retry && ! $this->upgrade_retry_scheduled ) {
			$this->upgrade_retry_scheduled = true;
			$this->boot_diagnostic         = 'upgrade_busy';
			add_action( 'init', array( $this, 'retry_upgrade_and_boot' ), 20 );
		} else {
			$this->boot_diagnostic = 'upgrade_' . $outcome->value;
		}
		$this->register_boot_notice();
	}

	/** Hook customer-facing/admin services once, after upgrade convergence. */
	private function boot_services(): void {
		if ( $this->services_booted ) {
			return;
		}
		$this->services_booted = true;

		// i18n.
		$translator = $this->container->get( Translator::class );
		add_action( 'init', array( $translator, 'load_textdomain' ) );
		add_action( 'init', array( $translator, 'register_dynamic_strings' ) );

		// Register blocks.
		add_action( 'init', array( $this, 'register_blocks' ) );

		// Admin.
		if ( is_admin() ) {
			$admin_page = $this->container->get( AdminPage::class );
			add_action( 'admin_menu', array( $admin_page, 'register_menu' ) );
		}

		// REST API.
		REST\RestCors::register();
		$this->container->get( REST\NamespaceRateLimitMiddleware::class )->register();
		add_action( 'wp_scheduled_delete', array( REST\RateLimiter::class, 'cleanup_expired' ) );

		$settings_controller = $this->container->get( SettingsController::class );
		add_action( 'rest_api_init', array( $settings_controller, 'register_routes' ) );

		$recs_controller = $this->container->get( RecommendationsController::class );
		add_action( 'rest_api_init', array( $recs_controller, 'register_routes' ) );

		$drawer_data_controller = $this->container->get( DrawerDataController::class );
		add_action( 'rest_api_init', array( $drawer_data_controller, 'register_routes' ) );

		$coupon_controller = new REST\CouponController();
		add_action( 'rest_api_init', array( $coupon_controller, 'register_routes' ) );

		$cart_state_controller = $this->container->get( REST\CartStateController::class );
		add_action( 'rest_api_init', array( $cart_state_controller, 'register_routes' ) );

		// Classic WC AJAX add-to-cart: piggyback cart data on fragment response.
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_cart_fragment' ) );

		// WooCommerce Store API extension.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );

		// Classic theme support.
		$classic_compat = $this->container->get( ClassicThemeCompat::class );
		add_action( 'wp_footer', array( $classic_compat, 'render_drawer' ), 10 );
		add_action( 'wp_footer', array( $classic_compat, 'render_launcher' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $classic_compat, 'enqueue_assets' ), 20 );
		add_action( 'init', array( $classic_compat, 'register_shortcode' ) );

		// User-defined custom CSS (advanced settings). Hooked after
		// ClassicThemeCompat::enqueue_assets() (priority 20) so the drawer
		// style handle it enqueues is registered before we attach inline CSS.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_custom_css' ), 30 );

		// WC Mini Cart integration.
		$mini_cart = $this->container->get( MiniCartIntegration::class );
		$mini_cart->register();

		// Blocksy theme integration.
		if ( 'blocksy' === get_template() ) {
			$this->container->get( BlocksyIntegration::class )->init();
		}

		if ( null !== $this->paid_service_graph ) {
			$this->boot_paid_services_if_ready();
		} elseif ( $this->container->has( StalePaidCartArtifactSanitizer::class ) ) {
			$sanitizer = $this->container->get( StalePaidCartArtifactSanitizer::class );
			add_action( 'woocommerce_cart_loaded_from_session', array( $sanitizer, 'sanitize' ), 1, 1 );
		}
	}

	/** Attach the complete paid runtime exactly once after shared services boot. */
	private function boot_paid_services_if_ready(): bool {
		if ( $this->paid_services_booted ) {
			return true;
		}
		if ( ! $this->services_booted || null === $this->paid_service_graph ) {
			return false;
		}
		if ( $this->paid_activation_lifecycle_was_missed() ) {
			$this->boot_diagnostic = 'paid_activation_lifecycle_missed';
			$this->register_boot_notice();
			return false;
		}

		try {
			$this->paid_service_graph->boot();
			$this->paid_service_graph->extend_shared_services();
			if ( ! $this->paid_drawer_provider_registered ) {
				$this->container->get( DrawerDataController::class )
					->add_provider( $this->paid_service_graph->drawer_data_provider() );
				$this->paid_drawer_provider_registered = true;
			}
			if ( ! $this->paid_rest_guard_registered ) {
				add_filter( 'rest_pre_dispatch', array( $this->paid_runtime_guard, 'enforce_rest_edition' ), PHP_INT_MAX - 1, 3 );
				$this->paid_rest_guard_registered = true;
			}
		} catch ( \Throwable ) {
			$this->boot_diagnostic = 'paid_service_activation_failed';
			$this->register_boot_notice();
			return false;
		}

		$this->paid_services_booted = true;
		try {
			$this->paid_service_graph->register_drawer_render_extension();
		} catch ( \Throwable ) {
			$this->paid_services_booted = false;
			$this->boot_diagnostic      = 'paid_service_activation_failed';
			$this->register_boot_notice();
			return false;
		}
		return true;
	}

	/** Reverify idempotent schema/cron prerequisites when entitlement becomes usable. */
	private function paid_prerequisites_are_ready(): bool {
		if ( ! isset( $this->upgrader ) ) {
			return false;
		}
		$outcome = $this->upgrader->upgrade_current_site( true );
		$ready   = in_array( $outcome, array( UpgradeOutcome::COMPLETE, UpgradeOutcome::NOT_APPLICABLE ), true );
		if ( ! $ready ) {
			$this->boot_diagnostic = 'paid_prerequisites_' . $outcome->value;
			$this->register_boot_notice();
		}
		return $ready;
	}

	/** Whether paid rendering/execution is safe for the current blog now. */
	public function paid_runtime_available(): bool {
		if ( ! $this->paid_services_registered ) {
			$this->reconcile_paid_runtime();
		} elseif ( ! $this->paid_services_booted ) {
			$this->boot_paid_services_if_ready();
		}

		return $this->paid_services_booted && $this->paid_runtime_guard->allows_execution();
	}

	/**
	 * Enqueue user-defined custom CSS in a final dependency-backed style layer.
	 *
	 * The value is sanitized on save (SettingsRepository::sanitize_custom_css);
	 * we strip tags again here as defence in depth before output. The empty
	 * final handle depends on every active edition-default stylesheet, keeping
	 * arbitrary customer CSS last in both Free and Pro without coupling shared
	 * source to a physically removable asset path or handle.
	 */
	public function enqueue_custom_css(): void {
		if ( ! $this->container->get( FrontendRuntimePolicy::class )->is_enabled() ) {
			return;
		}

		$settings   = $this->container->get( SettingsRepository::class );
		$custom_css = (string) $settings->get( 'advanced.custom_css', '' );

		if ( '' === trim( $custom_css ) ) {
			return;
		}

		// The drawer style is the handle that is always enqueued on the
		// frontend whenever the drawer renders (see ClassicThemeCompat).
		$handle = self::DRAWER_STYLE_HANDLE;

		if ( ! wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
			return;
		}

		$dependencies = apply_filters( 'cartpops_custom_css_style_dependencies', array( $handle ) );
		$dependencies = $this->normalize_custom_css_style_dependencies( $dependencies, $handle );
		wp_register_style( self::CUSTOM_CSS_STYLE_HANDLE, '', $dependencies, CARTPOPS_VERSION );
		wp_enqueue_style( self::CUSTOM_CSS_STYLE_HANDLE );

		// Defence in depth: strip any HTML tags from the stored CSS on output.
		wp_add_inline_style( self::CUSTOM_CSS_STYLE_HANDLE, wp_strip_all_tags( $custom_css ) );
	}

	/**
	 * Keep the shared drawer stylesheet first and accept only bounded WP handles.
	 *
	 * @param mixed  $candidate     Filtered dependency value.
	 * @param string $shared_handle Required shared default handle.
	 * @return string[]
	 */
	private function normalize_custom_css_style_dependencies( mixed $candidate, string $shared_handle ): array {
		$dependencies = array( $shared_handle );
		if ( ! is_array( $candidate ) ) {
			return $dependencies;
		}

		foreach ( $candidate as $dependency ) {
			if (
				! is_string( $dependency )
				|| strlen( $dependency ) > 100
				|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/D', $dependency )
				|| in_array( $dependency, $dependencies, true )
			) {
				continue;
			}
			$dependencies[] = $dependency;
		}

		return $dependencies;
	}

	/** Register a capability-protected, value-free boot diagnostic. */
	private function register_boot_notice(): void {
		if ( $this->boot_notice_registered || ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', array( $this, 'render_boot_notice' ) );
		if ( is_multisite() && is_network_admin() ) {
			add_action( 'network_admin_notices', array( $this, 'render_boot_notice' ) );
		}
		$this->boot_notice_registered = true;
	}

	/** Render no customer values, settings, or entitlement state. */
	public function render_boot_notice(): void {
		if (
			'' === $this->boot_diagnostic
			|| ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_network_plugins' ) )
		) {
			return;
		}
		$message = sprintf(
			/* translators: %s: value-free CartPops diagnostic code. */
			__( 'CartPops is paused because its required upgrade or build validation did not complete. Original data was preserved. Diagnostic: %s.', 'cartpops' ),
			$this->boot_diagnostic
		);
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Register block assets and Gutenberg blocks.
	 *
	 * Blocks are registered from the build directory so that
	 * viewScriptModule "file:./view.js" resolves to the built ES module.
	 */
	public function register_blocks(): void {
		$build = $this->block_build_path();
		$url   = CARTPOPS_URL . 'assets/build/';
		foreach ( $this->block_definitions() as $directory => $slug ) {
			if ( ! $this->block_build_is_readable( $build, $directory, $slug ) ) {
				$this->boot_diagnostic = 'missing_block_build';
				$this->register_boot_notice();
				continue;
			}
			if ( ! $this->register_block_assets( $build, $url, $slug ) ) {
				$this->boot_diagnostic = 'invalid_block_build';
				$this->register_boot_notice();
				continue;
			}
			register_block_type( $build . 'Blocks/' . $directory );
		}
	}

	/** Resolve the packaged or explicitly injected build root. */
	private function block_build_path(): string {
		return $this->block_build_root;
	}

	/**
	 * Return metadata directories mapped to asset slugs.
	 *
	 * @return array<string, string> Metadata directory => asset slug.
	 */
	private function block_definitions(): array {
		return array(
			'CartDrawer'   => 'cart-drawer',
			'CartLauncher' => 'cart-launcher',
		);
	}

	/**
	 * Verify all metadata-referenced and registered files before WordPress sees the path.
	 *
	 * @param string $build     Build root.
	 * @param string $directory Metadata directory.
	 * @param string $slug      Asset directory slug.
	 */
	private function block_build_is_readable( string $build, string $directory, string $slug ): bool {
		$metadata_path = $build . 'Blocks/' . $directory . '/block.json';
		if ( ! is_readable( $metadata_path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local immutable release metadata, never a URL.
		$raw      = file_get_contents( $metadata_path );
		$metadata = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if (
			! is_array( $metadata )
			|| ! isset( $metadata['name'] )
			|| ! is_string( $metadata['name'] )
			|| 1 !== preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/D', $metadata['name'] )
		) {
			return false;
		}

		$required = array(
			$build . 'Blocks/' . $directory . '/render.php',
			$build . 'Blocks/' . $directory . '/view.js',
			$build . 'Blocks/' . $directory . '/view.asset.php',
			$build . 'Blocks/' . $slug . '/index.js',
			$build . 'Blocks/' . $slug . '/index.asset.php',
			$build . 'Blocks/' . $slug . '/style-style-index.css',
		);

		return array() === array_filter( $required, static fn( string $file ): bool => ! is_readable( $file ) );
	}

	/**
	 * Register editor script and style handles referenced in block.json.
	 *
	 * View script modules are auto-registered by WordPress from block.json
	 * via the "file:./view.js" reference.
	 *
	 * @param string $build Build root.
	 * @param string $url   Public build URL.
	 * @param string $slug  Asset directory slug.
	 */
	private function register_block_assets( string $build, string $url, string $slug ): bool {
		$editor_asset = $build . "Blocks/{$slug}/index.asset.php";
		try {
			$asset = require $editor_asset;
		} catch ( \Throwable ) {
			return false;
		}
		if (
			! is_array( $asset )
			|| ! isset( $asset['dependencies'], $asset['version'] )
			|| ! is_array( $asset['dependencies'] )
			|| ! is_string( $asset['version'] )
		) {
			return false;
		}

		wp_register_script(
			"cartpops-{$slug}-editor",
			$url . "Blocks/{$slug}/index.js",
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( "cartpops-{$slug}-editor", 'cartpops', CARTPOPS_PATH . 'languages' );
		wp_register_style(
			"cartpops-{$slug}-style",
			$url . "Blocks/{$slug}/style-style-index.css",
			array(),
			$asset['version']
		);

		return true;
	}

	/**
	 * Add CartPops cart state as a WooCommerce AJAX fragment.
	 *
	 * This piggybacks on the classic WC add-to-cart AJAX response,
	 * so the drawer can open with fresh cart data instantly —
	 * without a separate GET /cart round-trip.
	 *
	 * @param mixed $fragments Existing fragments.
	 * @return mixed Filtered fragments, or an unchanged malformed prior value.
	 */
	public function add_cart_fragment( mixed $fragments ): mixed {
		if ( ! $this->container->get( FrontendRuntimePolicy::class )->is_enabled() ) {
			return $fragments;
		}
		if ( ! is_array( $fragments ) ) {
			return $fragments;
		}
		foreach ( $fragments as $selector => $markup ) {
			if ( ! is_string( $selector ) || ! is_string( $markup ) ) {
				return $fragments;
			}
		}

		$cart                  = WC()->cart;
		$cart_state            = Cart\CartStateBuilder::build();
		$cart_state['coupons'] = $cart instanceof \WC_Cart
			? Cart\CouponSerializer::serialize( $cart )
			: array();

		$fragments['.cartpops-cart-json'] = '<script type="application/json" class="cartpops-cart-json">'
			. wp_json_encode( $cart_state )
			. '</script>';

		return $fragments;
	}

	/**
	 * Register WooCommerce Store API extension.
	 */
	public function register_store_api(): void {
		$cart_extension = $this->container->get( CartExtension::class );
		$cart_extension->register();

		$block_integration = $this->container->get( BlockIntegration::class );

		$blocks = array(
			'woocommerce_blocks_mini-cart_block_registration',
			'woocommerce_blocks_cart_block_registration',
			'woocommerce_blocks_checkout_block_registration',
		);

		foreach ( $blocks as $hook ) {
			add_action(
				$hook,
				static function ( $registry ) use ( $block_integration ) {
					$registry->register( $block_integration );
				}
			);
		}
	}

	/**
	 * Get the DI container.
	 */
	public function container(): Container {
		return $this->container;
	}
}
