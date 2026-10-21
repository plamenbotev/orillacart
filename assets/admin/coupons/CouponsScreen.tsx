import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	FormTokenField,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { type Named, ProductTokens, TermTokens } from '../shared/CatalogTokens';
import { toApiError } from '../shared/api';
import { fromLocal, toLocal } from '../shared/dates';
import { useConfirm } from '../shared/useConfirm';
import {
	type Coupon,
	type CouponFields,
	type CouponPage,
	deleteCoupon,
	fetchCoupons,
	fetchTerms,
	type Kind,
	KINDS,
	NEW_COUPON,
	saveCoupon,
} from './api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';

type Message = { status: 'success' | 'error'; text: string } | null;

export const KIND_LABELS: Record< Kind, string > = {
	percent: __( 'Percentage off', 'orillacart' ),
	order_amount: __( 'Amount off the order', 'orillacart' ),
	item_amount: __( 'Amount off each item', 'orillacart' ),
	free_shipping: __( 'Free shipping', 'orillacart' ),
	buy_get: __( 'Buy X, get Y', 'orillacart' ),
	free_gift: __( 'Free gift', 'orillacart' ),
};

function amountLabel( kind: Kind ): string {
	return kind === 'percent' || kind === 'buy_get'
		? __( 'Percentage', 'orillacart' )
		: __( 'Amount', 'orillacart' );
}

/**
 * What a coupon takes off, in a few words: "10 %", "5 off the order", "Free shipping".
 *
 * @param coupon The coupon.
 */
export function discountLabel( coupon: CouponFields ): string {
	switch ( coupon.kind ) {
		case 'percent':
			return coupon.cap === null
				? sprintf(
						/* translators: %s: percentage */
						__( '%s %%', 'orillacart' ),
						coupon.amount
					)
				: sprintf(
						/* translators: 1: percentage, 2: cap */
						__( '%1$s %%, at most %2$s', 'orillacart' ),
						coupon.amount,
						coupon.cap
					);
		case 'order_amount':
			return sprintf(
				/* translators: %s: amount */
				__( '%s off the order', 'orillacart' ),
				coupon.amount
			);
		case 'item_amount':
			return sprintf(
				/* translators: %s: amount */
				__( '%s off each item', 'orillacart' ),
				coupon.amount
			);
		case 'buy_get':
			return Number( coupon.amount ) >= 100
				? sprintf(
						/* translators: 1: how many to buy, 2: how many free */
						__( 'Buy %1$d, get %2$d free', 'orillacart' ),
						coupon.buy_quantity,
						coupon.get_quantity
					)
				: sprintf(
						/* translators: 1: how many to buy, 2: how many given, 3: percentage */
						__( 'Buy %1$d, get %2$d at %3$s %% off', 'orillacart' ),
						coupon.buy_quantity,
						coupon.get_quantity,
						coupon.amount
					);
		case 'free_gift':
			return KIND_LABELS.free_gift;
		default:
			return KIND_LABELS.free_shipping;
	}
}

const FIELDS: Field< Coupon >[] = [
	{
		id: 'code',
		label: __( 'Code', 'orillacart' ),
		enableHiding: false,
		enableSorting: false,
	},
	{
		id: 'discount',
		label: __( 'Discount', 'orillacart' ),
		enableSorting: false,
		getValue: ( { item } ) => discountLabel( item ),
	},
	{
		id: 'description',
		label: __( 'Description', 'orillacart' ),
		enableSorting: false,
	},
	{
		id: 'uses',
		label: __( 'Used', 'orillacart' ),
		enableSorting: false,
		getValue: ( { item } ) =>
			item.usage_limit === null
				? String( item.uses )
				: `${ item.uses } / ${ item.usage_limit }`,
	},
	{
		id: 'ends_at',
		label: __( 'Ends', 'orillacart' ),
		enableSorting: false,
		getValue: ( { item } ) =>
			item.ends_at === null
				? '—'
				: new Date( item.ends_at ).toLocaleString(),
	},
	{
		id: 'active',
		label: __( 'Status', 'orillacart' ),
		enableSorting: false,
		getValue: ( { item } ) =>
			item.active
				? __( 'Active', 'orillacart' )
				: __( 'Off', 'orillacart' ),
	},
];

/**
 * The Shop → Coupons screen: the codes customers enter at the cart or checkout, what each takes off, on which
 * items, when and for whom it holds, and how often it may be used.
 */
export function CouponsScreen() {
	const [ confirmation, confirm ] = useConfirm();
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		fields: [ 'discount', 'description', 'uses', 'ends_at', 'active' ],
		titleField: 'code',
		layout: {},
	} );
	const [ page, setPage ] = useState< CouponPage | null >( null );
	const [ editing, setEditing ] = useState< Coupon | 'new' | null >( null );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );
	const load = useCallback( () => {
		fetchCoupons( {
			search: view.search === '' ? undefined : view.search,
			page: view.page ?? 1,
			per_page: view.perPage ?? 20,
		} )
			.then( setPage )
			.catch( fail );
	}, [ view ] );
	useEffect( load, [ load ] );

	return (
		<>
			{ confirmation }
			<h1>
				{ __( 'Coupons', 'orillacart' ) }{ ' ' }
				{ editing === null && (
					<Button
						variant="secondary"
						onClick={ () => setEditing( 'new' ) }
					>
						{ __( 'Add a coupon', 'orillacart' ) }
					</Button>
				) }
			</h1>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			{ editing !== null && (
				<CouponForm
					key={ editing === 'new' ? 'new' : editing.id }
					coupon={ editing === 'new' ? null : editing }
					onClose={ () => setEditing( null ) }
					onSaved={ ( saved ) => {
						setEditing( null );
						load();
						setMessage( {
							status: 'success',
							text: sprintf(
								/* translators: %s: coupon code */
								__( 'The coupon %s was saved.', 'orillacart' ),
								saved.code
							),
						} );
					} }
					onError={ fail }
				/>
			) }
			<DataViews< Coupon >
				view={ view }
				onChangeView={ setView }
				fields={ FIELDS }
				data={ page?.coupons ?? [] }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ page === null }
				paginationInfo={ {
					totalItems: page?.total ?? 0,
					totalPages: page?.pages ?? 1,
				} }
				defaultLayouts={ { table: {} } }
				searchLabel={ __( 'Search codes', 'orillacart' ) }
				onClickItem={ setEditing }
				isItemClickable={ () => true }
				actions={ [
					{
						id: 'edit',
						label: __( 'Edit', 'orillacart' ),
						isPrimary: true,
						callback: ( [ item ] ) => setEditing( item ?? null ),
					},
					{
						id: 'delete',
						label: __( 'Delete', 'orillacart' ),
						callback: ( [ item ] ) => {
							if ( item ) {
								confirm(
									sprintf(
										/* translators: %s: coupon code */
										__(
											'Delete the coupon %s? Orders that used it keep their discount.',
											'orillacart'
										),
										item.code
									),
									() =>
										deleteCoupon( item.id )
											.then( load )
											.catch( fail )
								);
							}
						},
					},
				] }
				empty={ <p>{ __( 'No coupons yet.', 'orillacart' ) }</p> }
			/>
		</>
	);
}

interface CouponFormProps {
	/** An automatic discount: no code, a description customers see. */
	automatic?: boolean;
	coupon: Coupon | null;
	onClose: () => void;
	onSaved: ( coupon: Coupon ) => void;
	onError: ( error: unknown ) => void;
}

export function CouponForm( {
	automatic = false,
	coupon,
	onClose,
	onSaved,
	onError,
}: CouponFormProps ) {
	const [ fields, setFields ] = useState< CouponFields >(
		coupon ??
			( automatic
				? { ...NEW_COUPON, combines: true, automatic: true }
				: NEW_COUPON )
	);
	const [ opened ] = useState( fields );
	useUnsavedChanges( differs( fields, opened ) );
	const [ categories, setCategories ] = useState< Named[] >( [] );
	const [ brands, setBrands ] = useState< Named[] >( [] );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchTerms( 'orillacart_category' )
			.then( setCategories )
			.catch( onError );
		fetchTerms( 'orillacart_brand' ).then( setBrands ).catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const set = < K extends keyof CouponFields >(
		name: K,
		value: CouponFields[ K ]
	) => setFields( { ...fields, [ name ]: value } );
	const optional = ( value: string ) =>
		value.trim() === '' ? null : value;
	const count = ( value: string ) =>
		value.trim() === ''
			? null
			: Math.max( 1, Math.floor( Number( value ) ) );
	let title: string = __( 'New coupon', 'orillacart' );
	if ( automatic ) {
		title =
			coupon?.description ?? __( 'New automatic discount', 'orillacart' );
	} else if ( coupon !== null ) {
		/* translators: %s: coupon code */
		title = sprintf( __( 'Coupon %s', 'orillacart' ), coupon.code );
	}

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ title }</h2>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'orillacart' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						setBusy( true );
						saveCoupon( coupon?.id ?? null, fields )
							.then( onSaved )
							.catch( onError )
							.finally( () => setBusy( false ) );
					} }
				>
					<Flex align="flex-start" wrap>
						{ ! automatic && (
							<FlexItem isBlock>
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Code', 'orillacart' ) }
									help={ __(
										'What customers enter; capitals and small letters are the same.',
										'orillacart'
									) }
									value={ fields.code }
									onChange={ ( value ) =>
										set( 'code', value.toUpperCase() )
									}
									required
								/>
							</FlexItem>
						) }
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Description', 'orillacart' ) }
								help={
									automatic
										? __(
												'What customers see on the cart, the receipt and the emails, such as "10 % off orders over 100".',
												'orillacart'
											)
										: __(
												'Shown next to the code on the cart, the receipt and the emails.',
												'orillacart'
											)
								}
								required={ automatic }
								value={ fields.description }
								onChange={ ( value ) =>
									set( 'description', value )
								}
							/>
						</FlexItem>
					</Flex>
					<Flex align="flex-start" wrap>
						<FlexItem isBlock>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Discount', 'orillacart' ) }
								value={ fields.kind }
								options={ KINDS.map( ( kind ) => ( {
									value: kind,
									label: KIND_LABELS[ kind ],
								} ) ) }
								onChange={ ( value ) => {
									const kind = value as Kind;
									setFields( {
										...fields,
										kind,
										// Each kind starts from quantities that make it.
										buy_quantity:
											kind === 'buy_get'
												? Math.max(
														1,
														fields.buy_quantity
													)
												: 0,
										get_quantity:
											kind === 'buy_get' ||
											kind === 'free_gift'
												? Math.max(
														1,
														fields.get_quantity
													)
												: 0,
										amount:
											kind === 'buy_get' &&
											Number( fields.amount ) > 100
												? '100'
												: fields.amount,
									} );
								} }
							/>
						</FlexItem>
						{ fields.kind === 'buy_get' && (
							<>
								<FlexItem isBlock>
									<TextControl
										__next40pxDefaultSize
										type="number"
										min={ 1 }
										label={ __( 'Buy', 'orillacart' ) }
										value={ String( fields.buy_quantity ) }
										onChange={ ( value ) =>
											set(
												'buy_quantity',
												count( value ) ?? 1
											)
										}
									/>
								</FlexItem>
								<FlexItem isBlock>
									<TextControl
										__next40pxDefaultSize
										type="number"
										min={ 1 }
										label={ __( 'Get', 'orillacart' ) }
										value={ String( fields.get_quantity ) }
										onChange={ ( value ) =>
											set(
												'get_quantity',
												count( value ) ?? 1
											)
										}
									/>
								</FlexItem>
							</>
						) }
						{ fields.kind === 'free_gift' && (
							<FlexItem isBlock>
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 1 }
									label={ __(
										'How many of the gift',
										'orillacart'
									) }
									value={ String( fields.get_quantity ) }
									onChange={ ( value ) =>
										set(
											'get_quantity',
											count( value ) ?? 1
										)
									}
								/>
							</FlexItem>
						) }
						{ fields.kind !== 'free_shipping' &&
							fields.kind !== 'free_gift' && (
								<FlexItem isBlock>
									<TextControl
										__next40pxDefaultSize
										label={ amountLabel( fields.kind ) }
										help={
											fields.kind === 'buy_get'
												? __(
														'Off the items given: 100 gives them free.',
														'orillacart'
													)
												: undefined
										}
										value={ fields.amount }
										onChange={ ( value ) =>
											set( 'amount', value )
										}
										required
									/>
								</FlexItem>
							) }
						{ ( fields.kind === 'percent' ||
							fields.kind === 'buy_get' ) && (
							<FlexItem isBlock>
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'At most (optional)',
										'orillacart'
									) }
									value={ fields.cap ?? '' }
									onChange={ ( value ) =>
										set( 'cap', optional( value ) )
									}
								/>
							</FlexItem>
						) }
					</Flex>
					<CheckboxControl
						label={ __(
							'Active: customers can use it',
							'orillacart'
						) }
						checked={ fields.active }
						onChange={ ( value ) => set( 'active', value ) }
					/>

					{ fields.kind === 'free_gift' && (
						<ProductTokens
							label={ __( 'The gift', 'orillacart' ) }
							help={ __(
								'A product without variations or options. Checkout adds it once the coupon holds, or makes the one in the cart free; while it is not for sale, the order goes without it.',
								'orillacart'
							) }
							ids={
								fields.gift_product_id === null
									? []
									: [ fields.gift_product_id ]
							}
							onChange={ ( ids ) =>
								set( 'gift_product_id', ids.at( -1 ) ?? null )
							}
							onError={ onError }
						/>
					) }
					{ fields.kind === 'buy_get' && (
						<>
							<h3>{ __( 'What it gives', 'orillacart' ) }</h3>
							<p>
								{ __(
									'Leave these empty to give off the items it applies to: the dearest count as bought, the cheapest as given. Otherwise buying the items it applies to gives these.',
									'orillacart'
								) }
							</p>
							<ProductTokens
								label={ __( 'Products given', 'orillacart' ) }
								ids={ fields.get_product_ids }
								onChange={ ( ids ) =>
									set( 'get_product_ids', ids )
								}
								onError={ onError }
							/>
							<TermTokens
								label={ __( 'Categories given', 'orillacart' ) }
								terms={ categories }
								ids={ fields.get_category_ids }
								onChange={ ( ids ) =>
									set( 'get_category_ids', ids )
								}
							/>
						</>
					) }

					<h3>{ __( 'What it applies to', 'orillacart' ) }</h3>
					<p>
						{ __(
							'Leave these empty for the whole order. A category includes its subcategories.',
							'orillacart'
						) }
					</p>
					<ProductTokens
						label={ __( 'Products', 'orillacart' ) }
						ids={ fields.product_ids }
						onChange={ ( ids ) => set( 'product_ids', ids ) }
						onError={ onError }
					/>
					<TermTokens
						label={ __( 'Categories', 'orillacart' ) }
						terms={ categories }
						ids={ fields.category_ids }
						onChange={ ( ids ) => set( 'category_ids', ids ) }
					/>
					<TermTokens
						label={ __( 'Brands', 'orillacart' ) }
						terms={ brands }
						ids={ fields.brand_ids }
						onChange={ ( ids ) => set( 'brand_ids', ids ) }
					/>
					<ProductTokens
						label={ __( 'Never for these products', 'orillacart' ) }
						ids={ fields.excluded_product_ids }
						onChange={ ( ids ) =>
							set( 'excluded_product_ids', ids )
						}
						onError={ onError }
					/>
					<TermTokens
						label={ __(
							'Never for these categories',
							'orillacart'
						) }
						terms={ categories }
						ids={ fields.excluded_category_ids }
						onChange={ ( ids ) =>
							set( 'excluded_category_ids', ids )
						}
					/>
					<CheckboxControl
						label={ __( 'Not for items on sale', 'orillacart' ) }
						checked={ fields.exclude_sale_items }
						onChange={ ( value ) =>
							set( 'exclude_sale_items', value )
						}
					/>

					<h3>{ __( 'When it holds', 'orillacart' ) }</h3>
					<Flex align="flex-start" wrap>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="datetime-local"
								label={ __( 'Starts', 'orillacart' ) }
								value={ toLocal( fields.starts_at ) }
								onChange={ ( value ) =>
									set( 'starts_at', fromLocal( value ) )
								}
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="datetime-local"
								label={ __( 'Ends', 'orillacart' ) }
								value={ toLocal( fields.ends_at ) }
								onChange={ ( value ) =>
									set( 'ends_at', fromLocal( value ) )
								}
							/>
						</FlexItem>
					</Flex>
					<Flex align="flex-start" wrap>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __(
									'Smallest order (before tax)',
									'orillacart'
								) }
								value={ fields.min_subtotal ?? '' }
								onChange={ ( value ) =>
									set( 'min_subtotal', optional( value ) )
								}
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Largest order', 'orillacart' ) }
								value={ fields.max_subtotal ?? '' }
								onChange={ ( value ) =>
									set( 'max_subtotal', optional( value ) )
								}
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								label={ __(
									'Items it applies to, at least',
									'orillacart'
								) }
								value={ String( fields.min_quantity ) }
								onChange={ ( value ) =>
									set(
										'min_quantity',
										Math.max(
											0,
											Math.floor( Number( value ) ) || 0
										)
									)
								}
							/>
						</FlexItem>
					</Flex>
					<FormTokenField
						__next40pxDefaultSize
						label={ __(
							'Only for orders shipped to (country codes, such as BG)',
							'orillacart'
						) }
						value={ fields.countries }
						onChange={ ( tokens ) =>
							set(
								'countries',
								tokens
									.map( ( token ) =>
										( typeof token === 'string'
											? token
											: token.value
										)
											.trim()
											.toUpperCase()
									)
									.filter( ( code ) =>
										/^[A-Z]{2}$/.test( code )
									)
							)
						}
					/>

					<h3>{ __( 'Limits', 'orillacart' ) }</h3>
					<Flex align="flex-start" wrap>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 1 }
								label={ __(
									'Uses in all (optional)',
									'orillacart'
								) }
								value={ String( fields.usage_limit ?? '' ) }
								onChange={ ( value ) =>
									set( 'usage_limit', count( value ) )
								}
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 1 }
								label={ __(
									'Uses per customer (optional)',
									'orillacart'
								) }
								help={ __(
									'By account, or by email for guests.',
									'orillacart'
								) }
								value={ String(
									fields.per_customer_limit ?? ''
								) }
								onChange={ ( value ) =>
									set( 'per_customer_limit', count( value ) )
								}
							/>
						</FlexItem>
					</Flex>
					<CheckboxControl
						label={ __( 'First orders only', 'orillacart' ) }
						checked={ fields.first_order_only }
						onChange={ ( value ) =>
							set( 'first_order_only', value )
						}
					/>
					<CheckboxControl
						label={
							automatic
								? __(
										'Goes with coupon codes and other discounts that allow it',
										'orillacart'
									)
								: __(
										'Can be used with other codes that allow it',
										'orillacart'
									)
						}
						checked={ fields.combines }
						onChange={ ( value ) => set( 'combines', value ) }
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Save the coupon', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
