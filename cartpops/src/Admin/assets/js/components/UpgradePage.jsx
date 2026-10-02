/**
 * Accessible fallback for settings that require the physically separate Pro app.
 */

import { __, sprintf } from '@wordpress/i18n';

export default function UpgradePage( { feature } ) {
	const upgradeUrl = window.cartpopsAdmin?.upgradeUrl || '';
	const titleId = `cpops-upgrade-${ String( feature )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' ) }`;

	return (
		<section
			className="cpops-page cpops-upgrade"
			aria-labelledby={ titleId }
		>
			<div className="cpops-settings-group">
				<span className="cpops-admin__pro-badge" aria-hidden="true">
					PRO
				</span>
				<h2 id={ titleId }>
					{ sprintf(
						/* translators: %s: paid feature name. */
						__( '%s is available in CartPops Pro', 'cartpops' ),
						feature
					) }
				</h2>
				<p className="cpops-page__description">
					{ __(
						'Upgrade to configure this feature. Your existing CartPops settings remain unchanged.',
						'cartpops'
					) }
				</p>
				{ upgradeUrl && (
					<a
						className="cpops-btn cpops-btn--primary"
						href={ upgradeUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Upgrade to CartPops Pro', 'cartpops' ) }
					</a>
				) }
			</div>
		</section>
	);
}
