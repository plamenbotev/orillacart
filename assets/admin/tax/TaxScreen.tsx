import {
	Button,
	CheckboxControl,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { useConfirm } from '../shared/useConfirm';
import { errorMessage } from '../shared/api';
import { useBusy } from '../shared/useBusy';
import { fetchCountries, type Country } from '../shared/geography';
import {
	createGroup,
	addEuStandardRates,
	deleteGroup,
	deleteRate,
	fetchGroups,
	fetchTaxSettings,
	REGION,
	renameGroup,
	saveTaxSettings,
	type TaxGroup,
	type TaxRate,
	type PriceShown,
	type TaxSettings,
} from './api';
import { EuSalesCard } from './EuSalesCard';
import { ImportSchemesCard } from './ImportSchemesCard';
import { NexusCard } from './NexusCard';
import { RatesCsv } from './RatesCsv';
import { RateModal } from './RateModal';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import { useLeaveGuard } from '../shared/useLeaveGuard';

type Message = { status: 'success' | 'error'; text: string } | null;

const BASIS_LABELS: Record< string, string > = {
	shipping_address: __( "Customer's shipping address", 'orillacart' ),
	billing_address: __( "Customer's billing address", 'orillacart' ),
	shop_address: __( "Shop's address", 'orillacart' ),
	eu_origin: __(
		"Shop's address for EU customers, no tax outside the EU",
		'orillacart'
	),
	eu_destination: __(
		"EU customers' own country (OSS), no tax outside the EU",
		'orillacart'
	),
};

/**
 * The Shop → Tax screen: how tax is charged, and the tax groups with their rates.
 */
export function TaxScreen() {
	const [ groups, setGroups ] = useState< TaxGroup[] | null >( null );
	const [ settings, setSettings ] = useState< TaxSettings | null >( null );
	const [ countries, setCountries ] = useState< Country[] >( [] );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( error ) } );

	const reload = useCallback(
		() =>
			fetchGroups()
				.then( setGroups )
				.catch( ( error: unknown ) =>
					setMessage( {
						status: 'error',
						text: errorMessage( error ),
					} )
				),
		[]
	);

	useEffect( () => {
		Promise.all( [ fetchGroups(), fetchTaxSettings(), fetchCountries() ] )
			.then( ( [ loadedGroups, loadedSettings, loadedCountries ] ) => {
				setGroups( loadedGroups );
				setSettings( loadedSettings );
				setCountries( loadedCountries );
				setSelected( loadedGroups[ 0 ]?.id ?? null );
			} )
			.catch( ( error: unknown ) =>
				setMessage( {
					status: 'error',
					text: errorMessage( error ),
				} )
			);
	}, [] );

	if ( groups === null || settings === null ) {
		return (
			<>
				<h1>{ __( 'Tax', 'orillacart' ) }</h1>
				{ message !== null ? (
					<Notice status={ message.status } isDismissible={ false }>
						{ message.text }
					</Notice>
				) : (
					<Spinner />
				) }
			</>
		);
	}

	const group = groups.find( ( candidate ) => candidate.id === selected );

	return (
		<>
			<h1>{ __( 'Tax', 'orillacart' ) }</h1>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			<SettingsCard
				settings={ settings }
				groups={ groups }
				onSaved={ ( saved ) => {
					setSettings( saved );
					setMessage( {
						status: 'success',
						text: __( 'Tax settings saved.', 'orillacart' ),
					} );
				} }
				onError={ fail }
			/>
			<GroupsCard
				groups={ groups }
				selected={ selected }
				onSelect={ setSelected }
				onCreated={ ( created ) => {
					setSelected( created.id );
					void reload();
				} }
				onError={ fail }
			/>
			{ group !== undefined && (
				<GroupCard
					key={ group.id }
					group={ group }
					countries={ countries }
					onChanged={ () => void reload() }
					onDeleted={ () => {
						setSelected(
							groups.find( ( other ) => other.id !== group.id )
								?.id ?? null
						);
						void reload();
					} }
					onError={ fail }
				/>
			) }
			<NexusCard
				onSaved={ () =>
					setMessage( {
						status: 'success',
						text: __( 'Thresholds saved.', 'orillacart' ),
					} )
				}
				onError={ fail }
			/>
			<ImportSchemesCard
				onSaved={ () =>
					setMessage( {
						status: 'success',
						text: __( 'Import schemes saved.', 'orillacart' ),
					} )
				}
				onError={ fail }
			/>
			<EuSalesCard
				onSaved={ () =>
					setMessage( {
						status: 'success',
						text: __( 'Threshold saved.', 'orillacart' ),
					} )
				}
				onError={ fail }
			/>
		</>
	);
}

function shownOptions(): { label: string; value: PriceShown }[] {
	return [
		{
			label: __( 'As they are entered', 'orillacart' ),
			value: 'as_entered',
		},
		{ label: __( 'With tax', 'orillacart' ), value: 'with_tax' },
		{ label: __( 'Without tax', 'orillacart' ), value: 'without_tax' },
	];
}

function SettingsCard( {
	settings,
	groups,
	onSaved,
	onError,
}: {
	settings: TaxSettings;
	groups: TaxGroup[];
	onSaved: ( saved: TaxSettings ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ basis, setBasis ] = useState( settings.basis );
	const [ defaultGroup, setDefaultGroup ] = useState(
		settings.default_group_id
	);
	const [ includeTax, setIncludeTax ] = useState(
		settings.prices_include_tax
	);
	const [ reverseCharge, setReverseCharge ] = useState(
		settings.reverse_charge
	);
	const [ eachLine, setEachLine ] = useState( settings.rounds_each_line );
	const [ provider, setProvider ] = useState( settings.provider );
	const [ whenFails, setWhenFails ] = useState(
		settings.when_provider_fails
	);
	const [ certificates, setCertificates ] = useState(
		settings.accepts_certificates
	);
	const [ shownInShop, setShownInShop ] = useState( settings.shown_in_shop );
	const [ shownInCart, setShownInCart ] = useState( settings.shown_in_cart );
	const [ suffix, setSuffix ] = useState( settings.price_suffix );
	const [ samePrice, setSamePrice ] = useState(
		settings.same_price_everywhere
	);
	const [ shippingAsItems, setShippingAsItems ] = useState(
		settings.shipping_taxed_as_items
	);
	const [ originRegions, setOriginRegions ] = useState(
		settings.origin_regions.join( ', ' )
	);
	const regions = originRegions
		.split( ',' )
		.map( ( region ) => region.trim().toUpperCase() )
		.filter( ( region ) => region !== '' );
	const [ saving, setSaving ] = useState( false );
	useUnsavedChanges(
		differs(
			[
				basis,
				defaultGroup,
				includeTax,
				reverseCharge,
				eachLine,
				regions.join( ',' ),
				provider,
				whenFails,
				certificates,
				shownInShop,
				shownInCart,
				suffix,
				samePrice,
				shippingAsItems,
			],
			[
				settings.basis,
				settings.default_group_id,
				settings.prices_include_tax,
				settings.reverse_charge,
				settings.rounds_each_line,
				settings.origin_regions.join( ',' ),
				settings.provider,
				settings.when_provider_fails,
				settings.accepts_certificates,
				settings.shown_in_shop,
				settings.shown_in_cart,
				settings.price_suffix,
				settings.same_price_everywhere,
				settings.shipping_taxed_as_items,
			]
		)
	);

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		setSaving( true );
		saveTaxSettings( {
			basis,
			default_group_id: defaultGroup,
			prices_include_tax: includeTax,
			reverse_charge: reverseCharge,
			rounds_each_line: eachLine,
			origin_regions: regions,
			provider,
			when_provider_fails: whenFails,
			accepts_certificates: certificates,
			shown_in_shop: shownInShop,
			shown_in_cart: shownInCart,
			price_suffix: suffix,
			same_price_everywhere: samePrice,
			shipping_taxed_as_items: shippingAsItems,
		} )
			.then( onSaved )
			.catch( onError )
			.finally( () => setSaving( false ) );
	};

	const saved = groups.find(
		( group ) => group.id === settings.default_group_id
	);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'How tax is charged', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ saved === undefined && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'There is no default tax group: products and shipping rates without their own group are sold without tax.',
							'orillacart'
						) }
					</Notice>
				) }
				{ saved !== undefined && saved.rates.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The default tax group has no rates yet: products and shipping rates without their own group are sold without tax.',
							'orillacart'
						) }
					</Notice>
				) }
				<form onSubmit={ submit }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Tax is based on', 'orillacart' ) }
						value={ basis }
						options={ settings.bases.map( ( value ) => ( {
							label: BASIS_LABELS[ value ] ?? value,
							value,
						} ) ) }
						onChange={ setBasis }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Default tax group', 'orillacart' ) }
						help={ __(
							'For products and shipping rates without their own group. Without one, they are not taxed.',
							'orillacart'
						) }
						value={
							defaultGroup === null ? '' : String( defaultGroup )
						}
						options={ [
							{
								label: __( '— None —', 'orillacart' ),
								value: '',
							},
							...groups.map( ( group ) => ( {
								label: group.name,
								value: String( group.id ),
							} ) ),
						] }
						onChange={ ( value ) =>
							setDefaultGroup(
								value === ''
									? null
									: Number.parseInt( value, 10 )
							)
						}
					/>
					<CheckboxControl
						label={ __(
							'Customers send their tax exemption certificates',
							'orillacart'
						) }
						help={ __(
							'Signed-in customers send a certificate (resale, nonprofit, government) with a copy of it, at checkout or in their account. You check it on the customer’s page; once you approve it, their orders into its states are not taxed.',
							'orillacart'
						) }
						checked={ certificates }
						onChange={ setCertificates }
					/>
					<CheckboxControl
						label={ __( 'Prices include tax', 'orillacart' ) }
						help={ __(
							'Prices of products and shipping are entered with tax in them, as EU shops show them. Every customer pays them as entered, and the tax in them is their own country’s; a customer who owes no tax, such as abroad, pays them less your own tax.',
							'orillacart'
						) }
						checked={ includeTax }
						onChange={ setIncludeTax }
					/>
					{ includeTax && (
						<SelectControl
							__next40pxDefaultSize
							label={ __(
								'For customers taxed at another rate',
								'orillacart'
							) }
							value={ samePrice ? 'same' : 'net' }
							options={ [
								{
									label: __(
										'They pay the price as entered',
										'orillacart'
									),
									value: 'same',
								},
								{
									label: __(
										'They pay the price before your tax, with theirs on top',
										'orillacart'
									),
									value: 'net',
								},
							] }
							help={ __(
								'The same price everywhere is what EU customers expect; the tax in it is their country’s. Otherwise the price changes with their rate. A customer who owes no tax pays the price without it either way.',
								'orillacart'
							) }
							onChange={ ( value ) =>
								setSamePrice( value === 'same' )
							}
						/>
					) }
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Prices in the shop', 'orillacart' ) }
						value={ shownInShop }
						options={ shownOptions() }
						onChange={ ( value ) =>
							setShownInShop( value as PriceShown )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __(
							'Prices in the cart and checkout',
							'orillacart'
						) }
						help={ __(
							'Prices with tax are worked out at the customer’s saved address, or at your shop’s for visitors. The totals and the tax lines are the same either way.',
							'orillacart'
						) }
						value={ shownInCart }
						options={ shownOptions() }
						onChange={ ( value ) =>
							setShownInCart( value as PriceShown )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Text after prices', 'orillacart' ) }
						help={ __(
							'Such as “incl. VAT” or “{price_excluding_tax} excl. VAT”: {price_including_tax} and {price_excluding_tax} stand for the price with and without tax. Without text, prices with tax say “incl. tax”.',
							'orillacart'
						) }
						maxLength={ 80 }
						value={ suffix }
						onChange={ setSuffix }
					/>
					<CheckboxControl
						label={ __(
							'Tax shipping like the items it carries',
							'orillacart'
						) }
						help={ __(
							'Shipping’s price is shared out by what the items cost, and each share is taxed at those items’ rates, as EU VAT asks: shipping books and lamps together is taxed partly at the books’ rate. Untaxed items’ share is not taxed. Otherwise shipping is taxed at the tax group of its way to ship.',
							'orillacart'
						) }
						checked={ shippingAsItems }
						onChange={ setShippingAsItems }
					/>
					<CheckboxControl
						label={ __(
							'Ask businesses for their VAT or tax number',
							'orillacart'
						) }
						help={ __(
							'The number goes on the order and the invoice. Businesses in another EU country than yours, with a VAT number the EU’s VIES service confirms, buy without VAT and account for it themselves (reverse charge); when VIES cannot be asked, VAT is charged. Numbers from the UK, Switzerland, Norway, Australia, New Zealand and Canada are checked by their check digit, and one that fails is left out.',
							'orillacart'
						) }
						checked={ reverseCharge }
						onChange={ setReverseCharge }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'States that tax at the seller’s address',
							'orillacart'
						) }
						help={
							regions.some(
								( region ) => ! REGION.test( region )
							)
								? __(
										'Use a country and a state code, such as US-TX.',
										'orillacart'
									)
								: __(
										'For sales within your own state, when it is one of these (such as US-TX, US-AZ), tax is charged at your shop’s address instead of the customer’s. Sales into other states follow the setting above. Separate them with commas.',
										'orillacart'
									)
						}
						value={ originRegions }
						onChange={ setOriginRegions }
					/>
					{ ( settings.providers.length > 0 ||
						settings.provider !== null ) && (
						<>
							<SelectControl
								__next40pxDefaultSize
								label={ __(
									'Taxes worked out by',
									'orillacart'
								) }
								help={ __(
									'A tax service rates every line by the street address and records the sales for the returns it files. Your own rates are used otherwise.',
									'orillacart'
								) }
								value={ provider ?? '' }
								options={ [
									{
										label: __(
											'Your own rates',
											'orillacart'
										),
										value: '',
									},
									...settings.providers.map(
										( offered ) => ( {
											label: offered.label,
											value: offered.id,
										} )
									),
								] }
								onChange={ ( value ) =>
									setProvider( value === '' ? null : value )
								}
							/>
							{ provider !== null && (
								<SelectControl
									__next40pxDefaultSize
									label={ __(
										'When the tax service cannot be reached',
										'orillacart'
									) }
									value={ whenFails }
									options={ [
										{
											label: __(
												'Tax at your own rates, and note it on the order',
												'orillacart'
											),
											value: 'shop_rates',
										},
										{
											label: __(
												'Ask the customer to try again',
												'orillacart'
											),
											value: 'refuse',
										},
									] }
									onChange={ ( value ) =>
										setWhenFails(
											value === 'refuse'
												? 'refuse'
												: 'shop_rates'
										)
									}
								/>
							) }
						</>
					) }
					<CheckboxControl
						label={ __(
							'Round the tax of each line',
							'orillacart'
						) }
						help={ __(
							'Each item’s tax is rounded to the cent and the rounded amounts added up, as some accounting systems expect. Otherwise each rate is rounded once over the whole order, which is closer to the exact tax; the two can differ by a cent.',
							'orillacart'
						) }
						checked={ eachLine }
						onChange={ setEachLine }
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save tax settings', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}

function GroupsCard( {
	groups,
	selected,
	onSelect,
	onCreated,
	onError,
}: {
	groups: TaxGroup[];
	selected: number | null;
	onSelect: ( id: number ) => void;
	onCreated: ( group: TaxGroup ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ name, setName ] = useState( '' );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		createGroup( name )
			.then( ( created ) => {
				setName( '' );
				onCreated( created );
			} )
			.catch( onError );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Tax groups', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ groups.length === 0 ? (
					<p>
						{ __(
							'No tax groups yet. Add one, such as "Standard", then its rates.',
							'orillacart'
						) }
					</p>
				) : (
					<Flex wrap justify="flex-start">
						{ groups.map( ( group ) => (
							<Button
								key={ group.id }
								variant={
									group.id === selected
										? 'primary'
										: 'secondary'
								}
								onClick={ () => onSelect( group.id ) }
							>
								{ group.name }
							</Button>
						) ) }
					</Flex>
				) }
				<form onSubmit={ submit }>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'New group', 'orillacart' ) }
								value={ name }
								onChange={ setName }
							/>
						</FlexItem>
						<Button
							variant="secondary"
							type="submit"
							disabled={ name.trim() === '' }
						>
							{ __( 'Add group', 'orillacart' ) }
						</Button>
					</Flex>
				</form>
			</CardBody>
		</Card>
	);
}

function GroupCard( {
	group,
	countries,
	onChanged,
	onDeleted,
	onError,
}: {
	group: TaxGroup;
	countries: Country[];
	onChanged: () => void;
	onDeleted: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ name, setName ] = useState( group.name );
	const [ taxCode, setTaxCode ] = useState( group.tax_code ?? '' );
	const [ rateConfirmation, confirmRate ] = useConfirm();
	const [ editing, setEditingNow ] = useState< TaxRate | 'new' | null >(
		null
	);
	const [ leaving, guard ] = useLeaveGuard();
	// Another row, or none, only once unsaved changes are let go.
	const setEditing = ( next: Parameters< typeof setEditingNow >[ 0 ] ) =>
		guard( () => setEditingNow( next ) );
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, run ] = useBusy();
	const countryName = ( code: string ) =>
		countries.find( ( country ) => country.code === code )?.name ?? code;

	return (
		<Card className="orillacart-card">
			{ leaving }
			{ rateConfirmation }
			<CardHeader>
				<h2>{ group.name }</h2>
				{ confirming ? (
					<Flex justify="flex-end" expanded={ false }>
						<Button
							variant="primary"
							isDestructive
							onClick={ () =>
								deleteGroup( group.id )
									.then( onDeleted )
									.catch( onError )
							}
						>
							{ __(
								'Delete the group and its rates',
								'orillacart'
							) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Keep it', 'orillacart' ) }
						</Button>
					</Flex>
				) : (
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => setConfirming( true ) }
					>
						{ __( 'Delete group', 'orillacart' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						renameGroup(
							group.id,
							name,
							taxCode.trim() === '' ? null : taxCode.trim()
						)
							.then( onChanged )
							.catch( onError );
					} }
				>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Group name', 'orillacart' ) }
								value={ name }
								onChange={ setName }
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __(
									'Tax code for tax services',
									'orillacart'
								) }
								help={ __(
									'What your tax service calls these goods, such as PC040100 or txcd_99999999. Products can have their own.',
									'orillacart'
								) }
								value={ taxCode }
								maxLength={ 40 }
								onChange={ setTaxCode }
							/>
						</FlexItem>
						<Button
							variant="secondary"
							type="submit"
							disabled={
								name.trim() === '' ||
								( name === group.name &&
									taxCode.trim() ===
										( group.tax_code ?? '' ) )
							}
						>
							{ __( 'Save', 'orillacart' ) }
						</Button>
					</Flex>
				</form>
				<table className="widefat striped orillacart-rates">
					<thead>
						<tr>
							<th>{ __( 'Country', 'orillacart' ) }</th>
							<th>{ __( 'State', 'orillacart' ) }</th>
							<th>{ __( 'Postcodes', 'orillacart' ) }</th>
							<th>{ __( 'Rate', 'orillacart' ) }</th>
							<th>{ __( 'Name', 'orillacart' ) }</th>
							<th>{ __( 'Priority', 'orillacart' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'orillacart' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ group.rates.length === 0 && (
							<tr>
								<td colSpan={ 7 }>
									{ __( 'No rates yet.', 'orillacart' ) }
								</td>
							</tr>
						) }
						{ group.rates.map( ( rate ) => (
							<tr key={ rate.id }>
								<td>{ countryName( rate.country ) }</td>
								<td>
									{ rate.state ??
										__( 'All states', 'orillacart' ) }
								</td>
								<td>
									{ rate.postcodes.length === 0
										? __( 'All postcodes', 'orillacart' )
										: rate.postcodes.join( ', ' ) }
									{ rate.cities.length > 0 && (
										<>
											<br />
											{ rate.cities.join( ', ' ) }
										</>
									) }
								</td>
								<td>
									{ rate.percent } %
									{ rate.compound &&
										` ${ __( '(compound)', 'orillacart' ) }` }
									{ ! rate.shipping &&
										` ${ __(
											'(not on shipping)',
											'orillacart'
										) }` }
								</td>
								<td>{ rate.name }</td>
								<td>{ rate.priority }</td>
								<td>
									<Button
										variant="link"
										onClick={ () => setEditing( rate ) }
									>
										{ __( 'Edit', 'orillacart' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () =>
											confirmRate(
												sprintf(
													/* translators: %s: tax rate name */
													__(
														'Delete the rate %s? Orders taxed at it keep their taxes.',
														'orillacart'
													),
													rate.name
												),
												() =>
													deleteRate(
														group.id,
														rate.id
													)
														.then( onChanged )
														.catch( onError )
											)
										}
									>
										{ __( 'Delete', 'orillacart' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<Flex justify="flex-start">
					<Button
						variant="secondary"
						onClick={ () => setEditing( 'new' ) }
					>
						{ __( 'Add rate', 'orillacart' ) }
					</Button>
					<Button
						variant="tertiary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () =>
							run( () =>
								addEuStandardRates( group.id )
									.then( onChanged )
									.catch( onError )
							)
						}
					>
						{ __( 'Add the EU’s standard rates', 'orillacart' ) }
					</Button>
				</Flex>
				<p className="description">
					{ __(
						'The standard rate of each EU country without one yet, as known in October 2026. Check them against the EU’s list before you rely on them.',
						'orillacart'
					) }
				</p>
				<RatesCsv
					group={ group }
					onImported={ onChanged }
					onError={ onError }
				/>
			</CardBody>
			{ editing !== null && (
				<RateModal
					groupId={ group.id }
					rate={ editing === 'new' ? null : editing }
					countries={ countries }
					onSaved={ () => {
						setEditingNow( null );
						onChanged();
					} }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</Card>
	);
}
