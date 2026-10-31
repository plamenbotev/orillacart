import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export type Interval = 'day' | 'week' | 'month';
export type Compare = 'previous' | 'year' | 'none';
export type Tab =
	| 'sales'
	| 'products'
	| 'categories'
	| 'coupons'
	| 'customers'
	| 'carts'
	| 'taxes';

/** What a report covers: days of the shop's calendar, Y-m-d, the last one included. */
export interface ReportQuery {
	from: string;
	to: string;
	/** Empty for what suits the period's length. */
	interval: Interval | '';
	compare: Compare;
	limit: number;
	sort: 'net' | 'quantity';
}

/** What every report says of itself. */
export interface Frame {
	from: string;
	to: string;
	interval: Interval;
	previous_from: string | null;
	previous_to: string | null;
	currency: string;
	/** Currencies of paid orders the report leaves out. */
	other_currencies: string[];
	/** Orders changed but not counted yet. */
	pending: number;
}

export interface Totals {
	orders: number;
	items: number;
	gross: string;
	discounts: string;
	refunds: string;
	shipping: string;
	taxes: string;
	total: string;
	customers: number;
	new_customers: number;
	net: string;
	total_sales: string;
	average_order: string;
}

export interface Share {
	key: string;
	orders: number;
	total: string;
}

export interface SalesReport {
	frame: Frame;
	totals: Totals;
	previous: Totals | null;
	series: {
		date: string;
		orders: number;
		net: string;
		total: string;
		refunds: string;
		previous_orders: number | null;
		previous_net: string | null;
	}[];
	payments: Share[];
	countries: Share[];
	shipping: Share[];
}

export interface ProductRow {
	product_id: number;
	name: string;
	quantity: number;
	/** Given free by discounts, not counted in quantity. */
	given: number;
	net: string;
	orders: number;
	previous_quantity: number | null;
	previous_net: string | null;
}

export interface CategoryRow {
	category_id: number;
	name: string;
	quantity: number;
	net: string;
	orders: number;
	previous_quantity: number | null;
	previous_net: string | null;
}

export interface CouponRow {
	code: string;
	orders: number;
	amount: string;
	previous_orders: number | null;
	previous_amount: string | null;
}

/** One rate of one jurisdiction over the period, as returns are filed. */
export interface TaxRow {
	country: string;
	region: string | null;
	level: 'country' | 'region' | 'local';
	/** The code it is filed under. */
	code: string;
	jurisdiction: string;
	percent: string;
	orders: number;
	/** Sales it was charged on, before tax. */
	taxable: string;
	/** Sales made there without tax, under customers' exemptions. */
	exempt: string;
	tax: string;
	refunded: string;
}

export interface RankingReport< Row > {
	frame: Frame;
	rows: Row[];
}

export interface CustomersReport {
	frame: Frame;
	totals: Totals;
	previous: Totals | null;
	series: {
		date: string;
		new: number;
		returning: number;
		new_total: string;
		returning_total: string;
	}[];
	top: {
		customer_id: number | null;
		name: string;
		orders: number;
		total: string;
	}[];
}

/** Carts left at checkout, counted when they were. */
export interface CartTotals {
	abandoned: number;
	value: string;
	reminded: number;
	recovered: number;
	recovered_value: string;
	recovered_after_reminder: number;
}

export interface CartsReport {
	frame: Frame;
	totals: CartTotals;
	previous: CartTotals | null;
	latest: {
		id: number;
		abandoned_at: string;
		customer_id: number | null;
		/** Only for a customer who asked to be reminded, while the cart is kept. */
		email: string | null;
		items: number;
		subtotal: string;
		reminded_at: string | null;
		order_id: number | null;
		order_total: string | null;
	}[];
}

export interface Reports {
	sales: SalesReport;
	products: RankingReport< ProductRow >;
	categories: RankingReport< CategoryRow >;
	coupons: RankingReport< CouponRow >;
	customers: CustomersReport;
	carts: CartsReport;
	taxes: RankingReport< TaxRow >;
}

/**
 * @param tab   Which report.
 * @param query What it covers.
 * @return The report, from GET /orillacart/v1/reports/{tab}.
 */
export function fetchReport< T extends Tab >(
	tab: T,
	query: ReportQuery
): Promise< Reports[ T ] > {
	return apiFetch< Reports[ T ] >( {
		path: addQueryArgs( `/orillacart/v1/reports/${ tab }`, {
			from: query.from,
			to: query.to,
			compare: query.compare,
			limit: query.limit,
			sort: query.sort,
			...( query.interval === '' ? {} : { interval: query.interval } ),
		} ),
	} );
}

/** Counts every order again, in the background. */
export function rebuildReports(): Promise< unknown > {
	return apiFetch( {
		path: '/orillacart/v1/reports/rebuild',
		method: 'POST',
	} );
}
