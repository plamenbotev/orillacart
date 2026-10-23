import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { AttributesEditor } from '../catalog/AttributesEditor';
import {
	fetchAttributeSets,
	fetchProduct,
	saveProduct,
	toProductInput,
	type AttributeSet,
	type Product,
	type ProductInput,
} from '../catalog/api';
import { fetchStockrooms, type Stockroom } from '../inventory/api';
import { toApiError } from '../shared/api';
import { fetchShippingClasses, type ShippingClass } from '../shipping/api';
import { fetchGroups, type TaxGroup } from '../tax/api';
import {
	fromDateField,
	orNull,
	BACKORDERS,
	STOCK_MODES,
	toDateField,
	withSalePrice,
} from './fields';
import { StockLevels } from './StockLevels';
import { BundlePanel, bundleItemKey, bundleItemLabel } from './BundlePanel';
import { DownloadsPanel } from './DownloadsPanel';
import { VariationsPanel } from './VariationsPanel';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import { MoneyControl } from '../shared/MoneyControl';

/**
 * Panels in the product editor's sidebar for what the shop sells the product as. The details are saved with the
 * post, or with the "Save product details" button; variations and stock are saved on their own.
 */
export function ProductPanels() {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId() as number | null,
		[]
	);
	const { isSaving, isAutosaving, saveFailed } = useSelect(
		( select ) => ( {
			isSaving: select( editorStore ).isSavingPost(),
			isAutosaving: select( editorStore ).isAutosavingPost(),
			saveFailed: select( editorStore ).didPostSaveRequestFail(),
		} ),
		[]
	);
	const { createErrorNotice, createSuccessNotice } =
		useDispatch( noticesStore );

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
	useUnsavedChanges(
		product !== null &&
			draft !== null &&
			differs( draft, toProductInput( product ) )
	);

	const fail = useCallback(
		( error: unknown ) => {
			const { message, data } = toApiError( error );
			const details = ( data?.violations ?? [] ).map(
				( violation ) => `${ violation.field }: ${ violation.message }`
			);
			void createErrorNotice( [ message, ...details ].join( ' ' ), {
				id: 'orillacart-product',
			} );
		},
		[ createErrorNotice ]
	);

	const show = ( loaded: Product ) => {
		setProduct( loaded );
		setDraft( toProductInput( loaded ) );
	};

	const reload = useCallback( () => {
		if ( postId !== null ) {
			fetchProduct( postId ).then( show ).catch( fail );
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
				setLoadError( toApiError( error ).message )
			);
	}, [ postId ] );

	const save = useCallback(
		( quiet: boolean ) => {
			if ( postId === null || draft === null ) {
				return;
			}
			setSaving( true );
			saveProduct( postId, draft )
				.then( ( saved ) => {
					show( saved );
					if ( ! quiet ) {
						void createSuccessNotice(
							__( 'Product details saved.', 'orillacart' ),
							{ id: 'orillacart-product', type: 'snackbar' }
						);
					}
				} )
				.catch( fail )
				.finally( () => setSaving( false ) );
		},
		[ postId, draft, fail, createSuccessNotice ]
	);

	// Save the details along with the post: when a save that is not an autosave finishes.
	const wasSaving = useRef( false );
	useEffect( () => {
		if (
			wasSaving.current &&
			! isSaving &&
			! isAutosaving &&
			! saveFailed
		) {
			save( true );
		}
		wasSaving.current = isSaving && ! isAutosaving;
	}, [ isSaving, isAutosaving, saveFailed, save ] );

	if ( loadError !== null ) {
		return (
			<PluginDocumentSettingPanel
				name="orillacart-price"
				title={ __( 'Price and tax', 'orillacart' ) }
			>
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			</PluginDocumentSettingPanel>
		);
	}
	if ( product === null || draft === null || postId === null ) {
		return (
			<PluginDocumentSettingPanel
				name="orillacart-price"
				title={ __( 'Price and tax', 'orillacart' ) }
			>
				<Spinner />
			</PluginDocumentSettingPanel>
		);
	}

	const change = ( fields: Partial< ProductInput > ) =>
		setDraft( { ...draft, ...fields } );
	const hasVariations = product.variations.length > 0;

	return (
		<>
			<PluginDocumentSettingPanel
				name="orillacart-price"
				title={ __( 'Price and tax', 'orillacart' ) }
			>
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
						change( { sale: withSalePrice( draft.sale, price ) } )
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
							value={ toDateField( draft.sale.until ) }
							onChange={ ( value ) =>
								change( {
									sale: draft.sale && {
										...draft.sale,
										until: fromDateField( value ),
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
								label: __( 'The default group', 'orillacart' ),
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
					onChange={ ( purchasable ) => change( { purchasable } ) }
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
					onClick={ () => save( false ) }
				>
					{ __( 'Save product details', 'orillacart' ) }
				</Button>
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
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
							{ value: '', label: __( 'None', 'orillacart' ) },
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
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
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
							stock_mode: mode as ProductInput[ 'stock_mode' ],
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
										value === '' ? null : Number( value ),
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
							id={ postId }
							stockrooms={ stockrooms }
							onError={ fail }
						/>
					) }
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
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
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
				name="orillacart-downloads"
				title={ __( 'Downloads', 'orillacart' ) }
			>
				{ product.saved ? (
					<DownloadsPanel
						productId={ postId }
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
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
				name="orillacart-bundle"
				title={ __( 'Bundle', 'orillacart' ) }
			>
				<BundlePanel
					productId={ postId }
					bundle={ draft.bundle }
					names={ Object.fromEntries(
						( product.bundle?.items ?? [] ).map( ( item ) => [
							bundleItemKey( item ),
							bundleItemLabel(
								item.name ?? `#${ item.product_id }`,
								( item.variations ?? [] ).map( ( v ) => v.name )
							),
						] )
					) }
					hasVariations={ product.variations.length > 0 }
					onChange={ ( bundle ) => change( { bundle } ) }
					onError={ fail }
				/>
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
				name="orillacart-variations"
				title={ __( 'Variations', 'orillacart' ) }
			>
				{ product.saved ? (
					<VariationsPanel
						productId={ postId }
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
			</PluginDocumentSettingPanel>
		</>
	);
}
