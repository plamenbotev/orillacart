import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export interface GiftCard {
	id: number;
	kind: 'gift_card' | 'store_credit';
	/** "Gift card ····7KQ2", or "Store credit". */
	label: string;
	code_end: string;
	initial: string;
	balance: string;
	currency: string;
	expires_at: string | null;
	active: boolean;
	recipient_name: string;
	recipient_email: string;
	message: string;
	/** The order it was bought with; null for one the shop gave. */
	order_id: number | null;
	customer_id: number | null;
	created_at: string;
}

export interface GiftCardEntry {
	id: number;
	/** Negative when spent or taken off. */
	amount: string;
	note: string;
	at: string;
	order_id: number | null;
	user: string;
}

export interface GiftCardDetail {
	card: GiftCard;
	/** Newest first. */
	entries: GiftCardEntry[];
}

export interface GiftCardPage {
	cards: GiftCard[];
	total: number;
	page: number;
	pages: number;
}

export interface IssueGiftCard {
	amount: string;
	expires_at: string | null;
	recipient_name: string;
	recipient_email: string;
	message: string;
}

export interface IssuedGiftCard {
	/** Shown this once: the shop keeps only its hash. */
	code: string;
	card: GiftCard;
	/** Whether the email with the code went out. */
	sent: boolean;
}

export interface GiftCardSettings {
	/** Products sold as gift cards. */
	product_ids: number[];
	/** Months a card lasts; null for ever. */
	valid_months: number | null;
}

export function fetchGiftCards( query: {
	search: string;
	page: number;
} ): Promise< GiftCardPage > {
	return apiFetch< GiftCardPage >( {
		path: addQueryArgs( '/orillacart/v1/gift-cards', {
			search: query.search || undefined,
			page: query.page,
			per_page: 20,
		} ),
	} );
}

export function fetchGiftCard( id: number ): Promise< GiftCardDetail > {
	return apiFetch< GiftCardDetail >( {
		path: `/orillacart/v1/gift-cards/${ id }`,
	} );
}

export function issueGiftCard(
	data: IssueGiftCard
): Promise< IssuedGiftCard > {
	return apiFetch< IssuedGiftCard >( {
		path: '/orillacart/v1/gift-cards',
		method: 'POST',
		data,
	} );
}

export function changeGiftCard(
	id: number,
	data: { active: boolean; expires_at: string | null }
): Promise< GiftCard > {
	return apiFetch< GiftCard >( {
		path: `/orillacart/v1/gift-cards/${ id }`,
		method: 'PUT',
		data,
	} );
}

export function adjustGiftCard(
	id: number,
	data: { amount: string; note: string }
): Promise< GiftCardDetail > {
	return apiFetch< GiftCardDetail >( {
		path: `/orillacart/v1/gift-cards/${ id }/adjustments`,
		method: 'POST',
		data,
	} );
}

export function newGiftCardCode( id: number ): Promise< IssuedGiftCard > {
	return apiFetch< IssuedGiftCard >( {
		path: `/orillacart/v1/gift-cards/${ id }/code`,
		method: 'POST',
	} );
}

export function fetchGiftCardSettings(): Promise< GiftCardSettings > {
	return apiFetch< GiftCardSettings >( {
		path: '/orillacart/v1/gift-cards/settings',
	} );
}

export function saveGiftCardSettings(
	data: GiftCardSettings
): Promise< GiftCardSettings > {
	return apiFetch< GiftCardSettings >( {
		path: '/orillacart/v1/gift-cards/settings',
		method: 'PUT',
		data,
	} );
}

export function fetchStoreCredit(
	customerId: number
): Promise< GiftCardDetail > {
	return apiFetch< GiftCardDetail >( {
		path: `/orillacart/v1/customers/${ customerId }/store-credit`,
	} );
}

export function adjustStoreCredit(
	customerId: number,
	data: { amount: string; note: string }
): Promise< GiftCardDetail > {
	return apiFetch< GiftCardDetail >( {
		path: `/orillacart/v1/customers/${ customerId }/store-credit`,
		method: 'POST',
		data,
	} );
}
