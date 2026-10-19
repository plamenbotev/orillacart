/**
 * The storefront script: the shop's pages are rendered on the server (templates/storefront); this script
 * sends the shopper's changes to the REST API and shows the result.
 */
import './storefront.css';

import { bindAddresses, bindConfirm, bindRegister } from './account';
import { bindCart } from './cart';
import { bindCheckout } from './checkout';
import { bindGallery } from './gallery';
import { bindPayNow, bindReceiptLink } from './paynow';
import { bindAddToCart, refreshCartCount } from './product';
import { bindShop } from './shop';

function start(): void {
	// Products that cannot be bought here still have pictures to look through.
	document.querySelectorAll( '.orillacart-product' ).forEach( ( product ) => {
		if ( ! product.querySelector( 'form[data-orillacart-add-to-cart]' ) ) {
			bindGallery( product );
		}
	} );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-add-to-cart]'
		)
		.forEach( bindAddToCart );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-checkout]' )
		.forEach( bindCheckout );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-pay-now]' )
		.forEach( bindPayNow );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-register]' )
		.forEach( bindRegister );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-receipt-link]'
		)
		.forEach( bindReceiptLink );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-confirm]' )
		.forEach( bindConfirm );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-addresses]'
		)
		.forEach( bindAddresses );
	bindShop();
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
