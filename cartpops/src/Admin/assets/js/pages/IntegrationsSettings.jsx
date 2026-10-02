/**
 * Integrations settings panel (used inside AdvancedSettings).
 */

import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';
import ColorPicker from '../components/ColorPicker';

const theme = window.cartpopsAdmin?.theme || {};

/* eslint-disable max-len */
const ICON_STYLES = [
	{
		key: 'cart',
		label: __( 'Cart', 'cartpops' ),
		svg: (
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<circle cx="9" cy="21" r="1" />
				<circle cx="20" cy="21" r="1" />
				<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
			</svg>
		),
	},
	{
		key: 'bag',
		label: __( 'Bag', 'cartpops' ),
		svg: (
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
				<line x1="3" y1="6" x2="21" y2="6" />
				<path d="M16 10a4 4 0 0 1-8 0" />
			</svg>
		),
	},
	{
		key: 'basket',
		label: __( 'Basket', 'cartpops' ),
		svg: (
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M2 10h20l-1.5 10a2 2 0 0 1-2 1.5H5.5a2 2 0 0 1-2-1.5L2 10z" />
				<path d="M6 10V6a6 6 0 0 1 12 0v4" />
				<line x1="12" y1="14" x2="12" y2="18" />
				<line x1="8" y1="14" x2="8" y2="18" />
				<line x1="16" y1="14" x2="16" y2="18" />
			</svg>
		),
	},
	{
		key: 'cart-2',
		label: __( 'Cart 2', 'cartpops' ),
		svg: (
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M6 2h12l3 7H3L6 2z" />
				<path d="M3 9v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9" />
				<path d="M9 13v4" />
				<path d="M15 13v4" />
			</svg>
		),
	},
	{
		key: 'bag-2',
		label: __( 'Bag 2', 'cartpops' ),
		svg: (
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M4 7h16a1 1 0 0 1 1 1v11a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V8a1 1 0 0 1 1-1z" />
				<path d="M8 7V5a4 4 0 0 1 8 0v2" />
			</svg>
		),
	},
	{
		key: 'basket-2',
		label: __( 'Basket 2', 'cartpops' ),
		svg: (
			<svg
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
			>
				<path d="M5.5 21h13a1 1 0 0 0 1-.88L21 10H3l1.5 10.12a1 1 0 0 0 1 .88z" />
				<path d="M3 10l5-8" />
				<path d="M21 10l-5-8" />
				<line x1="12" y1="10" x2="12" y2="21" />
				<line x1="7.5" y1="10" x2="8.5" y2="21" />
				<line x1="16.5" y1="10" x2="15.5" y2="21" />
			</svg>
		),
	},
];
/* eslint-enable max-len */

const SUPPORTED_THEMES = [
	{ slug: 'blocksy', name: 'Blocksy', method: 'Native filter' },
];

export default function IntegrationsSettings( { settings, updateSettings } ) {
	const integrations = settings?.integrations || {};
	const blocksy = integrations.blocksy || {};
	const isBlocksy = theme.slug === 'blocksy';
	const isSupported = SUPPORTED_THEMES.some( ( t ) => t.slug === theme.slug );

	return (
		<>
			{ /* Detected Theme */ }
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Theme', 'cartpops' ) }
				</h3>

				<div
					className={ `cpops-integration-banner ${
						isSupported ? 'cpops-integration-banner--supported' : ''
					}` }
				>
					<div className="cpops-integration-banner__icon">
						{ isSupported ? (
							<svg
								width="20"
								height="20"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
							>
								<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
								<polyline points="22 4 12 14.01 9 11.01" />
							</svg>
						) : (
							<svg
								width="20"
								height="20"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
							>
								<circle cx="12" cy="12" r="10" />
								<line x1="12" y1="8" x2="12" y2="12" />
								<line x1="12" y1="16" x2="12.01" y2="16" />
							</svg>
						) }
					</div>
					<div className="cpops-integration-banner__content">
						<strong>
							{ theme.name || __( 'Unknown Theme', 'cartpops' ) }
						</strong>
						{ theme.version && (
							<span className="cpops-integration-banner__version">
								v{ theme.version }
							</span>
						) }
						<span
							className={ `cpops-integration-banner__status ${
								isSupported
									? 'cpops-integration-banner__status--supported'
									: ''
							}` }
						>
							{ isSupported
								? __( 'Integration available', 'cartpops' )
								: __( 'No integration available', 'cartpops' ) }
						</span>
					</div>
				</div>
			</div>

			{ /* Blocksy Integration Settings */ }
			{ isBlocksy && (
				<>
					<div className="cpops-settings-group">
						<h3 className="cpops-settings-group__title">
							{ __( 'Cart Icon', 'cartpops' ) }
						</h3>

						<ToggleControl
							label={ __(
								'Replace header cart icon',
								'cartpops'
							) }
							help={ __(
								'Replace the Blocksy header cart icon with a CartPops icon.',
								'cartpops'
							) }
							checked={ blocksy.replace_icon || false }
							onChange={ ( value ) =>
								updateSettings(
									'integrations.blocksy.replace_icon',
									value
								)
							}
						/>

						{ blocksy.replace_icon && (
							<>
								<div className="cpops-icon-grid-label">
									{ __( 'Icon style', 'cartpops' ) }
								</div>
								<div className="cpops-icon-grid">
									{ ICON_STYLES.map( ( icon ) => (
										<button
											key={ icon.key }
											type="button"
											className={ `cpops-icon-option ${
												( blocksy.icon_style ||
													'cart' ) === icon.key
													? 'cpops-icon-option--active'
													: ''
											}` }
											onClick={ () =>
												updateSettings(
													'integrations.blocksy.icon_style',
													icon.key
												)
											}
											title={ icon.label }
										>
											{ icon.svg }
											<span className="cpops-icon-option__label">
												{ icon.label }
											</span>
										</button>
									) ) }
								</div>
							</>
						) }
					</div>

					{ blocksy.replace_icon && (
						<div className="cpops-settings-group">
							<h3 className="cpops-settings-group__title">
								{ __( 'Behavior', 'cartpops' ) }
							</h3>

							<ToggleControl
								label={ __(
									'Show item count badge',
									'cartpops'
								) }
								help={ __(
									'Display the number of items in the cart on the icon.',
									'cartpops'
								) }
								checked={ blocksy.show_count !== false }
								onChange={ ( value ) =>
									updateSettings(
										'integrations.blocksy.show_count',
										value
									)
								}
							/>

							<ToggleControl
								label={ __(
									'Open CartPops drawer on click',
									'cartpops'
								) }
								help={ __(
									'Click the header cart icon to open the CartPops drawer instead of the Blocksy cart panel.',
									'cartpops'
								) }
								checked={ blocksy.open_drawer !== false }
								onChange={ ( value ) =>
									updateSettings(
										'integrations.blocksy.open_drawer',
										value
									)
								}
							/>
						</div>
					) }

					{ blocksy.replace_icon && (
						<div className="cpops-settings-group">
							<h3 className="cpops-settings-group__title">
								{ __( 'Colors', 'cartpops' ) }
							</h3>
							<p className="cpops-settings-group__description">
								{ __(
									'Leave empty to inherit colors from the theme.',
									'cartpops'
								) }
							</p>
							<div className="cpops-color-grid">
								<ColorPicker
									label={ __( 'Icon Color', 'cartpops' ) }
									value={ blocksy.icon_color || '' }
									onChange={ ( value ) =>
										updateSettings(
											'integrations.blocksy.icon_color',
											value
										)
									}
								/>
								<ColorPicker
									label={ __(
										'Badge Background',
										'cartpops'
									) }
									value={ blocksy.badge_bg || '' }
									onChange={ ( value ) =>
										updateSettings(
											'integrations.blocksy.badge_bg',
											value
										)
									}
								/>
								<ColorPicker
									label={ __( 'Badge Text', 'cartpops' ) }
									value={ blocksy.badge_text || '' }
									onChange={ ( value ) =>
										updateSettings(
											'integrations.blocksy.badge_text',
											value
										)
									}
								/>
							</div>
						</div>
					) }

					{ /* Preview */ }
					{ blocksy.replace_icon && (
						<div className="cpops-settings-group">
							<h3 className="cpops-settings-group__title">
								{ __( 'Preview', 'cartpops' ) }
							</h3>
							<div className="cpops-icon-preview">
								<div className="cpops-icon-preview__mock-header">
									<span className="cpops-icon-preview__site-name">
										My Store
									</span>
									<div className="cpops-icon-preview__nav">
										<span>Shop</span>
										<span>About</span>
										<span>Contact</span>
									</div>
									<div
										className="cpops-icon-preview__cart-icon"
										style={
											blocksy.icon_color
												? { color: blocksy.icon_color }
												: undefined
										}
									>
										{
											ICON_STYLES.find(
												( i ) =>
													i.key ===
													( blocksy.icon_style ||
														'cart' )
											)?.svg
										}
										{ blocksy.show_count !== false && (
											<span
												className="cpops-icon-preview__badge"
												style={ {
													...( blocksy.badge_bg
														? {
																backgroundColor:
																	blocksy.badge_bg,
														  }
														: {} ),
													...( blocksy.badge_text
														? {
																color: blocksy.badge_text,
														  }
														: {} ),
												} }
											>
												3
											</span>
										) }
									</div>
								</div>
							</div>
						</div>
					) }
				</>
			) }

			{ /* Not Supported — Show supported themes list */ }
			{ ! isSupported && (
				<div className="cpops-settings-group">
					<h3 className="cpops-settings-group__title">
						{ __( 'Supported Themes', 'cartpops' ) }
					</h3>
					<p className="cpops-settings-group__description">
						{ __(
							'CartPops can integrate with these themes to replace the header cart icon:',
							'cartpops'
						) }
					</p>
					<div className="cpops-supported-themes">
						{ SUPPORTED_THEMES.map( ( t ) => (
							<div
								key={ t.slug }
								className="cpops-supported-theme"
							>
								<span className="cpops-supported-theme__name">
									{ t.name }
								</span>
								<span className="cpops-supported-theme__method">
									{ t.method }
								</span>
							</div>
						) ) }
						<div className="cpops-supported-theme cpops-supported-theme--coming">
							<span className="cpops-supported-theme__name">
								{ __( 'More themes coming soon', 'cartpops' ) }
							</span>
						</div>
					</div>
				</div>
			) }
		</>
	);
}
