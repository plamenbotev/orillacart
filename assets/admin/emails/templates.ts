import { __ } from '@wordpress/i18n';

import { EMAIL_LABELS } from '../orders/labels';

const PARTS: Record< string, string > = {
	layout: __( 'Frame of every email', 'orillacart' ),
	_order: __( 'Order details, in order emails', 'orillacart' ),
	_address: __( 'Address, in the order details', 'orillacart' ),
	'account-confirm': __( 'Link to make an account', 'orillacart' ),
	'account-exists': __(
		'Sign-up with an email that has an account',
		'orillacart'
	),
};

/**
 * What a template is, for the shop: its email's name, or the part of the emails it is.
 *
 * @param name The template, as the files name it.
 */
export function templateLabel( name: string ): string {
	return PARTS[ name ] ?? EMAIL_LABELS[ name.replaceAll( '-', '_' ) ] ?? name;
}

/**
 * Whether a template is a part of other emails, shown in one of them.
 *
 * @param name The template, as the files name it.
 */
export function isPart( name: string ): boolean {
	return name === 'layout' || name.startsWith( '_' );
}
