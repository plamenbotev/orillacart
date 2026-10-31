/**
 * The storefront script: the shop's pages are rendered on the server (templates/storefront); this script
 * sends the shopper's changes to the REST API and shows the result.
 */
import './storefront.css';
import './blocks-product.css';
import './blocks-shop.css';
import './blocks-listing.css';

import {
	bindAddressBook,
	bindConfirm,
	bindEmails,
	bindTaxExemption,
	bindFindOrder,
	bindRegister,
} from './account';
import { startAnalytics } from './analytics';
import { bindBackInStock } from './backinstock';
import { bindCart } from './cart';
import { bindCheckout } from './checkout';
import { bindDeliveryEstimate } from './delivery';
import { bindGallery, partsOf } from './gallery';
import { bindPayNow, bindReceiptLink, bindReorder, bindReturn } from './paynow';
import { bindAddToCart, refreshCartCount } from './product';
import { startRecentlyViewed } from './recent';
import { bindReviewForm } from './reviews';
import { bindShare } from './share';
import { bindShop } from './shop';
import {
	bindStoreNotices,
	startFreeShipping,
	startPromotions,
} from './shop-blocks';
import { startWishlist } from './wishlist';
import { bindWithdrawal } from './withdrawal';

function start(): void {
	// Products that cannot be bought here still have pictures to look through.
	new Set(
		Array.from( document.querySelectorAll( '[data-orillacart-for]' ) ).map(
			( part ) => part.getAttribute( 'data-orillacart-for' ) ?? ''
		)
	).forEach( ( id ) => {
		const parts = partsOf( id );
		if (
			! parts.some( ( part ) =>
				part.querySelector( 'form[data-orillacart-add-to-cart]' )
			)
		) {
			bindGallery( parts );
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
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-back-in-stock]'
		)
		.forEach( bindBackInStock );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-review-form]'
		)
		.forEach( bindReviewForm );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-pay-now]' )
		.forEach( bindPayNow );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-return]' )
		.forEach( bindReturn );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-reorder]' )
		.forEach( bindReorder );
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
			'form[data-orillacart-withdrawal]'
		)
		.forEach( bindWithdrawal );
	// Choosing a currency reloads the page in it; the button is for browsers without the script.
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-currency-switcher]'
		)
		.forEach( ( form ) => {
			form.querySelector< HTMLButtonElement >(
				'.orillacart-currency-switcher__go'
			)?.setAttribute( 'hidden', '' );
			form.querySelector( 'select' )?.addEventListener( 'change', () =>
				form.requestSubmit()
			);
		} );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-find-order]'
		)
		.forEach( bindFindOrder );
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-orillacart-emails]' )
		.forEach( bindEmails );
	document
		.querySelectorAll< HTMLFormElement >(
			'form[data-orillacart-tax-exemption]'
		)
		.forEach( bindTaxExemption );
	document
		.querySelectorAll< HTMLElement >( '.orillacart-account__addresses' )
		.forEach( bindAddressBook );
	document
		.querySelectorAll< HTMLElement >( '[data-orillacart-delivery]' )
		.forEach( bindDeliveryEstimate );
	document
		.querySelectorAll< HTMLElement >( '[data-orillacart-share]' )
		.forEach( bindShare );
	bindShop();
	startAnalytics();
	void startWishlist();
	void startRecentlyViewed();
	bindStoreNotices();
	startFreeShipping();
	startPromotions();
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
