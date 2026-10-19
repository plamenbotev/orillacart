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
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { type Coupon, deleteCoupon, fetchCoupons } from '../coupons/api';
import { CouponForm, discountLabel } from '../coupons/CouponsScreen';
import { toApiError } from '../shared/api';
import {
	fetchTerms,
	type Named,
	ProductTokens,
	TermTokens,
} from '../shared/CatalogTokens';
import { fromLocal, toLocal } from '../shared/dates';
import { useConfirm } from '../shared/useConfirm';
import {
	type CustomerGroup,
	deleteRule,
	fetchGroups,
	fetchRules,
	NEW_RULE,
	type PriceRule,
	type PriceRuleFields,
	saveRule,
	type Tier,
	type TierKind,
	TIER_KINDS,
} from './api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';

type Message = { status: 'success' | 'error'; text: string } | null;

const KIND_LABELS: Record< TierKind, string > = {
	percent: __( 'Percentage off', 'orillacart' ),
	amount_off: __( 'Amount off', 'orillacart' ),
	fixed_price: __( 'Price', 'orillacart' ),
};

/**
 * A rule's prices in a few words: "10 % off", "from 10: 9.00".
 *
 * @param tiers The rule's tiers, by quantity.
 */
/**
 * What one tier gives, such as "10 % off".
 *
 * @param tier The tier.
 */
function tierWhat( tier: Tier ): string {
	if ( tier.kind === 'percent' ) {
		/* translators: %s: percentage */
		return sprintf( __( '%s %% off', 'orillacart' ), tier.value );
	}
	if ( tier.kind === 'amount_off' ) {
		/* translators: %s: amount */
		return sprintf( __( '%s off', 'orillacart' ), tier.value );
	}
	return tier.value;
}

export function tiersLabel( tiers: Tier[] ): string {
	return tiers
		.map( ( tier ) => {
			const what = tierWhat( tier );
			return tier.min_quantity === 1
				? what
				: sprintf(
						/* translators: 1: quantity, 2: price */
						__( 'from %1$d: %2$s', 'orillacart' ),
						tier.min_quantity,
						what
					);
		} )
		.join( '; ' );
}

/**
 * The Shop → Price rules screen: prices of the shop's own making, for everyone or some customer groups, on the
 * whole catalog or some of it, between dates, by how many items are bought. Where several rules hold, the
 * shopper gets the lowest price.
 */
export function PriceRulesScreen() {
	const [ confirmation, confirm ] = useConfirm();
	const [ rules, setRules ] = useState< PriceRule[] | null >( null );
	const [ groups, setGroups ] = useState< CustomerGroup[] >( [] );
	const [ editing, setEditing ] = useState< PriceRule | 'new' | null >(
		null
	);
	const [ message, setMessage ] = useState< Message >( null );
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 50,
		fields: [ 'prices', 'groups', 'active' ],
		titleField: 'name',
		layout: {},
	} );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );
	const load = () => {
		fetchRules().then( setRules ).catch( fail );
	};
	useEffect( () => {
		load();
		fetchGroups().then( setGroups ).catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const groupName = ( id: number ) =>
		groups.find( ( group ) => group.id === id )?.name ?? `#${ id }`;
	const fields: Field< PriceRule >[] = [
		{
			id: 'name',
			label: __( 'Rule', 'orillacart' ),
			enableHiding: false,
			enableSorting: false,
		},
		{
			id: 'prices',
			label: __( 'Prices', 'orillacart' ),
			enableSorting: false,
			getValue: ( { item } ) => tiersLabel( item.tiers ),
		},
		{
			id: 'groups',
			label: __( 'For', 'orillacart' ),
			enableSorting: false,
			getValue: ( { item } ) =>
				item.group_ids.length === 0
					? __( 'Everyone', 'orillacart' )
					: item.group_ids.map( groupName ).join( ', ' ),
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

	return (
		<>
			{ confirmation }
			<h1>
				{ __( 'Price rules', 'orillacart' ) }{ ' ' }
				{ editing === null && (
					<Button
						variant="secondary"
						onClick={ () => setEditing( 'new' ) }
					>
						{ __( 'Add a rule', 'orillacart' ) }
					</Button>
				) }
			</h1>
			<p>
				{ __(
					'Where several rules hold for an item, the shopper gets the lowest price. A rule leaves items on sale alone unless it says it goes with sales.',
					'orillacart'
				) }
			</p>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			{ editing !== null && (
				<RuleForm
					key={ editing === 'new' ? 'new' : editing.id }
					rule={ editing === 'new' ? null : editing }
					groups={ groups }
					onClose={ () => setEditing( null ) }
					onSaved={ ( saved ) => {
						setEditing( null );
						load();
						setMessage( {
							status: 'success',
							text: sprintf(
								/* translators: %s: rule name */
								__(
									'The rule %s was saved. The shop prices with it now.',
									'orillacart'
								),
								saved.name
							),
						} );
					} }
					onError={ fail }
				/>
			) }
			<DataViews< PriceRule >
				view={ view }
				onChangeView={ setView }
				fields={ fields }
				data={ rules ?? [] }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ rules === null }
				paginationInfo={ {
					totalItems: rules?.length ?? 0,
					totalPages: 1,
				} }
				defaultLayouts={ { table: {} } }
				search={ false }
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
										/* translators: %s: rule name */
										__(
											'Delete the rule %s? Placed orders keep their prices.',
											'orillacart'
										),
										item.name
									),
									() =>
										deleteRule( item.id )
											.then( load )
											.catch( fail )
								);
							}
						},
					},
				] }
				empty={ <p>{ __( 'No price rules yet.', 'orillacart' ) }</p> }
			/>
			<AutomaticDiscounts
				onMessage={ ( text ) =>
					setMessage( { status: 'success', text } )
				}
				onError={ fail }
			/>
		</>
	);
}

/**
 * Discounts on the order that need no code, such as "10 % off orders over 100" or free shipping over an amount:
 * coupons that apply by themselves wherever they hold. Customers see their description.
 *
 * @param props           What to tell the shop.
 * @param props.onMessage Receives what was done.
 * @param props.onError   Receives a failed call.
 */
function AutomaticDiscounts( {
	onMessage,
	onError,
}: {
	onMessage: ( text: string ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ discounts, setDiscounts ] = useState< Coupon[] | null >( null );
	const [ editing, setEditing ] = useState< Coupon | 'new' | null >( null );
	const load = () =>
		fetchCoupons( { page: 1, per_page: 100, automatic: true } )
			.then( ( page ) => setDiscounts( page.coupons ) )
			.catch( onError );
	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Automatic discounts', 'orillacart' ) }</h2>
				{ editing === null && (
					<Button
						variant="secondary"
						onClick={ () => setEditing( 'new' ) }
					>
						{ __( 'Add an automatic discount', 'orillacart' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Discounts on the whole order that need no code, such as 10 % off orders over 100 or free shipping over 50. They apply wherever they hold, before coupon codes.',
						'orillacart'
					) }
				</p>
				{ editing !== null && (
					<CouponForm
						key={ editing === 'new' ? 'new' : editing.id }
						automatic
						coupon={ editing === 'new' ? null : editing }
						onClose={ () => setEditing( null ) }
						onSaved={ ( saved ) => {
							setEditing( null );
							void load();
							onMessage(
								sprintf(
									/* translators: %s: discount description */
									__(
										'The discount "%s" was saved.',
										'orillacart'
									),
									saved.description
								)
							);
						} }
						onError={ onError }
					/>
				) }
				{ discounts !== null && discounts.length === 0 && (
					<p>{ __( 'No automatic discounts yet.', 'orillacart' ) }</p>
				) }
				<ul className="orillacart-automatic-discounts">
					{ ( discounts ?? [] ).map( ( discount ) => (
						<li key={ discount.id }>
							<strong>{ discount.description }</strong>
							{ ' · ' }
							{ discountLabel( discount ) }
							{ ! discount.active &&
								` · ${ __( 'Off', 'orillacart' ) }` }{ ' ' }
							<Button
								variant="link"
								onClick={ () => setEditing( discount ) }
							>
								{ __( 'Edit', 'orillacart' ) }
							</Button>{ ' ' }
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									deleteCoupon( discount.id )
										.then( load )
										.catch( onError )
								}
							>
								{ __( 'Delete', 'orillacart' ) }
							</Button>
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}

interface RuleFormProps {
	rule: PriceRule | null;
	groups: CustomerGroup[];
	onClose: () => void;
	onSaved: ( rule: PriceRule ) => void;
	onError: ( error: unknown ) => void;
}

function RuleForm( {
	rule,
	groups,
	onClose,
	onSaved,
	onError,
}: RuleFormProps ) {
	const [ fields, setFields ] = useState< PriceRuleFields >(
		rule ?? NEW_RULE
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

	const set = < K extends keyof PriceRuleFields >(
		name: K,
		value: PriceRuleFields[ K ]
	) => setFields( { ...fields, [ name ]: value } );
	const setTier = ( index: number, tier: Partial< Tier > ) =>
		set(
			'tiers',
			fields.tiers.map( ( current, at ) =>
				at === index ? { ...current, ...tier } : current
			)
		);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>
					{ rule === null
						? __( 'New price rule', 'orillacart' )
						: rule.name }
				</h2>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'orillacart' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						setBusy( true );
						saveRule( rule?.id ?? null, fields )
							.then( onSaved )
							.catch( onError )
							.finally( () => setBusy( false ) );
					} }
				>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Name', 'orillacart' ) }
						help={ __(
							'For you, such as "Wholesale" or "Buy 10, save 10 %".',
							'orillacart'
						) }
						value={ fields.name }
						onChange={ ( value ) => set( 'name', value ) }
						required
					/>

					<h3>{ __( 'Prices', 'orillacart' ) }</h3>
					{ fields.tiers.map( ( tier, index ) => (
						<Flex key={ index } align="flex-end" wrap>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 1 }
									label={ __( 'From items', 'orillacart' ) }
									value={ String( tier.min_quantity ) }
									onChange={ ( value ) =>
										setTier( index, {
											min_quantity: Math.max(
												1,
												Math.floor( Number( value ) ) ||
													1
											),
										} )
									}
								/>
							</FlexItem>
							<FlexItem>
								<SelectControl
									__next40pxDefaultSize
									label={ __( 'Price', 'orillacart' ) }
									value={ tier.kind }
									options={ TIER_KINDS.map( ( kind ) => ( {
										value: kind,
										label: KIND_LABELS[ kind ],
									} ) ) }
									onChange={ ( value ) =>
										setTier( index, {
											kind: value as TierKind,
										} )
									}
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									label={
										tier.kind === 'percent'
											? __( 'Percentage', 'orillacart' )
											: __( 'Amount', 'orillacart' )
									}
									value={ tier.value }
									onChange={ ( value ) =>
										setTier( index, { value } )
									}
								/>
							</FlexItem>
							{ fields.tiers.length > 1 && (
								<FlexItem>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											set(
												'tiers',
												fields.tiers.filter(
													( _, at ) => at !== index
												)
											)
										}
									>
										{ __( 'Remove', 'orillacart' ) }
									</Button>
								</FlexItem>
							) }
						</Flex>
					) ) }
					<Button
						variant="secondary"
						onClick={ () =>
							set( 'tiers', [
								...fields.tiers,
								{
									min_quantity:
										Math.max(
											...fields.tiers.map(
												( tier ) => tier.min_quantity
											)
										) + 9,
									kind: 'percent',
									value: '15',
								},
							] )
						}
					>
						{ __( 'Add a price for more items', 'orillacart' ) }
					</Button>

					<h3>{ __( 'Who gets it', 'orillacart' ) }</h3>
					<FormTokenField
						__next40pxDefaultSize
						label={ __(
							'Customer groups (empty: everyone, guests too)',
							'orillacart'
						) }
						value={ fields.group_ids.map(
							( id ) =>
								groups.find( ( group ) => group.id === id )
									?.name ?? `#${ id }`
						) }
						suggestions={ groups.map( ( group ) => group.name ) }
						__experimentalExpandOnFocus
						onChange={ ( tokens ) =>
							set(
								'group_ids',
								tokens
									.map(
										( token ) =>
											groups.find(
												( group ) =>
													group.name ===
													( typeof token === 'string'
														? token
														: token.value )
											)?.id ?? 0
									)
									.filter( ( id ) => id > 0 )
							)
						}
					/>
					{ groups.length === 0 && (
						<p>
							{ __(
								'Make customer groups under Shop → Customers.',
								'orillacart'
							) }
						</p>
					) }

					<h3>{ __( 'What it prices', 'orillacart' ) }</h3>
					<p>
						{ __(
							'Leave these empty for the whole catalog. A category includes its subcategories.',
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
					<CheckboxControl
						label={ __(
							'Also for items on sale (from their sale price)',
							'orillacart'
						) }
						checked={ fields.with_sales }
						onChange={ ( value ) => set( 'with_sales', value ) }
					/>

					<h3>{ __( 'When', 'orillacart' ) }</h3>
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
					<CheckboxControl
						label={ __( 'Active', 'orillacart' ) }
						checked={ fields.active }
						onChange={ ( value ) => set( 'active', value ) }
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Save the rule', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
