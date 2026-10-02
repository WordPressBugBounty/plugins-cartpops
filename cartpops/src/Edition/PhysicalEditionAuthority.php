<?php
/**
 * Immutable physical package-edition authority.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Edition;

use CartPops\Pro\PhysicalEditionMarker;

/** Resolve physical source identity without SDK, site, option, or caller input. */
final class PhysicalEditionAuthority {
	private const EDITION_FREE    = 'free';
	private const EDITION_PRO     = 'pro';
	private const EDITION_UNKNOWN = 'unknown';

	private const SOURCE_RELATIVE_PATH     = 'src/Edition/PhysicalEditionAuthority.php';
	private const ENTRYPOINT_RELATIVE_PATH = 'cartpops.php';
	private const MARKER_RELATIVE_PATH     = 'src/Pro/PhysicalEditionMarker.php';
	private const PAID_ROOTS               = array( 'assets/build/Pro', 'src/Analytics', 'src/Pro' );

	/**
	 * Create one internally verified closed edition.
	 *
	 * @param string $edition One internally verified closed edition.
	 */
	private function __construct( private readonly string $edition ) {}

	/** Resolve the edition from the immutable package containing this class. */
	public static function from_package(): self {
		$package_root = dirname( __DIR__, 2 );
		if (
			! self::package_source_identity_is_exact( $package_root )
			|| ! self::bootstrap_identity_is_exact( $package_root )
		) {
			return new self( self::EDITION_UNKNOWN );
		}

		return self::resolve_package_root( $package_root );
	}

	/**
	 * Resolve one package root without exposing a caller-controlled runtime seam.
	 *
	 * @param string $candidate_root Candidate package root.
	 */
	private static function resolve_package_root( string $candidate_root ): self {
		$lexical_root = rtrim( $candidate_root, '/\\' );
		$package_root = realpath( $lexical_root );
		if (
			'' === $lexical_root
			|| false === $package_root
			|| $package_root !== $lexical_root
			|| ! self::directory_identity_is_exact( $package_root )
		) {
			return new self( self::EDITION_UNKNOWN );
		}
		$marker_path = $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, self::MARKER_RELATIVE_PATH );

		if ( ! self::path_entry_exists( $marker_path ) ) {
			return self::paid_roots_are_physically_absent( $package_root )
				? new self( self::EDITION_FREE )
				: new self( self::EDITION_UNKNOWN );
		}

		return self::marker_is_exact( $package_root, $marker_path )
			? new self( self::EDITION_PRO )
			: new self( self::EDITION_UNKNOWN );
	}

	/** Whether the exact physically removable Pro marker is authoritative. */
	public function has_paid_source(): bool {
		return self::EDITION_PRO === $this->edition;
	}

	/** Whether every declared paid source/build root is physically absent. */
	public function is_verified_free(): bool {
		return self::EDITION_FREE === $this->edition;
	}

	/** Return only one bounded diagnostic value. */
	public function edition(): string {
		return $this->edition;
	}

	/**
	 * Require every Freemius-removable source/build root to be entirely absent.
	 *
	 * @param string $package_root Canonical package root.
	 */
	private static function paid_roots_are_physically_absent( string $package_root ): bool {
		foreach ( self::PAID_ROOTS as $relative_root ) {
			$path = $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_root );
			if ( self::path_entry_exists( $path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prove the package root, loaded authority source, and plugin entrypoint.
	 *
	 * @param string $package_root Expected package root derived from this source.
	 */
	private static function package_source_identity_is_exact( string $package_root ): bool {
		if ( ! self::directory_identity_is_exact( $package_root ) ) {
			return false;
		}

		$source_path = $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, self::SOURCE_RELATIVE_PATH );
		$entrypoint  = $package_root . DIRECTORY_SEPARATOR . self::ENTRYPOINT_RELATIVE_PATH;
		if (
			! self::file_identity_is_exact( $source_path, __FILE__ )
			|| ! self::file_identity_is_exact( $entrypoint, $entrypoint )
		) {
			return false;
		}

		try {
			$reflection_file = ( new \ReflectionClass( self::class ) )->getFileName();
			return is_string( $reflection_file )
				&& self::file_identity_is_exact( $source_path, $reflection_file );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Treat bootstrap constants only as identities that must match loaded bytes.
	 *
	 * @param string $package_root Exact package root derived from this source.
	 */
	private static function bootstrap_identity_is_exact( string $package_root ): bool {
		if (
			! defined( 'CARTPOPS_PATH' )
			|| ! is_string( CARTPOPS_PATH )
			|| ! defined( 'CARTPOPS_FILE' )
			|| ! is_string( CARTPOPS_FILE )
		) {
			return false;
		}

		$declared_roots = array_values(
			array_unique(
				array(
					$package_root . DIRECTORY_SEPARATOR,
					$package_root . '/',
				)
			)
		);
		$entrypoint     = $package_root . DIRECTORY_SEPARATOR . self::ENTRYPOINT_RELATIVE_PATH;

		return in_array( CARTPOPS_PATH, $declared_roots, true )
			&& CARTPOPS_FILE === $entrypoint
			&& self::file_identity_is_exact( $entrypoint, CARTPOPS_FILE );
	}

	/**
	 * Require a canonical, non-symbolic directory and stable inode identity.
	 *
	 * @param string $path Exact directory path.
	 */
	private static function directory_identity_is_exact( string $path ): bool {
		if ( ! is_dir( $path ) || is_link( $path ) || realpath( $path ) !== $path ) {
			return false;
		}

		return self::path_and_entry_identity_match( $path );
	}

	/**
	 * Require an expected canonical file and the exact observed source spelling.
	 *
	 * @param string $expected_path Exact expected file path.
	 * @param string $observed_path Exact path reported by the runtime.
	 */
	private static function file_identity_is_exact( string $expected_path, string $observed_path ): bool {
		return $observed_path === $expected_path
			&& is_file( $expected_path )
			&& ! is_link( $expected_path )
			&& is_readable( $expected_path )
			&& realpath( $expected_path ) === $expected_path
			&& self::path_and_entry_identity_match( $expected_path );
	}

	/**
	 * Fail closed if canonical lookup and the exact directory entry diverge.
	 *
	 * @param string $path Exact canonical path.
	 */
	private static function path_and_entry_identity_match( string $path ): bool {
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

	/**
	 * Verify exact canonical path, file identity, class origin, and marker bytes.
	 *
	 * @param string $package_root Canonical package root.
	 * @param string $marker_path  Expected canonical marker path.
	 */
	private static function marker_is_exact( string $package_root, string $marker_path ): bool {
		if ( ! self::paid_roots_are_exact( $package_root ) ) {
			return false;
		}

		$canonical_marker = realpath( $marker_path );
		if (
			$canonical_marker !== $marker_path
			|| ! is_file( $marker_path )
			|| is_link( $marker_path )
			|| ! is_readable( $marker_path )
		) {
			return false;
		}

		// Fail closed across a package-file race without emitting an absolute path.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$path_stat = @stat( $marker_path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$entry_stat = @lstat( $marker_path );
		if (
			! is_array( $path_stat )
			|| ! is_array( $entry_stat )
			|| ! isset( $path_stat['dev'], $path_stat['ino'], $entry_stat['dev'], $entry_stat['ino'] )
			|| $path_stat['dev'] !== $entry_stat['dev']
			|| $path_stat['ino'] !== $entry_stat['ino']
		) {
			return false;
		}

		try {
			if ( ! class_exists( PhysicalEditionMarker::class ) ) {
				return false;
			}
			$reflection      = new \ReflectionClass( PhysicalEditionMarker::class );
			$reflection_file = $reflection->getFileName();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fail closed across a package-file race.
			$reflection_stat  = is_string( $reflection_file ) ? @stat( $reflection_file ) : false;
			$marker_constants = $reflection->getConstants();

			return $reflection->isFinal()
				&& $reflection_file === $marker_path
				&& is_array( $reflection_stat )
				&& isset( $reflection_stat['dev'], $reflection_stat['ino'] )
				&& $reflection_stat['dev'] === $path_stat['dev']
				&& $reflection_stat['ino'] === $path_stat['ino']
				&& array( 'IDENTITY' ) === array_keys( $marker_constants )
				&& 'cartpops-pro-source-v1' === $marker_constants['IDENTITY'];
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Require every declared paid source/build root and entry identity.
	 *
	 * @param string $package_root Canonical package root.
	 */
	private static function paid_roots_are_exact( string $package_root ): bool {
		foreach ( self::PAID_ROOTS as $relative_root ) {
			$path = $package_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_root );
			if ( ! self::directory_identity_is_exact( $path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a filesystem entry exists, including a broken symbolic link.
	 *
	 * @param string $path Exact entry path.
	 */
	private static function path_entry_exists( string $path ): bool {
		return file_exists( $path ) || is_link( $path );
	}
}
