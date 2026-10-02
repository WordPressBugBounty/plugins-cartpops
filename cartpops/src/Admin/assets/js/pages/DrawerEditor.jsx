/**
 * Unified Drawer Editor — sub-tabs left, live preview right.
 *
 * Combines Layout (drawer), Design, and Shipping Meter settings
 * into a single editor with persistent preview.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import DrawerSettings from './DrawerSettings';
import DesignSettings from './DesignSettings';
import DrawerPreview from '../components/DrawerPreview';
import UpgradePage from '../components/UpgradePage';
import { isProFeature } from '../feature-editions';

const TABS = [
	{ key: 'layout', label: __( 'Layout', 'cartpops' ) },
	{ key: 'design', label: __( 'Design', 'cartpops' ) },
	{
		key: 'shipping-meter',
		label: __( 'Shipping meter', 'cartpops' ),
		pro: isProFeature( 'shipping-meter' ),
	},
];

export default function DrawerEditor( {
	settings,
	updateSettings,
	updateSettingsBatch,
	ShippingMeterPanel,
	PreviewEnhancements,
	RecommendationButtonPreview,
	SecondaryActionPanel,
	initialTab = 'layout',
} ) {
	const [ activeTab, setActiveTab ] = useState( initialTab );

	const renderPanel = () => {
		switch ( activeTab ) {
			case 'design':
				return (
					<DesignSettings
						settings={ settings }
						updateSettings={ updateSettings }
						updateSettingsBatch={ updateSettingsBatch }
					/>
				);
			case 'shipping-meter':
				return ShippingMeterPanel ? (
					<ShippingMeterPanel
						settings={ settings }
						updateSettings={ updateSettings }
					/>
				) : (
					<UpgradePage
						feature={ __( 'Shipping meter', 'cartpops' ) }
					/>
				);
			default:
				return (
					<DrawerSettings
						settings={ settings }
						updateSettings={ updateSettings }
						SecondaryActionPanel={ SecondaryActionPanel }
					/>
				);
		}
	};

	return (
		<div className="cpops-page cpops-page--split">
			<div className="cpops-page__settings">
				{ /* Sub-tabs */ }
				<div className="cpops-editor-tabs">
					{ TABS.map( ( tab ) => (
						<button
							key={ tab.key }
							className={ `cpops-editor-tab ${
								activeTab === tab.key
									? 'cpops-editor-tab--active'
									: ''
							}` }
							onClick={ () => setActiveTab( tab.key ) }
							type="button"
						>
							{ tab.label }
							{ tab.pro && ! ShippingMeterPanel && (
								<span className="cpops-admin__pro-badge">
									PRO
								</span>
							) }
						</button>
					) ) }
				</div>

				{ /* Active panel content */ }
				{ renderPanel() }
			</div>

			{ /* Preview — always visible, shows everything */ }
			<div className="cpops-page__preview">
				<DrawerPreview
					settings={ settings }
					Enhancements={ PreviewEnhancements }
					RecommendationButtonPreview={ RecommendationButtonPreview }
				/>
			</div>
		</div>
	);
}
