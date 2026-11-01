import { createElement, render } from '@wordpress/element';
import * as element from '@wordpress/element';
import App from '../vendor/App';
import '../core3/ui/ui.css';
import '../vendor/vendor.css';

const node = document.getElementById( 'dev3va-vendor-root' );
if ( node ) {
	let boot = {};
	try {
		boot = JSON.parse( node.getAttribute( 'data-config' ) || '{}' );
	} catch ( e ) {
		boot = {};
	}
	if ( element.createRoot ) {
		element.createRoot( node ).render( createElement( App, { boot } ) );
	} else {
		render( createElement( App, { boot } ), node );
	}
}
