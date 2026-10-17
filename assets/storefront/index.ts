/**
 * The storefront script: the shop's pages are rendered on the server (templates/storefront); this script
 * sends the shopper's changes to the REST API and shows the result.
 */
import './storefront.css';

import { bindAddresses, bindRegister } from './account';
import { bindCart } from './cart';
import { bindCheckout } from './checkout';
import { bindAddToCart, refreshCartCount } from './product';

function start(): void {
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-add-to-cart]'
		)
		.forEach( bindAddToCart );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-checkout]' )
		.forEach( bindCheckout );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-register]' )
		.forEach( bindRegister );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-addresses]'
		)
		.forEach( bindAddresses );
	if ( document.querySelector( '[data-orillacart-region="cart"]' ) ) {
		bindCart( document );
	} else {
		void refreshCartCount();
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
