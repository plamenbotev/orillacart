import apiFetch from '@wordpress/api-fetch';

import type { OrderSummary } from '../orders/api';

export interface Figures {
	orders: number;
	net: string;
	average_order: string;
	items: number;
}

export interface NeedsYouLine {
	/** What waits: attention, on_hold, ship, low_stock, failed_jobs, status, or an add-on's id. */
	kind: string;
	text: string;
	count: number;
	/** Where to act: a Shop page and its query ("orders?status=processing"), or a full URL from an add-on. */
	url: string;
	tone: 'attention' | 'warning' | 'info';
}

export interface Tile {
	label: string;
	value: string;
	detail: string;
	url: string;
}

/** The Home screen, as GET /orillacart/v1/home answers. */
export interface Home {
	today: Figures;
	week: Figures & { series: { date: string; net: string; orders: number }[] };
	currency: string;
	needs_you: NeedsYouLine[];
	setup_finished: boolean;
	recent: OrderSummary[];
	tiles: Tile[];
}

export function fetchHome(): Promise< Home > {
	return apiFetch< Home >( { path: '/orillacart/v1/home' } );
}

/**
 * The admin URL of a "needs you" line or tile: a Shop page with its query, or an add-on's own URL as it is.
 *
 * @param url The line's url.
 */
export function adminUrl( url: string ): string {
	if (
		url === '' ||
		/^(https?:)?\/\//.test( url ) ||
		url.includes( '.php' )
	) {
		return url;
	}
	const [ page, query ] = url.split( '?' );

	return `admin.php?page=orillacart-${ page }${ query ? `&${ query }` : '' }`;
}
