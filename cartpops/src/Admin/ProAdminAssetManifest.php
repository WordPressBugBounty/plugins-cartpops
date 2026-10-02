<?php
/**
 * Closed package manifest for the physically removable Pro admin app.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Admin;

/** Single runtime authority for the complete Pro admin source and build graph. */
final class ProAdminAssetManifest {
	/** Exact authored source required by the Pro admin registry. */
	public const SOURCE_FILES = array(
		'src/Pro/Admin/assets/css/admin.scss',
		'src/Pro/Admin/assets/js/index.js',
		'src/Pro/Admin/assets/js/components/AnalyticsChart.jsx',
		'src/Pro/Admin/assets/js/components/AnalyticsResetActions.jsx',
		'src/Pro/Admin/assets/js/components/ConditionBuilder.jsx',
		'src/Pro/Admin/assets/js/components/ConversionFunnel.jsx',
		'src/Pro/Admin/assets/js/components/CustomRecommendationSelection.jsx',
		'src/Pro/Admin/assets/js/components/DashboardInsights.jsx',
		'src/Pro/Admin/assets/js/components/DrawerPreviewEnhancements.jsx',
		'src/Pro/Admin/assets/js/components/ProductSearch.jsx',
		'src/Pro/Admin/assets/js/components/product-selection.js',
		'src/Pro/Admin/assets/js/pages/AnalyticsSettings.jsx',
		'src/Pro/Admin/assets/js/pages/BundleBuilderSettings.jsx',
		'src/Pro/Admin/assets/js/pages/NotificationSettings.jsx',
		'src/Pro/Admin/assets/js/pages/ShippingMeterSettings.jsx',
		'src/Pro/Admin/assets/js/pages/SmartAddonsSettings.jsx',
		'src/Pro/Admin/assets/js/utils/analytics.js',
	);

	/** Exact wp-scripts output required by the Pro admin registry. */
	public const BUILD_FILES = array(
		'assets/build/Pro/admin/index.asset.php',
		'assets/build/Pro/admin/index.css',
		'assets/build/Pro/admin/index-rtl.css',
		'assets/build/Pro/admin/index.js',
	);

	/** Exact dependency handles emitted by the pinned wp-scripts build. */
	public const SCRIPT_DEPENDENCIES = array(
		'react',
		'react-dom',
		'react-jsx-runtime',
		'wp-api-fetch',
		'wp-components',
		'wp-element',
		'wp-i18n',
	);

	/** Prevent construction of this static manifest. */
	private function __construct() {}

	/**
	 * Return every authored and compiled Pro admin asset.
	 *
	 * @return list<string>
	 */
	public static function required_files(): array {
		return array_merge( self::SOURCE_FILES, self::BUILD_FILES );
	}
}
