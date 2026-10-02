/**
 * Background submission for classic single-product add-to-cart forms.
 *
 * The form's own fields are posted to the form's own action URL, so
 * WooCommerce's normal form handler (`WC_Form_Handler::add_to_cart_action`)
 * runs with every product type, add-on field and validation hook exactly as a
 * page submit would. The returned page carries WooCommerce's notices, and the
 * cart hash cookie proves whether the cart changed. Anything this module
 * cannot prove falls back to the browser's own navigation.
 *
 * @package
 */

import { readWooCartCookie } from './initial-cart';
import { classicAddOptsOutOfAutoOpen } from './cart-add-trigger-policy';

const INTERCEPTOR_KEY = Symbol.for( 'cartpops.productFormAddInterceptor' );
const PENDING_CLASS = 'loading';
const SUBMITTER_FIELD_ATTRIBUTE = 'data-cpops-submitter-field';
const NOTICE_SELECTOR =
	'.woocommerce-error, .woocommerce-message, .woocommerce-info, .wc-block-components-notice-banner';
const ERROR_NOTICE_SELECTOR =
	'.woocommerce-error, .wc-block-components-notice-banner.is-error';

/**
 * Read an attribute without trusting form named-property shadowing, where a
 * field called `action` or `method` replaces the matching form property.
 *
 * @param {Element} element Element.
 * @param {string}  name    Attribute name.
 * @return {string} Attribute value or an empty string.
 */
function attribute( element, name ) {
	try {
		return String( element.getAttribute( name ) ?? '' );
	} catch {
		return '';
	}
}

/**
 * Resolve the same-origin HTTP(S) URL a form would submit to.
 *
 * @param {HTMLFormElement} form          Product form.
 * @param {Document}        documentScope Current document.
 * @return {URL|null} Submission URL, or null when it is not safe to fetch.
 */
export function resolveProductFormAction( form, documentScope ) {
	try {
		const base = documentScope?.baseURI || documentScope?.URL;
		const url = new URL( attribute( form, 'action' ) || base, base );
		const origin = new URL( base ).origin;
		if (
			url.origin !== origin ||
			( url.protocol !== 'https:' && url.protocol !== 'http:' )
		) {
			return null;
		}
		url.hash = '';
		return url;
	} catch {
		return null;
	}
}

/**
 * Collect the exact fields a native submit by this button would send.
 *
 * @param {HTMLFormElement}  form      Product form.
 * @param {HTMLElement|null} submitter Clicked submit control.
 * @return {FormData} Submitted fields.
 */
export function buildProductFormData( form, submitter ) {
	const data = new FormData( form );
	const name = submitter ? attribute( submitter, 'name' ) : '';
	if ( name && ! submitter.disabled && ! data.has( name ) ) {
		data.append( name, submitter.value ?? '' );
	}
	return data;
}

/**
 * Decide whether a submit event belongs to CartPops' background add.
 *
 * @param {Object}      options
 * @param {SubmitEvent} options.event         Submit event.
 * @param {Object}      options.config        Drawer frontend config.
 * @param {Document}    options.documentScope Current document.
 * @return {{form: HTMLFormElement, submitter: HTMLElement|null, url: URL, data: FormData}|null}
 * Submission details, or null when the browser should submit normally.
 */
export function resolveProductFormSubmission( {
	event,
	config,
	documentScope,
} ) {
	if ( ! event || event.defaultPrevented ) {
		return null;
	}
	if ( config?.productPageAjaxAdd !== true || config.cartRedirectAfterAdd ) {
		return null;
	}

	const form = event.target;
	if (
		! form ||
		form.nodeType !== 1 ||
		form.tagName !== 'FORM' ||
		typeof form.matches !== 'function' ||
		! form.matches( 'form.cart' ) ||
		form.matches( '.grouped_form' ) ||
		form.closest( '.product-type-external, .product-type-grouped' )
	) {
		return null;
	}

	const method = attribute( form, 'method' ).toLowerCase();
	const target = attribute( form, 'target' ).toLowerCase();
	if (
		method !== 'post' ||
		( target !== '' && target !== '_self' ) ||
		form.querySelector( 'input[type="file"]' )
	) {
		return null;
	}

	const submitter =
		event.submitter && event.submitter.form === form
			? event.submitter
			: null;
	if (
		submitter &&
		( attribute( submitter, 'formaction' ) ||
			attribute( submitter, 'formtarget' ) ||
			attribute( submitter, 'formmethod' ) )
	) {
		return null;
	}

	const url = resolveProductFormAction( form, documentScope );
	if ( ! url ) {
		return null;
	}

	let data;
	try {
		data = buildProductFormData( form, submitter );
	} catch {
		return null;
	}
	const productId = data.get( 'add-to-cart' );
	if ( typeof productId !== 'string' || ! /^[1-9]\d*$/.test( productId ) ) {
		return null;
	}

	return { form, submitter, url, data };
}

/**
 * Extract WooCommerce's printed notices from a product page response.
 *
 * @param {string}   html          Response HTML.
 * @param {Document} documentScope Current document, for its DOMParser.
 * @return {{notices: Element[], hasError: boolean}} Parsed notices.
 */
export function parseProductPageNotices( html, documentScope ) {
	try {
		const Parser = documentScope?.defaultView?.DOMParser ?? DOMParser;
		const page = new Parser().parseFromString(
			String( html ?? '' ),
			'text/html'
		);
		// Prefer WooCommerce's notice areas so unrelated info boxes in the
		// product description are never copied onto the page.
		const wrappers = page.querySelectorAll(
			'.woocommerce-notices-wrapper'
		);
		const candidates = wrappers.length
			? Array.from( wrappers ).flatMap( ( wrapper ) =>
					Array.from( wrapper.querySelectorAll( NOTICE_SELECTOR ) )
			  )
			: Array.from( page.querySelectorAll( NOTICE_SELECTOR ) );
		const notices = candidates.filter(
			( notice ) => ! notice.parentElement?.closest( NOTICE_SELECTOR )
		);
		for ( const notice of notices ) {
			notice
				.querySelectorAll( 'script' )
				.forEach( ( script ) => script.remove() );
		}
		return {
			notices,
			hasError: notices.some( ( notice ) =>
				notice.matches( ERROR_NOTICE_SELECTOR )
			),
		};
	} catch {
		return { notices: [], hasError: false };
	}
}

/**
 * Replace the page's WooCommerce notices with those from the response.
 *
 * @param {Element[]}       notices       Parsed notices.
 * @param {HTMLFormElement} form          Submitted form.
 * @param {Document}        documentScope Current document.
 * @return {boolean} Whether notices are displayed.
 */
export function showProductPageNotices( notices, form, documentScope ) {
	if ( ! notices.length ) {
		return false;
	}

	try {
		let wrapper = documentScope.querySelector(
			'.woocommerce-notices-wrapper'
		);
		if ( ! wrapper ) {
			wrapper = documentScope.createElement( 'div' );
			wrapper.className = 'woocommerce-notices-wrapper';
			form.parentNode.insertBefore( wrapper, form );
		}
		wrapper.replaceChildren(
			...notices.map( ( notice ) =>
				documentScope.importNode( notice, true )
			)
		);
		const first = wrapper.firstElementChild;
		if ( ! first.hasAttribute( 'tabindex' ) ) {
			first.setAttribute( 'tabindex', '-1' );
		}
		first.scrollIntoView?.( { block: 'center' } );
		first.focus?.( { preventScroll: true } );
		return true;
	} catch {
		return false;
	}
}

/**
 * Remove notices left from an earlier attempt, such as a stock error the
 * shopper has since corrected.
 *
 * @param {Document} documentScope Current document.
 */
export function clearProductPageNotices( documentScope ) {
	try {
		documentScope
			.querySelector( '.woocommerce-notices-wrapper' )
			?.replaceChildren();
	} catch {
		// Leave the page as it is.
	}
}

/**
 * Undo the archive-button state WooCommerce's add-to-cart script applies
 * when `added_to_cart` fires: an `added` class on the origin and a "View
 * cart" link after it. On a product page the drawer or WooCommerce's own
 * notice already offers the cart, so the extra link would linger.
 *
 * @param {Element}      origin        Element passed to `added_to_cart`.
 * @param {Set<Element>} existingLinks View cart links present before the event.
 * @param {boolean}      wasAdded      Whether the origin had `added` before.
 */
export function removeArchiveAddedState( origin, existingLinks, wasAdded ) {
	try {
		if ( ! wasAdded ) {
			origin?.classList?.remove( 'added' );
		}
		origin?.parentNode
			?.querySelectorAll?.( 'a.added_to_cart' )
			.forEach( ( link ) => {
				if ( ! existingLinks.has( link ) ) {
					link.remove();
				}
			} );
	} catch {
		// Cosmetic only.
	}
}

/**
 * Submit natively after a background attempt failed before any cart change.
 *
 * `form.submit()` skips submit events (so no handler runs twice) but also
 * omits the clicked button, which carries `add-to-cart` on simple products.
 *
 * @param {HTMLFormElement}  form          Product form.
 * @param {HTMLElement|null} submitter     Clicked submit control.
 * @param {Document}         documentScope Current document.
 */
export function submitProductFormNatively( form, submitter, documentScope ) {
	const name = submitter ? attribute( submitter, 'name' ) : '';
	if ( name && ! new FormData( form ).has( name ) ) {
		const field = documentScope.createElement( 'input' );
		field.type = 'hidden';
		field.name = name;
		field.value = submitter.value ?? '';
		field.setAttribute( SUBMITTER_FIELD_ATTRIBUTE, '' );
		form.appendChild( field );
	}
	const FormElement =
		documentScope?.defaultView?.HTMLFormElement ?? HTMLFormElement;
	FormElement.prototype.submit.call( form );
}

/**
 * Whether two URLs identify the same document, ignoring the fragment.
 *
 * @param {string} first  URL.
 * @param {string} second URL.
 * @return {boolean} Whether they match.
 */
function sameDocument( first, second ) {
	try {
		const a = new URL( first );
		const b = new URL( second );
		a.hash = '';
		b.hash = '';
		return a.href === b.href;
	} catch {
		return false;
	}
}

/**
 * The current page URL without parameters that would add to the cart again.
 *
 * @param {string} href Current page URL.
 * @return {string} URL safe to load by GET.
 */
export function reloadUrlWithoutAdd( href ) {
	try {
		const url = new URL( href );
		url.searchParams.delete( 'add-to-cart' );
		url.searchParams.delete( 'added-to-cart' );
		url.searchParams.delete( 'quantity' );
		return url.href;
	} catch {
		return href;
	}
}

/**
 * Fetch WooCommerce's refreshed fragments after a confirmed add.
 *
 * @param {Function} fetchImpl Fetch implementation.
 * @param {string}   url       `wc-ajax=get_refreshed_fragments` endpoint.
 * @return {Promise<{fragments: Object, cartHash: string}|null>} Fragments.
 */
async function fetchFragments( fetchImpl, url ) {
	if ( typeof url !== 'string' || url === '' ) {
		return null;
	}
	try {
		const body = new URLSearchParams( { time: String( Date.now() ) } );
		const response = await fetchImpl( url, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} );
		if ( ! response?.ok ) {
			return null;
		}
		const json = await response.json();
		const fragments = json?.fragments;
		if (
			! fragments ||
			typeof fragments !== 'object' ||
			Array.isArray( fragments )
		) {
			return null;
		}
		return {
			fragments,
			cartHash: typeof json.cart_hash === 'string' ? json.cart_hash : '',
		};
	} catch {
		return null;
	}
}

/**
 * Pick the element whose `data-cpops-cart-open="false"` should apply.
 *
 * @param {HTMLFormElement}  form      Product form.
 * @param {HTMLElement|null} submitter Clicked submit control.
 * @return {HTMLElement} Button-equivalent origin of the add.
 */
function addOrigin( form, submitter ) {
	if ( submitter && classicAddOptsOutOfAutoOpen( null, submitter ) ) {
		return submitter;
	}
	if ( classicAddOptsOutOfAutoOpen( null, form ) ) {
		return form;
	}
	return submitter || form;
}

/**
 * Install the single-product form interceptor once per page.
 *
 * Listening on the window in the bubble phase lets every theme or plugin
 * handler on the form or document run first; one that already handles the
 * add calls preventDefault and CartPops stays out of the way.
 *
 * @param {Object}   options
 * @param {Object}   [options.globalScope]       Browser global scope.
 * @param {Document} [options.documentScope]     Browser document.
 * @param {Function} options.getConfig           Returns the drawer frontend config.
 * @param {Function} options.willOpenDrawer      Whether an add from this origin opens the drawer.
 * @param {Function} options.onAdded             Receives (fragments|null, cartHash, origin) when the
 *                                               drawer bridge cannot hear jQuery's `added_to_cart`.
 * @param {boolean}  [options.bridgeHearsJquery] Whether the drawer bridge listens to jQuery events.
 * @param {Function} [options.fetchImpl]         Fetch implementation.
 * @param {Function} [options.navigate]          Navigates the page by GET.
 * @return {Function} Idempotent cleanup callback.
 */
export function installProductFormAddInterceptor( {
	globalScope = globalThis,
	documentScope = globalScope?.document,
	getConfig,
	willOpenDrawer,
	onAdded,
	bridgeHearsJquery = true,
	fetchImpl = globalScope?.fetch?.bind( globalScope ),
	navigate = ( url ) => globalScope.location.assign( url ),
} = {} ) {
	globalScope?.[ INTERCEPTOR_KEY ]?.();
	if (
		typeof globalScope?.addEventListener !== 'function' ||
		typeof fetchImpl !== 'function' ||
		! documentScope
	) {
		return () => {};
	}

	const pendingForms = new WeakSet();
	const readCartHash = () => {
		try {
			return readWooCartCookie( documentScope.cookie ).cartHash ?? '';
		} catch {
			return '';
		}
	};

	const loadFragments = () =>
		fetchFragments( fetchImpl, getConfig?.()?.fragmentsUrl );

	const announceAdd = async ( form, origin, notices, fragments ) => {
		// An opening drawer confirms the add itself; keep only problems on the
		// page. Otherwise WooCommerce's own "added to cart" message is shown.
		const opensDrawer = Boolean( willOpenDrawer?.( origin ) );
		const shown = opensDrawer
			? notices.filter( ( notice ) =>
					notice.matches( ERROR_NOTICE_SELECTOR )
			  )
			: notices;
		if ( ! showProductPageNotices( shown, form, documentScope ) ) {
			clearProductPageNotices( documentScope );
		}

		const result =
			fragments === undefined ? await loadFragments() : fragments;
		const jQueryFactory = globalScope.jQuery;
		let triggered = false;
		if ( typeof jQueryFactory === 'function' ) {
			const existingLinks = new Set(
				origin?.parentNode?.querySelectorAll?.( 'a.added_to_cart' ) ??
					[]
			);
			const wasAdded = Boolean( origin?.classList?.contains( 'added' ) );
			try {
				// The same event WooCommerce's archive buttons fire, so mini-carts,
				// analytics and the CartPops bridge all react to this add.
				jQueryFactory( documentScope.body ).trigger( 'added_to_cart', [
					result?.fragments ?? {},
					result?.cartHash ?? readCartHash(),
					jQueryFactory( origin ),
				] );
				triggered = true;
			} catch {
				// Fall through to the direct drawer update.
			}
			removeArchiveAddedState( origin, existingLinks, wasAdded );
		}
		// jQuery that loaded after the drawer (delayed scripts) has no CartPops
		// listener, so the drawer must still hear about this add directly.
		if ( ! triggered || ! bridgeHearsJquery ) {
			onAdded?.(
				result?.fragments ?? null,
				result?.cartHash ?? '',
				origin
			);
		}
	};

	const submit = async ( { form, submitter, url, data } ) => {
		pendingForms.add( form );
		submitter?.classList?.add( PENDING_CLASS );
		const hashBefore = readCartHash();
		let response = null;
		let html = '';
		try {
			response = await fetchImpl( url.href, {
				method: 'POST',
				body: data,
				credentials: 'same-origin',
				redirect: 'follow',
				headers: { Accept: 'text/html' },
			} );
			html = response?.ok ? await response.text() : '';
		} catch {
			response = null;
		} finally {
			pendingForms.delete( form );
			submitter?.classList?.remove( PENDING_CLASS );
		}

		// A changed hash proves the add happened, so never resubmit after it.
		const hashAfter = readCartHash();
		const cartChanged = hashAfter !== '' && hashAfter !== hashBefore;
		// No readable hash before or after: either the cart is still empty or
		// the cookie is hidden from scripts, so the outcome is not proven.
		const hashUnknown = hashBefore === '' && hashAfter === '';

		if ( ! response?.ok ) {
			if ( cartChanged ) {
				await announceAdd( form, addOrigin( form, submitter ), [] );
				return;
			}
			if ( hashUnknown ) {
				// The add may have happened; reload by GET rather than post twice.
				navigate( reloadUrlWithoutAdd( globalScope.location.href ) );
				return;
			}
			submitProductFormNatively( form, submitter, documentScope );
			return;
		}

		// A store-level redirect (for example "buy now" to checkout) wins.
		if (
			response.redirected &&
			typeof response.url === 'string' &&
			! sameDocument( response.url, url.href )
		) {
			navigate( response.url );
			return;
		}

		const { notices, hasError } = parseProductPageNotices(
			html,
			documentScope
		);
		if ( cartChanged ) {
			await announceAdd( form, addOrigin( form, submitter ), notices );
			return;
		}
		if ( hashUnknown && ! hasError ) {
			// WooCommerce's fragments report the cart hash even when its cookie
			// cannot be read; a non-empty cart there confirms the add.
			const fragments = await loadFragments();
			if ( fragments?.cartHash ) {
				await announceAdd(
					form,
					addOrigin( form, submitter ),
					notices,
					fragments
				);
				return;
			}
		}
		if (
			hasError &&
			showProductPageNotices( notices, form, documentScope )
		) {
			return;
		}

		// Nothing proves an add or explains why not. Reload by GET, never by
		// re-posting, so the shopper sees WooCommerce's own session notices.
		navigate( reloadUrlWithoutAdd( globalScope.location.href ) );
	};

	const handleSubmit = ( event ) => {
		if ( event?.target && pendingForms.has( event.target ) ) {
			event.preventDefault();
			return;
		}

		let submission = null;
		try {
			submission = resolveProductFormSubmission( {
				event,
				config: getConfig?.(),
				documentScope,
			} );
		} catch {
			submission = null;
		}
		if ( ! submission ) {
			return;
		}

		event.preventDefault();
		submit( submission ).catch( () => {
			// The only remaining failures are optional theme integrations.
		} );
	};

	globalScope.addEventListener( 'submit', handleSubmit );
	let cleaned = false;
	const cleanup = () => {
		if ( cleaned ) {
			return;
		}
		cleaned = true;
		globalScope.removeEventListener( 'submit', handleSubmit );
		if ( globalScope[ INTERCEPTOR_KEY ] === cleanup ) {
			delete globalScope[ INTERCEPTOR_KEY ];
		}
	};
	globalScope[ INTERCEPTOR_KEY ] = cleanup;
	return cleanup;
}
