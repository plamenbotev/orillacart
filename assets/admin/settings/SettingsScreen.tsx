import {
	Button,
	CheckboxControl,
	FormTokenField,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import {
	errorMessage,
	toApiError,
	type Violation,
	violationOf,
} from '../shared/api';
import { fetchCountries, type Country } from '../shared/geography';
import { LocationFields } from '../shared/LocationFields';
import { CurrenciesSection } from './CurrenciesSection';
import { SettingsFileSection } from './SettingsFileSection';
import {
	type CurrencySettings,
	fetchCurrencies,
	saveCurrencies,
} from './currencies';
import {
	fetchSettings,
	saveSettings,
	type Settings,
	type SettingsInput,
	settingsInput,
} from './api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import {
	Field,
	More,
	notify,
	Page,
	Row,
	SaveBar,
	Section,
	Skeleton,
} from '../shared/ui';

type Status =
	| { kind: 'idle' }
	| { kind: 'saving' }
	| { kind: 'saved' }
	| { kind: 'error'; message: string };

/**
 * The choices of price decimals: the currency's own, or fewer.
 *
 * @param most The currency's decimals; null when the form's currency is not saved yet.
 */
function decimalChoices( most: number | null ) {
	const fewer = Array.from( { length: most ?? 4 }, ( _, decimals ) => ( {
		label: String( decimals ),
		value: String( decimals ),
	} ) );
	return [
		{
			label:
				most === null
					? __( 'The currency’s own', 'orillacart' )
					: sprintf(
							/* translators: %d: how many decimals the currency has */
							__( 'The currency’s own: %d', 'orillacart' ),
							most
						),
			value: '',
		},
		...fewer,
	];
}

const POSITIONS: Record< string, string > = {
	before: __( 'Before the amount: $100', 'orillacart' ),
	before_space: __( 'Before, with a space: $ 100', 'orillacart' ),
	after: __( 'After the amount: 100$', 'orillacart' ),
	after_space: __( 'After, with a space: 100 $', 'orillacart' ),
};

/**
 * The Shop → Settings screen: loads the settings from the REST API and saves them back.
 */
export function SettingsScreen() {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ countries, setCountries ] = useState< Country[] >( [] );
	const [ form, setForm ] = useState< SettingsInput | null >( null );
	/** The other currencies as saved, and as the form holds them: part of this form, saved with it. */
	const [ currencies, setCurrencies ] = useState< CurrencySettings | null >(
		null
	);
	const [ offered, setOffered ] = useState< CurrencySettings | null >( null );
	const [ violations, setViolations ] = useState< Violation[] >( [] );
	const [ status, setStatus ] = useState< Status >( { kind: 'idle' } );
	// Counts the settings files imported: each loads the screen's settings anew, so a save after it does not put
	// back what the file changed.
	const [ imports, setImports ] = useState( 0 );
	const dirty =
		( form !== null &&
			settings !== null &&
			differs( form, settingsInput( settings ) ) ) ||
		( offered !== null &&
			currencies !== null &&
			differs( offered, currencies, [ 'rate' ] ) );
	useUnsavedChanges( dirty );

	useEffect( () => {
		Promise.all( [ fetchSettings(), fetchCountries(), fetchCurrencies() ] )
			.then( ( [ loaded, list, others ] ) => {
				setSettings( loaded );
				setCountries( list );
				setForm( settingsInput( loaded ) );
				setCurrencies( others );
				setOffered( others );
			} )
			.catch( ( error: unknown ) =>
				setStatus( {
					kind: 'error',
					message: errorMessage( error ),
				} )
			);
	}, [ imports ] );

	const fieldError = ( field: keyof SettingsInput ) =>
		violationOf( violations, field );

	const update = < K extends keyof SettingsInput >(
		field: K,
		value: SettingsInput[ K ]
	) => setForm( ( current ) => current && { ...current, [ field ]: value } );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( form === null ) {
			return;
		}
		setStatus( { kind: 'saving' } );
		setViolations( [] );
		saveSettings( form )
			.then( async ( saved ) => {
				setSettings( saved );
				setForm( settingsInput( saved ) );
				if (
					offered !== null &&
					differs( offered, currencies, [ 'rate' ] )
				) {
					const others = await saveCurrencies( offered );
					setCurrencies( others );
					setOffered( others );
				}
				setStatus( { kind: 'saved' } );
				notify( __( 'Settings saved.', 'orillacart' ) );
			} )
			.catch( ( error: unknown ) => {
				const apiError = toApiError( error );
				setViolations( apiError.data?.violations ?? [] );
				setStatus( { kind: 'error', message: apiError.message } );
			} );
	};

	return (
		<Page
			title={ __( 'Settings', 'orillacart' ) }
			actions={
				<Button
					variant="secondary"
					href="admin.php?page=orillacart-setup"
				>
					{ __( 'Set up the shop', 'orillacart' ) }
				</Button>
			}
		>
			{ status.kind === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ status.message }
				</Notice>
			) }
			{ settings === null || form === null ? (
				status.kind !== 'error' && (
					<Skeleton lines={ 3 } widths={ [ '60%', '90%', '40%' ] } />
				)
			) : (
				<form id="orillacart-settings" onSubmit={ submit }>
					<Section
						title={ __( 'Currency and units', 'orillacart' ) }
						description={ __(
							'What the shop prices in, and how it weighs and measures.',
							'orillacart'
						) }
					>
						<Row>
							<Field size="xs">
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Currency', 'orillacart' ) }
									help={
										fieldError( 'currency' ) ??
										__(
											'Three-letter ISO code, such as EUR or USD.',
											'orillacart'
										)
									}
									value={ form.currency }
									maxLength={ 3 }
									onChange={ ( value ) =>
										update(
											'currency',
											value.toUpperCase()
										)
									}
								/>
							</Field>
							<Field size="xs">
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Currency sign',
										'orillacart'
									) }
									help={
										fieldError( 'currency_sign' ) ??
										__(
											'Such as $ or лв. Empty: the currency code is shown.',
											'orillacart'
										)
									}
									value={ form.currency_sign }
									maxLength={ 8 }
									onChange={ ( value ) =>
										update( 'currency_sign', value )
									}
								/>
							</Field>
							<Field size="m">
								<SelectControl
									__next40pxDefaultSize
									label={ __(
										'Where the sign goes',
										'orillacart'
									) }
									help={ fieldError( 'currency_position' ) }
									value={ form.currency_position }
									options={ settings.currency_positions.map(
										( position ) => ( {
											label:
												POSITIONS[ position ] ??
												position,
											value: position,
										} )
									) }
									onChange={ ( value ) =>
										update( 'currency_position', value )
									}
								/>
							</Field>
							<Field size="m">
								<SelectControl
									__next40pxDefaultSize
									label={ __(
										'Price decimals',
										'orillacart'
									) }
									help={
										fieldError( 'fewer_decimals' ) ??
										__(
											'Amounts are priced, charged and shown with these decimals. Fewer than the currency has suit shops that price in whole amounts, as PayPal takes forints.',
											'orillacart'
										)
									}
									value={
										form.fewer_decimals === null
											? ''
											: String( form.fewer_decimals )
									}
									options={ decimalChoices(
										form.currency === settings.currency
											? settings.currency_decimals
											: null
									) }
									onChange={ ( value ) =>
										update(
											'fewer_decimals',
											value === ''
												? null
												: Number.parseInt( value, 10 )
										)
									}
								/>
							</Field>
						</Row>
						<Row>
							<Field size="xs">
								<SelectControl
									__next40pxDefaultSize
									label={ __( 'Weight unit', 'orillacart' ) }
									help={ fieldError( 'weight_unit' ) }
									value={ form.weight_unit }
									options={ settings.weight_units.map(
										( unit ) => ( {
											label: unit,
											value: unit,
										} )
									) }
									onChange={ ( value ) =>
										update( 'weight_unit', value )
									}
								/>
							</Field>
							<Field size="xs">
								<SelectControl
									__next40pxDefaultSize
									label={ __(
										'Dimension unit',
										'orillacart'
									) }
									help={ fieldError( 'dimension_unit' ) }
									value={ form.dimension_unit }
									options={ settings.dimension_units.map(
										( unit ) => ( {
											label: unit,
											value: unit,
										} )
									) }
									onChange={ ( value ) =>
										update( 'dimension_unit', value )
									}
								/>
							</Field>
						</Row>
					</Section>
					{ offered !== null && (
						<CurrenciesSection
							currency={ form.currency }
							offered={ offered.offered }
							onChange={ ( list ) =>
								setOffered( { offered: list } )
							}
						/>
					) }
					<Section
						title={ __( 'Where the shop is', 'orillacart' ) }
						description={ __(
							'Taxes and shipping start from here.',
							'orillacart'
						) }
					>
						<Row>
							<Field size="m">
								<LocationFields
									countries={ countries }
									value={ {
										country: form.shop_country,
										state: form.shop_state,
									} }
									onChange={ ( location ) =>
										setForm(
											( current ) =>
												current && {
													...current,
													shop_country:
														location.country,
													shop_state: location.state,
												}
										)
									}
									countryLabel={ __(
										'Shop country',
										'orillacart'
									) }
									stateLabel={ __(
										'Shop state',
										'orillacart'
									) }
									anyState={ __(
										'— Not set —',
										'orillacart'
									) }
									optionalCountry
									countryError={ fieldError(
										'shop_country'
									) }
									stateError={ fieldError( 'shop_state' ) }
								/>
							</Field>
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Shop postcode',
										'orillacart'
									) }
									help={ fieldError( 'shop_postcode' ) }
									value={ form.shop_postcode ?? '' }
									onChange={ ( value ) =>
										update(
											'shop_postcode',
											value.trim() === '' ? null : value
										)
									}
								/>
							</Field>
						</Row>
					</Section>
					<Section title={ __( 'Selling', 'orillacart' ) }>
						<Field size="l">
							<FormTokenField
								__next40pxDefaultSize
								label={ __( 'Sell to', 'orillacart' ) }
								value={ form.sell_to.map(
									( code ) =>
										countries.find(
											( country ) => country.code === code
										)?.name ?? code
								) }
								suggestions={ countries.map(
									( country ) => country.name
								) }
								onChange={ ( tokens ) =>
									update(
										'sell_to',
										tokens.flatMap( ( token ) => {
											const name =
												typeof token === 'string'
													? token
													: token.value;
											const found = countries.find(
												( country ) =>
													country.name === name ||
													country.code === name
											);
											return found ? [ found.code ] : [];
										} )
									)
								}
								__experimentalExpandOnFocus
								help={
									fieldError( 'sell_to' ) ??
									__(
										'Checkout offers only these countries. Empty: every country.',
										'orillacart'
									)
								}
							/>
						</Field>
						<Field size="s">
							<TextControl
								__next40pxDefaultSize
								inputMode="decimal"
								label={ __( 'Minimum order', 'orillacart' ) }
								help={
									fieldError( 'minimum_order' ) ??
									__(
										'The least the items must come to, after discounts, for a customer to order; the cart says how much more is needed. Empty: any amount. A shipping rate can ask for more with its own order value.',
										'orillacart'
									)
								}
								value={ form.minimum_order ?? '' }
								onChange={ ( value ) =>
									update(
										'minimum_order',
										value.trim() === ''
											? null
											: value.trim()
									)
								}
							/>
						</Field>
						<CheckboxControl
							label={ __(
								'Catalog only: show products, take no orders',
								'orillacart'
							) }
							checked={ form.catalog_only }
							onChange={ ( value ) =>
								update( 'catalog_only', value )
							}
						/>
						<CheckboxControl
							label={ __(
								'Hide prices; shoppers ask for them',
								'orillacart'
							) }
							help={ __(
								'Products with a hidden price are not sold online. A product can show its price anyway.',
								'orillacart'
							) }
							checked={ form.hide_prices }
							onChange={ ( value ) =>
								update( 'hide_prices', value )
							}
						/>
						<CheckboxControl
							label={ __(
								'Show the lowest price of the last 30 days on sales',
								'orillacart'
							) }
							help={ __(
								'Next to a sale price, as the EU’s Omnibus directive asks of shops announcing a price reduction. The shop keeps each product’s prices from now on to know it.',
								'orillacart'
							) }
							checked={ form.show_lowest_price }
							onChange={ ( value ) =>
								update( 'show_lowest_price', value )
							}
						/>
					</Section>
					<Section title={ __( 'Orders and returns', 'orillacart' ) }>
						<Row>
							<Field size="xs">
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 0 }
									label={ __( 'Low stock at', 'orillacart' ) }
									help={ __(
										'The shop hears daily of items a stockroom holds this many of or fewer. A stockroom or product can say otherwise.',
										'orillacart'
									) }
									value={ String( form.low_stock_threshold ) }
									onChange={ ( value ) =>
										update(
											'low_stock_threshold',
											Math.max( 0, Number( value ) || 0 )
										)
									}
								/>
							</Field>
							<Field size="xs">
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 1 }
									max={ 365 }
									label={ __(
										'Cancel unpaid orders after (days)',
										'orillacart'
									) }
									help={ __(
										'Orders on hold for a bank transfer or cheque; their stock sells again and the customer hears of it. Empty: never. Cash on delivery waits.',
										'orillacart'
									) }
									value={ form.unpaid_hold_days ?? '' }
									onChange={ ( value ) =>
										update(
											'unpaid_hold_days',
											value === ''
												? null
												: Number( value )
										)
									}
								/>
							</Field>
							<Field size="xs">
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 1 }
									max={ 365 }
									label={ __(
										'Customers may return items within (days)',
										'orillacart'
									) }
									help={ __(
										'Counted from when the order shipped; customers ask from their order page, and you approve or refuse. Empty: you take no returns.',
										'orillacart'
									) }
									value={ form.return_days ?? '' }
									onChange={ ( value ) =>
										update(
											'return_days',
											value === ''
												? null
												: Number( value )
										)
									}
								/>
							</Field>
						</Row>
						<Row>
							<Field size="m">
								<SelectControl
									__next40pxDefaultSize
									label={ __(
										'Returns from a deal that gave free items',
										'orillacart'
									) }
									help={ __(
										'Two lamps bought and a third given, one sent back. Spread: each lamp refunds its share of the deal (20 of 60). The others apply once what is kept no longer earns the free lamp; the lamp sent back then refunds what it cost.',
										'orillacart'
									) }
									value={ form.free_items_on_return }
									options={ [
										{
											value: 'spread',
											label: __(
												'Each item refunds its share of the deal',
												'orillacart'
											),
										},
										{
											value: 'charged',
											label: __(
												'Keep its price from the refund',
												'orillacart'
											),
										},
										{
											value: 'come_back',
											label: __(
												'It comes back with them',
												'orillacart'
											),
										},
										{
											value: 'stay_free',
											label: __(
												'It stays free',
												'orillacart'
											),
										},
									] }
									onChange={ ( value ) =>
										update(
											'free_items_on_return',
											value as SettingsInput[ 'free_items_on_return' ]
										)
									}
								/>
							</Field>
							<Field size="m">
								<SelectControl
									__next40pxDefaultSize
									label={ __(
										'Reports count an order on',
										'orillacart'
									) }
									help={ __(
										'The day its money came suits periods you file; the day it was placed shows when customers ordered. Changing it counts every order again.',
										'orillacart'
									) }
									value={ form.report_day }
									options={ [
										{
											value: 'paid',
											label: __(
												'The day it was paid',
												'orillacart'
											),
										},
										{
											value: 'placed',
											label: __(
												'The day it was placed',
												'orillacart'
											),
										},
									] }
									onChange={ ( value ) =>
										update( 'report_day', value )
									}
								/>
							</Field>
						</Row>
						<More>
							<CheckboxControl
								label={ __(
									'Remove all shop data when the plugin is deleted',
									'orillacart'
								) }
								help={ __(
									'Orders, products, customers’ shop data, settings and stored files go with the plugin, for good. Without it, deleting the plugin keeps them for when it comes back.',
									'orillacart'
								) }
								checked={ form.remove_data_on_uninstall }
								onChange={ ( value ) =>
									update( 'remove_data_on_uninstall', value )
								}
							/>
						</More>
					</Section>
				</form>
			) }
			{ form !== null && settings !== null && (
				<SaveBar
					dirty={ dirty }
					busy={ status.kind === 'saving' }
					form="orillacart-settings"
					label={ __( 'Save settings', 'orillacart' ) }
				/>
			) }
			<SettingsFileSection
				onImported={ () => setImports( ( count ) => count + 1 ) }
			/>
		</Page>
	);
}
