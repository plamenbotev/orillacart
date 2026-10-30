import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { AttributesEditor } from '../catalog/AttributesEditor';
import {
	changedProduct,
	fetchAttributeSets,
	fetchProduct,
	saveProduct,
	toProductInput,
	type AttributeSet,
	type Product,
	type ProductInput,
} from '../catalog/api';
import { fetchStockrooms, type Stockroom } from '../inventory/api';
import { errorMessage, type FieldLabels } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import { fetchShippingClasses, type ShippingClass } from '../shipping/api';
import { fetchGroups, type TaxGroup } from '../tax/api';
import {
	fromDateField,
	fromLastDayField,
	orNull,
	BACKORDERS,
	STOCK_MODES,
	toDateField,
	toLastDayField,
	withSalePrice,
} from './fields';
import { StockLevels } from './StockLevels';
import { BundlePanel, bundleItemKey, bundleItemLabel } from './BundlePanel';
import { DownloadsPanel } from './DownloadsPanel';
import { VariationsPanel } from './VariationsPanel';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import { MoneyControl } from '../shared/MoneyControl';
import { useEditorHost } from './host';
import { ProductSections, Section } from './ProductSections';

/**
 * The product's data box, below the post in either editor, for what the shop sells the product as. The details
 * are saved with the post, or with the "Save product details" button; variations and stock are saved on their own.
 */
export function ProductPanels() {
	const host = useEditorHost();
	const postId = host.usePostId();
	const notices = host.useNotices();

	const [ product, setProduct ] = useState< Product | null >( null );
	const [ draft, setDraft ] = useState< ProductInput | null >( null );
	const [ sets, setSets ] = useState< AttributeSet[] >( [] );
	const [ taxGroups, setTaxGroups ] = useState< TaxGroup[] >( [] );
	const [ shippingClasses, setShippingClasses ] = useState< ShippingClass[] >(
		[]
	);
	const [ stockrooms, setStockrooms ] = useState< Stockroom[] >( [] );
	const [ loadError, setLoadError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	// The product as another save left it, when ours was refused for it (409): shown until reloaded or saved over.
	const [ meanwhile, setMeanwhile ] = useState< Product | null >( null );
	const [ dialog, confirm ] = useConfirm();
	const edited =
		product !== null && draft !== null && changedFrom( draft, product );
	useUnsavedChanges( edited );

	const fail = useCallback(
		( error: unknown ) =>
			host.notify( { status: 'error', message: refusal( error ) } ),
		[ host ]
	);

	const show = ( loaded: Product ) => {
		setProduct( loaded );
		setDraft( toProductInput( loaded ) );
		setMeanwhile( null );
	};

	/**
	 * A save refused because the product was saved since it was loaded: the owner is shown so, with what is
	 * there now, and chooses; any other refusal goes on as one.
	 *
	 * @param error What the save failed with.
	 * @return Whether it was that refusal.
	 */
	const refusedAsChanged = ( error: unknown ): boolean => {
		const now = changedProduct( error );
		if ( now !== null ) {
			setMeanwhile( now );
		}
		return now !== null;
	};

	// After a variation or a download was saved: their lists, and whatever else is new, come from the shop, and
	// the details being edited and not yet saved stay as typed.
	const current = useRef( { product, draft } );
	current.current = { product, draft };
	const reload = useCallback( () => {
		if ( postId !== null ) {
			fetchProduct( postId )
				.then( ( loaded ) => {
					const { product: before, draft: typed } = current.current;
					setProduct( loaded );
					setDraft(
						before === null || typed === null
							? toProductInput( loaded )
							: keepEdits( typed, before, loaded )
					);
				} )
				.catch( fail );
		}
	}, [ postId, fail ] );

	useEffect( () => {
		if ( postId === null ) {
			return;
		}
		Promise.all( [
			fetchProduct( postId ),
			fetchAttributeSets(),
			fetchGroups(),
			fetchStockrooms(),
			fetchShippingClasses(),
		] )
			.then(
				( [
					loaded,
					loadedSets,
					groups,
					loadedStockrooms,
					classes,
				] ) => {
					show( loaded );
					setSets( loadedSets );
					setTaxGroups( groups );
					setStockrooms( loadedStockrooms );
					setShippingClasses( classes );
				}
			)
			.catch( ( error: unknown ) =>
				setLoadError( errorMessage( error ) )
			);
	}, [ postId ] );

	/**
	 * @param over The product as it is now, to save over: its updated_at is sent in place of the one loaded.
	 */
	const save = useCallback(
		( over: Product | null = null ) => {
			if ( postId === null || draft === null || product === null ) {
				return;
			}
			setSaving( true );
			saveProduct( postId, draft, ( over ?? product ).updated_at ?? null )
				.then( ( saved ) => {
					show( saved );
					host.notify( {
						status: 'success',
						message: __( 'Product details saved.', 'orillacart' ),
					} );
				} )
				.catch( ( error: unknown ) => {
					if ( ! refusedAsChanged( error ) ) {
						fail( error );
					}
				} )
				.finally( () => setSaving( false ) );
		},
		[ postId, draft, product, fail, host ]
	);

	// The details go with the post: after it in the block editor, before the classic screen sends it, which a
	// refusal stops with its reasons. Details not changed are not sent: an Update of the post's text does not
	// write the product as it was loaded over a save made meanwhile (a sale price set from the list).
	const saveWithPost = useCallback( async () => {
		if (
			postId === null ||
			draft === null ||
			product === null ||
			! changedFrom( draft, product )
		) {
			return;
		}
		try {
			show(
				await saveProduct( postId, draft, product.updated_at ?? null )
			);
		} catch ( error ) {
			if ( refusedAsChanged( error ) ) {
				throw new Error( CHANGED_MEANWHILE );
			}
			throw new Error( refusal( error ) );
		}
	}, [ postId, draft, product ] );
	host.useSaveWithPost( saveWithPost );

	if ( loadError !== null ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}
	if ( product === null || draft === null || postId === null ) {
		return <Spinner />;
	}

	const change = ( fields: Partial< ProductInput > ) =>
		setDraft( { ...draft, ...fields } );
	const hasVariations = product.variations.length > 0;
	// On a translation's post (Polylang, WPML) the details are its original's: one product in every language.
	const productId = product.id;
	const translation = productId !== postId;

	return (
		<>
			{ dialog }
			{ meanwhile !== null && (
				<Notice status="warning" isDismissible={ false }>
					<p>{ CHANGED_MEANWHILE }</p>
					<Button
						variant="secondary"
						onClick={ () =>
							edited
								? confirm(
										__(
											'The changes you made here are not saved, and are lost. Load the product as it is now?',
											'orillacart'
										),
										() => show( meanwhile ),
										__(
											'Load it, losing my changes',
											'orillacart'
										)
									)
								: show( meanwhile )
						}
					>
						{ __( 'Load it as it is now', 'orillacart' ) }
					</Button>{ ' ' }
					<Button
						variant="primary"
						isBusy={ saving }
						disabled={ saving }
						onClick={ () => save( meanwhile ) }
					>
						{ __( 'Save mine over it', 'orillacart' ) }
					</Button>
				</Notice>
			) }
			<ProductSections notices={ notices }>
				<Section
					name="orillacart-price"
					title={ __( 'Price and tax', 'orillacart' ) }
				>
					{ translation && (
						<Notice status="info" isDismissible={ false }>
							{ sprintf(
								/* translators: %s: the product's name in its original language */
								__(
									'This is a translation of %s. Price, stock and variations are the same product in every language: what you change here changes it.',
									'orillacart'
								),
								product.name
							) }{ ' ' }
							<a href={ editLink( productId ) }>
								{ __( 'Open the original', 'orillacart' ) }
							</a>
						</Notice>
					) }
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Product type', 'orillacart' ) }
						value={ draft.type }
						options={ [
							{
								label: __( 'Shipped', 'orillacart' ),
								value: 'physical',
							},
							{
								label: __( 'Not shipped', 'orillacart' ),
								value: 'virtual',
							},
						] }
						onChange={ ( type ) =>
							change( { type: type as ProductInput[ 'type' ] } )
						}
					/>
					<MoneyControl
						label={ __( 'Price', 'orillacart' ) }
						help={
							hasVariations
								? __(
										'Variations have their own prices.',
										'orillacart'
									)
								: undefined
						}
						value={ draft.price }
						onChange={ ( price ) => change( { price } ) }
					/>
					<MoneyControl
						label={ __( 'Sale price', 'orillacart' ) }
						value={ draft.sale?.price ?? '' }
						onChange={ ( price ) =>
							change( {
								sale: withSalePrice( draft.sale, price ),
							} )
						}
					/>
					{ draft.sale !== null && (
						<>
							<TextControl
								__next40pxDefaultSize
								type="date"
								label={ __( 'Sale starts', 'orillacart' ) }
								value={ toDateField( draft.sale.from ) }
								onChange={ ( value ) =>
									change( {
										sale: draft.sale && {
											...draft.sale,
											from: fromDateField( value ),
										},
									} )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								type="date"
								label={ __( 'Sale ends', 'orillacart' ) }
								value={ toLastDayField( draft.sale.until ) }
								onChange={ ( value ) =>
									change( {
										sale: draft.sale && {
											...draft.sale,
											until: fromLastDayField( value ),
										},
									} )
								}
							/>
						</>
					) }
					<TextControl
						__next40pxDefaultSize
						label={ __( 'SKU', 'orillacart' ) }
						value={ draft.sku ?? '' }
						onChange={ ( sku ) => change( { sku: orNull( sku ) } ) }
					/>
					<CheckboxControl
						label={ __( 'Taxable', 'orillacart' ) }
						checked={ draft.taxable }
						onChange={ ( taxable ) => change( { taxable } ) }
					/>
					{ draft.taxable && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Tax group', 'orillacart' ) }
							value={
								draft.tax_group_id === null
									? ''
									: String( draft.tax_group_id )
							}
							options={ [
								{
									label: __(
										'The default group',
										'orillacart'
									),
									value: '',
								},
								...taxGroups.map( ( group ) => ( {
									label: group.name,
									value: String( group.id ),
								} ) ),
							] }
							onChange={ ( value ) =>
								change( {
									tax_group_id:
										value === ''
											? null
											: Number.parseInt( value, 10 ),
								} )
							}
						/>
					) }
					{ draft.taxable && (
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Tax code for tax services',
								'orillacart'
							) }
							help={ __(
								'Only when it differs from its tax group’s, such as clothing in a group of general goods.',
								'orillacart'
							) }
							value={ draft.tax_code ?? '' }
							maxLength={ 40 }
							onChange={ ( code ) =>
								change( { tax_code: orNull( code.trim() ) } )
							}
						/>
					) }
					<CheckboxControl
						label={ __( 'Can be bought', 'orillacart' ) }
						checked={ draft.purchasable }
						onChange={ ( purchasable ) =>
							change( { purchasable } )
						}
					/>
					<CheckboxControl
						label={ __( 'Featured', 'orillacart' ) }
						checked={ draft.featured }
						onChange={ ( featured ) => change( { featured } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Show the price', 'orillacart' ) }
						help={ __(
							'A hidden price is not shown; shoppers ask for it, and the product is not sold online.',
							'orillacart'
						) }
						value={ draft.price_visibility }
						options={ [
							{
								label: __(
									'As the shop’s setting says',
									'orillacart'
								),
								value: 'shop',
							},
							{
								label: __( 'Always show it', 'orillacart' ),
								value: 'shown',
							},
							{
								label: __( 'Hide it', 'orillacart' ),
								value: 'hidden',
							},
						] }
						onChange={ ( value ) =>
							change( {
								price_visibility:
									value as ProductInput[ 'price_visibility' ],
							} )
						}
					/>
					<Button
						variant="primary"
						isBusy={ saving }
						disabled={ saving }
						onClick={ () => save() }
					>
						{ __( 'Save product details', 'orillacart' ) }
					</Button>
				</Section>

				<Section
					name="orillacart-shipping"
					title={ __( 'Weight and size', 'orillacart' ) }
				>
					{ shippingClasses.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Shipping class', 'orillacart' ) }
							help={ __(
								'Only rates that carry this class ship it. Variations ship in the product’s class.',
								'orillacart'
							) }
							value={ String( draft.shipping_class_id ?? '' ) }
							options={ [
								{
									value: '',
									label: __( 'None', 'orillacart' ),
								},
								...shippingClasses.map( ( shippingClass ) => ( {
									value: String( shippingClass.id ),
									label: shippingClass.name,
								} ) ),
							] }
							onChange={ ( value ) =>
								change( {
									shipping_class_id:
										value === ''
											? null
											: Number.parseInt( value, 10 ),
								} )
							}
						/>
					) }
					{ (
						[
							[ 'weight', __( 'Weight', 'orillacart' ) ],
							[ 'length', __( 'Length', 'orillacart' ) ],
							[ 'width', __( 'Width', 'orillacart' ) ],
							[ 'height', __( 'Height', 'orillacart' ) ],
						] as const
					 ).map( ( [ field, label ] ) => (
						<TextControl
							key={ field }
							__next40pxDefaultSize
							label={ label }
							value={ draft[ field ] ?? '' }
							onChange={ ( value ) =>
								change( { [ field ]: orNull( value ) } )
							}
						/>
					) ) }
				</Section>

				<Section
					name="orillacart-stock"
					title={ __( 'Stock', 'orillacart' ) }
				>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Stock', 'orillacart' ) }
						value={ draft.stock_mode }
						options={ STOCK_MODES }
						onChange={ ( mode ) =>
							change( {
								stock_mode:
									mode as ProductInput[ 'stock_mode' ],
							} )
						}
					/>
					{ ( draft.stock_mode === 'tracked' || hasVariations ) && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'When out of stock', 'orillacart' ) }
							help={
								hasVariations
									? __(
											'Variations follow this unless they say otherwise.',
											'orillacart'
										)
									: undefined
							}
							value={ draft.backorders }
							options={ BACKORDERS }
							onChange={ ( value ) =>
								change( {
									backorders:
										value as ProductInput[ 'backorders' ],
								} )
							}
						/>
					) }
					{ ( draft.stock_mode === 'tracked' || hasVariations ) &&
						draft.backorders !== 'no' && (
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 1 }
								label={ __( 'Owe at most', 'orillacart' ) }
								help={ __(
									'How many may be sold on backorder in all. Empty for no limit.',
									'orillacart'
								) }
								value={ draft.backorder_limit ?? '' }
								onChange={ ( value ) =>
									change( {
										backorder_limit:
											value === ''
												? null
												: Number( value ),
									} )
								}
							/>
						) }
					{ ( draft.stock_mode === 'tracked' || hasVariations ) && (
						<TextControl
							__next40pxDefaultSize
							type="number"
							min={ 0 }
							label={ __( 'Low stock at', 'orillacart' ) }
							help={ __(
								'The shop hears when a stockroom holds this many or fewer. Empty for the stockroom’s or the shop’s.',
								'orillacart'
							) }
							value={ draft.low_stock_threshold ?? '' }
							onChange={ ( value ) =>
								change( {
									low_stock_threshold:
										value === '' ? null : Number( value ),
								} )
							}
						/>
					) }
					{ hasVariations && (
						<p>
							{ __(
								'Each variation has its own stock.',
								'orillacart'
							) }
						</p>
					) }
					{ ! hasVariations &&
						product.saved &&
						draft.stock_mode === 'tracked' && (
							<StockLevels
								kind="product"
								id={ productId }
								stockrooms={ stockrooms }
								onError={ fail }
							/>
						) }
				</Section>

				<Section
					name="orillacart-attributes"
					title={ __( 'Attributes', 'orillacart' ) }
				>
					{ sets.map( ( set ) => (
						<CheckboxControl
							key={ set.id }
							label={ set.active ? set.name : `${ set.name } ✕` }
							help={
								set.active
									? undefined
									: __( 'Inactive set', 'orillacart' )
							}
							checked={ draft.set_ids.includes( set.id ) }
							onChange={ ( checked ) =>
								change( {
									set_ids: checked
										? [ ...draft.set_ids, set.id ]
										: draft.set_ids.filter(
												( id ) => id !== set.id
											),
								} )
							}
						/>
					) ) }
					<AttributesEditor
						attributes={ draft.attributes }
						onChange={ ( attributes ) => change( { attributes } ) }
					/>
				</Section>

				<Section
					name="orillacart-downloads"
					title={ __( 'Downloads', 'orillacart' ) }
				>
					{ product.saved ? (
						<DownloadsPanel
							productId={ productId }
							downloads={ product.downloads }
							limit={ draft.download_limit }
							expiryDays={ draft.download_expiry_days }
							onTermsChange={ change }
							onChanged={ reload }
							onError={ fail }
						/>
					) : (
						<p>
							{ __(
								'Save the product details first.',
								'orillacart'
							) }
						</p>
					) }
				</Section>

				<Section
					name="orillacart-bundle"
					title={ __( 'Bundle', 'orillacart' ) }
				>
					<BundlePanel
						productId={ productId }
						bundle={ draft.bundle }
						names={ Object.fromEntries(
							( product.bundle?.items ?? [] ).map( ( item ) => [
								bundleItemKey( item ),
								bundleItemLabel(
									item.name ?? `#${ item.product_id }`,
									( item.variations ?? [] ).map(
										( v ) => v.name
									)
								),
							] )
						) }
						hasVariations={ product.variations.length > 0 }
						onChange={ ( bundle ) => change( { bundle } ) }
						onError={ fail }
					/>
				</Section>

				<Section
					name="orillacart-variations"
					title={ __( 'Variations', 'orillacart' ) }
				>
					{ product.saved ? (
						<VariationsPanel
							productId={ productId }
							attributes={ product.offered }
							variations={ product.variations }
							stockrooms={ stockrooms }
							onChanged={ reload }
							onError={ fail }
						/>
					) : (
						<p>
							{ __(
								'Save the product details first.',
								'orillacart'
							) }
						</p>
					) }
				</Section>
			</ProductSections>
		</>
	);
}

const CHANGED_MEANWHILE = __(
	'This product was changed elsewhere since you opened it. Load it as it is now, or save your details over it.',
	'orillacart'
);

/** The box's words for the fields the API names, so a refusal reads as the box does. */
const LABELS: FieldLabels = {
	type: __( 'Product type', 'orillacart' ),
	price: __( 'Price', 'orillacart' ),
	sale: __( 'Sale price', 'orillacart' ),
	'sale.price': __( 'Sale price', 'orillacart' ),
	'sale.from': __( 'Sale starts', 'orillacart' ),
	'sale.until': __( 'Sale ends', 'orillacart' ),
	sku: __( 'SKU', 'orillacart' ),
	tax_group_id: __( 'Tax group', 'orillacart' ),
	tax_code: __( 'Tax code for tax services', 'orillacart' ),
	price_visibility: __( 'Show the price', 'orillacart' ),
	shipping_class_id: __( 'Shipping class', 'orillacart' ),
	weight: __( 'Weight', 'orillacart' ),
	length: __( 'Length', 'orillacart' ),
	width: __( 'Width', 'orillacart' ),
	height: __( 'Height', 'orillacart' ),
	stock_mode: __( 'Stock', 'orillacart' ),
	backorders: __( 'When out of stock', 'orillacart' ),
	backorder_limit: __( 'Owe at most', 'orillacart' ),
	low_stock_threshold: __( 'Low stock at', 'orillacart' ),
	/* translators: %d: the attribute's number in the list */
	'attributes[]': __( 'Attribute %d', 'orillacart' ),
	'attributes[].name': __( 'Name', 'orillacart' ),
	'attributes[].options': __( 'Options', 'orillacart' ),
	download_limit: __( 'Downloads per file', 'orillacart' ),
	download_expiry_days: __( 'Downloads work for (days)', 'orillacart' ),
	'bundle.items': __( 'Bundle items', 'orillacart' ),
	'bundle.discount': __( 'Percentage off the sum', 'orillacart' ),
};

/**
 * The shop's refusal with each field's reason, by the field's label.
 *
 * @param error What the request failed with.
 */
function refusal( error: unknown ): string {
	return errorMessage( error, LABELS );
}

/**
 * A form's value as compared: amounts by their number ("2.50" is "2.5"), text without the spaces around it.
 *
 * @param value A value of the details.
 */
function normalised( value: unknown ): unknown {
	if ( typeof value === 'string' ) {
		const text = value.trim();
		return /^-?\d+(\.\d+)?$/.test( text ) ? Number( text ) : text;
	}
	if ( Array.isArray( value ) ) {
		return value.map( normalised );
	}
	if ( value !== null && typeof value === 'object' ) {
		return Object.fromEntries(
			Object.entries( value )
				.filter( ( [ , field ] ) => field !== undefined )
				.sort( ( [ a ], [ b ] ) => a.localeCompare( b ) )
				.map( ( [ key, field ] ) => [ key, normalised( field ) ] )
		);
	}
	return value;
}

/**
 * Whether the details as edited differ from the product as loaded.
 *
 * @param draft   The details as edited.
 * @param product The product as loaded.
 */
function changedFrom( draft: ProductInput, product: Product ): boolean {
	return differs(
		normalised( draft ),
		normalised( toProductInput( product ) )
	);
}

/**
 * The details as edited, onto the product as reloaded: each field changed here keeps what was typed, the
 * others take what the shop has now.
 *
 * @param typed  The details as edited.
 * @param before The product they were edited from.
 * @param loaded The product as reloaded.
 */
function keepEdits(
	typed: ProductInput,
	before: Product,
	loaded: Product
): ProductInput {
	const was = toProductInput( before );
	const now = toProductInput( loaded );
	const merged: Record< string, unknown > = { ...now };
	for ( const key of Object.keys( typed ) as ( keyof ProductInput )[] ) {
		if ( differs( normalised( typed[ key ] ), normalised( was[ key ] ) ) ) {
			merged[ key ] = typed[ key ];
		}
	}
	return merged as ProductInput;
}

/**
 * The edit screen of a product's post.
 *
 * @param id The post's id.
 */
const editLink = ( id: number ) => `post.php?post=${ id }&action=edit`;
