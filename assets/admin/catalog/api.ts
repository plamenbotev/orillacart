import apiFetch from '@wordpress/api-fetch';

export interface AttributeOption {
	id: number;
	name: string;
	/** Decimal string added to the price, such as "2.5" or "-1"; a percentage of the price when the kind is percent. */
	adjustment: string;
	adjustment_kind: AdjustmentKind;
	/** The name in the site's other languages, by locale. */
	translations: Record< string, string >;
}

/** amount: added to the price; percent: a percentage of the product's price. */
export type AdjustmentKind = 'amount' | 'percent';

export interface Attribute {
	id: number;
	name: string;
	required: boolean;
	price_hidden: boolean;
	options: AttributeOption[];
	/** The attribute set it comes from; null for a product's own attribute. */
	set_id: number | null;
	/** The name in the site's other languages, by locale. */
	translations: Record< string, string >;
}

/** An attribute as it is sent: new attributes and options have no id yet. */
export interface AttributeInput {
	id?: number;
	name: string;
	required: boolean;
	price_hidden: boolean;
	options: {
		id?: number;
		name: string;
		adjustment: string;
		adjustment_kind: AdjustmentKind;
		translations?: Record< string, string >;
	}[];
	/** The name in the site's other languages, by locale; left out, they stay as they are. */
	translations?: Record< string, string >;
}

export interface AttributeSet {
	id: number;
	name: string;
	active: boolean;
	attributes: Attribute[];
}

export interface AttributeSetInput {
	name: string;
	active: boolean;
	attributes: AttributeInput[];
}

export interface Sale {
	price: string;
	/** ISO 8601 date-time, or null for an open start. */
	from: string | null;
	/** ISO 8601 date-time, or null for an open end. */
	until: string | null;
}

export type StockMode = 'tracked' | 'always_available' | 'unavailable';

/** When counted stock runs out: stop selling, sell on, or sell on and tell the customer. */
export type Backorders = 'no' | 'yes' | 'notify';

export interface Variation {
	id: number;
	option_ids: number[];
	/** The option names, such as "M / Blue". */
	label: string;
	price: string;
	sale: Sale | null;
	sku: string | null;
	stock_mode: StockMode;
	/** Its own; null to follow the product. */
	backorders: Backorders | null;
	/** How many it may owe at most; null to follow the product. */
	backorder_limit: number | null;
	/** The shop hears when a stockroom holds this many or fewer; null to follow the product. */
	low_stock_threshold: number | null;
	/** Its own; null for the product's. Length, width and height likewise. */
	weight: string | null;
	length: string | null;
	width: string | null;
	height: string | null;
}

export type VariationInput = Omit< Variation, 'id' | 'label' >;

export interface Product {
	id: number;
	name: string;
	/** False until the commerce details are first saved. */
	saved: boolean;
	type: 'physical' | 'virtual';
	price: string;
	sale: Sale | null;
	sku: string | null;
	taxable: boolean;
	tax_group_id: number | null;
	/** What tax services call it, over its tax group's code; null for the group's. */
	tax_code: string | null;
	stock_mode: StockMode;
	backorders: Backorders;
	/** How many it may owe at most when it takes backorders; null for no limit. */
	backorder_limit: number | null;
	/** The shop hears when a stockroom holds this many or fewer; null for the stockroom's or the shop's. */
	low_stock_threshold: number | null;
	purchasable: boolean;
	featured: boolean;
	/** shop: as the shop's setting says; shown; hidden (not sold online). */
	price_visibility: 'shop' | 'shown' | 'hidden';
	/** The product's and its variations' shipping class; null for none. */
	shipping_class_id: number | null;
	weight: string | null;
	length: string | null;
	width: string | null;
	height: string | null;
	set_ids: number[];
	/** The product's own attributes. */
	attributes: Attribute[];
	/** Every attribute the product offers, from its active sets and its own. */
	offered: Attribute[];
	variations: Variation[];
	/** How many times a buyer may download each file; null for no limit. */
	download_limit: number | null;
	/** How many days after payment the downloads work; null for always. */
	download_expiry_days: number | null;
	downloads: Download[];
	/** What it is made of when it is a bundle; null for a product of its own. */
	bundle: Bundle | null;
	/**
	 * When its details were last saved; null for a product never saved. Sent back with a save, which a product
	 * saved since refuses (409 orillacart_product_changed).
	 */
	updated_at?: string | null;
}

export interface BundleItem {
	product_id: number;
	/** For a product with variations: the one the bundle holds, or several the customer chooses from. */
	variation_ids: number[];
	quantity: number;
	/** Left out unless the customer adds it. */
	optional: boolean;
	/** As the API returns it; not sent back. */
	name?: string;
	/** The listed variations' names, as the API returns them; not sent back. */
	variations?: { id: number; name: string }[];
}

export interface Bundle {
	items: BundleItem[];
	/** Its own price, or its items' prices added up with the discount off. */
	pricing: 'fixed' | 'sum';
	/** A percentage, for sum pricing. */
	discount: string | null;
}

export interface Download {
	id: number;
	name: string;
	mime_type: string;
	size: number;
}

export interface DownloadStorage {
	location: 'outside_web_root' | 'uploads' | 'configured';
	directory: string;
	/** Whether the web server handed out a test file by itself; null when not tested. */
	reachable: boolean | null;
	nginx_rule: string;
}

export type ProductInput = Omit<
	Product,
	| 'id'
	| 'name'
	| 'saved'
	| 'attributes'
	| 'offered'
	| 'variations'
	| 'downloads'
	| 'updated_at'
> & { attributes: AttributeInput[] };

const BASE = '/orillacart/v1';

/**
 * @param attribute An attribute as the API returns it.
 * @return The same attribute as it is sent back.
 */
export function toAttributeInput( attribute: Attribute ): AttributeInput {
	return {
		id: attribute.id,
		name: attribute.name,
		required: attribute.required,
		price_hidden: attribute.price_hidden,
		options: attribute.options.map(
			( {
				id,
				name,
				adjustment,
				adjustment_kind: adjustmentKind,
				translations,
			} ) => ( {
				id,
				name,
				adjustment,
				adjustment_kind: adjustmentKind,
				translations,
			} )
		),
		translations: attribute.translations,
	};
}

/**
 * @param product A product as the API returns it.
 * @return What saving it unchanged sends.
 */
export function toProductInput( product: Product ): ProductInput {
	return {
		type: product.type,
		price: product.price,
		sale: product.sale,
		sku: product.sku,
		taxable: product.taxable,
		tax_group_id: product.tax_group_id,
		tax_code: product.tax_code,
		stock_mode: product.stock_mode,
		backorders: product.backorders,
		backorder_limit: product.backorder_limit,
		low_stock_threshold: product.low_stock_threshold,
		purchasable: product.purchasable,
		featured: product.featured,
		price_visibility: product.price_visibility,
		shipping_class_id: product.shipping_class_id,
		weight: product.weight,
		length: product.length,
		width: product.width,
		height: product.height,
		set_ids: product.set_ids,
		attributes: product.attributes.map( toAttributeInput ),
		download_limit: product.download_limit,
		download_expiry_days: product.download_expiry_days,
		bundle: toBundleInput( product.bundle ?? null ),
	};
}

/**
 * @param bundle A bundle as the API returns it.
 * @return The same bundle as it is sent back: without its items' names.
 */
export function toBundleInput( bundle: Bundle | null ): Bundle | null {
	return bundle === null
		? null
		: {
				pricing: bundle.pricing,
				discount: bundle.discount,
				items: bundle.items.map( ( item ) => ( {
					product_id: item.product_id,
					variation_ids: item.variation_ids,
					quantity: item.quantity,
					optional: item.optional,
				} ) ),
			};
}

export function fetchProduct( id: number ): Promise< Product > {
	return apiFetch< Product >( { path: `${ BASE }/products/${ id }` } );
}

/**
 * @param id        The product.
 * @param input     Its details.
 * @param updatedAt The product's updated_at as it was loaded: a product saved since is refused with 409
 *                  orillacart_product_changed (see changedProduct), rather than overwritten.
 */
export function saveProduct(
	id: number,
	input: ProductInput,
	updatedAt: string | null
): Promise< Product > {
	return apiFetch< Product >( {
		path: `${ BASE }/products/${ id }`,
		method: 'PUT',
		data: { ...input, updated_at: updatedAt },
	} );
}

/**
 * The product as it is now, when a save was refused because it was saved since it was loaded (409
 * orillacart_product_changed); null for another failure.
 *
 * @param error Whatever the save rejected with.
 */
export function changedProduct( error: unknown ): Product | null {
	if (
		typeof error !== 'object' ||
		error === null ||
		( error as { code?: unknown } ).code !== 'orillacart_product_changed'
	) {
		return null;
	}
	const product = ( error as { data?: { product?: unknown } } ).data?.product;
	return typeof product === 'object' && product !== null
		? ( product as Product )
		: null;
}

export function saveVariation(
	productId: number,
	variationId: number | null,
	input: VariationInput
): Promise< Variation > {
	return apiFetch< Variation >(
		variationId === null
			? {
					path: `${ BASE }/products/${ productId }/variations`,
					method: 'POST',
					data: input,
				}
			: {
					path: `${ BASE }/products/${ productId }/variations/${ variationId }`,
					method: 'PUT',
					data: input,
				}
	);
}

export function deleteVariation(
	productId: number,
	variationId: number
): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/products/${ productId }/variations/${ variationId }`,
		method: 'DELETE',
	} );
}

export function fetchAttributeSets(): Promise< AttributeSet[] > {
	return apiFetch< AttributeSet[] >( { path: `${ BASE }/attribute-sets` } );
}

export function saveAttributeSet(
	id: number | null,
	input: AttributeSetInput
): Promise< AttributeSet > {
	return apiFetch< AttributeSet >(
		id === null
			? { path: `${ BASE }/attribute-sets`, method: 'POST', data: input }
			: {
					path: `${ BASE }/attribute-sets/${ id }`,
					method: 'PUT',
					data: input,
				}
	);
}

export function deleteAttributeSet( id: number ): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/attribute-sets/${ id }`,
		method: 'DELETE',
	} );
}

export function uploadDownload(
	productId: number,
	file: File
): Promise< Download > {
	const body = new FormData();
	body.append( 'file', file );

	return apiFetch< Download >( {
		path: `${ BASE }/products/${ productId }/downloads`,
		method: 'POST',
		body,
	} );
}

export function renameDownload(
	productId: number,
	fileId: number,
	name: string
): Promise< Download > {
	return apiFetch< Download >( {
		path: `${ BASE }/products/${ productId }/downloads/${ fileId }`,
		method: 'PUT',
		data: { name },
	} );
}

export function removeDownload(
	productId: number,
	fileId: number
): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/products/${ productId }/downloads/${ fileId }`,
		method: 'DELETE',
	} );
}

export function fetchDownloadStorage(
	fresh = false
): Promise< DownloadStorage > {
	return apiFetch< DownloadStorage >( {
		path: `${ BASE }/download-storage${ fresh ? '?fresh=1' : '' }`,
	} );
}

/** A variation's post: what shoppers read and see of it. */
export interface VariationContent {
	title: string;
	/** Shown under the price when the variation is chosen. */
	description: string;
	/** Media library id, 0 for none. */
	image: number;
	gallery: number[];
}

type VariationPost = {
	title: { raw: string };
	excerpt: { raw: string };
	featured_media: number;
	meta: { orillacart_gallery?: number[] };
};

const VARIATION_POSTS = '/wp/v2/orillacart-variations';

export async function fetchVariationContent(
	id: number
): Promise< VariationContent > {
	const post = await apiFetch< VariationPost >( {
		path: `${ VARIATION_POSTS }/${ id }?context=edit`,
	} );
	return {
		title: post.title.raw,
		description: post.excerpt.raw,
		image: post.featured_media,
		gallery: post.meta.orillacart_gallery ?? [],
	};
}

export async function saveVariationContent(
	id: number,
	content: VariationContent
): Promise< void > {
	await apiFetch( {
		path: `${ VARIATION_POSTS }/${ id }`,
		method: 'POST',
		data: {
			// An empty title keeps the one made with the variation: product and options.
			...( content.title.trim() === '' ? {} : { title: content.title } ),
			excerpt: content.description,
			featured_media: content.image,
			meta: { orillacart_gallery: content.gallery },
		},
	} );
}
