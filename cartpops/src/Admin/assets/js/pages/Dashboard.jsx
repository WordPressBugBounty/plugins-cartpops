/**
 * Dashboard page.
 */

import { __ } from '@wordpress/i18n';
import { isProFeature } from '../feature-editions';

/* eslint-disable max-len */
const RESOURCE_ICONS = {
	docs: (
		<svg
			width="20"
			height="20"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M4 19.5A2.5 2.5 0 016.5 17H20" />
			<path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" />
		</svg>
	),
	changelog: (
		<svg
			width="20"
			height="20"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<polyline points="12 8 12 12 14 14" />
			<circle cx="12" cy="12" r="10" />
		</svg>
	),
	support: (
		<svg
			width="20"
			height="20"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="10" />
			<circle cx="12" cy="12" r="4" />
			<line x1="4.93" y1="4.93" x2="9.17" y2="9.17" />
			<line x1="14.83" y1="14.83" x2="19.07" y2="19.07" />
			<line x1="14.83" y1="9.17" x2="19.07" y2="4.93" />
			<line x1="4.93" y1="19.07" x2="9.17" y2="14.83" />
		</svg>
	),
};
/* eslint-enable max-len */

export default function Dashboard( {
	settings,
	Insights,
	hasProAdmin = false,
} ) {
	const isEnabled = settings?.general?.enabled !== false;

	const features = [
		{
			label: __( 'Cart Drawer', 'cartpops' ),
			enabled: settings?.general?.enabled !== false,
			href: '#drawer',
		},
		{
			label: __( 'Cart Launcher', 'cartpops' ),
			enabled: settings?.launcher?.enabled || false,
			href: '#launcher',
		},
		{
			label: __( 'Shipping Meter', 'cartpops' ),
			enabled:
				hasProAdmin && ( settings?.shipping_meter?.enabled || false ),
			href: '#shipping-meter',
			pro: isProFeature( 'shipping-meter' ),
		},
		{
			label: __( 'Recommendations', 'cartpops' ),
			enabled: settings?.recommendations?.enabled || false,
			href: '#recommendations',
			pro: isProFeature( 'recommendations' ),
		},
		{
			label: __( 'Smart Add-ons', 'cartpops' ),
			enabled:
				hasProAdmin &&
				( settings?.smart_addons?.addons?.shipping_protection
					?.enabled ||
					false ),
			href: '#smart-addons',
			pro: isProFeature( 'smart-addons' ),
		},
		{
			label: __( 'Smart Bar', 'cartpops' ),
			enabled:
				hasProAdmin &&
				settings?.notifications?.drawer_bar_enabled === true,
			href: '#smart-bar',
			pro: isProFeature( 'smart-bar' ),
		},
	];

	return (
		<div className="cpops-page cpops-page--dashboard">
			<div className="cpops-dash-layout">
				{ /* Left: Main Content */ }
				<div className="cpops-dash-main">
					<div className="cpops-page__header">
						<h2>{ __( 'Dashboard', 'cartpops' ) }</h2>
						<p className="cpops-page__description">
							{ __(
								'Welcome to CartPops. Manage your cart drawer and boost conversions.',
								'cartpops'
							) }
						</p>
					</div>

					{ /* Status Cards */ }
					<div className="cpops-cards">
						<a
							href="#advanced"
							className={ `cpops-card cpops-card--status cpops-card--link ${
								isEnabled
									? 'cpops-card--success'
									: 'cpops-card--muted'
							}` }
						>
							<div className="cpops-card__icon">
								{ isEnabled ? '●' : '○' }
							</div>
							<div className="cpops-card__content">
								<h3>{ __( 'Plugin Status', 'cartpops' ) }</h3>
								<p>
									{ isEnabled
										? __( 'Active', 'cartpops' )
										: __( 'Inactive', 'cartpops' ) }
								</p>
							</div>
						</a>

						<a
							href="#drawer"
							className="cpops-card cpops-card--link"
						>
							<div className="cpops-card__content">
								<h3>{ __( 'Drawer Position', 'cartpops' ) }</h3>
								<p>
									{ settings?.drawer?.position === 'left'
										? __( 'Left', 'cartpops' )
										: __( 'Right', 'cartpops' ) }
								</p>
							</div>
						</a>

						<a
							href="#launcher"
							className="cpops-card cpops-card--link"
						>
							<div className="cpops-card__content">
								<h3>{ __( 'Cart Launcher', 'cartpops' ) }</h3>
								<p>
									{ settings?.launcher?.enabled
										? __( 'Visible', 'cartpops' )
										: __( 'Hidden', 'cartpops' ) }
								</p>
							</div>
						</a>
					</div>

					{ Insights && <Insights /> }

					{ /* Features Overview */ }
					<div className="cpops-dash-section">
						<h3 className="cpops-dash-section__title">
							{ __( 'Features', 'cartpops' ) }
						</h3>
					</div>
					<div className="cpops-dash-features">
						{ features.map( ( feature ) => (
							<a
								key={ feature.label }
								href={ feature.href }
								className="cpops-dash-feature"
							>
								<span
									className={ `cpops-dash-feature__dot ${
										feature.enabled
											? 'cpops-dash-feature__dot--on'
											: 'cpops-dash-feature__dot--off'
									}` }
								/>
								<span className="cpops-dash-feature__label">
									{ feature.label }
									{ feature.pro && ! hasProAdmin && (
										<span className="cpops-admin__pro-badge">
											PRO
										</span>
									) }
								</span>
								<span className="cpops-dash-feature__status">
									{ feature.enabled
										? __( 'On', 'cartpops' )
										: __( 'Off', 'cartpops' ) }
								</span>
								<span className="cpops-dash-feature__arrow">
									→
								</span>
							</a>
						) ) }
					</div>

					{ /* Environment Footer */ }
					<div className="cpops-dash-footer">
						<span>
							CartPops v
							{ window.cartpopsAdmin?.version || '2.0.0' }
						</span>
						<span className="cpops-dash-footer__sep" />
						<span>
							{ window.cartpopsAdmin?.theme?.name ||
								__( 'Unknown Theme', 'cartpops' ) }
						</span>
						{ hasProAdmin && (
							<>
								<span className="cpops-dash-footer__sep" />
								<span>Pro</span>
							</>
						) }
					</div>
				</div>

				{ /* Right: Resources Sidebar */ }
				<aside className="cpops-dash-sidebar">
					<h3 className="cpops-dash-sidebar__title">
						{ __( 'Resources', 'cartpops' ) }
					</h3>

					<div className="cpops-dash-resources">
						<a
							href="https://cartpops.com/docs"
							target="_blank"
							rel="noopener noreferrer"
							className="cpops-dash-resource"
						>
							<span className="cpops-dash-resource__icon">
								{ RESOURCE_ICONS.docs }
							</span>
							<span className="cpops-dash-resource__text">
								<span className="cpops-dash-resource__label">
									{ __( 'Documentation', 'cartpops' ) }
								</span>
								<span className="cpops-dash-resource__desc">
									{ __(
										'Guides and setup instructions',
										'cartpops'
									) }
								</span>
							</span>
						</a>

						<a
							href="https://cartpops.com/changelog"
							target="_blank"
							rel="noopener noreferrer"
							className="cpops-dash-resource"
						>
							<span className="cpops-dash-resource__icon">
								{ RESOURCE_ICONS.changelog }
							</span>
							<span className="cpops-dash-resource__text">
								<span className="cpops-dash-resource__label">
									{ __( 'Changelog', 'cartpops' ) }
								</span>
								<span className="cpops-dash-resource__desc">
									{ __(
										'Latest updates and releases',
										'cartpops'
									) }
								</span>
							</span>
						</a>

						<a
							href="https://cartpops.com/support"
							target="_blank"
							rel="noopener noreferrer"
							className="cpops-dash-resource"
						>
							<span className="cpops-dash-resource__icon">
								{ RESOURCE_ICONS.support }
							</span>
							<span className="cpops-dash-resource__text">
								<span className="cpops-dash-resource__label">
									{ __( 'Support', 'cartpops' ) }
								</span>
								<span className="cpops-dash-resource__desc">
									{ __(
										'Get help from our team',
										'cartpops'
									) }
								</span>
							</span>
						</a>
					</div>
				</aside>
			</div>
		</div>
	);
}
