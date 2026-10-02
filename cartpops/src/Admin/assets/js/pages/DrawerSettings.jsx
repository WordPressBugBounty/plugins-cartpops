/**
 * Drawer Layout settings panel (used inside DrawerEditor).
 */

import { __ } from '@wordpress/i18n';
import {
	SelectControl,
	RangeControl,
	ToggleControl,
	TextControl,
	Button,
} from '@wordpress/components';
import { normalizeProductNameDisplay } from '../utils/productNameDisplay';

const upgradeUrl = window.cartpopsAdmin?.upgradeUrl || '';

export default function DrawerSettings( {
	settings,
	updateSettings,
	SecondaryActionPanel,
} ) {
	const drawer = settings?.drawer || {};
	const translatedDefaultHelp = __(
		'Optional. Leave blank to use the translated CartPops default.',
		'cartpops'
	);
	const hiddenByDefaultHelp = __(
		'Optional. Leave blank to hide this text.',
		'cartpops'
	);

	return (
		<>
			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Position & Size', 'cartpops' ) }
				</h3>

				<SelectControl
					label={ __( 'Position', 'cartpops' ) }
					value={ drawer.position || 'right' }
					options={ [
						{ label: __( 'Right', 'cartpops' ), value: 'right' },
						{ label: __( 'Left', 'cartpops' ), value: 'left' },
					] }
					onChange={ ( value ) =>
						updateSettings( 'drawer.position', value )
					}
				/>

				<RangeControl
					label={ __( 'Width (Desktop)', 'cartpops' ) }
					value={ drawer.width_desktop || 480 }
					onChange={ ( value ) =>
						updateSettings( 'drawer.width_desktop', value )
					}
					min={ 50 }
					max={ 1400 }
					step={ 1 }
				/>

				<RangeControl
					label={ __( 'Width (Mobile %)', 'cartpops' ) }
					value={ drawer.width_mobile || 100 }
					onChange={ ( value ) =>
						updateSettings( 'drawer.width_mobile', value )
					}
					min={ 50 }
					max={ 100 }
					step={ 1 }
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Secondary action', 'cartpops' ) }
					{ ! SecondaryActionPanel && (
						<span
							className="cpops-admin__pro-badge"
							aria-hidden="true"
						>
							PRO
						</span>
					) }
				</h3>

				{ SecondaryActionPanel ? (
					<SecondaryActionPanel
						settings={ settings }
						updateSettings={ updateSettings }
					/>
				) : (
					<div className="cpops-settings-help">
						<p>
							{ __(
								'Add a Continue shopping, View cart, or custom customer action below checkout with CartPops Pro.',
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
								{ __( 'Explore Pro actions', 'cartpops' ) }
							</Button>
						) }
					</div>
				) }
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Animation', 'cartpops' ) }
				</h3>

				<SelectControl
					label={ __( 'Animation Type', 'cartpops' ) }
					value={ drawer.animation || 'slide' }
					options={ [
						{ label: __( 'Slide', 'cartpops' ), value: 'slide' },
						{ label: __( 'Fade', 'cartpops' ), value: 'fade' },
						{ label: __( 'None', 'cartpops' ), value: 'none' },
					] }
					onChange={ ( value ) =>
						updateSettings( 'drawer.animation', value )
					}
				/>

				<RangeControl
					label={ __( 'Duration (ms)', 'cartpops' ) }
					value={ drawer.animation_duration || 300 }
					onChange={ ( value ) =>
						updateSettings( 'drawer.animation_duration', value )
					}
					min={ 50 }
					max={ 800 }
					step={ 1 }
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Behavior', 'cartpops' ) }
				</h3>

				<ToggleControl
					label={ __( 'Show undo after item removal', 'cartpops' ) }
					checked={ drawer.show_undo !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_undo', value )
					}
				/>

				<ToggleControl
					label={ __( 'Show coupon code field', 'cartpops' ) }
					help={ __(
						'Allow customers to apply coupon codes from the cart drawer.',
						'cartpops'
					) }
					checked={ drawer.show_coupon !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_coupon', value )
					}
				/>

				<SelectControl
					label={ __( 'Product name display', 'cartpops' ) }
					help={ __(
						'Choose how product names wrap inside drawer cart items.',
						'cartpops'
					) }
					value={ normalizeProductNameDisplay(
						drawer.product_name_display
					) }
					options={ [
						{
							label: __( 'Two lines', 'cartpops' ),
							value: 'two_lines',
						},
						{
							label: __( 'Single line', 'cartpops' ),
							value: 'single_line',
						},
						{
							label: __( 'Full name', 'cartpops' ),
							value: 'full',
						},
					] }
					onChange={ ( value ) =>
						updateSettings( 'drawer.product_name_display', value )
					}
				/>

				<SelectControl
					label={ __( 'Totals breakdown', 'cartpops' ) }
					help={ __(
						'Where to show subtotal, discounts, shipping, fees, and tax lines.',
						'cartpops'
					) }
					value={ drawer.totals_breakdown || 'footer' }
					options={ [
						{
							label: __( 'In footer (sticky)', 'cartpops' ),
							value: 'footer',
						},
						{
							label: __(
								'Above checkout (scrollable)',
								'cartpops'
							),
							value: 'body',
						},
						{
							label: __( 'Hidden (total only)', 'cartpops' ),
							value: 'hidden',
						},
					] }
					onChange={ ( value ) =>
						updateSettings( 'drawer.totals_breakdown', value )
					}
				/>

				<ToggleControl
					label={ __( 'Show subtotal', 'cartpops' ) }
					help={ __(
						'Display the subtotal in the selected totals area.',
						'cartpops'
					) }
					checked={ drawer.show_subtotal !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_subtotal', value )
					}
				/>

				<ToggleControl
					label={ __( 'Show discounts', 'cartpops' ) }
					help={ __(
						'Display the discount line when a coupon is applied.',
						'cartpops'
					) }
					checked={ drawer.show_discount !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_discount', value )
					}
				/>

				<ToggleControl
					label={ __( 'Show shipping', 'cartpops' ) }
					help={ __(
						'Display the shipping line when shipping is calculated.',
						'cartpops'
					) }
					checked={ drawer.show_shipping !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_shipping', value )
					}
				/>

				<ToggleControl
					label={ __( 'Show taxes', 'cartpops' ) }
					help={ __(
						'Display the applicable tax line or included-tax note.',
						'cartpops'
					) }
					checked={ drawer.show_tax !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_tax', value )
					}
				/>

				<ToggleControl
					label={ __( 'Show total', 'cartpops' ) }
					help={ __(
						'Display the grand total above the checkout button.',
						'cartpops'
					) }
					checked={ drawer.show_total !== false }
					onChange={ ( value ) =>
						updateSettings( 'drawer.show_total', value )
					}
				/>

				<TextControl
					label={ __( 'Checkout Button Text', 'cartpops' ) }
					help={ __(
						'Leave empty to use the default "Proceed to Checkout".',
						'cartpops'
					) }
					value={ drawer.checkout_button_text || '' }
					placeholder={ __( 'Proceed to Checkout', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.checkout_button_text', value )
					}
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Customer messages', 'cartpops' ) }
				</h3>

				<TextControl
					label={ __( 'Cart title', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.header_title ?? '' }
					placeholder={ __( 'Your Cart', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.header_title', value )
					}
				/>

				<TextControl
					label={ __( 'Added-to-cart announcement', 'cartpops' ) }
					help={ __(
						'Optional generic announcement. Enter complete plain text without a product placeholder, or leave blank to disable it.',
						'cartpops'
					) }
					value={ drawer.added_to_cart_message ?? '' }
					placeholder={ __(
						'Product successfully added to your cart.',
						'cartpops'
					) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.added_to_cart_message', value )
					}
				/>

				<TextControl
					label={ __( 'Coupon heading', 'cartpops' ) }
					help={ hiddenByDefaultHelp }
					value={ drawer.coupon_title ?? '' }
					placeholder={ __( 'No heading by default', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.coupon_title', value )
					}
				/>

				<TextControl
					label={ __( 'Coupon input placeholder', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.coupon_input_placeholder ?? '' }
					placeholder={ __( 'Coupon code', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings(
							'drawer.coupon_input_placeholder',
							value
						)
					}
				/>

				<TextControl
					label={ __( 'Coupon button text', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.coupon_button_text ?? '' }
					placeholder={ __( 'Apply', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.coupon_button_text', value )
					}
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Totals and empty-cart text', 'cartpops' ) }
				</h3>

				<TextControl
					label={ __( 'Subtotal label', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.subtotal_label ?? '' }
					placeholder={ __( 'Subtotal', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.subtotal_label', value )
					}
				/>

				<TextControl
					label={ __( 'Discount label', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.discount_label ?? '' }
					placeholder={ __( 'Discount', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.discount_label', value )
					}
				/>

				<TextControl
					label={ __( 'Total label', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.total_label ?? '' }
					placeholder={ __( 'Total', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.total_label', value )
					}
				/>

				<TextControl
					label={ __( 'Empty-cart title', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.empty_title ?? '' }
					placeholder={ __( 'Your cart is empty', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.empty_title', value )
					}
				/>

				<TextControl
					label={ __( 'Empty-cart subtitle', 'cartpops' ) }
					help={ hiddenByDefaultHelp }
					value={ drawer.empty_subtitle ?? '' }
					placeholder={ __( 'No subtitle by default', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.empty_subtitle', value )
					}
				/>

				<TextControl
					label={ __( 'Empty-cart button text', 'cartpops' ) }
					help={ translatedDefaultHelp }
					value={ drawer.empty_button_text ?? '' }
					placeholder={ __( 'Continue shopping', 'cartpops' ) }
					onChange={ ( value ) =>
						updateSettings( 'drawer.empty_button_text', value )
					}
				/>
			</div>

			<div className="cpops-settings-group">
				<h3 className="cpops-settings-group__title">
					{ __( 'Quantity Selector', 'cartpops' ) }
				</h3>

				<SelectControl
					label={ __( 'Style', 'cartpops' ) }
					value={ drawer.quantity_style || 'default' }
					options={ [
						{ label: __( 'None', 'cartpops' ), value: 'none' },
						{
							label: __( 'Default', 'cartpops' ),
							value: 'default',
						},
						{
							label: __( 'Rounded', 'cartpops' ),
							value: 'rounded',
						},
						{
							label: __( 'Minimal', 'cartpops' ),
							value: 'minimal',
						},
					] }
					onChange={ ( value ) =>
						updateSettings( 'drawer.quantity_style', value )
					}
				/>
			</div>
		</>
	);
}
