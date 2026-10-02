/**
 * Searchable page exclusions for the floating launcher.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

export const MAX_HIDDEN_PAGE_IDS = 500;
const HYDRATION_PAGE_SIZE = 100;
const SEARCH_PAGE_SIZE = 20;
const SEARCH_DEBOUNCE_MS = 300;
const MIN_SEARCH_LENGTH = 2;
export const MAX_PAGE_SEARCH_LENGTH = 100;
export const MAX_PAGE_TITLE_LENGTH = 200;

/**
 * Convert the controlled value to the server's closed ID-list contract.
 *
 * @param {unknown} value Candidate page identifiers.
 * @param {number}  limit Maximum identifiers to retain.
 * @return {number[]} Ordered unique positive integer identifiers.
 */
export function normalizePageIds( value, limit = MAX_HIDDEN_PAGE_IDS ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	const normalizedLimit =
		Number.isSafeInteger( limit ) && limit > 0
			? limit
			: MAX_HIDDEN_PAGE_IDS;
	const ids = [];
	const seen = new Set();

	for ( const candidate of value ) {
		let id = null;
		if ( Number.isSafeInteger( candidate ) && candidate > 0 ) {
			id = candidate;
		} else if (
			typeof candidate === 'string' &&
			/^[1-9][0-9]*$/.test( candidate )
		) {
			const parsed = Number( candidate );
			if ( Number.isSafeInteger( parsed ) ) {
				id = parsed;
			}
		}

		if ( null === id || seen.has( id ) ) {
			continue;
		}

		seen.add( id );
		ids.push( id );
		if ( ids.length >= normalizedLimit ) {
			break;
		}
	}

	return ids;
}

/**
 * Decode a REST title to plain text. React remains the only markup renderer.
 *
 * @param {unknown} value REST `title.rendered` value.
 * @return {string} Plain-text title.
 */
function titleToText( value ) {
	if ( typeof value !== 'string' || '' === value ) {
		return '';
	}

	if ( typeof DOMParser === 'undefined' ) {
		return value
			.replace( /<[^>]*>/g, '' )
			.trim()
			.slice( 0, MAX_PAGE_TITLE_LENGTH );
	}

	return (
		new DOMParser()
			.parseFromString( value, 'text/html' )
			.body.textContent?.trim()
			.slice( 0, MAX_PAGE_TITLE_LENGTH ) || ''
	);
}

/**
 * Accept only the bounded page fields requested from WordPress core.
 *
 * @param {unknown} candidate REST response item.
 * @return {{ id: number, title: string }|null} Safe page choice.
 */
function normalizePage( candidate ) {
	if (
		! candidate ||
		typeof candidate !== 'object' ||
		! Number.isSafeInteger( candidate.id ) ||
		candidate.id < 1
	) {
		return null;
	}

	return {
		id: candidate.id,
		title: titleToText( candidate.title?.rendered ),
	};
}

/**
 * Split selected IDs into requests accepted by the core REST collection.
 *
 * @param {number[]} ids Page identifiers.
 * @return {number[][]} Bounded request chunks.
 */
function chunkPageIds( ids ) {
	const chunks = [];
	for ( let index = 0; index < ids.length; index += HYDRATION_PAGE_SIZE ) {
		chunks.push( ids.slice( index, index + HYDRATION_PAGE_SIZE ) );
	}
	return chunks;
}

/**
 * @param {number[]} ids Page identifiers to hydrate.
 * @return {string} Core Pages REST path.
 */
function hydrationPath( ids ) {
	return `/wp/v2/pages?include=${ ids.join( ',' ) }&per_page=${
		ids.length
	}&orderby=include&_fields=id%2Ctitle`;
}

/**
 * @param {string} query Search query.
 * @return {string} Bounded core Pages REST path.
 */
function searchPath( query ) {
	return `/wp/v2/pages?search=${ encodeURIComponent(
		query
	) }&per_page=${ SEARCH_PAGE_SIZE }&orderby=relevance&_fields=id%2Ctitle`;
}

/**
 * Search and manage the Pages on which the floating launcher is hidden.
 *
 * @param {Object}   props
 * @param {unknown}  props.pageIds  Controlled selected page identifiers.
 * @param {Function} props.onChange Publish the canonical selected IDs.
 */
export default function PageExclusionsControl( { pageIds, onChange } ) {
	const selectedIds = normalizePageIds( pageIds );
	const selectedIdsKey = selectedIds.join( ',' );
	const selectedIdSet = new Set( selectedIds );
	const [ pagesById, setPagesById ] = useState( {} );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ searchComplete, setSearchComplete ] = useState( false );
	const [ searchError, setSearchError ] = useState( false );
	const [ hydrationError, setHydrationError ] = useState( false );
	const [ hydrationRetry, setHydrationRetry ] = useState( 0 );
	const mountedRef = useRef( true );
	const debounceRef = useRef( null );
	const hydrationEpochRef = useRef( 0 );
	const searchEpochRef = useRef( 0 );
	const hydratedIdsRef = useRef( new Set() );

	useEffect( () => {
		const hydrationEpoch = hydrationEpochRef;
		const searchEpoch = searchEpochRef;
		const debounce = debounceRef;
		mountedRef.current = true;
		return () => {
			mountedRef.current = false;
			++hydrationEpoch.current;
			++searchEpoch.current;
			if ( debounce.current ) {
				clearTimeout( debounce.current );
				debounce.current = null;
			}
		};
	}, [] );

	useEffect( () => {
		const hydrationEpoch = hydrationEpochRef;
		const epoch = ++hydrationEpoch.current;
		const missingIds = selectedIds.filter(
			( id ) => ! hydratedIdsRef.current.has( id )
		);
		if ( 0 === missingIds.length ) {
			setHydrationError( false );
			return undefined;
		}

		setHydrationError( false );
		const requests = chunkPageIds( missingIds ).map( ( ids ) => ( {
			ids,
			request: Promise.resolve().then( () =>
				apiFetch( { path: hydrationPath( ids ) } )
			),
		} ) );

		Promise.allSettled( requests.map( ( { request } ) => request ) ).then(
			( outcomes ) => {
				if (
					! mountedRef.current ||
					hydrationEpoch.current !== epoch
				) {
					return;
				}

				let hasFailure = false;
				const hydratedPages = [];
				outcomes.forEach( ( outcome, index ) => {
					if ( 'fulfilled' !== outcome.status ) {
						hasFailure = true;
						return;
					}

					requests[ index ].ids.forEach( ( id ) =>
						hydratedIdsRef.current.add( id )
					);
					if ( Array.isArray( outcome.value ) ) {
						outcome.value.forEach( ( candidate ) => {
							const page = normalizePage( candidate );
							if (
								page &&
								requests[ index ].ids.includes( page.id )
							) {
								hydratedPages.push( page );
							}
						} );
					}
				} );

				if ( hydratedPages.length > 0 ) {
					setPagesById( ( current ) => {
						const next = { ...current };
						hydratedPages.forEach( ( page ) => {
							next[ page.id ] = page;
						} );
						return next;
					} );
				}
				setHydrationError( hasFailure );
			}
		);

		return () => {
			if ( hydrationEpoch.current === epoch ) {
				++hydrationEpoch.current;
			}
		};
		// selectedIds is represented by its canonical key.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ hydrationRetry, selectedIdsKey ] );

	const handleQueryChange = ( value ) => {
		const nextQuery = String( value ).slice( 0, MAX_PAGE_SEARCH_LENGTH );
		const epoch = ++searchEpochRef.current;
		setQuery( nextQuery );
		setResults( [] );
		setSearchComplete( false );
		setSearchError( false );
		setIsSearching( false );

		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
			debounceRef.current = null;
		}

		const trimmedQuery = nextQuery.trim();
		if ( trimmedQuery.length < MIN_SEARCH_LENGTH ) {
			return;
		}

		debounceRef.current = setTimeout( () => {
			debounceRef.current = null;
			if ( ! mountedRef.current || searchEpochRef.current !== epoch ) {
				return;
			}

			setIsSearching( true );
			Promise.resolve()
				.then( () => apiFetch( { path: searchPath( trimmedQuery ) } ) )
				.then( ( data ) => {
					if (
						! mountedRef.current ||
						searchEpochRef.current !== epoch
					) {
						return;
					}

					const uniqueResults = new Map();
					if ( Array.isArray( data ) ) {
						data.slice( 0, SEARCH_PAGE_SIZE ).forEach( ( item ) => {
							const page = normalizePage( item );
							if ( page && ! uniqueResults.has( page.id ) ) {
								uniqueResults.set( page.id, page );
							}
						} );
					}
					setResults( Array.from( uniqueResults.values() ) );
					setSearchComplete( true );
				} )
				.catch( () => {
					if (
						! mountedRef.current ||
						searchEpochRef.current !== epoch
					) {
						return;
					}
					setResults( [] );
					setSearchError( true );
				} )
				.finally( () => {
					if (
						mountedRef.current &&
						searchEpochRef.current === epoch
					) {
						setIsSearching( false );
					}
				} );
		}, SEARCH_DEBOUNCE_MS );
	};

	const addPage = ( page ) => {
		const nextIds = normalizePageIds( [ ...selectedIds, page.id ] );
		hydratedIdsRef.current.add( page.id );
		setPagesById( ( current ) => ( {
			...current,
			[ page.id ]: page,
		} ) );
		onChange( nextIds );
		++searchEpochRef.current;
		setQuery( '' );
		setResults( [] );
		setSearchComplete( false );
		setSearchError( false );
		setIsSearching( false );
	};

	const removePage = ( pageId ) => {
		onChange( selectedIds.filter( ( id ) => id !== pageId ) );
	};

	const availableResults = results.filter(
		( page ) => ! selectedIdSet.has( page.id )
	);
	const selectionLimitReached = selectedIds.length >= MAX_HIDDEN_PAGE_IDS;

	return (
		<section
			className="cpops-page-exclusions"
			aria-labelledby="cpops-page-exclusions-heading"
		>
			<h4
				className="cpops-page-exclusions__heading"
				id="cpops-page-exclusions-heading"
			>
				{ __( 'Hide launcher on pages', 'cartpops' ) }
			</h4>
			<p className="cpops-page-exclusions__help">
				{ __(
					'Select pages where the floating cart launcher should not appear. Existing exclusions stay selected until you remove them.',
					'cartpops'
				) }
			</p>

			{ selectedIds.length > 0 && (
				<ul
					className="cpops-page-exclusions__selected"
					aria-label={ __(
						'Pages with the launcher hidden',
						'cartpops'
					) }
				>
					{ selectedIds.map( ( id ) => {
						const page = pagesById[ id ];
						const label = page
							? page.title ||
							  sprintf(
									/* translators: %d: WordPress page ID. */
									__( 'Page #%d (untitled)', 'cartpops' ),
									id
							  )
							: sprintf(
									/* translators: %d: WordPress page ID. */
									__( 'Page #%d (unavailable)', 'cartpops' ),
									id
							  );

						return (
							<li key={ id }>
								<span>{ label }</span>
								<button
									type="button"
									className="cpops-page-exclusions__remove"
									onClick={ () => removePage( id ) }
									aria-label={ sprintf(
										/* translators: %s: Page title. */
										__(
											'Remove %s from hidden pages',
											'cartpops'
										),
										label
									) }
								>
									<span aria-hidden="true">&times;</span>
								</button>
							</li>
						);
					} ) }
				</ul>
			) }

			{ hydrationError && (
				<div className="cpops-page-exclusions__notice">
					<p role="status">
						{ __(
							'Some selected page names could not be loaded. Their exclusions are still preserved.',
							'cartpops'
						) }
					</p>
					<button
						type="button"
						onClick={ () => {
							setHydrationError( false );
							setHydrationRetry( ( current ) => current + 1 );
						} }
					>
						{ __( 'Retry page names', 'cartpops' ) }
					</button>
				</div>
			) }

			<TextControl
				label={ __( 'Search pages', 'cartpops' ) }
				help={ __(
					'Type at least two characters, then choose a page below.',
					'cartpops'
				) }
				value={ query }
				onChange={ handleQueryChange }
				disabled={ selectionLimitReached }
				maxLength={ MAX_PAGE_SEARCH_LENGTH }
			/>

			<div className="cpops-page-exclusions__status" aria-live="polite">
				{ selectionLimitReached && (
					<p>
						{ __(
							'The maximum number of page exclusions has been selected.',
							'cartpops'
						) }
					</p>
				) }
				{ isSearching && (
					<p>{ __( 'Searching pages…', 'cartpops' ) }</p>
				) }
				{ searchError && (
					<p role="alert">
						{ __(
							'Pages could not be loaded. Try searching again.',
							'cartpops'
						) }
					</p>
				) }
				{ ! searchError &&
					! isSearching &&
					searchComplete &&
					0 === availableResults.length && (
						<p>{ __( 'No matching pages found.', 'cartpops' ) }</p>
					) }
			</div>

			{ ! isSearching && availableResults.length > 0 && (
				<ul
					className="cpops-page-exclusions__results"
					aria-label={ __( 'Matching pages', 'cartpops' ) }
				>
					{ availableResults.map( ( page ) => {
						const label =
							page.title ||
							sprintf(
								/* translators: %d: WordPress page ID. */
								__( 'Page #%d (untitled)', 'cartpops' ),
								page.id
							);
						return (
							<li key={ page.id }>
								<button
									type="button"
									onClick={ () => addPage( page ) }
								>
									<span>{ label }</span>
									<small>
										{ sprintf(
											/* translators: %d: WordPress page ID. */
											__( 'Page ID: %d', 'cartpops' ),
											page.id
										) }
									</small>
								</button>
							</li>
						);
					} ) }
				</ul>
			) }
		</section>
	);
}
