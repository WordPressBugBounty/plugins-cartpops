/**
 * Color Picker component with swatch and popover.
 */

import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ColorPicker as WPColorPicker } from '@wordpress/components';

export function normalizeColorPickerValue( value, enableAlpha = false ) {
	if (
		! enableAlpha ||
		typeof value !== 'string' ||
		! /^#[0-9a-f]{8}$/i.test( value )
	) {
		return value;
	}

	const red = Number.parseInt( value.slice( 1, 3 ), 16 );
	const green = Number.parseInt( value.slice( 3, 5 ), 16 );
	const blue = Number.parseInt( value.slice( 5, 7 ), 16 );
	const alpha = Number.parseInt( value.slice( 7, 9 ), 16 ) / 255;
	const canonicalAlpha = Number( alpha.toFixed( 6 ) ).toString();

	return `rgba(${ red }, ${ green }, ${ blue }, ${ canonicalAlpha })`;
}

export default function ColorPicker( {
	label,
	value,
	onChange,
	id,
	enableAlpha = false,
} ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const ref = useRef( null );

	// Close on outside click.
	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}

		const handleClick = ( e ) => {
			if ( ref.current && ! ref.current.contains( e.target ) ) {
				setIsOpen( false );
			}
		};

		document.addEventListener( 'mousedown', handleClick );
		return () => document.removeEventListener( 'mousedown', handleClick );
	}, [ isOpen ] );

	// Close on Escape.
	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}

		const handleKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				setIsOpen( false );
			}
		};

		document.addEventListener( 'keydown', handleKey );
		return () => document.removeEventListener( 'keydown', handleKey );
	}, [ isOpen ] );

	return (
		<div className="cpops-color-field" ref={ ref } id={ id }>
			<button
				className="cpops-color-field__trigger"
				onClick={ () => setIsOpen( ! isOpen ) }
				aria-expanded={ isOpen }
				aria-label={ `${ label }: ${ value }` }
				type="button"
			>
				<span
					className="cpops-color-field__swatch"
					style={ { backgroundColor: value } }
				/>
				<span className="cpops-color-field__info">
					<span className="cpops-color-field__label">{ label }</span>
					<span className="cpops-color-field__value">{ value }</span>
				</span>
			</button>

			{ isOpen && (
				<div className="cpops-color-field__popover">
					<WPColorPicker
						color={ value }
						onChange={ ( nextValue ) =>
							onChange(
								normalizeColorPickerValue(
									nextValue,
									enableAlpha
								)
							)
						}
						enableAlpha={ enableAlpha }
					/>
					<div className="cpops-color-field__input-row">
						<input
							type="text"
							value={ value }
							onChange={ ( e ) => onChange( e.target.value ) }
							className="cpops-color-field__hex-input"
							maxLength={ enableAlpha ? 64 : 7 }
							aria-label={
								enableAlpha
									? __( 'CSS color value', 'cartpops' )
									: __( 'Hex color value', 'cartpops' )
							}
						/>
					</div>
				</div>
			) }
		</div>
	);
}
