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
	/** Hours after a cart was left at checkout its customer is reminded of it. */
	cart_reminder_hours: number;
	/** The code of the coupon the cart reminder offers. */
	cart_reminder_coupon: string | null;
}

export type EmailSettingsInput = Pick<
	EmailSettings,
	| 'from_name'
	| 'from_address'
	| 'shop_address'
	| 'disabled'
	| 'reminder_days'
	| 'cart_reminder_hours'
	| 'cart_reminder_coupon'
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

export interface EmailTemplate {
	/** As the files name it, without emails/ and .html.twig: "order-shipped", "layout". */
	name: string;
	edited: boolean;
	/** The email it shows in a preview: an email kind, account_confirm or account_exists. */
	previews: string;
	updated_at: string | null;
	/** The locales that have a template of their own in place of this one. */
	languages: string[];
}

export interface EmailTemplateSource extends EmailTemplate {
	/** What the shop uses now: its own, or the file's. */
	source: string;
	/** The theme's or the plugin's file; null when there is none. */
	file_source: string | null;
	/** The site's language it is for (de_DE); empty for every language. */
	locale: string;
	/** For a language: the shop's template for every language, which its readers get until it has its own. */
	every_language: string | null;
}

export interface PreviewInput {
	/** The template as edited; null for the one in use. */
	source: string | null;
	/** The email to show; null for the one the template makes. */
	kind: string | null;
	/** The order an order email is about; null for the latest. */
	order: number | null;
	/** The language's template, written in it; empty for every language's. */
	locale: string;
}

export interface EmailPreview {
	to: string;
	subject: string;
	html: string;
	text: string;
}

export function fetchEmailTemplates(): Promise< EmailTemplate[] > {
	return apiFetch< EmailTemplate[] >( { path: `${ BASE }/email-templates` } );
}

/**
 * The address of a template, for one language or (empty) every language.
 *
 * @param name   The template, as the files name it.
 * @param locale A site's language, or empty.
 */
const templatePath = ( name: string, locale: string ): string =>
	`${ BASE }/email-templates/${ name }${
		locale === '' ? '' : `?locale=${ encodeURIComponent( locale ) }`
	}`;

/**
 * One template, with the file it started from.
 *
 * @param name   The template, as the files name it.
 * @param locale One of the site's languages; empty for every language.
 */
export function fetchEmailTemplate(
	name: string,
	locale = ''
): Promise< EmailTemplateSource > {
	return apiFetch< EmailTemplateSource >( {
		path: templatePath( name, locale ),
	} );
}

/**
 * Keeps the shop's own template in place of the file's, for every language or one.
 *
 * @param name   The template, as the files name it.
 * @param source Its Twig.
 * @param locale One of the site's languages; empty for every language.
 */
export function saveEmailTemplate(
	name: string,
	source: string,
	locale = ''
): Promise< EmailTemplateSource > {
	return apiFetch< EmailTemplateSource >( {
		path: `${ BASE }/email-templates/${ name }`,
		method: 'PUT',
		data: { source, locale },
	} );
}

/**
 * Goes back to the template's file; a language's, to the template for every language.
 *
 * @param name   The template, as the files name it.
 * @param locale One of the site's languages; empty for every language.
 */
export function resetEmailTemplate(
	name: string,
	locale = ''
): Promise< EmailTemplateSource > {
	return apiFetch< EmailTemplateSource >( {
		path: templatePath( name, locale ),
		method: 'DELETE',
	} );
}

/**
 * The email the template makes, saved or as edited.
 *
 * @param name  The template, as the files name it.
 * @param input What to show, and with which order.
 */
export function previewEmail(
	name: string,
	input: PreviewInput
): Promise< EmailPreview > {
	return apiFetch< EmailPreview >( {
		path: `${ BASE }/email-templates/${ name }/preview`,
		method: 'POST',
		data: input,
	} );
}

/**
 * Sends the preview to whoever asks.
 *
 * @param name  The template, as the files name it.
 * @param input What to send, and with which order.
 */
export function sendTestEmail(
	name: string,
	input: PreviewInput
): Promise< { to: string; sent: boolean } > {
	return apiFetch< { to: string; sent: boolean } >( {
		path: `${ BASE }/email-templates/${ name }/test`,
		method: 'POST',
		data: input,
	} );
}
