import apiFetch from '@wordpress/api-fetch';

export interface EmailSettings {
	from_name: string | null;
	from_address: string | null;
	shop_address: string | null;
	disabled: string[];
	/** What applies when a field is left empty: the site's name and administration email. */
	default_name: string;
	default_address: string;
	kinds: string[];
	/** Days after the order the customer is reminded to pay for it. */
	reminder_days: number;
}

export type EmailSettingsInput = Pick<
	EmailSettings,
	'from_name' | 'from_address' | 'shop_address' | 'disabled' | 'reminder_days'
>;

const BASE = '/orillacart/v1';

export function fetchEmailSettings(): Promise< EmailSettings > {
	return apiFetch< EmailSettings >( { path: `${ BASE }/email-settings` } );
}

export function saveEmailSettings(
	input: EmailSettingsInput
): Promise< EmailSettings > {
	return apiFetch< EmailSettings >( {
		path: `${ BASE }/email-settings`,
		method: 'PUT',
		data: input,
	} );
}
