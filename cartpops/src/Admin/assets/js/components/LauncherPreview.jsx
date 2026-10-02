/**
 * LauncherPreview — Live visual preview of the cart launcher button.
 *
 * Used in LauncherSettings for WYSIWYG editing.
 * Renders an interactive mockup that reflects all settings in real-time.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatCurrency } from '../utils/currency';

const ICONS = {
	cart: (
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
	bag: (
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
	basket: (
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
			<path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l7 4.12a2 2 0 0 0 2.06 0l7-4.12a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71l-7-4.12a2 2 0 0 0-2.06 0l-7 4.12z" />
			<path d="M12 22V12" />
			<path d="M2 12l10-6 10 6" />
		</svg>
	),
};

export default function LauncherPreview( { settings, onClickElement } ) {
	const [ viewMode, setViewMode ] = useState( 'desktop' );
	const [ cartState, setCartState ] = useState( 'filled' );
	const [ darkPreview, setDarkPreview ] = useState( false );

	const launcher = settings?.launcher || {};
	const colors = launcher.colors || {};
	const inlineColors = launcher.inline_colors || {};
	const menuLauncher = launcher.menu || {};
	const position = launcher.position || 'bottom_right';
	const size = launcher.size ?? 56;
	const offsetX = launcher.offset_x ?? 24;
	const offsetY = launcher.offset_y ?? 24;
	const icon = launcher.icon || 'cart';
	const isEmpty = cartState === 'empty';
	const isVisible =
		launcher.enabled !== false && ! ( isEmpty && launcher.hide_empty );
	const showBadge =
		launcher.show_count !== false &&
		! ( isEmpty && launcher.hide_indicator_empty !== false );
	const inlineIndicator = [ 'none', 'bubble', 'plain' ].includes(
		menuLauncher.indicator
	)
		? menuLauncher.indicator
		: 'bubble';
	const showInlineBadge =
		inlineIndicator !== 'none' &&
		! ( isEmpty && menuLauncher.hide_indicator_empty );
	const inlineIcon = menuLauncher.icon || 'bag';

	const isMobile = viewMode === 'mobile';
	const effectiveSize = isMobile ? Math.min( size, 48 ) : size;
	const effectiveOffsetX = isMobile ? Math.min( offsetX, 16 ) : offsetX;
	const effectiveOffsetY = isMobile ? Math.min( offsetY, 16 ) : offsetY;
	const iconSize = Math.round( effectiveSize * 0.43 );

	const positionStyles = {
		bottom: `${ effectiveOffsetY }px`,
	};
	if ( position === 'bottom_left' ) {
		positionStyles.left = `${ effectiveOffsetX }px`;
	} else {
		positionStyles.right = `${ effectiveOffsetX }px`;
	}

	const handleClick = ( elementKey ) => {
		if ( onClickElement ) {
			onClickElement( elementKey );
		}
	};

	// Keyboard equivalent for click-to-select preview regions: activate on
	// Enter/Space, mirroring the onClick behavior (including stopPropagation).
	const handleSelectKeyDown = ( elementKey ) => ( event ) => {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			event.stopPropagation();
			handleClick( elementKey );
		}
	};

	return (
		<div className="cpops-lp">
			{ /* Toolbar */ }
			<div className="cpops-lp__toolbar">
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
							isEmpty ? 'cpops-dp__toolbar-btn--active' : ''
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

			{ /* Preview Frame */ }
			<div
				className={ `cpops-lp__frame cpops-lp__frame--${ viewMode }` }
				style={ {
					backgroundColor: darkPreview ? '#1a1a2e' : '#e5e7eb',
				} }
			>
				{ /* Page wireframe mockup */ }
				<div className="cpops-lp__page-mockup">
					<div className="cpops-lp__wireframe-bar cpops-lp__wireframe-bar--wide" />
					<div className="cpops-lp__wireframe-bar cpops-lp__wireframe-bar--medium" />
					<div className="cpops-lp__wireframe-bar cpops-lp__wireframe-bar--narrow" />
					<div className="cpops-lp__wireframe-bar cpops-lp__wireframe-bar--wide" />
					<div className="cpops-lp__wireframe-bar cpops-lp__wireframe-bar--medium" />
				</div>

				{ /* Inline navigation launcher */ }
				<div
					className="cpops-lp__inline-nav"
					style={ {
						position: 'absolute',
						top: '14px',
						left: '14px',
						right: '14px',
						height: '42px',
						padding: '0 12px',
						backgroundColor: darkPreview ? '#374151' : '#ffffff',
						borderRadius: '6px',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'space-between',
						boxShadow: '0 1px 3px rgba(0, 0, 0, 0.12)',
					} }
				>
					<span
						style={ {
							color: darkPreview ? '#f9fafb' : '#374151',
							fontSize: '11px',
							fontWeight: 600,
						} }
					>
						{ __( 'Store menu', 'cartpops' ) }
					</span>
					<button
						className="cpops-lp__inline-button"
						type="button"
						aria-label={ __(
							'Inline navigation cart launcher preview',
							'cartpops'
						) }
						style={ {
							position: 'relative',
							display: 'inline-flex',
							alignItems: 'center',
							justifyContent: 'center',
							gap: '7px',
							minHeight: '32px',
							padding: '5px 8px',
							border: 'none',
							borderRadius: '6px',
							backgroundColor:
								inlineColors.background ||
								'rgba(255, 255, 255, 0)',
							color: inlineColors.text || '#000000',
							font: 'inherit',
						} }
					>
						<span
							style={ {
								width: '18px',
								height: '18px',
								display: 'flex',
							} }
						>
							{ ICONS[ inlineIcon ] || ICONS.bag }
						</span>

						{ showInlineBadge && (
							<span
								className={ `cpops-lp__inline-badge cpops-lp__inline-badge--${ inlineIndicator }` }
								style={
									inlineIndicator === 'plain'
										? {
												position: 'static',
												color: 'inherit',
												background: 'transparent',
												fontSize: 'inherit',
												fontWeight: 'inherit',
										  }
										: {
												position: 'absolute',
												top: '-5px',
												right: '-5px',
												minWidth: '18px',
												height: '18px',
												padding: '0 4px',
												borderRadius: '100px',
												backgroundColor:
													inlineColors.badge_bg ||
													'#705aef',
												color:
													inlineColors.badge_text ||
													'#ffffff',
												fontSize: '9px',
												fontWeight: 700,
												display: 'flex',
												alignItems: 'center',
												justifyContent: 'center',
										  }
								}
							>
								{ isEmpty ? 0 : 3 }
							</span>
						) }

						{ menuLauncher.show_total && (
							<span
								className="cpops-lp__inline-total"
								style={ {
									fontSize: '9px',
									fontWeight: 600,
									whiteSpace: 'nowrap',
								} }
							>
								{ formatCurrency( isEmpty ? 0 : 226.4 ) }
							</span>
						) }
					</button>
				</div>

				{ /* Launcher button */ }
				{ isVisible ? (
					<button
						className="cpops-lp__button cpops-lp__clickable"
						onClick={ () => handleClick( 'background' ) }
						type="button"
						aria-label={ __( 'Open cart', 'cartpops' ) }
						style={ {
							position: 'absolute',
							...positionStyles,
							width: `${ effectiveSize }px`,
							height: `${ effectiveSize }px`,
							borderRadius: '50%',
							backgroundColor: colors.background || '#6f23e1',
							color: colors.icon || '#ffffff',
							border: 'none',
							boxShadow:
								'0 4px 12px rgba(0, 0, 0, 0.15), 0 1px 3px rgba(0, 0, 0, 0.1)',
							cursor: 'pointer',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							gap: '4px',
							padding: 0,
						} }
					>
						<span
							style={ {
								width: `${ iconSize }px`,
								height: `${ iconSize }px`,
								display: 'flex',
							} }
						>
							{ ICONS[ icon ] || ICONS.cart }
						</span>

						{ /* Badge */ }
						{ showBadge && (
							<span
								className="cpops-lp__badge cpops-lp__clickable"
								role="button"
								tabIndex={ 0 }
								onClick={ ( e ) => {
									e.stopPropagation();
									handleClick( 'badge_bg' );
								} }
								onKeyDown={ handleSelectKeyDown( 'badge_bg' ) }
								style={ {
									position: 'absolute',
									top: '-4px',
									right: '-4px',
									backgroundColor:
										colors.badge_bg || '#ef4444',
									color: colors.badge_text || '#ffffff',
									minWidth: '22px',
									height: '22px',
									padding: '0 5px',
									fontSize: '11px',
									fontWeight: 700,
									borderRadius: '100px',
									display: 'flex',
									alignItems: 'center',
									justifyContent: 'center',
									lineHeight: 1,
									fontFamily:
										'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
								} }
							>
								{ isEmpty ? 0 : 3 }
							</span>
						) }

						{ launcher.show_total && (
							<span
								className="cpops-lp__total"
								style={ {
									fontSize: '9px',
									fontWeight: 600,
									whiteSpace: 'nowrap',
								} }
							>
								{ formatCurrency( isEmpty ? 0 : 226.4 ) }
							</span>
						) }
					</button>
				) : (
					<p
						className="cpops-lp__hidden-note"
						style={ {
							position: 'absolute',
							inset: 0,
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							color: darkPreview ? '#e5e7eb' : '#374151',
						} }
					>
						{ __(
							'Launcher hidden by current settings',
							'cartpops'
						) }
					</p>
				) }
			</div>
		</div>
	);
}
