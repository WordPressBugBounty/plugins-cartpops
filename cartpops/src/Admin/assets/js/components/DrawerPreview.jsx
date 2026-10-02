/**
 * DrawerPreview — Live visual preview of the cart drawer.
 *
 * Used in the unified DrawerEditor for WYSIWYG editing.
 * Renders an interactive mockup for shared cart, coupon, recommendation, and
 * totals settings. Paid preview inserts are supplied by the Pro application.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { formatCurrency } from '../utils/currency';
import { normalizeProductNameDisplay } from '../utils/productNameDisplay';

const MOCK_RECS = [
	{ id: 101, name: 'Silk Scarf', price: 34 },
	{ id: 102, name: 'Canvas Tote Bag', price: 22 },
	{ id: 103, name: 'Travel Mug', price: 16.5 },
	{ id: 104, name: 'Sunglasses', price: 45 },
];

const MOCK_ITEMS = [
	{
		id: 1,
		name: 'Classic Cotton T-Shirt',
		price: 29.95,
		quantity: 2,
		image: null,
		variation: 'Size: M / Color: Navy',
	},
	{
		id: 2,
		name: 'Leather Weekend Bag',
		price: 149,
		regularPrice: 179,
		quantity: 1,
		image: null,
		variation: null,
	},
	{
		id: 3,
		name: 'Organic Coffee Blend',
		price: 18.5,
		quantity: 3,
		image: null,
		variation: '250g — Whole Bean',
	},
];

const DARK_PALETTE_DEFAULTS = {
	background: '#1a1a2e',
	surface: '#252542',
	text_primary: '#f9fafb',
	text_secondary: '#d1d5db',
	border: '#6b7280',
	input_bg: '#252542',
	input_border: '#6b7280',
	overlay: 'rgba(0, 0, 0, 0.7)',
};

function ImagePlaceholder( { name, colors } ) {
	const initial = name.charAt( 0 ).toUpperCase();
	return (
		<div
			className="cpops-dp__item-image"
			style={ {
				backgroundColor: colors.border || '#e5e7eb',
				color: colors.text_primary || '#1a1a2e',
			} }
		>
			{ initial }
		</div>
	);
}

function overlayBackground( color, opacity ) {
	const hex = /^#([0-9a-f]{6})$/i.exec( color || '' );
	if ( ! hex ) {
		return 'rgba(0, 0, 0, 0.5)';
	}

	const value = hex[ 1 ];
	const alpha = Math.max( 0, Math.min( 100, opacity ?? 50 ) ) / 100;
	return `rgba(${ parseInt( value.slice( 0, 2 ), 16 ) }, ${ parseInt(
		value.slice( 2, 4 ),
		16
	) }, ${ parseInt( value.slice( 4, 6 ), 16 ) }, ${ alpha })`;
}

function safeCssColor( candidate, fallback ) {
	if ( typeof candidate !== 'string' || candidate.length > 64 ) {
		return fallback;
	}

	const value = candidate.trim();
	if ( /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test( value ) ) {
		return value;
	}

	const functional = /^(rgba?)\(([^()]*)\)$/i.exec( value );
	if ( ! functional ) {
		return fallback;
	}
	const channels = functional[ 2 ]
		.split( ',' )
		.map( ( part ) => part.trim() );
	const expectedLength = functional[ 1 ].toLowerCase() === 'rgba' ? 4 : 3;
	if (
		channels.length !== expectedLength ||
		! channels.slice( 0, 3 ).every( ( channel ) => {
			return /^\d{1,3}$/.test( channel ) && Number( channel ) <= 255;
		} )
	) {
		return fallback;
	}
	if (
		expectedLength === 4 &&
		( ! /^(?:0(?:\.\d+)?|1(?:\.0+)?|\.\d+)$/.test( channels[ 3 ] ) ||
			Number( channels[ 3 ] ) > 1 )
	) {
		return fallback;
	}

	return value;
}

export default function DrawerPreview( {
	settings,
	onClickElement,
	Enhancements,
	RecommendationButtonPreview,
} ) {
	const [ viewMode, setViewMode ] = useState( 'desktop' );
	const [ cartState, setCartState ] = useState( 'filled' );
	const [ darkPreview, setDarkPreview ] = useState( false );
	const [ enhancementState, setEnhancementState ] = useState( {} );

	const design = settings?.design || {};
	const drawer = settings?.drawer || {};
	const drawerText = ( key, fallback = '' ) =>
		typeof drawer[ key ] === 'string' && drawer[ key ] !== ''
			? drawer[ key ]
			: fallback;
	const headerTitle = drawerText(
		'header_title',
		__( 'Your Cart', 'cartpops' )
	);
	const couponTitle = drawerText( 'coupon_title' );
	const couponPlaceholder = drawerText(
		'coupon_input_placeholder',
		__( 'Coupon code', 'cartpops' )
	);
	const couponButtonText = drawerText(
		'coupon_button_text',
		__( 'Apply', 'cartpops' )
	);
	const subtotalLabel = drawerText(
		'subtotal_label',
		__( 'Subtotal', 'cartpops' )
	);
	const totalLabel = drawerText( 'total_label', __( 'Total', 'cartpops' ) );
	const emptyTitle = drawerText(
		'empty_title',
		__( 'Your cart is empty', 'cartpops' )
	);
	const emptySubtitle = drawerText( 'empty_subtitle' );
	const emptyButtonText = drawerText(
		'empty_button_text',
		__( 'Continue shopping', 'cartpops' )
	);
	const colors = design.colors || {};
	const darkColors =
		design.colors_dark &&
		typeof design.colors_dark === 'object' &&
		! Array.isArray( design.colors_dark )
			? design.colors_dark
			: {};
	const darkPalette = Object.fromEntries(
		Object.entries( DARK_PALETTE_DEFAULTS ).map( ( [ key, fallback ] ) => [
			key,
			safeCssColor( darkColors[ key ], fallback ),
		] )
	);
	const previewColors = darkPreview ? { ...colors, ...darkPalette } : colors;
	const borderRadius = design.border_radius ?? 8;
	const recs = settings?.recommendations || {};
	const recsEnabled = recs.enabled || false;
	const recsHeading = recs.heading || 'You may also like';
	const recsLayout = recs.layout || 'horizontal';
	const quantityStyle = drawer.quantity_style || 'default';
	const productNameDisplay = normalizeProductNameDisplay(
		drawer.product_name_display
	);

	// Quantity-button corner rounding depends on the selected quantity style.
	let qtyBtnBorderRadius;
	if ( quantityStyle === 'rounded' ) {
		qtyBtnBorderRadius = '50%';
	} else if ( quantityStyle === 'minimal' ) {
		qtyBtnBorderRadius = 'var(--cpops-radius)';
	} else {
		qtyBtnBorderRadius = undefined;
	}

	const totalsBreakdown = [ 'body', 'footer', 'hidden' ].includes(
		drawer.totals_breakdown
	)
		? drawer.totals_breakdown
		: 'footer';
	const showsDrawerRow = ( key ) => ( drawer[ key ] ?? true ) === true;
	const showSubtotal = showsDrawerRow( 'show_subtotal' );
	const showDiscount = showsDrawerRow( 'show_discount' );
	const showShipping = showsDrawerRow( 'show_shipping' );
	const showTax = showsDrawerRow( 'show_tax' );
	const showTotal = showsDrawerRow( 'show_total' );
	const hasStaticBreakdownRows =
		showSubtotal || showDiscount || showShipping || showTax;
	const hasPreviewBreakdownRows =
		hasStaticBreakdownRows || Boolean( Enhancements );
	const cssVars = {
		'--cpops-color-primary': colors.primary || '#6f23e1',
		'--cpops-color-primary-text': colors.primary_text || '#ffffff',
		'--cpops-color-bg': darkPreview
			? darkPalette.background
			: colors.background || '#ffffff',
		'--cpops-color-surface': darkPreview
			? darkPalette.surface
			: colors.surface || '#f9fafb',
		'--cpops-color-text': darkPreview
			? darkPalette.text_primary
			: colors.text_primary || '#1a1a2e',
		'--cpops-color-text-secondary': darkPreview
			? darkPalette.text_secondary
			: colors.text_secondary || '#6b7280',
		'--cpops-color-border': darkPreview
			? darkPalette.border
			: colors.border || '#e5e7eb',
		'--cpops-color-btn-bg': colors.button_primary_bg || '#6f23e1',
		'--cpops-color-btn-text': colors.button_primary_text || '#ffffff',
		'--cpops-color-input-bg': darkPreview
			? darkPalette.input_bg
			: colors.input_bg || '#f9fafb',
		'--cpops-color-input-border': darkPreview
			? darkPalette.input_border
			: colors.input_border || '#d1d5db',
		'--cpops-color-input-text': darkPreview
			? '#f9fafb'
			: colors.input_text || '#1a1a2e',
		'--cpops-color-quantity-btn-bg': darkPreview
			? '#374151'
			: colors.quantity_button_bg || '#f9fafb',
		'--cpops-color-quantity-btn-text': darkPreview
			? '#f9fafb'
			: colors.quantity_button_text || '#6b7280',
		'--cpops-color-quantity-input-bg': darkPreview
			? '#1a1a2e'
			: colors.quantity_input_bg || '#ffffff',
		'--cpops-color-quantity-input-border': darkPreview
			? '#6b7280'
			: colors.quantity_input_border || '#e5e7eb',
		'--cpops-color-quantity-input-text': darkPreview
			? '#f9fafb'
			: colors.quantity_input_text || '#1a1a2e',
		'--cpops-sale': darkPreview ? '#f87171' : colors.sale || '#dc2626',
		'--cpops-color-success': darkPreview
			? '#4ade80'
			: colors.success || '#10b981',
		'--cpops-color-danger': darkPreview
			? '#f87171'
			: colors.danger || '#ef4444',
		'--cpops-radius': `${ borderRadius }px`,
		'--cpops-btn-radius': `${ design.button_border_radius ?? 8 }px`,
		'--cpops-color-recs-btn-bg': darkPreview
			? colors.button_primary_bg || colors.primary || '#6f23e1'
			: colors.recs_button_bg || '#6f23e1',
		'--cpops-color-recs-btn-text': darkPreview
			? colors.button_primary_text || colors.primary_text || '#ffffff'
			: colors.recs_button_text || '#ffffff',
		'--cpops-color-recs-bg': darkPreview
			? '#1f1f36'
			: colors.recs_background || '#ffffff',
		'--cpops-color-recs-border': darkPreview
			? '#6b7280'
			: colors.recs_border || '#e5e7eb',
		'--cpops-color-recs-text': darkPreview
			? '#f9fafb'
			: colors.recs_text || '#1a1a2e',
	};

	const actualWidth = drawer.width_desktop || 480;
	const animation = drawer.animation || 'slide';
	const animationDuration = drawer.animation_duration ?? 300;
	const drawerWidth =
		viewMode === 'mobile'
			? `${ drawer.width_mobile ?? 100 }%`
			: `${ actualWidth }px`;
	const position = drawer.position || 'right';

	const handleClick = ( elementKey ) => {
		if ( onClickElement ) {
			onClickElement( elementKey );
		}
	};

	// Keyboard equivalent for the click-to-select preview regions: activate on
	// Enter/Space, mirroring the onClick behavior without changing it.
	const handleSelectKeyDown = ( elementKey ) => ( event ) => {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			handleClick( elementKey );
		}
	};

	const subtotal = 226.4;
	const total = subtotal;

	const TotalsBreakdownRows = () => (
		<>
			{ showSubtotal && (
				<div className="cpops-dp__total-row cpops-dp__total-row--subtotal">
					<span
						style={ {
							color: 'var(--cpops-color-text-secondary)',
						} }
					>
						{ subtotalLabel }
					</span>
					<span style={ { color: 'var(--cpops-color-text)' } }>
						{ formatCurrency( subtotal ) }
					</span>
				</div>
			) }
			{ showDiscount && (
				<div className="cpops-dp__total-row cpops-dp__total-row--discount">
					<span
						style={ {
							color: 'var(--cpops-color-text-secondary)',
						} }
					>
						{ drawerText(
							'discount_label',
							__( 'Discount', 'cartpops' )
						) }
					</span>
					<span style={ { color: 'var(--cpops-color-text)' } }>
						{ `-${ formatCurrency( 10 ) }` }
					</span>
				</div>
			) }
			{ showShipping && (
				<div className="cpops-dp__total-row cpops-dp__total-row--shipping">
					<span
						style={ {
							color: 'var(--cpops-color-text-secondary)',
						} }
					>
						{ __( 'Shipping', 'cartpops' ) }
					</span>
					<span style={ { color: 'var(--cpops-color-text)' } }>
						{ __( 'Free', 'cartpops' ) }
					</span>
				</div>
			) }
			{ Enhancements && (
				<Enhancements
					placement="totals"
					settings={ settings }
					subtotal={ subtotal }
				/>
			) }
			{ showTax && (
				<div className="cpops-dp__total-row cpops-dp__total-row--tax">
					<span
						style={ {
							color: 'var(--cpops-color-text-secondary)',
						} }
					>
						{ __( 'Tax', 'cartpops' ) }
					</span>
					<span style={ { color: 'var(--cpops-color-text)' } }>
						{ formatCurrency( 21.4 ) }
					</span>
				</div>
			) }
		</>
	);

	return (
		<div className="cpops-dp">
			{ /* Toolbar */ }
			<div className="cpops-dp__toolbar">
				<div className="cpops-dp__toolbar-group">
					<button
						className={ `cpops-dp__toolbar-btn ${
							viewMode === 'desktop'
								? 'cpops-dp__toolbar-btn--active'
								: ''
						}` }
						onClick={ () => setViewMode( 'desktop' ) }
						title={ __( 'Desktop', 'cartpops' ) }
						type="button"
					>
						<svg
							width="16"
							height="16"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							strokeWidth="2"
						>
							<rect x="2" y="3" width="20" height="14" rx="2" />
							<path d="M8 21h8M12 17v4" />
						</svg>
					</button>
					<button
						className={ `cpops-dp__toolbar-btn ${
							viewMode === 'mobile'
								? 'cpops-dp__toolbar-btn--active'
								: ''
						}` }
						onClick={ () => setViewMode( 'mobile' ) }
						title={ __( 'Mobile', 'cartpops' ) }
						type="button"
					>
						<svg
							width="16"
							height="16"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							strokeWidth="2"
						>
							<rect x="5" y="2" width="14" height="20" rx="2" />
							<path d="M12 18h.01" />
						</svg>
					</button>
				</div>

				<div className="cpops-dp__toolbar-group">
					<button
						className={ `cpops-dp__toolbar-btn ${
							cartState === 'filled'
								? 'cpops-dp__toolbar-btn--active'
								: ''
						}` }
						onClick={ () => setCartState( 'filled' ) }
						type="button"
					>
						{ __( 'Items', 'cartpops' ) }
					</button>
					<button
						className={ `cpops-dp__toolbar-btn ${
							cartState === 'empty'
								? 'cpops-dp__toolbar-btn--active'
								: ''
						}` }
						onClick={ () => setCartState( 'empty' ) }
						type="button"
					>
						{ __( 'Empty', 'cartpops' ) }
					</button>
				</div>

				<div className="cpops-dp__toolbar-group">
					<button
						className={ `cpops-dp__toolbar-btn ${
							darkPreview ? 'cpops-dp__toolbar-btn--active' : ''
						}` }
						onClick={ () => setDarkPreview( ! darkPreview ) }
						title={ __( 'Toggle dark mode preview', 'cartpops' ) }
						type="button"
					>
						<svg
							width="16"
							height="16"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							strokeWidth="2"
						>
							<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
						</svg>
					</button>
				</div>
			</div>

			{ Enhancements && (
				<Enhancements
					placement="toolbar"
					settings={ settings }
					cartState={ cartState }
					state={ enhancementState }
					setState={ setEnhancementState }
				/>
			) }

			{ /* Preview Frame */ }
			<div className={ `cpops-dp__frame cpops-dp__frame--${ viewMode }` }>
				{ /* Overlay */ }
				<div
					className="cpops-dp__overlay"
					data-animation={ animation }
					style={ {
						backgroundColor: darkPreview
							? darkPalette.overlay
							: overlayBackground(
									design.overlay_color,
									design.overlay_opacity
							  ),
						transition:
							animation === 'none'
								? 'none'
								: `opacity ${ animationDuration }ms ease, visibility ${ animationDuration }ms ease`,
					} }
				/>

				{ /* Drawer Panel */ }
				<div
					className={ `cpops-dp__drawer cpops-dp__drawer--${ position }` }
					data-animation={ animation }
					data-product-name-display={ productNameDisplay }
					style={ {
						...cssVars,
						width: drawerWidth,
						maxWidth: '100%',
						backgroundColor: 'var(--cpops-color-bg)',
						color: 'var(--cpops-color-text)',
						transition: animation === 'none' ? 'none' : undefined,
						transitionDuration: `${ animationDuration }ms`,
					} }
				>
					{ /* Header */ }
					<div
						className="cpops-dp__header"
						style={ {
							borderBottomColor: 'var(--cpops-color-border)',
						} }
					>
						<span
							className="cpops-dp__header-title"
							style={ { color: 'var(--cpops-color-text)' } }
						>
							{ headerTitle }
							{ cartState === 'filled' && (
								<span
									className="cpops-dp__header-count"
									style={ {
										backgroundColor:
											'var(--cpops-color-primary)',
										color: 'var(--cpops-color-primary-text)',
									} }
								>
									6
								</span>
							) }
						</span>
						<button
							className="cpops-dp__close-btn"
							type="button"
							style={ {
								color: 'var(--cpops-color-text-secondary)',
								borderRadius: 'var(--cpops-radius)',
							} }
						>
							<svg
								width="20"
								height="20"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
							>
								<line x1="18" y1="6" x2="6" y2="18" />
								<line x1="6" y1="6" x2="18" y2="18" />
							</svg>
						</button>
					</div>

					{ /* Body */ }
					<div className="cpops-dp__body">
						{ cartState === 'empty' ? (
							<div
								className="cpops-dp__empty cpops-dp__clickable"
								role="button"
								tabIndex={ 0 }
								onClick={ () => handleClick( 'background' ) }
								onKeyDown={ handleSelectKeyDown(
									'background'
								) }
							>
								<svg
									width="48"
									height="48"
									viewBox="0 0 64 64"
									fill="none"
									stroke="var(--cpops-color-text-secondary)"
									strokeWidth="1.5"
									opacity="0.5"
								>
									<circle cx="24" cy="56" r="4" />
									<circle cx="48" cy="56" r="4" />
									<path d="M2 2h8l6 36h36l6-24H16" />
								</svg>
								<p
									style={ {
										color: 'var(--cpops-color-text-secondary)',
									} }
								>
									{ emptyTitle }
								</p>
								{ emptySubtitle && (
									<p
										style={ {
											color: 'var(--cpops-color-text-secondary)',
										} }
									>
										{ emptySubtitle }
									</p>
								) }
								<button
									className="cpops-dp__continue-btn"
									type="button"
									style={ {
										color: 'var(--cpops-color-primary)',
										borderColor:
											'var(--cpops-color-primary)',
										borderRadius: 'var(--cpops-radius)',
									} }
								>
									{ emptyButtonText }
								</button>
							</div>
						) : (
							<>
								{ Enhancements && (
									<Enhancements
										placement="before-items"
										settings={ settings }
										state={ enhancementState }
									/>
								) }

								{ /* Cart Items */ }
								<div className="cpops-dp__items">
									{ MOCK_ITEMS.map( ( item ) => (
										<div
											key={ item.id }
											className="cpops-dp__item cpops-dp__clickable"
											role="button"
											tabIndex={ 0 }
											onClick={ () =>
												handleClick( 'background' )
											}
											onKeyDown={ handleSelectKeyDown(
												'background'
											) }
											style={ {
												borderBottomColor:
													'var(--cpops-color-border)',
											} }
										>
											<ImagePlaceholder
												name={ item.name }
												colors={ previewColors }
											/>

											<div className="cpops-dp__item-details">
												<div
													className="cpops-dp__item-name"
													style={ {
														color: 'var(--cpops-color-text)',
													} }
												>
													{ item.name }
												</div>
												{ item.variation && (
													<div
														className="cpops-dp__item-variation"
														style={ {
															color: 'var(--cpops-color-text-secondary)',
														} }
													>
														{ item.variation }
													</div>
												) }
												<div
													className="cpops-dp__item-price"
													style={ {
														color: 'var(--cpops-color-text)',
														display: 'flex',
														flexWrap: 'wrap',
														alignItems: 'baseline',
														gap: '2px 6px',
													} }
												>
													{ item.regularPrice && (
														<span
															style={ {
																textDecoration:
																	'line-through',
																color: 'var(--cpops-color-text-secondary)',
															} }
														>
															{ formatCurrency(
																item.regularPrice *
																	item.quantity
															) }
														</span>
													) }
													<span
														style={
															item.regularPrice
																? {
																		color: `var(--cpops-sale, ${
																			colors.sale ||
																			'#dc2626'
																		})`,
																  }
																: undefined
														}
													>
														{ formatCurrency(
															item.price *
																item.quantity
														) }
													</span>
													{ item.quantity > 1 && (
														<span
															className="cpops-dp__item-price-each"
															style={ {
																flexBasis:
																	'100%',
																fontSize:
																	'12px',
																color: 'var(--cpops-color-text-secondary)',
															} }
														>
															{ sprintf(
																/* translators: %s: price of a single unit, shown under a cart line's total. */
																__(
																	'%s each',
																	'cartpops'
																),
																formatCurrency(
																	item.price
																)
															) }
														</span>
													) }
												</div>
											</div>

											{ /* Actions column — quantity + remove stacked */ }
											<div className="cpops-dp__item-actions">
												{ quantityStyle !== 'none' && (
													<div
														className="cpops-dp__qty"
														style={ {
															border:
																quantityStyle ===
																'minimal'
																	? 'none'
																	: '1px solid var(--cpops-color-quantity-input-border)',
															borderRadius:
																quantityStyle ===
																'rounded'
																	? '100px'
																	: 'var(--cpops-radius)',
															gap:
																quantityStyle ===
																'minimal'
																	? '2px'
																	: undefined,
														} }
													>
														<button
															type="button"
															className="cpops-dp__qty-btn"
															style={ {
																color: 'var(--cpops-color-quantity-btn-text)',
																borderRadius:
																	qtyBtnBorderRadius,
																background:
																	'var(--cpops-color-quantity-btn-bg)',
															} }
														>
															<svg
																width="14"
																height="14"
																viewBox="0 0 16 16"
																fill="none"
																stroke="currentColor"
																strokeWidth="2"
															>
																<line
																	x1="4"
																	y1="8"
																	x2="12"
																	y2="8"
																/>
															</svg>
														</button>
														<span
															className="cpops-dp__qty-value"
															style={ {
																color: 'var(--cpops-color-quantity-input-text)',
																backgroundColor:
																	'var(--cpops-color-quantity-input-bg)',
															} }
														>
															{ item.quantity }
														</span>
														<button
															type="button"
															className="cpops-dp__qty-btn"
															style={ {
																color: 'var(--cpops-color-quantity-btn-text)',
																borderRadius:
																	qtyBtnBorderRadius,
																background:
																	'var(--cpops-color-quantity-btn-bg)',
															} }
														>
															<svg
																width="14"
																height="14"
																viewBox="0 0 16 16"
																fill="none"
																stroke="currentColor"
																strokeWidth="2"
															>
																<line
																	x1="4"
																	y1="8"
																	x2="12"
																	y2="8"
																/>
																<line
																	x1="8"
																	y1="4"
																	x2="8"
																	y2="12"
																/>
															</svg>
														</button>
													</div>
												) }
												<button
													className="cpops-dp__remove-btn"
													type="button"
													style={ {
														color: 'var(--cpops-color-text-secondary)',
													} }
													title={ __(
														'Remove',
														'cartpops'
													) }
												>
													<svg
														width="16"
														height="16"
														viewBox="0 0 16 16"
														fill="none"
														stroke="currentColor"
														strokeWidth="1.5"
													>
														<path d="M2 4h12M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1M6 7v5M10 7v5M3 4l1 9a1 1 0 001 1h6a1 1 0 001-1l1-9" />
													</svg>
												</button>
											</div>
										</div>
									) ) }
								</div>

								{ /* Recommendations */ }
								{ recsEnabled && (
									<div
										className="cpops-dp__recs"
										style={ {
											borderTopColor:
												'var(--cpops-color-recs-border)',
											backgroundColor:
												'var(--cpops-color-recs-bg)',
										} }
									>
										<h4
											className="cpops-dp__recs-heading"
											style={ {
												color: 'var(--cpops-color-recs-text)',
											} }
										>
											{ recsHeading }
										</h4>
										<div
											className={ `cpops-dp__recs-list cpops-dp__recs-list--${ recsLayout }` }
										>
											{ MOCK_RECS.slice(
												0,
												recs.limit || 4
											).map( ( rec ) => (
												<div
													key={ rec.id }
													className="cpops-dp__rec-card"
													style={ {
														backgroundColor:
															'var(--cpops-color-surface)',
														borderRadius:
															'var(--cpops-radius)',
													} }
												>
													<ImagePlaceholder
														name={ rec.name }
														colors={ colors }
													/>
													<div className="cpops-dp__rec-info">
														<span
															className="cpops-dp__rec-name"
															style={ {
																color: 'var(--cpops-color-text)',
															} }
														>
															{ rec.name }
														</span>
														<span
															className="cpops-dp__rec-price"
															style={ {
																color: 'var(--cpops-color-text-secondary)',
															} }
														>
															{ formatCurrency(
																rec.price
															) }
														</span>
													</div>
													{ RecommendationButtonPreview ? (
														<RecommendationButtonPreview
															settings={
																settings
															}
															productName={
																rec.name
															}
														/>
													) : (
														<button
															className="cpops-dp__rec-add"
															type="button"
															aria-label={ sprintf(
																/* translators: %s: product name. */
																__(
																	'Add %s to cart',
																	'cartpops'
																),
																rec.name
															) }
															style={ {
																backgroundColor:
																	'var(--cpops-color-recs-btn-bg)',
																color: 'var(--cpops-color-recs-btn-text)',
																borderRadius:
																	'50%',
															} }
														>
															<svg
																aria-hidden="true"
																focusable="false"
																width="14"
																height="14"
																viewBox="0 0 24 24"
																fill="none"
																stroke="currentColor"
																strokeWidth="2"
																strokeLinecap="round"
																strokeLinejoin="round"
															>
																<circle
																	cx="9"
																	cy="21"
																	r="1"
																/>
																<circle
																	cx="20"
																	cy="21"
																	r="1"
																/>
																<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
															</svg>
														</button>
													) }
												</div>
											) ) }
										</div>
									</div>
								) }

								{ /* Totals Breakdown (body position) */ }
								{ totalsBreakdown === 'body' &&
									hasPreviewBreakdownRows && (
										<div
											className="cpops-dp__totals cpops-dp__totals--body"
											style={ {
												borderTopColor:
													'var(--cpops-color-border)',
											} }
										>
											<TotalsBreakdownRows />
										</div>
									) }
							</>
						) }
					</div>

					{ /* Footer */ }
					{ cartState === 'filled' && (
						<div
							className="cpops-dp__footer"
							style={ {
								borderTopColor: 'var(--cpops-color-border)',
							} }
						>
							{ Enhancements && (
								<Enhancements
									placement="footer"
									settings={ settings }
								/>
							) }

							{ /* Coupon */ }
							{ drawer.show_coupon !== false && (
								<>
									{ couponTitle && (
										<p style={ { margin: '0 0 8px' } }>
											{ couponTitle }
										</p>
									) }
									<div
										className="cpops-dp__coupon"
										style={ {
											borderColor:
												'var(--cpops-color-input-border)',
										} }
									>
										<input
											type="text"
											placeholder={ couponPlaceholder }
											className="cpops-dp__coupon-input"
											readOnly
											style={ {
												borderColor:
													'var(--cpops-color-input-border)',
												borderRadius:
													'var(--cpops-radius)',
												color: 'var(--cpops-color-input-text)',
												backgroundColor:
													'var(--cpops-color-input-bg)',
											} }
										/>
										<button
											type="button"
											className="cpops-dp__coupon-btn"
											style={ {
												borderColor:
													'var(--cpops-color-primary)',
												color: 'var(--cpops-color-primary)',
												borderRadius:
													'var(--cpops-radius)',
											} }
										>
											{ couponButtonText }
										</button>
									</div>
								</>
							) }

							{ /* Totals */ }
							{ ( showTotal ||
								( totalsBreakdown === 'footer' &&
									hasPreviewBreakdownRows ) ) && (
								<div className="cpops-dp__totals">
									{ totalsBreakdown === 'footer' && (
										<TotalsBreakdownRows />
									) }
									{ showTotal && (
										<div
											className="cpops-dp__total-row cpops-dp__total-row--grand"
											style={ {
												borderTopColor:
													'var(--cpops-color-border)',
											} }
										>
											<span
												style={ {
													color: 'var(--cpops-color-text)',
												} }
											>
												{ totalLabel }
											</span>
											{ Enhancements ? (
												<Enhancements
													placement="total-value"
													settings={ settings }
													subtotal={ subtotal }
												/>
											) : (
												<span
													style={ {
														color: 'var(--cpops-color-text)',
													} }
												>
													{ formatCurrency( total ) }
												</span>
											) }
										</div>
									) }
								</div>
							) }

							{ /* Checkout Button */ }
							<button
								className="cpops-dp__checkout-btn cpops-dp__clickable"
								onClick={ () =>
									handleClick( 'button_primary_bg' )
								}
								type="button"
								style={ {
									backgroundColor:
										'var(--cpops-color-btn-bg)',
									color: 'var(--cpops-color-btn-text)',
									borderRadius: 'var(--cpops-btn-radius)',
								} }
							>
								{ drawer.checkout_button_text ||
									__( 'Proceed to Checkout', 'cartpops' ) }
							</button>

							{ /* Edition-correct post-checkout actions. */ }
							{ Enhancements ? (
								<Enhancements
									placement="after-checkout"
									settings={ settings }
								/>
							) : (
								<span
									className="cpops-dp__view-cart"
									style={ {
										color: 'var(--cpops-color-text-secondary)',
									} }
								>
									{ __( 'View Cart', 'cartpops' ) }
								</span>
							) }
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
