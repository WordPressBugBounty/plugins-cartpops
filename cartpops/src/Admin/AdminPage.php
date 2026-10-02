<?php
/**
 * Registers the CartPops admin page and enqueues the React dashboard.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Admin;

use CartPops\Edition\PhysicalEditionAuthority;
use CartPops\Licensing\EditionAuthority;

/**
 * Registers the CartPops admin page and enqueues the React dashboard.
 */
final class AdminPage {
	/**
	 * Public pricing page for upgrade buttons.
	 *
	 * Freemius only registers its in-admin pricing page once the site has opted
	 * in or activated a license, so linking an unlicensed store there lands on
	 * "Sorry, you are not allowed to access this page."
	 */
	public const UPGRADE_URL = 'https://cartpops.com/pricing/';

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $repository       Settings repository.
	 * @param EditionAuthority   $edition_authority Current-blog edition authority.
	 */
	public function __construct(
		private readonly SettingsRepository $repository,
		private readonly EditionAuthority $edition_authority,
	) {}

	/**
	 * Register the admin menu page under WooCommerce.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'CartPops', 'cartpops' ),
			__( 'CartPops', 'cartpops' ),
			'manage_woocommerce',
			'cartpops',
			array( $this, 'render' ),
		);

		if ( $hook ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Render the root element for the React app.
	 */
	public function render(): void {
		echo '<div id="cartpops-admin-root" class="cartpops-admin"></div>';
	}

	/**
	 * Enqueue the React admin dashboard assets.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_cartpops' !== $hook_suffix ) {
			return;
		}

		$asset = $this->load_asset_metadata( 'assets/build/admin/index.asset.php' );
		if (
			null === $asset
			|| ! $this->exact_regular_file( 'assets/build/admin/index.css' )
			|| ! $this->exact_regular_file( 'assets/build/admin/index.js' )
		) {
			return;
		}

		$edition   = $this->edition_authority->current_state();
		$pro_asset = $this->load_pro_asset_metadata( $edition->allows_paid_code() );
		$pro_ready = null !== $pro_asset;

		$dependencies = $asset['dependencies'];
		if ( $pro_ready ) {
			wp_enqueue_script(
				'cartpops-pro-admin',
				CARTPOPS_URL . 'assets/build/Pro/admin/index.js',
				$pro_asset['dependencies'],
				$pro_asset['version'],
				true
			);
			wp_set_script_translations(
				'cartpops-pro-admin',
				'cartpops',
				CARTPOPS_PATH . 'languages'
			);
			$dependencies[] = 'cartpops-pro-admin';
		}

		wp_enqueue_script(
			'cartpops-admin',
			CARTPOPS_URL . 'assets/build/admin/index.js',
			array_values( array_unique( $dependencies ) ),
			$asset['version'],
			true
		);

		wp_set_script_translations( 'cartpops-admin', 'cartpops', CARTPOPS_PATH . 'languages' );

		wp_enqueue_style(
			'cartpops-admin',
			CARTPOPS_URL . 'assets/build/admin/index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		if ( $pro_ready ) {
			wp_enqueue_style(
				'cartpops-pro-admin',
				CARTPOPS_URL . 'assets/build/Pro/admin/index.css',
				array( 'cartpops-admin' ),
				$pro_asset['version']
			);
		}

		$payload = array(
			'restUrl'     => rest_url( 'cartpops/v1/' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'version'     => CARTPOPS_VERSION,
			'settings'    => $this->repository->all(),
			'defaults'    => $this->repository->defaults(),
			'isPro'       => $pro_ready,
			'edition'     => $edition->edition(),
			'entitlement' => $edition->entitlement()->jsonSerialize(),
			'upgradeUrl'  => self::UPGRADE_URL,
			'theme'       => array(
				'name'    => wp_get_theme()->get( 'Name' ),
				'slug'    => get_template(),
				'version' => wp_get_theme()->get( 'Version' ),
			),
			'currency'    => array(
				'symbol'             => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'position'           => get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals'           => wc_get_price_decimals(),
				'decimal_separator'  => wc_get_price_decimal_separator(),
				'thousand_separator' => wc_get_price_thousand_separator(),
			),
		);

		if ( $pro_ready ) {
			$this->add_admin_payload( 'cartpops-pro-admin', $payload );
		}
		$this->add_admin_payload( 'cartpops-admin', $payload );
	}

	/**
	 * Expose the admin payload with its JSON types intact.
	 *
	 * `wp_localize_script()` casts top-level scalars to strings, which turns
	 * `isPro => true` into `"1"` and fails the strict client-side Pro check.
	 *
	 * @param string               $handle  Registered script handle.
	 * @param array<string, mixed> $payload Admin payload.
	 */
	private function add_admin_payload( string $handle, array $payload ): void {
		$json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return;
		}

		wp_add_inline_script( $handle, 'var cartpopsAdmin = ' . $json . ';', 'before' );
	}

	/**
	 * Load and validate one wp-scripts asset metadata file.
	 *
	 * @param string                  $relative_file        Plugin-relative metadata path.
	 * @param array<int, string>|null $expected_dependencies Exact dependency list, when closed.
	 * @return array{dependencies: array<int, string>, version: string}|null
	 */
	private function load_asset_metadata( string $relative_file, ?array $expected_dependencies = null ): ?array {
		if ( ! $this->exact_regular_file( $relative_file ) ) {
			return null;
		}

		try {
			$asset = require $this->absolute_path( $relative_file );
		} catch ( \Throwable ) {
			return null;
		}

		if (
			! is_array( $asset )
			|| array( 'dependencies', 'version' ) !== array_keys( $asset )
			|| ! is_array( $asset['dependencies'] )
			|| ! array_is_list( $asset['dependencies'] )
			|| ! is_string( $asset['version'] )
			|| 1 !== preg_match( '/\A[0-9a-f]{20}\z/D', $asset['version'] )
		) {
			return null;
		}

		foreach ( $asset['dependencies'] as $dependency ) {
			if ( ! is_string( $dependency ) || '' === $dependency ) {
				return null;
			}
		}

		if ( count( $asset['dependencies'] ) !== count( array_unique( $asset['dependencies'] ) ) ) {
			return null;
		}
		if ( null !== $expected_dependencies && $expected_dependencies !== $asset['dependencies'] ) {
			return null;
		}

		return array(
			'dependencies' => $asset['dependencies'],
			'version'      => $asset['version'],
		);
	}

	/**
	 * Return Pro metadata only for exact physical/entitled and complete assets.
	 *
	 * @param bool $entitled Whether the fresh current-blog state allows paid code.
	 * @return array{dependencies: array<int, string>, version: string}|null
	 */
	private function load_pro_asset_metadata( bool $entitled ): ?array {
		if (
			! $entitled
			|| ! class_exists( PhysicalEditionAuthority::class )
			|| ! PhysicalEditionAuthority::from_package()->has_paid_source()
		) {
			return null;
		}

		$manifest = $this->load_pro_asset_manifest();
		if ( null === $manifest ) {
			return null;
		}

		$required_files = $manifest['required_files'];
		foreach ( $required_files as $relative_file ) {
			if ( ! $this->exact_regular_file( $relative_file ) ) {
				return null;
			}
		}

		return $this->load_asset_metadata(
			'assets/build/Pro/admin/index.asset.php',
			$manifest['dependencies']
		);
	}

	/**
	 * Load and validate the closed Pro admin asset manifest.
	 *
	 * @return array{required_files: list<string>, dependencies: list<string>}|null
	 */
	private function load_pro_asset_manifest(): ?array {
		$manifest_file = 'src/Admin/ProAdminAssetManifest.php';

		try {
			if (
				! class_exists( ProAdminAssetManifest::class )
				|| ! $this->exact_regular_file( $manifest_file )
				|| ( new \ReflectionClass( ProAdminAssetManifest::class ) )->getFileName()
					!== $this->absolute_path( $manifest_file )
			) {
				return null;
			}

			$required_files = ProAdminAssetManifest::required_files();
			$dependencies   = ProAdminAssetManifest::SCRIPT_DEPENDENCIES;
		} catch ( \Throwable ) {
			return null;
		}

		if (
			! array_is_list( $required_files )
			|| ! array_is_list( $dependencies )
			|| count( $required_files ) !== count( array_unique( $required_files ) )
			|| count( $dependencies ) !== count( array_unique( $dependencies ) )
		) {
			return null;
		}

		foreach ( array_merge( $required_files, $dependencies ) as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
		}

		return array(
			'required_files' => $required_files,
			'dependencies'   => $dependencies,
		);
	}

	/**
	 * Determine whether a plugin-relative path is one exact regular file.
	 *
	 * @param string $relative_file Plugin-relative path.
	 */
	private function exact_regular_file( string $relative_file ): bool {
		$root = $this->package_root();
		$file = $this->absolute_path( $relative_file );
		$real = realpath( $file );

		return null !== $root
			&& false !== $real
			&& $real === $file
			&& str_starts_with( $real, trailingslashit( $root ) )
			&& is_file( $real )
			&& is_readable( $real )
			&& ! is_link( $file )
			&& $this->path_and_entry_identity_match( $file );
	}

	/**
	 * Build one normalized absolute path from the configured plugin root.
	 *
	 * @param string $relative_file Plugin-relative path.
	 */
	private function absolute_path( string $relative_file ): string {
		$root = $this->package_root();
		return ( null === $root ? '' : trailingslashit( $root ) ) . ltrim( $relative_file, '/\\' );
	}

	/** Derive and verify the immutable package root containing this class. */
	private function package_root(): ?string {
		$root   = dirname( __DIR__, 2 );
		$source = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'AdminPage.php';

		try {
			$reflection_file = ( new \ReflectionClass( self::class ) )->getFileName();
		} catch ( \Throwable ) {
			return null;
		}

		return realpath( $root ) === $root
			&& ! is_link( $root )
			&& $this->path_and_entry_identity_match( $root )
			&& is_string( $reflection_file )
			&& $reflection_file === $source
			&& realpath( $source ) === $source
			&& ! is_link( $source )
			&& is_readable( $source )
			&& $this->path_and_entry_identity_match( $source )
			? $root
			: null;
	}

	/**
	 * Fail closed if canonical lookup and the exact path entry diverge.
	 *
	 * @param string $path Exact path to verify.
	 */
	private function path_and_entry_identity_match( string $path ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fail closed across a package-path race.
		$path_stat = @stat( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fail closed across a package-path race.
		$entry_stat = @lstat( $path );

		return is_array( $path_stat )
			&& is_array( $entry_stat )
			&& isset( $path_stat['dev'], $path_stat['ino'], $entry_stat['dev'], $entry_stat['ino'] )
			&& $path_stat['dev'] === $entry_stat['dev']
			&& $path_stat['ino'] === $entry_stat['ino'];
	}
}
