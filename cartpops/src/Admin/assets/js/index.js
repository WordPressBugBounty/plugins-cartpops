/**
 * CartPops Admin Dashboard — Entry Point.
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import '../css/admin.scss';

const root = document.getElementById( 'cartpops-admin-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
