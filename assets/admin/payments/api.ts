import apiFetch from '@wordpress/api-fetch';

export interface GatewaySetting {
	key: string;
	label: string;
	type: 'text' | 'secret' | 'boolean';
	required: boolean;
	help: string;
}

export type Settings = Record< string, string | boolean >;

export interface PaymentMethod {
	gateway: string;
	name: string;
	active: boolean;
	position: number;
	countries: string[];
	instructions: string;
	receipt_note: string;
	/** Secret settings always come back empty; secrets_set names the saved ones. */
	settings: Settings;
	secrets_set: string[];
	fields: GatewaySetting[];
	/** Whether checkout can offer it with these settings and the shop's currency. */
	ready: boolean;
	notification_url: string | null;
}

export interface PaymentMethodInput {
	name: string;
	active: boolean;
	position: number;
	countries: string[];
	instructions: string;
	receipt_note: string;
	settings: Settings;
}

const BASE = '/orillacart/v1';

export function fetchPaymentMethods(): Promise< PaymentMethod[] > {
	return apiFetch< PaymentMethod[] >( { path: `${ BASE }/payment-methods` } );
}

export function savePaymentMethod(
	gateway: string,
	input: PaymentMethodInput
): Promise< PaymentMethod > {
	return apiFetch< PaymentMethod >( {
		path: `${ BASE }/payment-methods/${ gateway }`,
		method: 'PUT',
		data: input,
	} );
}
