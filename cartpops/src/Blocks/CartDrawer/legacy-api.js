/**
 * Deprecated V1 JavaScript API for themes and snippets written for V1.
 *
 * V1 exposed `window.CartPops.drawer.show()` and friends, and opened the drawer
 * from any element with the `cpops-toggle-drawer` class. Both now forward to the
 * public `cartpops:open`, `cartpops:close` and `cartpops:toggle` document events
 * and log one console warning per member, so old integrations keep working while
 * their authors move to the events.
 */

const LEGACY_API_KEY = Symbol.for( 'cartpops.legacyGlobal' );
const DOCS_URL = 'https://docs.cartpops.com/upgrading-to-v2/developers';
const DRAWER_EVENTS = [ 'show', 'shown', 'hide', 'hidden' ];
const TOGGLE_CLASS_SELECTOR = '.cpops-toggle-drawer';
// V2's own launcher carries the V1 class for styling and already toggles itself.
const DRAWER_SELECTOR = '.cpops-modal[data-wp-interactive="cartpops"]';
const OWN_MARKUP_SELECTOR =
	'[data-wp-interactive="cartpops"], [data-cartpops-launcher]';

/**
 * Install the V1 API unless another script already owns `window.CartPops`.
 *
 * @param {Object} [options]
 * @param {Object} [options.globalScope]   Window-like global.
 * @param {Object} [options.documentScope] Document receiving the events.
 * @return {Object|undefined} The installed object, if any.
 */
export function installLegacyCartPopsGlobal( {
	globalScope = globalThis,
	documentScope = globalScope?.document,
} = {} ) {
	if ( ! globalScope || ! documentScope ) {
		return undefined;
	}
	const existing = globalScope.CartPops;
	if ( existing !== undefined && ! existing?.[ LEGACY_API_KEY ] ) {
		return undefined;
	}
	existing?.[ LEGACY_API_KEY ]?.cleanup();

	const warned = new Set();
	const warn = ( member, advice ) => {
		if ( warned.has( member ) ) {
			return;
		}
		warned.add( member );
		globalScope.console?.warn?.(
			`CartPops: ${ member } is deprecated and will be removed in a future version. ${ advice } See ${ DOCS_URL }`
		);
	};
	const dispatch = ( type ) => {
		const EventConstructor =
			globalScope.CustomEvent ?? documentScope.defaultView?.CustomEvent;
		documentScope.dispatchEvent( new EventConstructor( type ) );
	};
	const eventAdvice = ( type ) =>
		`Use document.dispatchEvent( new CustomEvent( '${ type }' ) ) instead.`;

	const listeners = new Map( DRAWER_EVENTS.map( ( name ) => [ name, [] ] ) );
	let observer;
	let wasOpen = false;
	const emit = ( name ) => {
		for ( const entry of [ ...listeners.get( name ) ] ) {
			if ( entry.once ) {
				off( name, entry.callback );
			}
			try {
				entry.callback( drawer );
			} catch ( error ) {
				globalScope.console?.error?.( error );
			}
		}
	};
	const watchDrawer = () => {
		const element = documentScope.querySelector( DRAWER_SELECTOR );
		const MutationObserverConstructor = globalScope.MutationObserver;
		if ( observer || ! element || ! MutationObserverConstructor ) {
			return;
		}
		wasOpen = element.classList.contains( 'cpops-drawer-open' );
		observer = new MutationObserverConstructor( () => {
			const isOpen = element.classList.contains( 'cpops-drawer-open' );
			if ( isOpen === wasOpen ) {
				return;
			}
			wasOpen = isOpen;
			emit( isOpen ? 'show' : 'hide' );
			emit( isOpen ? 'shown' : 'hidden' );
		} );
		observer.observe( element, {
			attributes: true,
			attributeFilter: [ 'class' ],
		} );
	};
	const subscribe = ( name, callback, once ) => {
		warn(
			`window.CartPops.drawer.${ once ? 'once' : 'on' }()`,
			'CartPops V2 sends no open or close events; while the drawer is open, .cpops-drawer has the cpops-drawer-open class.'
		);
		if ( listeners.has( name ) && typeof callback === 'function' ) {
			listeners.get( name ).push( { callback, once } );
			watchDrawer();
		}
		return drawer;
	};
	function off( name, callback ) {
		const registered = listeners.get( name );
		const index =
			registered?.findIndex( ( entry ) => entry.callback === callback ) ??
			-1;
		if ( index !== -1 ) {
			registered.splice( index, 1 );
		}
		return drawer;
	}

	const commands = ( name, target ) => {
		const command = ( method, type ) => () => {
			warn(
				`window.CartPops.${ name }.${ method }()`,
				eventAdvice( type )
			);
			dispatch( type );
			return target();
		};
		return {
			show: command( 'show', 'cartpops:open' ),
			hide: command( 'hide', 'cartpops:close' ),
			toggle: command( 'toggle', 'cartpops:toggle' ),
		};
	};
	const drawer = {
		...commands( 'drawer', () => drawer ),
		on: ( name, callback ) => subscribe( name, callback, false ),
		once: ( name, callback ) => subscribe( name, callback, true ),
		off,
	};
	// V2 opens the drawer for every add-to-cart trigger, including V1's popup and bar.
	const popup = commands( 'popup', () => popup );
	const bar = commands( 'bar', () => bar );
	const removedAssistant = () => {
		warn(
			'window.CartPops.assistant',
			'The shopping assistant was removed in V2 and does nothing.'
		);
		return assistant;
	};
	const assistant = {
		show: removedAssistant,
		hide: removedAssistant,
		toggle: removedAssistant,
	};

	const onToggleClassClick = ( event ) => {
		const target = event.target;
		const trigger =
			typeof target?.closest === 'function'
				? target.closest( TOGGLE_CLASS_SELECTOR )
				: null;
		if (
			! trigger ||
			trigger.closest( OWN_MARKUP_SELECTOR ) ||
			event.defaultPrevented ||
			event.button > 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			// Without a drawer to open, let a link navigate as usual.
			! documentScope.querySelector( DRAWER_SELECTOR )
		) {
			return;
		}
		event.preventDefault();
		warn(
			'The cpops-toggle-drawer class as a drawer trigger',
			eventAdvice( 'cartpops:open' )
		);
		dispatch( 'cartpops:open' );
	};
	documentScope.addEventListener( 'click', onToggleClassClick );

	const legacy = { drawer, popup, bar, assistant };
	Object.defineProperty( legacy, LEGACY_API_KEY, {
		value: {
			cleanup: () => {
				observer?.disconnect();
				observer = undefined;
				documentScope.removeEventListener(
					'click',
					onToggleClassClick
				);
			},
		},
	} );

	try {
		globalScope.CartPops = legacy;
	} catch {
		legacy[ LEGACY_API_KEY ].cleanup();
		return undefined;
	}
	return legacy;
}
