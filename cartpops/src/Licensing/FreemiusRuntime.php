<?php
/**
 * Narrow boundary around the external Freemius SDK instance.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Licensing;

/** Hide the SDK's untyped public surface from licensing callers. */
final class FreemiusRuntime {
	private const METHOD_PRODUCT_ID          = 'get_id';
	private const METHOD_SITE                = 'get_site';
	private const METHOD_PREMIUM_ENTITLEMENT = 'can_use_premium_code__premium_only';
	private const METHOD_PREMIUM_EDITION     = 'is_premium';
	private const METHOD_SET_BASENAME        = 'set_basename';
	private const METHOD_ADD_ACTION          = 'add_action';
	private const METHOD_INSTALL             = 'get_install_by_blog_id';
	private const METHOD_USER                = 'get_user';
	private const METHOD_REGISTERED          = 'is_registered';
	private const METHOD_SITE_URL            = 'get_unfiltered_site_url';

	private const REQUIRED_METHODS = array(
		self::METHOD_PRODUCT_ID,
		self::METHOD_SITE,
		self::METHOD_PREMIUM_ENTITLEMENT,
	);

	private const ALLOWED_METHODS = array(
		self::METHOD_PRODUCT_ID,
		self::METHOD_SITE,
		self::METHOD_PREMIUM_ENTITLEMENT,
		self::METHOD_PREMIUM_EDITION,
		self::METHOD_SET_BASENAME,
		self::METHOD_ADD_ACTION,
	);

	private const METHOD_ARGUMENT_COUNTS = array(
		self::METHOD_PRODUCT_ID          => 0,
		self::METHOD_SITE                => 0,
		self::METHOD_PREMIUM_ENTITLEMENT => 0,
		self::METHOD_PREMIUM_EDITION     => 0,
		self::METHOD_SET_BASENAME        => 2,
		self::METHOD_ADD_ACTION          => 2,
		self::METHOD_INSTALL             => 0,
		self::METHOD_USER                => 0,
		self::METHOD_REGISTERED          => 0,
		self::METHOD_SITE_URL            => 0,
	);

	private const ARGUMENT_BOTH_BOOLEANS   = 'both_booleans';
	private const ARGUMENT_STRING          = 'string';
	private const ARGUMENT_CALLABLE_STRING = 'callable_string';

	private const METHOD_ARGUMENT_CATEGORIES = array(
		self::METHOD_SET_BASENAME => array(
			self::ARGUMENT_BOTH_BOOLEANS,
			self::ARGUMENT_STRING,
		),
		self::METHOD_ADD_ACTION   => array(
			self::ARGUMENT_STRING,
			self::ARGUMENT_CALLABLE_STRING,
		),
	);

	/**
	 * Exact external candidate captured before registration side effects.
	 *
	 * @var object|null
	 */
	private ?object $registration_candidate = null;

	/**
	 * Exact canonical product representation.
	 *
	 * @var int|string|null
	 */
	private int|string|null $registration_product_id = null;

	/**
	 * Captured physical edition.
	 *
	 * @var bool|null
	 */
	private ?bool $registration_premium_edition = null;

	/**
	 * Expected positive CartPops product identity.
	 *
	 * @var int|null
	 */
	private ?int $registration_expected_product_id = null;

	/**
	 * Bind one external SDK instance.
	 *
	 * @param mixed $sdk External Freemius SDK instance.
	 */
	public function __construct( private readonly mixed $sdk ) {}

	/** Whether every exact SDK method needed for one resolution is callable. */
	public function is_available(): bool {
		foreach ( self::REQUIRED_METHODS as $method ) {
			if ( null === $this->method_callable( $method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Capture one immutable registration authority for this exact candidate.
	 *
	 * The authority binds the candidate object, exact canonical product
	 * representation, and physical-edition value before registration side
	 * effects. Returning null means the external candidate cannot safely serve
	 * the complete CartPops registration interface.
	 *
	 * @param int $expected_product_id Exact positive CartPops product identity.
	 */
	public function registration_authority( int $expected_product_id ): ?self {
		$authority = new self( $this->sdk );
		return $authority->capture_registration_snapshot( $expected_product_id )
			? $authority
			: null;
	}

	/**
	 * Return the exact external object captured for registration.
	 *
	 * @throws \RuntimeException When no authority was captured.
	 */
	public function registration_candidate(): object {
		if ( null === $this->registration_candidate ) {
			throw new \RuntimeException( 'Freemius registration authority is unavailable.' );
		}
		return $this->registration_candidate;
	}

	/**
	 * Return the captured physical edition without rereading mutable SDK state.
	 *
	 * @throws \RuntimeException When no authority was captured.
	 */
	public function registration_premium_edition(): bool {
		if ( null === $this->registration_premium_edition ) {
			throw new \RuntimeException( 'Freemius registration authority is unavailable.' );
		}
		return $this->registration_premium_edition;
	}

	/**
	 * Revalidate exact object identity, product representation, and edition.
	 *
	 * @param mixed $candidate Current global/accessor candidate.
	 */
	public function registration_is_current( mixed $candidate ): bool {
		if (
			null === $this->registration_candidate
			|| null === $this->registration_product_id
			|| null === $this->registration_premium_edition
			|| null === $this->registration_expected_product_id
			|| $candidate !== $this->registration_candidate
		) {
			return false;
		}

		$current = ( new self( $this->sdk ) )->registration_authority( $this->registration_expected_product_id );
		return $current instanceof self
			&& $current->registration_candidate === $this->registration_candidate
			&& $current->registration_product_id === $this->registration_product_id
			&& $current->registration_premium_edition === $this->registration_premium_edition;
	}

	/**
	 * Register the captured physical edition's exact plugin basename.
	 *
	 * @param string $plugin_file Exact plugin entrypoint.
	 */
	public function set_registration_basename( string $plugin_file ): void {
		$this->set_basename( $this->registration_premium_edition(), $plugin_file );
	}

	/**
	 * Register the exact data-preserving CartPops after-uninstall callback.
	 *
	 * @param callable-string $callback Exact named data-preserving cleanup callback.
	 */
	public function register_captured_after_uninstall( callable $callback ): void {
		$this->register_after_uninstall( 'after_uninstall', $callback );
	}

	/** Return the SDK product identity without coercion. */
	public function product_id(): mixed {
		return $this->invoke( self::METHOD_PRODUCT_ID );
	}

	/**
	 * Match only the integer or its exact canonical decimal-string form.
	 *
	 * Freemius 2.13.4 preserves the public configuration value type when it
	 * hydrates a fresh plugin entity. Older stored entities can hold the same
	 * identity as an integer, so both exact SDK representations are valid.
	 *
	 * @param int $expected Expected positive product identity.
	 */
	public function product_id_matches( int $expected ): bool {
		return self::canonical_integer_matches( $this->product_id(), $expected );
	}

	/** Return the SDK site's WordPress blog identity without coercion. */
	public function site_blog_id(): mixed {
		$site = $this->invoke( self::METHOD_SITE );
		if ( ! is_object( $site ) ) {
			return null;
		}

		$public_properties = get_object_vars( $site );

		return array_key_exists( 'blog_id', $public_properties )
			? $public_properties['blog_id']
			: null;
	}

	/**
	 * Match the SDK site to an exact current-blog integer identity.
	 *
	 * @param int $expected Expected positive current blog identity.
	 */
	public function site_blog_id_matches( int $expected ): bool {
		$site = $this->invoke( self::METHOD_SITE );
		if ( ! is_object( $site ) ) {
			return false;
		}
		$properties = get_object_vars( $site );
		if ( ! array_key_exists( 'blog_id', $properties ) ) {
			return false;
		}
		if ( null !== $properties['blog_id'] ) {
			return self::canonical_integer_matches( $properties['blog_id'], $expected );
		}

		return $this->single_site_matches( $properties, $expected );
	}

	/**
	 * Bind the SDK's null-blog single-site representation without inventing a blog ID.
	 *
	 * Freemius keeps a null blog_id on single-site installs. Its public persisted
	 * install lookup returns a clone, so compare canonical identity, not objects.
	 * Registration and ownership do not grant entitlement: the adapter still
	 * requires the canonical premium-code predicate after this check.
	 *
	 * @param array<string, mixed> $site     Public SDK site properties.
	 * @param int                  $expected Expected WordPress blog identity.
	 */
	private function single_site_matches( array $site, int $expected ): bool {
		if ( ! self::single_site_context_matches( $expected ) ) {
			return false;
		}
		foreach ( array( self::METHOD_INSTALL, self::METHOD_USER, self::METHOD_REGISTERED, self::METHOD_SITE_URL ) as $method ) {
			if ( null === $this->method_callable( $method ) ) {
				return false;
			}
		}
		if ( true !== $this->invoke( self::METHOD_REGISTERED ) ) {
			return false;
		}
		$install = $this->invoke( self::METHOD_INSTALL );
		$user    = $this->invoke( self::METHOD_USER );
		if ( ! is_object( $install ) || ! is_object( $user ) ) {
			return false;
		}
		$stored     = get_object_vars( $install );
		$owner      = get_object_vars( $user );
		$product_id = self::canonical_positive_integer( $this->product_id() );
		if ( ! array_key_exists( 'blog_id', $stored ) || null !== $stored['blog_id'] || null === $product_id ) {
			return false;
		}
		foreach ( array( 'id', 'plugin_id', 'user_id' ) as $key ) {
			$identity = self::canonical_positive_integer( $site[ $key ] ?? null );
			if ( null === $identity || ! self::canonical_integer_matches( $stored[ $key ] ?? null, $identity ) ) {
				return false;
			}
		}
		if (
			! self::canonical_integer_matches( $site['plugin_id'], $product_id )
			|| ! self::canonical_integer_matches( $owner['id'] ?? null, self::canonical_positive_integer( $site['user_id'] ) ?? 0 )
		) {
			return false;
		}
		// The SDK honors WP_SITEURL and excludes multilingual site-URL filters.
		$url = self::single_site_url( $this->invoke( self::METHOD_SITE_URL ) );
		return null !== $url
			&& self::single_site_url( $site['url'] ?? null ) === $url
			&& self::single_site_url( $stored['url'] ?? null ) === $url
			&& self::single_site_context_matches( $expected );
	}

	/**
	 * Require one unchanged, unswitched single-site WordPress context.
	 *
	 * @param int $expected Expected WordPress blog identity.
	 * @phpstan-impure External SDK calls can switch the WordPress context between reads.
	 */
	private static function single_site_context_matches( int $expected ): bool {
		return function_exists( 'is_multisite' )
			&& function_exists( 'get_current_blog_id' )
			&& false === is_multisite()
			&& get_current_blog_id() === $expected
			&& self::canonical_integer_matches( $GLOBALS['blog_id'] ?? null, $expected )
			&& false === ( $GLOBALS['switched'] ?? false )
			&& array() === ( $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	/**
	 * Match the SDK's protocol-insensitive, trailing-slash clone comparison only.
	 *
	 * @param mixed $url SDK or WordPress installation URL.
	 */
	private static function single_site_url( mixed $url ): ?string {
		if ( ! is_string( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) || str_contains( $url, '\\' ) ) {
			return null;
		}
		$parts = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url )
			: parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit fallback; WordPress uses wp_parse_url().
		if (
			! is_array( $parts )
			|| ! in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true )
			|| array() !== array_diff( array_keys( $parts ), array( 'scheme', 'host', 'port', 'path' ) )
		) {
			return null;
		}
		return rtrim( substr( $url, strpos( $url, '://' ) + 3 ), '/' ) . '/';
	}

	/**
	 * Read only the SDK's positive integer or exact decimal-string identity.
	 *
	 * @param mixed $identity Untyped SDK identity.
	 */
	private static function canonical_positive_integer( mixed $identity ): ?int {
		if ( ! is_int( $identity ) && ! is_string( $identity ) ) {
			return null;
		}
		$integer = (int) $identity;
		return self::canonical_integer_matches( $identity, $integer ) ? $integer : null;
	}

	/** Return the canonical premium-code predicate without coercion. */
	public function premium_code_entitlement(): mixed {
		return $this->invoke( self::METHOD_PREMIUM_ENTITLEMENT );
	}

	/** Return whether the SDK is executing the physical premium build. */
	public function premium_edition(): mixed {
		return $this->invoke( self::METHOD_PREMIUM_EDITION );
	}

	/**
	 * Register the exact physical plugin basename with Freemius.
	 *
	 * @param bool   $premium     Whether this is the physical premium build.
	 * @param string $plugin_file Exact plugin entrypoint.
	 */
	public function set_basename( bool $premium, string $plugin_file ): void {
		$this->invoke( self::METHOD_SET_BASENAME, $premium, $plugin_file );
	}

	/**
	 * Register V2's data-preserving cleanup through Freemius' uninstall hook.
	 *
	 * @param string   $hook     Exact supported Freemius hook.
	 * @param callable $callback Data-preserving cleanup callback.
	 * @throws \InvalidArgumentException When the hook is not after_uninstall.
	 */
	public function register_after_uninstall( string $hook, callable $callback ): void {
		if ( 'after_uninstall' !== $hook ) {
			throw new \InvalidArgumentException( 'Invalid Freemius uninstall hook.' );
		}
		$this->invoke( self::METHOD_ADD_ACTION, $hook, $callback );
	}

	/**
	 * Invoke one exact public SDK method.
	 *
	 * @param string $method       Approved SDK method name.
	 * @param mixed  ...$arguments Exact SDK method arguments.
	 * @return mixed
	 *
	 * @throws \RuntimeException When the SDK method is unavailable.
	 */
	private function invoke( string $method, mixed ...$arguments ): mixed {
		$callable = $this->method_callable( $method );
		if ( null === $callable ) {
			throw new \RuntimeException( 'Freemius runtime is unavailable.' );
		}

		return $callable( ...$arguments );
	}

	/**
	 * Return one approved SDK method as a callable closure.
	 *
	 * @param string $method Approved SDK method name.
	 */
	private function method_callable( string $method ): ?\Closure {
		if (
			! array_key_exists( $method, self::METHOD_ARGUMENT_COUNTS ) ||
			! is_object( $this->sdk )
		) {
			return null;
		}

		try {
			$reflected_method = new \ReflectionMethod( $this->sdk, $method );
			if (
				$method !== $reflected_method->getName() ||
				! $reflected_method->isPublic() ||
				$reflected_method->isStatic() !== ( self::METHOD_SITE_URL === $method ) ||
				$reflected_method->isAbstract() ||
				$reflected_method->returnsReference() ||
				$reflected_method->getNumberOfRequiredParameters() > self::METHOD_ARGUMENT_COUNTS[ $method ] ||
				(
					! $reflected_method->isVariadic() &&
					$reflected_method->getNumberOfParameters() < self::METHOD_ARGUMENT_COUNTS[ $method ]
				)
			) {
				return null;
			}
			foreach ( $reflected_method->getParameters() as $parameter ) {
				if ( $parameter->isPassedByReference() ) {
					return null;
				}
			}
			if ( ! self::method_accepts_supplied_categories( $method, $reflected_method ) ) {
				return null;
			}

			return $reflected_method->getClosure( $reflected_method->isStatic() ? null : $this->sdk );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Prove that reviewed supplied value categories satisfy declared parameter types.
	 *
	 * @param string            $method           Approved SDK method name.
	 * @param \ReflectionMethod $reflected_method Exact reflected SDK method.
	 */
	private static function method_accepts_supplied_categories(
		string $method,
		\ReflectionMethod $reflected_method
	): bool {
		$categories = self::METHOD_ARGUMENT_CATEGORIES[ $method ] ?? array();
		if ( count( $categories ) !== self::METHOD_ARGUMENT_COUNTS[ $method ] ) {
			return false;
		}
		$parameters = $reflected_method->getParameters();
		$variadic   = array() !== $parameters && $parameters[ count( $parameters ) - 1 ]->isVariadic()
			? $parameters[ count( $parameters ) - 1 ]
			: null;
		foreach ( $categories as $index => $category ) {
			$parameter = $parameters[ $index ] ?? $variadic;
			if (
				! $parameter instanceof \ReflectionParameter
				|| ! self::type_accepts_argument_category( $parameter->getType(), $category )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Test a complete supplied value category against one reflected type.
	 *
	 * @param \ReflectionType|null $type     Declared parameter type, if any.
	 * @param string               $category Reviewed supplied value category.
	 */
	private static function type_accepts_argument_category(
		?\ReflectionType $type,
		string $category
	): bool {
		return match ( $category ) {
			self::ARGUMENT_BOTH_BOOLEANS => self::type_accepts_atomic_category( $type, 'true' )
				&& self::type_accepts_atomic_category( $type, 'false' ),
			self::ARGUMENT_STRING => self::type_accepts_atomic_category( $type, 'string' ),
			self::ARGUMENT_CALLABLE_STRING => self::type_accepts_atomic_category( $type, 'callable_string' ),
			default => false,
		};
	}

	/**
	 * Test one concrete supplied category against named, union, or intersection types.
	 *
	 * @param \ReflectionType|null $type   Declared parameter type, if any.
	 * @param string               $atomic Concrete supplied category.
	 */
	private static function type_accepts_atomic_category(
		?\ReflectionType $type,
		string $atomic
	): bool {
		if ( null === $type ) {
			return true;
		}
		if ( $type instanceof \ReflectionUnionType ) {
			foreach ( $type->getTypes() as $member ) {
				if ( self::type_accepts_atomic_category( $member, $atomic ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! $type instanceof \ReflectionNamedType || ! $type->isBuiltin() ) {
			return false;
		}

		$name = $type->getName();
		return match ( $atomic ) {
			'true' => in_array( $name, array( 'mixed', 'bool', 'true' ), true ),
			'false' => in_array( $name, array( 'mixed', 'bool', 'false' ), true ),
			'string' => in_array( $name, array( 'mixed', 'string' ), true ),
			'callable_string' => in_array( $name, array( 'mixed', 'string', 'callable' ), true ),
			default => false,
		};
	}

	/**
	 * Capture the complete registration interface and immutable identity snapshot.
	 *
	 * @param int $expected_product_id Exact positive CartPops product identity.
	 */
	private function capture_registration_snapshot( int $expected_product_id ): bool {
		if ( 0 >= $expected_product_id || ! is_object( $this->sdk ) ) {
			return false;
		}
		foreach ( self::ALLOWED_METHODS as $method ) {
			if ( null === $this->method_callable( $method ) ) {
				return false;
			}
		}

		try {
			$product_id = $this->product_id();
			if (
				( ! is_int( $product_id ) && ! is_string( $product_id ) )
				|| ! self::canonical_integer_matches( $product_id, $expected_product_id )
			) {
				return false;
			}
			$premium_edition = $this->premium_edition();
			if ( ! is_bool( $premium_edition ) ) {
				return false;
			}

			$this->registration_candidate           = $this->sdk;
			$this->registration_expected_product_id = $expected_product_id;
			$this->registration_product_id          = $product_id;
			$this->registration_premium_edition     = $premium_edition;
			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Accept no numeric coercion beyond the SDK's two canonical ID types.
	 *
	 * @param mixed $actual   Untyped SDK identity.
	 * @param int   $expected Expected positive WordPress or product identity.
	 */
	private static function canonical_integer_matches( mixed $actual, int $expected ): bool {
		return 0 < $expected
			&& ( $expected === $actual || ( is_string( $actual ) && (string) $expected === $actual ) );
	}
}
