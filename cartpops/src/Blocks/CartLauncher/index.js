/**
 * CartLauncher block — Editor registration.
 *
 * Floating cart button. Server-side rendered with editor placeholder.
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';

import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const { position, showCount } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Launcher Settings', 'cartpops' ) }>
					<SelectControl
						label={ __( 'Position', 'cartpops' ) }
						value={ position }
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
							setAttributes( { position: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show item count', 'cartpops' ) }
						checked={ showCount }
						onChange={ ( value ) =>
							setAttributes( { showCount: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div
					style={ {
						display: 'inline-flex',
						alignItems: 'center',
						justifyContent: 'center',
						width: '56px',
						height: '56px',
						borderRadius: '50%',
						background: '#6f23e1',
						color: '#ffffff',
						boxShadow: '0 4px 12px rgba(111, 35, 225, 0.3)',
						position: 'relative',
					} }
				>
					<svg
						width="22"
						height="22"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
					>
						<circle cx="9" cy="21" r="1" />
						<circle cx="20" cy="21" r="1" />
						<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
					</svg>
					{ showCount && (
						<span
							style={ {
								position: 'absolute',
								top: '-2px',
								right: '-2px',
								background: '#ef4444',
								color: '#fff',
								fontSize: '10px',
								fontWeight: 700,
								width: '18px',
								height: '18px',
								borderRadius: '50%',
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'center',
							} }
						>
							3
						</span>
					) }
				</div>
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,

	save() {
		return null;
	},
} );
