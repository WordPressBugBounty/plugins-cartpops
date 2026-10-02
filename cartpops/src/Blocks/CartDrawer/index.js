/**
 * CartDrawer block — Editor registration.
 *
 * This block is rendered server-side only. In the editor we show a placeholder.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

import metadata from './block.json';

function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<div
				style={ {
					padding: '40px 20px',
					textAlign: 'center',
					background: '#f8f9fb',
					border: '1px dashed #c4c7cc',
					borderRadius: '8px',
				} }
			>
				<p
					style={ {
						margin: 0,
						fontSize: '14px',
						fontWeight: 600,
						color: '#1a1a2e',
					} }
				>
					{ __( 'CartPops Cart Drawer', 'cartpops' ) }
				</p>
				<p
					style={ {
						margin: '4px 0 0',
						fontSize: '12px',
						color: '#6b7280',
					} }
				>
					{ __(
						'The cart drawer will appear on the frontend when triggered.',
						'cartpops'
					) }
				</p>
			</div>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,

	save() {
		// Server-side rendered.
		return null;
	},
} );
