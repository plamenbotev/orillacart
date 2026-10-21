/**
 * The review form under a product's reviews.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { clearErrors, failure, formBody, showErrors, showMessage } from './dom';

type Written = { status: 'published' | 'held' };

/**
 * Sends the review, then says whether it is out or waits for the shop, in place of the form's fields.
 *
 * @param form The form, with data-product.
 */
export function bindReviewForm( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button ) {
			button.disabled = true;
		}
		const result = await api< Written >(
			'POST',
			`/products/${ Number( form.dataset.product ) }/reviews`,
			formBody( form, { numbers: [ 'rating' ] } )
		);
		if ( button ) {
			button.disabled = false;
		}
		if ( ! result.ok ) {
			const unplaced = showErrors( form, result.violations );
			showMessage( form, failure( result, unplaced ), 'error' );
			return;
		}
		form.querySelectorAll( '.orillacart-field, button' ).forEach(
			( part ) => part.setAttribute( 'hidden', '' )
		);
		showMessage(
			form,
			result.data.status === 'published'
				? __(
						'Thank you: your review is out. Reload the page to see it.',
						'orillacart'
					)
				: __(
						'Thank you: your review shows once the shop has approved it.',
						'orillacart'
					)
		);
	} );
}
