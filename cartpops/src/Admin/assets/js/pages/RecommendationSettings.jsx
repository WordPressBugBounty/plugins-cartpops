/**
 * Basic WooCommerce recommendation settings.
 */

import { __ } from '@wordpress/i18n';
import {
	ToggleControl,
	SelectControl,
	RangeControl,
	TextControl,
	Button,
} from '@wordpress/components';
import { isProFeature } from '../feature-editions';
import {
	recommendationFallbackOptions,
	recommendationStrategyOptions,
} from '../recommendation-options';

const upgradeUrl = window.cartpopsAdmin?.upgradeUrl || '';
const customRecommendationsArePro = isProFeature( 'custom-recommendations' );

export default function RecommendationSettings( {
	settings,
	updateSettings,
	CustomSelection,
	ButtonPresentation,
} ) {
	const recs = settings?.recommendations || {};

	return (
		<div className="cpops-page">
			<div className="cpops-page__header">
				<h2>{ __( 'Product Recommendations', 'cartpops' ) }</h2>
				<p className="cpops-page__description">
					{ __(
						'Show relevant product suggestions in the cart drawer to boost average order value.',
						'cartpops'
					) }
				</p>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'General', 'cartpops' ) }
				</h3>

				<ToggleControl
					label={ __( 'Enable Recommendations', 'cartpops' ) }
					checked={ recs.enabled || false }
					onChange={ ( value ) =>
						updateSettings( 'recommendations.enabled', value )
					}
				/>

				<SelectControl
					label={ __( 'Strategy', 'cartpops' ) }
					help={ __(
						'How to select which products to recommend.',
						'cartpops'
					) }
					value={ recs.strategy || 'cross_sell' }
					options={ recommendationStrategyOptions(
						{
							crossSells: __( 'Cross-sells', 'cartpops' ),
							upsells: __( 'Upsells', 'cartpops' ),
							customProducts: __(
								'Custom products (Pro)',
								'cartpops'
							),
						},
						customRecommendationsArePro && ! CustomSelection
					) }
					onChange={ ( value ) =>
						updateSettings( 'recommendations.strategy', value )
					}
				/>

				{ customRecommendationsArePro && ! CustomSelection && (
					<div className="cpops-settings-help">
						<p>
							{ __(
								'Choose specific recommendation products with CartPops Pro. Cross-sells and upsells remain available in the Free edition.',
								'cartpops'
							) }
						</p>
						{ upgradeUrl && (
							<Button
								variant="primary"
								href={ upgradeUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Upgrade to CartPops Pro', 'cartpops' ) }
							</Button>
						) }
					</div>
				) }

				{ CustomSelection && recs.strategy === 'custom' && (
					<CustomSelection
						productIds={ recs.custom_product_ids || [] }
						onChange={ ( productIds ) =>
							updateSettings(
								'recommendations.custom_product_ids',
								productIds
							)
						}
					/>
				) }

				<SelectControl
					label={ __( 'Fallback Strategy', 'cartpops' ) }
					help={ __(
						'Used when the primary strategy returns no results.',
						'cartpops'
					) }
					value={ recs.fallback || 'random' }
					options={ recommendationFallbackOptions( {
						randomProducts: __( 'Random products', 'cartpops' ),
						upsells: __( 'Upsells', 'cartpops' ),
						crossSells: __( 'Cross-sells', 'cartpops' ),
						none: __( 'None', 'cartpops' ),
					} ) }
					onChange={ ( value ) =>
						updateSettings( 'recommendations.fallback', value )
					}
				/>

				<RangeControl
					label={ __( 'Number of products', 'cartpops' ) }
					value={ recs.limit ?? 4 }
					onChange={ ( value ) =>
						updateSettings( 'recommendations.limit', value )
					}
					min={ 1 }
					max={ 8 }
					step={ 1 }
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Display', 'cartpops' ) }
				</h3>

				<SelectControl
					label={ __( 'Layout', 'cartpops' ) }
					value={ recs.layout || 'horizontal' }
					options={ [
						{
							label: __( 'Horizontal Scroll', 'cartpops' ),
							value: 'horizontal',
						},
						{ label: __( 'Grid', 'cartpops' ), value: 'grid' },
						{ label: __( 'List', 'cartpops' ), value: 'list' },
					] }
					onChange={ ( value ) =>
						updateSettings( 'recommendations.layout', value )
					}
				/>

				<TextControl
					label={ __( 'Heading', 'cartpops' ) }
					value={ recs.heading || '' }
					placeholder={ __( 'You may also like', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'recommendations.heading', value )
					}
				/>

				{ ButtonPresentation ? (
					<ButtonPresentation
						settings={ settings }
						updateSettings={ updateSettings }
					/>
				) : (
					<div className="cpops-settings-help">
						<p>
							{ __(
								'Recommendation add buttons use the accessible icon presentation. Text and text-with-icon buttons are available with CartPops Pro.',
								'cartpops'
							) }
						</p>
						{ upgradeUrl && (
							<Button
								variant="secondary"
								href={ upgradeUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Explore Pro button styles',
									'cartpops'
								) }
							</Button>
						) }
					</div>
				) }
			</div>
		</div>
	);
}
