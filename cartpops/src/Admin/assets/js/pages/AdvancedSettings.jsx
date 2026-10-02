/**
 * Advanced Settings page with sub-tabs.
 */

import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	TextareaControl,
	ToggleControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import IntegrationsSettings from './IntegrationsSettings';
import { triggerDisplayValue } from '../trigger-display';

const TABS = [
	{ key: 'general', label: __( 'General', 'cartpops' ) },
	{ key: 'integrations', label: __( 'Integrations', 'cartpops' ) },
];

export function PoweredBySettings( { general = {}, updateSettings } ) {
	const poweredByEnabled = general.powered_by === true;

	return (
		<div className="cpops-settings-group">
			<h3 className="cpops-settings-group__title">
				{ __( 'Powered by CartPops', 'cartpops' ) }
			</h3>

			<ToggleControl
				label={ __( 'Show the CartPops partner link', 'cartpops' ) }
				help={ __(
					'Opt in to show a CartPops referral link in the drawer footer. The link remains hidden until a valid partner code is saved.',
					'cartpops'
				) }
				checked={ poweredByEnabled }
				onChange={ ( value ) =>
					updateSettings( 'general.powered_by', value )
				}
			/>

			{ poweredByEnabled && (
				<TextControl
					label={ __( 'Partner code', 'cartpops' ) }
					help={ __(
						'Enter a positive base-10 integer of up to 20 digits. A valid code is required for the link to display.',
						'cartpops'
					) }
					type="text"
					inputMode="numeric"
					value={ general.powered_by_partner_code ?? '' }
					onChange={ ( value ) =>
						updateSettings(
							'general.powered_by_partner_code',
							value
						)
					}
				/>
			) }
		</div>
	);
}

export function ForceFragmentsRefreshSetting( {
	advanced = {},
	updateSettings,
} ) {
	return (
		<ToggleControl
			label={ __( 'Refresh cart data on page load', 'cartpops' ) }
			help={ __(
				'Requests fresh cart data on every page load. Use only when cached pages show stale cart contents.',
				'cartpops'
			) }
			checked={ advanced.force_fragments_refresh === true }
			onChange={ ( value ) =>
				updateSettings( 'advanced.force_fragments_refresh', value )
			}
		/>
	);
}

export default function AdvancedSettings( {
	settings,
	updateSettings,
	onResetSettings,
	isSettingsMutationPending = false,
	AnalyticsResetActions,
} ) {
	const [ activeTab, setActiveTab ] = useState( 'general' );
	const advanced = settings?.advanced || {};
	const general = settings?.general || {};
	const [ showReset, setShowReset ] = useState( false );
	const [ importStatus, setImportStatus ] = useState( null );
	const resetTriggerRef = useRef( null );
	const resetConfirmRef = useRef( null );
	const resetPendingStatusRef = useRef( null );
	const restoreResetTriggerFocusRef = useRef( false );

	useEffect( () => {
		if ( showReset ) {
			const focusTarget = isSettingsMutationPending
				? resetPendingStatusRef.current
				: resetConfirmRef.current;
			focusTarget?.focus();
			return;
		}

		if ( restoreResetTriggerFocusRef.current ) {
			restoreResetTriggerFocusRef.current = false;
			resetTriggerRef.current?.focus();
		}
	}, [ showReset, isSettingsMutationPending ] );

	const closeResetConfirmation = () => {
		restoreResetTriggerFocusRef.current = true;
		setShowReset( false );
	};

	const handleExport = useCallback( () => {
		apiFetch( { path: '/cartpops/v1/settings/export' } )
			.then( ( data ) => {
				const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], {
					type: 'application/json',
				} );
				const url = URL.createObjectURL( blob );
				const a = document.createElement( 'a' );
				a.href = url;
				a.download = `cartpops-settings-${ new Date()
					.toISOString()
					.slice( 0, 10 ) }.json`;
				a.click();
				URL.revokeObjectURL( url );
			} )
			.catch( () => {
				setImportStatus( {
					type: 'error',
					message: __( 'Export failed.', 'cartpops' ),
				} );
			} );
	}, [] );

	const handleImport = useCallback( () => {
		const input = document.createElement( 'input' );
		input.type = 'file';
		input.accept = '.json';
		input.onchange = ( e ) => {
			const file = e.target.files[ 0 ];
			if ( ! file ) {
				return;
			}

			const reader = new FileReader();
			reader.onload = ( event ) => {
				try {
					const data = JSON.parse( event.target.result );
					apiFetch( {
						path: '/cartpops/v1/settings/import',
						method: 'POST',
						data,
					} )
						.then( ( result ) => {
							setImportStatus( {
								type: 'success',
								message: __(
									'Settings imported! Reload to see changes.',
									'cartpops'
								),
							} );
							if ( result.settings ) {
								Object.entries( result.settings ).forEach(
									( [ section, values ] ) => {
										Object.entries( values ).forEach(
											( [ key, value ] ) => {
												updateSettings(
													`${ section }.${ key }`,
													value
												);
											}
										);
									}
								);
							}
						} )
						.catch( ( err ) => {
							setImportStatus( {
								type: 'error',
								message:
									err.message ||
									__( 'Import failed.', 'cartpops' ),
							} );
						} );
				} catch {
					setImportStatus( {
						type: 'error',
						message: __( 'Invalid JSON file.', 'cartpops' ),
					} );
				}
			};
			reader.readAsText( file );
		};
		input.click();
	}, [ updateSettings ] );

	const renderGeneralPanel = () => (
		<>
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'General', 'cartpops' ) }
				</h3>

				<ToggleControl
					label={ __( 'Enable CartPops', 'cartpops' ) }
					help={ __(
						'Disable to turn off all CartPops frontend functionality.',
						'cartpops'
					) }
					checked={ general.enabled !== false }
					onChange={ ( value ) =>
						updateSettings( 'general.enabled', value )
					}
				/>

				<SelectControl
					label={ __( 'Open drawer on', 'cartpops' ) }
					value={ triggerDisplayValue( general.trigger ) }
					options={ [
						{
							label: __( 'Add to cart', 'cartpops' ),
							value: 'add_to_cart',
						},
						{
							label: __( 'Launcher click only', 'cartpops' ),
							value: 'launcher',
						},
					] }
					onChange={ ( value ) =>
						updateSettings( 'general.trigger', value )
					}
				/>

				<ToggleControl
					label={ __(
						'Add to cart on product pages without reloading',
						'cartpops'
					) }
					help={ __(
						'Adds the product in the background so shoppers stay on the page. The drawer then opens if “Open drawer on” is set to Add to cart. Turn this off if your theme or another plugin already handles product-page add to cart.',
						'cartpops'
					) }
					checked={ general.product_page_ajax_add !== false }
					onChange={ ( value ) =>
						updateSettings( 'general.product_page_ajax_add', value )
					}
				/>
			</div>

			<PoweredBySettings
				general={ general }
				updateSettings={ updateSettings }
			/>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'WooCommerce Mini Cart', 'cartpops' ) }
				</h3>

				<SelectControl
					label={ __( 'Integration mode', 'cartpops' ) }
					help={ __(
						'Controls how CartPops interacts with the WooCommerce Mini Cart block.',
						'cartpops'
					) }
					value={
						general.mini_cart_mode === 'standalone'
							? 'standalone'
							: 'replace'
					}
					options={ [
						{
							label: __(
								'Replace — CartPops drawer replaces WC Mini Cart',
								'cartpops'
							),
							value: 'replace',
						},
						{
							label: __(
								'Standalone — Both operate independently',
								'cartpops'
							),
							value: 'standalone',
						},
					] }
					onChange={ ( value ) =>
						updateSettings( 'general.mini_cart_mode', value )
					}
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Custom CSS', 'cartpops' ) }
				</h3>

				<TextareaControl
					label={ __( 'Additional CSS', 'cartpops' ) }
					help={ __(
						'Add custom CSS to style the cart drawer. Uses the .cpops- prefix for all CartPops elements.',
						'cartpops'
					) }
					value={ advanced.custom_css || '' }
					onChange={ ( value ) =>
						updateSettings( 'advanced.custom_css', value )
					}
					rows={ 8 }
					className="cpops-code-editor"
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Performance', 'cartpops' ) }
				</h3>

				<ForceFragmentsRefreshSetting
					advanced={ advanced }
					updateSettings={ updateSettings }
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Import / Export', 'cartpops' ) }
				</h3>

				<p className="cpops-settings-group__description">
					{ __(
						'Export your CartPops settings to a JSON file for backup, or import settings from another site.',
						'cartpops'
					) }
				</p>

				<div className="cpops-import-export">
					<button
						className="cpops-btn cpops-btn--outline"
						onClick={ handleExport }
						type="button"
					>
						{ __( 'Export Settings', 'cartpops' ) }
					</button>
					<button
						className="cpops-btn cpops-btn--outline"
						onClick={ handleImport }
						type="button"
					>
						{ __( 'Import Settings', 'cartpops' ) }
					</button>
				</div>

				{ importStatus && (
					<div
						className={ `cpops-notice cpops-notice--${ importStatus.type }` }
						role="alert"
					>
						{ importStatus.message }
					</div>
				) }
			</div>

			<div className="cpops-settings-group cpops-settings-group--danger">
				<h3 className="cpops-settings-group__title">
					{ __( 'Reset', 'cartpops' ) }
				</h3>

				{ AnalyticsResetActions && <AnalyticsResetActions /> }

				{ ! showReset ? (
					<button
						className="cpops-btn cpops-btn--outline cpops-btn--danger"
						ref={ resetTriggerRef }
						onClick={ () => setShowReset( true ) }
						type="button"
					>
						{ __( 'Reset all settings to defaults', 'cartpops' ) }
					</button>
				) : (
					<div className="cpops-reset-confirm">
						<p>
							{ __(
								'Are you sure? This will reset all CartPops settings to their default values.',
								'cartpops'
							) }
						</p>
						<div className="cpops-reset-confirm__actions">
							<button
								className="cpops-btn cpops-btn--danger"
								disabled={ isSettingsMutationPending }
								ref={ resetConfirmRef }
								onClick={ async () => {
									if (
										typeof onResetSettings === 'function' &&
										( await onResetSettings() )
									) {
										closeResetConfirmation();
									}
								} }
								type="button"
							>
								{ __( 'Yes, reset everything', 'cartpops' ) }
							</button>
							<button
								className="cpops-btn cpops-btn--outline"
								disabled={ isSettingsMutationPending }
								onClick={ closeResetConfirmation }
								type="button"
							>
								{ __( 'Cancel', 'cartpops' ) }
							</button>
						</div>
						{ isSettingsMutationPending && (
							<p
								className="cpops-reset-confirm__status"
								ref={ resetPendingStatusRef }
								role="status"
								tabIndex={ -1 }
							>
								{ __(
									'Settings update in progress…',
									'cartpops'
								) }
							</p>
						) }
					</div>
				) }
			</div>
		</>
	);

	return (
		<div className="cpops-page">
			<div className="cpops-page__header">
				<h2>{ __( 'Advanced', 'cartpops' ) }</h2>
				<p className="cpops-page__description">
					{ __(
						'Fine-tune CartPops behavior, styling, and integrations.',
						'cartpops'
					) }
				</p>
			</div>

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
					</button>
				) ) }
			</div>

			{ activeTab === 'integrations' ? (
				<IntegrationsSettings
					settings={ settings }
					updateSettings={ updateSettings }
				/>
			) : (
				renderGeneralPanel()
			) }
		</div>
	);
}
