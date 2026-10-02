/**
 * Stable recommendation selectors shared by the settings UI and unit tests.
 */

export function recommendationStrategyOptions( labels, customDisabled ) {
	return [
		{ label: labels.crossSells, value: 'cross_sell' },
		{ label: labels.upsells, value: 'upsell' },
		{
			label: labels.customProducts,
			value: 'custom',
			disabled: customDisabled,
		},
	];
}

export function recommendationFallbackOptions( labels ) {
	return [
		{ label: labels.randomProducts, value: 'random' },
		{ label: labels.upsells, value: 'upsell' },
		{ label: labels.crossSells, value: 'cross_sell' },
		{ label: labels.none, value: 'none' },
	];
}
