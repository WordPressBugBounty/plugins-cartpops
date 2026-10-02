/**
 * Design settings panel (used inside DrawerEditor).
 */

import { __ } from '@wordpress/i18n';
import { RangeControl, SelectControl } from '@wordpress/components';
import ColorPicker from '../components/ColorPicker';

const PRESETS = {
	default: {
		label: __( 'Default', 'cartpops' ),
		colors: {
			primary: '#6f23e1',
			primary_text: '#ffffff',
			background: '#ffffff',
			text_primary: '#1a1a2e',
			border: '#e5e7eb',
			button_primary_bg: '#6f23e1',
			button_primary_text: '#ffffff',
		},
	},
	minimal: {
		label: __( 'Minimal', 'cartpops' ),
		colors: {
			primary: '#111827',
			primary_text: '#ffffff',
			background: '#ffffff',
			text_primary: '#111827',
			border: '#f3f4f6',
			button_primary_bg: '#111827',
			button_primary_text: '#ffffff',
		},
	},
	bold: {
		label: __( 'Bold', 'cartpops' ),
		colors: {
			primary: '#dc2626',
			primary_text: '#ffffff',
			background: '#ffffff',
			text_primary: '#1f2937',
			border: '#e5e7eb',
			button_primary_bg: '#dc2626',
			button_primary_text: '#ffffff',
		},
	},
	ocean: {
		label: __( 'Ocean', 'cartpops' ),
		colors: {
			primary: '#0ea5e9',
			primary_text: '#ffffff',
			background: '#f0f9ff',
			text_primary: '#0c4a6e',
			border: '#bae6fd',
			button_primary_bg: '#0ea5e9',
			button_primary_text: '#ffffff',
		},
	},
};

const COLOR_FIELDS = [
	{ key: 'primary', label: __( 'Primary', 'cartpops' ) },
	{ key: 'primary_text', label: __( 'Primary Text', 'cartpops' ) },
	{ key: 'background', label: __( 'Background', 'cartpops' ) },
	{ key: 'surface', label: __( 'Surface', 'cartpops' ) },
	{ key: 'text_primary', label: __( 'Text', 'cartpops' ) },
	{ key: 'text_secondary', label: __( 'Text Secondary', 'cartpops' ) },
	{ key: 'text_tertiary', label: __( 'Text Tertiary', 'cartpops' ) },
	{ key: 'border', label: __( 'Border', 'cartpops' ) },
	{ key: 'input_bg', label: __( 'Input Background', 'cartpops' ) },
	{ key: 'input_border', label: __( 'Input Border', 'cartpops' ) },
	{ key: 'input_text', label: __( 'Input Text', 'cartpops' ) },
	{ key: 'button_primary_bg', label: __( 'Button Background', 'cartpops' ) },
	{ key: 'button_primary_text', label: __( 'Button Text', 'cartpops' ) },
	{
		key: 'button_secondary_bg',
		label: __( 'Secondary Button Background', 'cartpops' ),
	},
	{
		key: 'button_secondary_text',
		label: __( 'Secondary Button Text', 'cartpops' ),
	},
	{
		key: 'quantity_button_bg',
		label: __( 'Quantity Button Background', 'cartpops' ),
	},
	{
		key: 'quantity_button_text',
		label: __( 'Quantity Button Text', 'cartpops' ),
	},
	{
		key: 'quantity_input_bg',
		label: __( 'Quantity Value Background', 'cartpops' ),
	},
	{
		key: 'quantity_input_border',
		label: __( 'Quantity Border', 'cartpops' ),
	},
	{
		key: 'quantity_input_text',
		label: __( 'Quantity Value Text', 'cartpops' ),
	},
	{
		key: 'recs_button_bg',
		label: __( 'Recommendations Button', 'cartpops' ),
	},
	{
		key: 'recs_button_text',
		label: __( 'Recommendations Button Icon', 'cartpops' ),
	},
	{
		key: 'recs_background',
		label: __( 'Recommendations Background', 'cartpops' ),
	},
	{
		key: 'recs_border',
		label: __( 'Recommendations Border', 'cartpops' ),
	},
	{ key: 'recs_text', label: __( 'Recommendations Text', 'cartpops' ) },
	{ key: 'sale', label: __( 'Sale Price', 'cartpops' ) },
	{ key: 'success', label: __( 'Success', 'cartpops' ) },
	{ key: 'danger', label: __( 'Danger', 'cartpops' ) },
];

const DARK_COLOR_FIELDS = [
	{
		key: 'background',
		label: __( 'Background', 'cartpops' ),
		fallback: '#1a1a2e',
	},
	{
		key: 'surface',
		label: __( 'Surface', 'cartpops' ),
		fallback: '#252542',
	},
	{
		key: 'text_primary',
		label: __( 'Text', 'cartpops' ),
		fallback: '#f9fafb',
	},
	{
		key: 'text_secondary',
		label: __( 'Text Secondary', 'cartpops' ),
		fallback: '#d1d5db',
	},
	{
		key: 'border',
		label: __( 'Border', 'cartpops' ),
		fallback: '#6b7280',
	},
	{
		key: 'input_bg',
		label: __( 'Input Background', 'cartpops' ),
		fallback: '#252542',
	},
	{
		key: 'input_border',
		label: __( 'Input Border', 'cartpops' ),
		fallback: '#6b7280',
	},
];

export default function DesignSettings( {
	settings,
	updateSettings,
	updateSettingsBatch,
} ) {
	const design = settings?.design || {};
	const colors = design.colors || {};
	const darkColors = design.colors_dark || {};
	const activePreset =
		Object.entries( PRESETS ).find( ( [ , preset ] ) =>
			Object.entries( preset.colors ).every(
				( [ key, value ] ) => colors[ key ] === value
			)
		)?.[ 0 ] || null;

	const applyPreset = ( presetKey ) => {
		const preset = PRESETS[ presetKey ];
		if ( ! preset ) {
			return;
		}
		if ( typeof updateSettingsBatch !== 'function' ) {
			return;
		}

		const updates = Object.fromEntries(
			Object.entries( preset.colors ).map( ( [ key, value ] ) => [
				`design.colors.${ key }`,
				value,
			] )
		);
		updates[ 'design.preset' ] = presetKey;
		updateSettingsBatch( updates );
	};

	return (
		<>
			{ /* Presets */ }
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Presets', 'cartpops' ) }
				</h3>
				<div className="cpops-presets">
					{ Object.entries( PRESETS ).map( ( [ key, preset ] ) => (
						<button
							key={ key }
							type="button"
							aria-pressed={ activePreset === key }
							className={ `cpops-preset ${
								activePreset === key
									? 'cpops-preset--active'
									: ''
							}` }
							onClick={ () => applyPreset( key ) }
							style={ {
								'--preset-primary': preset.colors.primary,
								'--preset-bg': preset.colors.background,
							} }
						>
							<span
								className="cpops-preset__swatch"
								style={ { background: preset.colors.primary } }
							/>
							<span className="cpops-preset__label">
								{ preset.label }
							</span>
						</button>
					) ) }
				</div>
			</div>

			{ /* Colors */ }
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Colors', 'cartpops' ) }
				</h3>
				<div className="cpops-color-grid">
					{ COLOR_FIELDS.map( ( field ) => (
						<ColorPicker
							key={ field.key }
							label={ field.label }
							value={ colors[ field.key ] || '#000000' }
							onChange={ ( value ) =>
								updateSettings(
									`design.colors.${ field.key }`,
									value
								)
							}
						/>
					) ) }
				</div>
			</div>

			{ /* Dark mode colors */ }
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Dark mode palette', 'cartpops' ) }
				</h3>
				<p>
					{ __(
						'Used for forced dark mode and when system dark mode is active. Primary branding remains shared with the light palette.',
						'cartpops'
					) }
				</p>
				<div className="cpops-color-grid">
					{ DARK_COLOR_FIELDS.map( ( field ) => (
						<ColorPicker
							key={ field.key }
							id={ `cpops-design-dark-${ field.key.replace(
								/_/g,
								'-'
							) }` }
							label={ field.label }
							value={ darkColors[ field.key ] || field.fallback }
							onChange={ ( value ) =>
								updateSettings(
									`design.colors_dark.${ field.key }`,
									value
								)
							}
						/>
					) ) }
				</div>
			</div>

			{ /* Overlay */ }
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Background Overlay', 'cartpops' ) }
				</h3>
				<ColorPicker
					label={ __( 'Color', 'cartpops' ) }
					value={ design.overlay_color || '#000000' }
					onChange={ ( value ) =>
						updateSettings( 'design.overlay_color', value )
					}
				/>
				<RangeControl
					label={ __( 'Opacity', 'cartpops' ) }
					value={ design.overlay_opacity ?? 50 }
					onChange={ ( value ) =>
						updateSettings( 'design.overlay_opacity', value )
					}
					min={ 0 }
					max={ 100 }
					step={ 1 }
				/>
			</div>

			{ /* Border Radius */ }
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Shape', 'cartpops' ) }
				</h3>
				<RangeControl
					label={ __( 'Border Radius', 'cartpops' ) }
					value={ design.border_radius ?? 8 }
					onChange={ ( value ) =>
						updateSettings( 'design.border_radius', value )
					}
					min={ 0 }
					max={ 32 }
					step={ 1 }
				/>

				<RangeControl
					label={ __( 'Button Border Radius', 'cartpops' ) }
					value={ design.button_border_radius ?? 8 }
					onChange={ ( value ) =>
						updateSettings( 'design.button_border_radius', value )
					}
					min={ 0 }
					max={ 32 }
					step={ 1 }
				/>

				<SelectControl
					label={ __( 'Dark Mode', 'cartpops' ) }
					value={ design.dark_mode || 'auto' }
					options={ [
						{
							label: __( 'Auto (follow system)', 'cartpops' ),
							value: 'auto',
						},
						{
							label: __( 'Always light', 'cartpops' ),
							value: 'light',
						},
						{
							label: __( 'Always dark', 'cartpops' ),
							value: 'dark',
						},
					] }
					onChange={ ( value ) =>
						updateSettings( 'design.dark_mode', value )
					}
				/>
			</div>
		</>
	);
}
