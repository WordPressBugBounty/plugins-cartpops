/**
 * CartPops Admin App.
 */

import {
	useState,
	useCallback,
	useEffect,
	useMemo,
	useReducer,
	useRef,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import Dashboard from './pages/Dashboard';
import DrawerEditor from './pages/DrawerEditor';
import LauncherSettings from './pages/LauncherSettings';
import RecommendationSettings from './pages/RecommendationSettings';
import AdvancedSettings from './pages/AdvancedSettings';
import UpgradePage from './components/UpgradePage';
import Wordmark from './components/Wordmark';
import { isProFeature } from './feature-editions';
import { getProAdminExtension } from './pro-extension';

/* eslint-disable max-len */
const NAV_ICONS = {
	dashboard: (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<rect x="3" y="3" width="7" height="7" rx="1" />
			<rect x="14" y="3" width="7" height="7" rx="1" />
			<rect x="3" y="14" width="7" height="7" rx="1" />
			<rect x="14" y="14" width="7" height="7" rx="1" />
		</svg>
	),
	drawer: (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<rect x="3" y="3" width="18" height="18" rx="2" />
			<line x1="15" y1="3" x2="15" y2="21" />
		</svg>
	),
	launcher: (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="10" />
			<path d="M8 12l2 2 4-4" />
		</svg>
	),
	recommendations: (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
		</svg>
	),
	'smart-addons': (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M12 2L2 7l10 5 10-5-10-5z" />
			<path d="M2 17l10 5 10-5" />
			<path d="M2 12l10 5 10-5" />
		</svg>
	),
	'smart-bar': (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
		</svg>
	),
	'bundle-builder': (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" />
			<polyline points="3.27 6.96 12 12.01 20.73 6.96" />
			<line x1="12" y1="22.08" x2="12" y2="12" />
		</svg>
	),
	analytics: (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<line x1="18" y1="20" x2="18" y2="10" />
			<line x1="12" y1="20" x2="12" y2="4" />
			<line x1="6" y1="20" x2="6" y2="14" />
		</svg>
	),
	advanced: (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<circle cx="12" cy="12" r="3" />
			<path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
		</svg>
	),
};
/* eslint-enable max-len */

const NAV_SECTIONS = [
	{
		label: __( 'Setup', 'cartpops' ),
		items: [
			{ key: 'dashboard', label: __( 'Dashboard', 'cartpops' ) },
			{ key: 'drawer', label: __( 'Drawer', 'cartpops' ) },
			{ key: 'launcher', label: __( 'Launcher', 'cartpops' ) },
		],
	},
	{
		label: __( 'Features', 'cartpops' ),
		items: [
			{
				key: 'recommendations',
				label: __( 'Recommendations', 'cartpops' ),
				pro: isProFeature( 'recommendations' ),
			},
			{
				key: 'smart-addons',
				label: __( 'Smart add-ons', 'cartpops' ),
				pro: isProFeature( 'smart-addons' ),
			},
			{
				key: 'smart-bar',
				label: __( 'Smart Bar', 'cartpops' ),
				pro: isProFeature( 'smart-bar' ),
			},
			{
				key: 'bundle-builder',
				label: __( 'Bundle Builder', 'cartpops' ),
				pro: isProFeature( 'bundle-builder' ),
			},
		],
	},
	{
		label: __( 'Insights', 'cartpops' ),
		items: [
			{
				key: 'analytics',
				label: __( 'Analytics', 'cartpops' ),
				pro: isProFeature( 'analytics' ),
			},
		],
	},
	{
		label: __( 'System', 'cartpops' ),
		items: [ { key: 'advanced', label: __( 'Advanced', 'cartpops' ) } ],
	},
];

// Flat page map for hash routing.
const PAGES = {};
NAV_SECTIONS.forEach( ( section ) => {
	section.items.forEach( ( item ) => {
		PAGES[ item.key ] = item;
	} );
} );

// Hashes that open a tab inside a page rather than a page of their own.
const TAB_ROUTES = {
	'shipping-meter': { page: 'drawer', tab: 'shipping-meter' },
};

function resolveHashRoute( hash ) {
	if ( TAB_ROUTES[ hash ] ) {
		return TAB_ROUTES[ hash ];
	}

	return hash && PAGES[ hash ] ? { page: hash, tab: null } : null;
}

const MAX_SETTINGS_BATCH_SIZE = 50;
const MAX_SETTINGS_PATH_LENGTH = 160;
const MAX_SETTINGS_PATH_DEPTH = 12;
const BLOCKED_SETTINGS_PATH_SEGMENTS = new Set( [
	'__proto__',
	'prototype',
	'constructor',
] );
const SETTINGS_PATH_SEGMENT_PATTERN = /^[A-Za-z_][A-Za-z0-9_-]*$/;

function parseSettingsPath( path ) {
	if (
		typeof path !== 'string' ||
		path.length === 0 ||
		path.length > MAX_SETTINGS_PATH_LENGTH ||
		path.trim() !== path
	) {
		return null;
	}

	const segments = path.split( '.' );
	if (
		segments.length === 0 ||
		segments.length > MAX_SETTINGS_PATH_DEPTH ||
		segments.some(
			( segment ) =>
				! SETTINGS_PATH_SEGMENT_PATTERN.test( segment ) ||
				BLOCKED_SETTINGS_PATH_SEGMENTS.has( segment.toLowerCase() )
		)
	) {
		return null;
	}

	return segments;
}

function normalizeSettingsBatch( batch ) {
	let entries;
	if ( Array.isArray( batch ) ) {
		entries = batch;
	} else if ( batch instanceof Map ) {
		entries = Array.from( batch.entries() );
	} else if ( batch && typeof batch === 'object' ) {
		const prototype = Object.getPrototypeOf( batch );
		if ( prototype !== Object.prototype && null !== prototype ) {
			return [];
		}
		entries = Object.entries( batch );
	} else {
		return [];
	}

	if ( entries.length === 0 || entries.length > MAX_SETTINGS_BATCH_SIZE ) {
		return [];
	}

	const normalized = new Map();
	entries.forEach( ( entry ) => {
		if ( ! Array.isArray( entry ) || entry.length !== 2 ) {
			return;
		}
		const [ path, value ] = entry;
		const segments = parseSettingsPath( path );
		if ( ! segments || undefined === value ) {
			return;
		}
		normalized.set( path, { segments, value } );
	} );

	return Array.from( normalized.values() );
}

function settingAtPath( settings, segments ) {
	let current = settings;
	for ( const segment of segments ) {
		if (
			null === current ||
			typeof current !== 'object' ||
			! Object.prototype.hasOwnProperty.call( current, segment )
		) {
			return { exists: false, value: undefined };
		}
		current = current[ segment ];
	}

	return { exists: true, value: current };
}

function hasEffectiveSettingsUpdates( settings, updates ) {
	return updates.some( ( { segments, value } ) => {
		const current = settingAtPath( settings, segments );
		return ! current.exists || ! Object.is( current.value, value );
	} );
}

function applySettingsBatch( settings, updates ) {
	if ( ! hasEffectiveSettingsUpdates( settings, updates ) ) {
		return settings;
	}

	const next = structuredClone( settings );
	updates.forEach( ( { segments, value } ) => {
		let current = next;
		for ( let index = 0; index < segments.length - 1; index++ ) {
			const segment = segments[ index ];
			if (
				! Object.prototype.hasOwnProperty.call( current, segment ) ||
				null === current[ segment ] ||
				typeof current[ segment ] !== 'object' ||
				Array.isArray( current[ segment ] )
			) {
				current[ segment ] = {};
			}
			current = current[ segment ];
		}
		current[ segments[ segments.length - 1 ] ] = value;
	} );

	return next;
}

/**
 * Undo/redo history reducer using the classic
 * `{ past, present, future }` model.
 *
 * - SET applies an updater to `present`, pushes the previous present onto
 *   `past`, and clears `future` (a new edit invalidates any redo stack).
 * - UNDO moves the last `past` entry into `present` and pushes the previous
 *   present onto `future`.
 * - REDO moves the first `future` entry into `present` and pushes the
 *   previous present onto `past`.
 * - RESET replaces `present` (e.g. after a save) and discards all history.
 *
 * No assumptions are made about React batching: every transition is derived
 * purely from the current state value.
 * @param {Object} state
 * @param {Object} action
 */
function historyReducer( state, action ) {
	switch ( action.type ) {
		case 'SET': {
			const next = action.updater( state.present );
			if ( next === state.present ) {
				return state;
			}
			return {
				past: [ ...state.past, state.present ],
				present: next,
				future: [],
			};
		}
		case 'UNDO': {
			if ( state.past.length === 0 ) {
				return state;
			}
			const previous = state.past[ state.past.length - 1 ];
			return {
				past: state.past.slice( 0, -1 ),
				present: previous,
				future: [ state.present, ...state.future ],
			};
		}
		case 'REDO': {
			if ( state.future.length === 0 ) {
				return state;
			}
			const next = state.future[ 0 ];
			return {
				past: [ ...state.past, state.present ],
				present: next,
				future: state.future.slice( 1 ),
			};
		}
		case 'RESET': {
			return {
				past: [],
				present: action.present,
				future: [],
			};
		}
		default:
			return state;
	}
}

export default function App() {
	const proExtension = getProAdminExtension();
	const hasProAdmin = null !== proExtension;
	const [ route, setRoute ] = useState(
		() =>
			resolveHashRoute( window.location.hash.slice( 1 ) ) || {
				page: 'dashboard',
				tab: null,
			}
	);
	const currentPage = route.page;
	const setCurrentPage = ( page ) => setRoute( { page, tab: null } );

	const [ historyState, dispatch ] = useReducer(
		historyReducer,
		undefined,
		() => ( {
			past: [],
			present: window.cartpopsAdmin?.settings || {},
			future: [],
		} )
	);
	const settings = historyState.present;

	const [ savedSettings, setSavedSettings ] = useState(
		() => window.cartpopsAdmin?.settings || {}
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ toast, setToast ] = useState( null );
	const settingsMutationInFlightRef = useRef( false );

	const hasChanges = useMemo(
		() => JSON.stringify( settings ) !== JSON.stringify( savedSettings ),
		[ settings, savedSettings ]
	);

	// Hash routing.
	useEffect( () => {
		const onHash = () => {
			const next = resolveHashRoute( window.location.hash.slice( 1 ) );
			if ( next ) {
				setRoute( next );
			}
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	// Auto-hide toast.
	useEffect( () => {
		if ( toast ) {
			const timer = setTimeout( () => setToast( null ), 3000 );
			return () => clearTimeout( timer );
		}
	}, [ toast ] );

	const updateSettings = useCallback( ( path, value ) => {
		// Push a new history entry by deriving the next present from the
		// current present inside the reducer (no batching assumptions).
		dispatch( {
			type: 'SET',
			updater: ( prev ) => {
				const next = structuredClone( prev );
				const keys = path.split( '.' );
				let current = next;
				for ( let i = 0; i < keys.length - 1; i++ ) {
					if ( ! current[ keys[ i ] ] ) {
						current[ keys[ i ] ] = {};
					}
					current = current[ keys[ i ] ];
				}
				current[ keys[ keys.length - 1 ] ] = value;
				return next;
			},
		} );
	}, [] );

	const updateSettingsBatch = useCallback( ( batch ) => {
		const updates = normalizeSettingsBatch( batch );
		if ( updates.length === 0 ) {
			return false;
		}

		dispatch( {
			type: 'SET',
			updater: ( prev ) => applySettingsBatch( prev, updates ),
		} );
		return true;
	}, [] );

	const undo = useCallback( () => {
		dispatch( { type: 'UNDO' } );
	}, [] );

	const redo = useCallback( () => {
		dispatch( { type: 'REDO' } );
	}, [] );

	const saveSettings = useCallback( async () => {
		if ( settingsMutationInFlightRef.current ) {
			return false;
		}

		settingsMutationInFlightRef.current = true;
		setIsSaving( true );
		try {
			const result = await apiFetch( {
				path: '/cartpops/v1/settings',
				method: 'POST',
				data: settings,
			} );
			setSavedSettings( result );
			dispatch( { type: 'RESET', present: result } );
			setToast( {
				type: 'success',
				message: __( 'Settings saved!', 'cartpops' ),
			} );
			return true;
		} catch ( err ) {
			setToast( {
				type: 'error',
				message: err.message || __( 'Failed to save.', 'cartpops' ),
			} );
			return false;
		} finally {
			settingsMutationInFlightRef.current = false;
			setIsSaving( false );
		}
	}, [ settings ] );

	const resetSettings = useCallback( async () => {
		if ( settingsMutationInFlightRef.current ) {
			return false;
		}

		settingsMutationInFlightRef.current = true;
		setIsSaving( true );
		try {
			const result = await apiFetch( {
				path: '/cartpops/v1/settings',
				method: 'DELETE',
			} );

			if (
				null === result ||
				typeof result !== 'object' ||
				Array.isArray( result )
			) {
				throw new Error();
			}

			setSavedSettings( result );
			dispatch( { type: 'RESET', present: result } );
			setToast( {
				type: 'success',
				message: __( 'Settings reset to defaults.', 'cartpops' ),
			} );
			return true;
		} catch ( err ) {
			const apiMessage =
				typeof err?.message === 'string' ? err.message.trim() : '';
			setToast( {
				type: 'error',
				message:
					apiMessage.length > 0 && apiMessage.length <= 500
						? apiMessage
						: __(
								'Settings could not be reset. Please try again.',
								'cartpops'
						  ),
			} );
			return false;
		} finally {
			settingsMutationInFlightRef.current = false;
			setIsSaving( false );
		}
	}, [] );

	// Keep the latest shortcut handlers/state in a ref so the document-level
	// keydown listener can be registered once (empty deps) without missing
	// updates to `hasChanges` or `saveSettings` between renders.
	const shortcutsRef = useRef();
	shortcutsRef.current = { hasChanges, saveSettings, undo, redo };

	// Keyboard shortcuts: Cmd/Ctrl+S save, Cmd/Ctrl+Z undo, Shift+Cmd/Ctrl+Z redo.
	useEffect( () => {
		const onKeydown = ( e ) => {
			const {
				hasChanges: changed,
				saveSettings: save,
				undo: doUndo,
				redo: doRedo,
			} = shortcutsRef.current;
			if ( ( e.metaKey || e.ctrlKey ) && e.key === 's' ) {
				e.preventDefault();
				if ( changed ) {
					save();
				}
			}
			// Cmd+Z: Undo.
			if ( ( e.metaKey || e.ctrlKey ) && e.key === 'z' && ! e.shiftKey ) {
				e.preventDefault();
				doUndo();
			}
			// Cmd+Shift+Z: Redo.
			if ( ( e.metaKey || e.ctrlKey ) && e.key === 'z' && e.shiftKey ) {
				e.preventDefault();
				doRedo();
			}
		};
		document.addEventListener( 'keydown', onKeydown );
		return () => document.removeEventListener( 'keydown', onKeydown );
	}, [] );

	const navigate = ( page ) => {
		window.location.hash = page;
		setCurrentPage( page );
	};

	const renderPage = () => {
		const props = { settings, updateSettings };
		switch ( currentPage ) {
			case 'drawer':
				return (
					<DrawerEditor
						key={ route.tab || 'layout' }
						{ ...props }
						initialTab={ route.tab || 'layout' }
						updateSettingsBatch={ updateSettingsBatch }
						ShippingMeterPanel={
							proExtension?.components?.ShippingMeterSettings
						}
						PreviewEnhancements={
							proExtension?.components?.DrawerPreviewEnhancements
						}
						RecommendationButtonPreview={
							proExtension?.components
								?.RecommendationButtonPreview
						}
						SecondaryActionPanel={
							proExtension?.components?.SecondaryActionSettings
						}
					/>
				);
			case 'launcher':
				return <LauncherSettings { ...props } />;
			case 'recommendations':
				return (
					<RecommendationSettings
						{ ...props }
						CustomSelection={
							proExtension?.components
								?.CustomRecommendationSelection
						}
						ButtonPresentation={
							proExtension?.components
								?.RecommendationButtonSettings
						}
					/>
				);
			case 'advanced':
				return (
					<AdvancedSettings
						{ ...props }
						onResetSettings={ resetSettings }
						isSettingsMutationPending={ isSaving }
						AnalyticsResetActions={
							proExtension?.components?.AnalyticsResetActions
						}
					/>
				);
			case 'smart-addons':
			case 'smart-bar':
			case 'bundle-builder':
			case 'analytics': {
				const ProPage = proExtension?.pages?.[ currentPage ];
				return ProPage ? (
					<ProPage { ...props } />
				) : (
					<UpgradePage feature={ PAGES[ currentPage ].label } />
				);
			}
			default:
				return (
					<Dashboard
						settings={ settings }
						Insights={ proExtension?.components?.DashboardInsights }
						hasProAdmin={ hasProAdmin }
					/>
				);
		}
	};

	return (
		<div className="cpops-admin">
			{ /* Header */ }
			<header className="cpops-admin__header">
				<div className="cpops-admin__brand">
					<Wordmark className="cpops-admin__logo" />
					<span className="cpops-admin__version">
						v{ window.cartpopsAdmin?.version || '2.0.0' }
					</span>
				</div>
				<div className="cpops-admin__actions">
					{ hasChanges && (
						<span className="cpops-admin__unsaved">
							{ __( 'Unsaved changes', 'cartpops' ) }
						</span>
					) }
					<button
						className="cpops-admin__save-btn"
						onClick={ saveSettings }
						disabled={ ! hasChanges || isSaving }
					>
						{ isSaving
							? __( 'Saving…', 'cartpops' )
							: __( 'Save Changes', 'cartpops' ) }
					</button>
				</div>
			</header>

			<div className="cpops-admin__body">
				{ /* Sidebar Navigation */ }
				<nav className="cpops-admin__nav">
					{ NAV_SECTIONS.map( ( section, idx ) => (
						<div
							key={ section.label }
							className="cpops-admin__nav-section"
						>
							{ idx > 0 && (
								<div className="cpops-admin__nav-divider" />
							) }
							<span className="cpops-admin__nav-label">
								{ section.label }
							</span>
							{ section.items.map( ( item ) => (
								<button
									key={ item.key }
									className={ `cpops-admin__nav-item ${
										currentPage === item.key
											? 'cpops-admin__nav-item--active'
											: ''
									} ${
										item.pro && ! hasProAdmin
											? 'cpops-admin__nav-item--pro'
											: ''
									}` }
									onClick={ () => navigate( item.key ) }
								>
									<span className="cpops-admin__nav-icon">
										{ NAV_ICONS[ item.key ] }
									</span>
									{ item.label }
									{ item.pro && ! hasProAdmin && (
										<span className="cpops-admin__pro-badge">
											PRO
										</span>
									) }
								</button>
							) ) }
						</div>
					) ) }
				</nav>

				{ /* Page Content */ }
				<main className="cpops-admin__content">{ renderPage() }</main>
			</div>

			{ /* Toast Notification */ }
			{ toast && (
				<div
					className={ `cpops-toast cpops-toast--${ toast.type }` }
					role="alert"
				>
					{ toast.message }
				</div>
			) }
		</div>
	);
}
