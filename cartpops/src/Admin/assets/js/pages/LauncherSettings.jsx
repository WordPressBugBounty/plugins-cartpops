/**
 * Launcher Settings page — Split layout with visual preview.
 */

import { __ } from '@wordpress/i18n';
import {
	SelectControl,
	ToggleControl,
	RangeControl,
} from '@wordpress/components';
import ColorPicker from '../components/ColorPicker';
import LauncherPreview from '../components/LauncherPreview';
import PageExclusionsControl from '../components/PageExclusionsControl';

const LAUNCHER_COLOR_FIELDS = [
	{ key: 'background', label: __( 'Button Background', 'cartpops' ) },
	{ key: 'icon', label: __( 'Icon Color', 'cartpops' ) },
	{ key: 'badge_bg', label: __( 'Badge Background', 'cartpops' ) },
	{ key: 'badge_text', label: __( 'Badge Text', 'cartpops' ) },
];

const LAUNCHER_COLOR_DEFAULTS = {
	background: '#6f23e1',
	icon: '#ffffff',
	badge_bg: '#ef4444',
	badge_text: '#ffffff',
};

const INLINE_LAUNCHER_COLOR_FIELDS = [
	{
		key: 'background',
		label: __( 'Inline Background', 'cartpops' ),
		enableAlpha: true,
	},
	{ key: 'text', label: __( 'Inline Text', 'cartpops' ) },
	{
		key: 'badge_bg',
		label: __( 'Inline Badge Background', 'cartpops' ),
	},
	{ key: 'badge_text', label: __( 'Inline Badge Text', 'cartpops' ) },
];

const INLINE_LAUNCHER_COLOR_DEFAULTS = {
	background: 'rgba(255, 255, 255, 0)',
	text: '#000000',
	badge_bg: '#705aef',
	badge_text: '#ffffff',
};

export default function LauncherSettings( { settings, updateSettings } ) {
	const launcher = settings?.launcher || {};
	const menuLauncher = launcher.menu || {};
	const colors = launcher.colors || {};
	const inlineColors = launcher.inline_colors || {};

	return (
		<div className="cpops-page cpops-page--split">
			<div className="cpops-page__settings">
				<div className="cpops-page__header">
					<h2>{ __( 'Floating Cart Launcher', 'cartpops' ) }</h2>
					<p className="cpops-page__description">
						{ __(
							'Configure the floating cart button that opens the drawer.',
							'cartpops'
						) }
					</p>
				</div>

				{ /* Visibility */ }
				<div className="cpops-settings-group">
					<h3 className="cpops-settings-group__title">
						{ __( 'Visibility', 'cartpops' ) }
					</h3>

					<ToggleControl
						label={ __(
							'Show floating cart launcher',
							'cartpops'
						) }
						help={ __(
							'Display the floating cart button on the frontend.',
							'cartpops'
						) }
						checked={ launcher.enabled !== false }
						onChange={ ( value ) =>
							updateSettings( 'launcher.enabled', value )
						}
					/>

					<ToggleControl
						label={ __( 'Show item count', 'cartpops' ) }
						checked={ launcher.show_count !== false }
						onChange={ ( value ) =>
							updateSettings( 'launcher.show_count', value )
						}
					/>

					<ToggleControl
						label={ __( 'Show cart total', 'cartpops' ) }
						checked={ launcher.show_total || false }
						onChange={ ( value ) =>
							updateSettings( 'launcher.show_total', value )
						}
					/>

					{ launcher.show_count !== false && (
						<ToggleControl
							label={ __( 'Hide zero item count', 'cartpops' ) }
							checked={ launcher.hide_indicator_empty !== false }
							onChange={ ( value ) =>
								updateSettings(
									'launcher.hide_indicator_empty',
									value
								)
							}
						/>
					) }

					<ToggleControl
						label={ __( 'Hide when cart is empty', 'cartpops' ) }
						checked={ launcher.hide_empty || false }
						onChange={ ( value ) =>
							updateSettings( 'launcher.hide_empty', value )
						}
					/>

					<PageExclusionsControl
						pageIds={ launcher.hidden_page_ids }
						onChange={ ( pageIds ) =>
							updateSettings(
								'launcher.hidden_page_ids',
								pageIds
							)
						}
					/>
				</div>

				{ /* Position & Size */ }
				<div className="cpops-settings-group">
					<h3 className="cpops-settings-group__title">
						{ __( 'Position & Size', 'cartpops' ) }
					</h3>

					<SelectControl
						label={ __( 'Screen Position', 'cartpops' ) }
						value={ launcher.position || 'bottom_right' }
						options={ [
							{
								label: __( 'Bottom Right', 'cartpops' ),
								value: 'bottom_right',
							},
							{
								label: __( 'Bottom Left', 'cartpops' ),
								value: 'bottom_left',
							},
						] }
						onChange={ ( value ) =>
							updateSettings( 'launcher.position', value )
						}
					/>

					<RangeControl
						label={ __( 'Offset X (px)', 'cartpops' ) }
						value={ launcher.offset_x ?? 24 }
						onChange={ ( value ) =>
							updateSettings( 'launcher.offset_x', value )
						}
						min={ 0 }
						max={ 80 }
						step={ 4 }
					/>

					<RangeControl
						label={ __( 'Offset Y (px)', 'cartpops' ) }
						value={ launcher.offset_y ?? 24 }
						onChange={ ( value ) =>
							updateSettings( 'launcher.offset_y', value )
						}
						min={ 0 }
						max={ 80 }
						step={ 4 }
					/>

					<RangeControl
						label={ __( 'Size (px)', 'cartpops' ) }
						value={ launcher.size ?? 56 }
						onChange={ ( value ) =>
							updateSettings( 'launcher.size', value )
						}
						min={ 40 }
						max={ 80 }
						step={ 4 }
					/>
				</div>

				{ /* Appearance */ }
				<div className="cpops-settings-group">
					<h3 className="cpops-settings-group__title">
						{ __( 'Appearance', 'cartpops' ) }
					</h3>

					<SelectControl
						label={ __( 'Icon Style', 'cartpops' ) }
						value={ launcher.icon || 'cart' }
						options={ [
							{
								label: __( 'Shopping Cart', 'cartpops' ),
								value: 'cart',
							},
							{
								label: __( 'Shopping Bag', 'cartpops' ),
								value: 'bag',
							},
							{
								label: __( 'Basket', 'cartpops' ),
								value: 'basket',
							},
						] }
						onChange={ ( value ) =>
							updateSettings( 'launcher.icon', value )
						}
					/>

					<div className="cpops-color-grid">
						{ LAUNCHER_COLOR_FIELDS.map( ( field ) => (
							<ColorPicker
								key={ field.key }
								label={ field.label }
								value={
									colors[ field.key ] ||
									LAUNCHER_COLOR_DEFAULTS[ field.key ]
								}
								onChange={ ( value ) =>
									updateSettings(
										`launcher.colors.${ field.key }`,
										value
									)
								}
							/>
						) ) }
					</div>
				</div>

				{ /* Legacy navigation menu launcher */ }
				<section
					className="cpops-settings-group"
					aria-labelledby="cpops-menu-launcher-heading"
				>
					<h3
						className="cpops-settings-group__title"
						id="cpops-menu-launcher-heading"
					>
						{ __(
							'Navigation menu launcher (legacy)',
							'cartpops'
						) }
					</h3>
					<p className="cpops-page__description">
						{ __(
							'These settings affect existing navigation menu items with the cpops-cart-menu-item CSS class. They do not add a menu item.',
							'cartpops'
						) }
					</p>

					<SelectControl
						label={ __( 'Navigation menu icon', 'cartpops' ) }
						value={ menuLauncher.icon || 'bag' }
						options={ [
							{
								label: __( 'Shopping Cart', 'cartpops' ),
								value: 'cart',
							},
							{
								label: __( 'Shopping Bag', 'cartpops' ),
								value: 'bag',
							},
							{
								label: __( 'Basket', 'cartpops' ),
								value: 'basket',
							},
						] }
						onChange={ ( value ) =>
							updateSettings( 'launcher.menu.icon', value )
						}
					/>

					<SelectControl
						label={ __(
							'Navigation menu item count style',
							'cartpops'
						) }
						value={ menuLauncher.indicator || 'bubble' }
						options={ [
							{
								label: __( 'None', 'cartpops' ),
								value: 'none',
							},
							{
								label: __( 'Bubble', 'cartpops' ),
								value: 'bubble',
							},
							{
								label: __( 'Plain', 'cartpops' ),
								value: 'plain',
							},
						] }
						onChange={ ( value ) =>
							updateSettings( 'launcher.menu.indicator', value )
						}
					/>

					{ ( menuLauncher.indicator || 'bubble' ) !== 'none' && (
						<ToggleControl
							label={ __(
								'Hide zero item count in navigation menu',
								'cartpops'
							) }
							checked={
								menuLauncher.hide_indicator_empty || false
							}
							onChange={ ( value ) =>
								updateSettings(
									'launcher.menu.hide_indicator_empty',
									value
								)
							}
						/>
					) }

					<ToggleControl
						label={ __(
							'Show cart total in navigation menu',
							'cartpops'
						) }
						checked={ menuLauncher.show_total || false }
						onChange={ ( value ) =>
							updateSettings( 'launcher.menu.show_total', value )
						}
					/>

					<h4>{ __( 'Inline launcher appearance', 'cartpops' ) }</h4>
					<p className="cpops-page__description">
						{ __(
							'These colors apply to navigation menu, shortcode, and inline block launchers. Floating launcher colors remain separate.',
							'cartpops'
						) }
					</p>

					<div className="cpops-color-grid">
						{ INLINE_LAUNCHER_COLOR_FIELDS.map( ( field ) => (
							<ColorPicker
								key={ field.key }
								label={ field.label }
								value={
									inlineColors[ field.key ] ||
									INLINE_LAUNCHER_COLOR_DEFAULTS[ field.key ]
								}
								enableAlpha={ field.enableAlpha || false }
								onChange={ ( value ) =>
									updateSettings(
										`launcher.inline_colors.${ field.key }`,
										value
									)
								}
							/>
						) ) }
					</div>
				</section>
			</div>

			<div className="cpops-page__preview">
				<div className="cpops-preview-toolbar">
					<span className="cpops-preview-toolbar__label">
						{ __( 'Launcher previews', 'cartpops' ) }
					</span>
				</div>
				<LauncherPreview settings={ settings } />
			</div>
		</div>
	);
}
