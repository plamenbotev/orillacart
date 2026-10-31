/**
 * The product's share links work without the script; with it, the browser's own share and copying the link.
 */
import { __ } from '@wordpress/i18n';

import { showMessage } from './dom';

/**
 * Shows the buttons the browser can do.
 *
 * @param box The share block (data-orillacart-share).
 */
export function bindShare( box: HTMLElement ): void {
	const url = box.dataset.url ?? window.location.href;
	const title = box.dataset.title ?? document.title;
	const native = box.querySelector< HTMLButtonElement >(
		'[data-orillacart-share-native]'
	);
	if ( native && typeof navigator.share === 'function' ) {
		native.closest( 'li' )?.removeAttribute( 'hidden' );
		native.addEventListener( 'click', () => {
			// Closing the browser's sheet rejects: nothing to say.
			navigator.share( { url, title } ).catch( () => undefined );
		} );
	}
	const copy = box.querySelector< HTMLButtonElement >(
		'[data-orillacart-share-copy]'
	);
	if ( copy && navigator.clipboard ) {
		copy.closest( 'li' )?.removeAttribute( 'hidden' );
		copy.addEventListener( 'click', async () => {
			try {
				await navigator.clipboard.writeText( url );
				showMessage( box, __( 'Link copied.', 'orillacart' ) );
			} catch {
				showMessage(
					box,
					__( 'The link could not be copied.', 'orillacart' ),
					'error'
				);
			}
		} );
	}
}
