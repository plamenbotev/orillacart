import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	FormTokenField,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { toApiError, violationOf, type Violation } from '../shared/api';
import { fetchCountries, type Country } from '../shared/geography';
import { LocationFields } from '../shared/LocationFields';
import { DownloadDeliveryCard } from './DownloadDeliveryCard';
import { FiltersCard } from './FiltersCard';
import { InvoicesCard } from './InvoicesCard';
import { SearchEngineCard } from './SearchEngineCard';
import { ShopPagesCard } from './ShopPagesCard';
import {
	fetchSettings,
	saveSettings,
	type Settings,
	type SettingsInput,
} from './api';

type Status =
	| { kind: 'idle' }
	| { kind: 'saving' }
	| { kind: 'saved' }
	| { kind: 'error'; message: string };

function inputOf( settings: Settings ): SettingsInput {
	return {
		currency: settings.currency,
		price_decimals: settings.price_decimals,
		weight_unit: settings.weight_unit,
		dimension_unit: settings.dimension_unit,
		shop_country: settings.shop_country,
		shop_state: settings.shop_state,
		shop_postcode: settings.shop_postcode,
		currency_sign: settings.currency_sign,
		currency_position: settings.currency_position,
		catalog_only: settings.catalog_only,
		hide_prices: settings.hide_prices,
		sell_to: settings.sell_to,
	};
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
	const [ violations, setViolations ] = useState< Violation[] >( [] );
	const [ status, setStatus ] = useState< Status >( { kind: 'idle' } );

	useEffect( () => {
		Promise.all( [ fetchSettings(), fetchCountries() ] )
			.then( ( [ loaded, list ] ) => {
				setSettings( loaded );
				setCountries( list );
				setForm( inputOf( loaded ) );
			} )
			.catch( ( error: unknown ) =>
				setStatus( {
					kind: 'error',
					message: toApiError( error ).message,
				} )
			);
	}, [] );

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
			.then( ( saved ) => {
				setSettings( saved );
				setForm( inputOf( saved ) );
				setStatus( { kind: 'saved' } );
			} )
			.catch( ( error: unknown ) => {
				const apiError = toApiError( error );
				setViolations( apiError.data?.violations ?? [] );
				setStatus( { kind: 'error', message: apiError.message } );
			} );
	};

	return (
		<>
			<h1>{ __( 'Shop settings', 'orillacart' ) }</h1>
			{ status.kind === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ status.message }
				</Notice>
			) }
			{ status.kind === 'saved' && (
				<Notice status="success" isDismissible={ false }>
					{ __( 'Settings saved.', 'orillacart' ) }
				</Notice>
			) }
			{ settings === null || form === null ? (
				status.kind !== 'error' && <Spinner />
			) : (
				<Card className="orillacart-settings-card">
					<CardBody>
						<form onSubmit={ submit }>
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
									update( 'currency', value.toUpperCase() )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Currency sign', 'orillacart' ) }
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
											POSITIONS[ position ] ?? position,
										value: position,
									} )
								) }
								onChange={ ( value ) =>
									update( 'currency_position', value )
								}
							/>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								max={ 4 }
								label={ __( 'Price decimals', 'orillacart' ) }
								help={ fieldError( 'price_decimals' ) }
								value={ String( form.price_decimals ) }
								onChange={ ( value ) =>
									update(
										'price_decimals',
										Number.parseInt( value, 10 ) || 0
									)
								}
							/>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Weight unit', 'orillacart' ) }
								help={ fieldError( 'weight_unit' ) }
								value={ form.weight_unit }
								options={ settings.weight_units.map(
									( unit ) => ( { label: unit, value: unit } )
								) }
								onChange={ ( value ) =>
									update( 'weight_unit', value )
								}
							/>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Dimension unit', 'orillacart' ) }
								help={ fieldError( 'dimension_unit' ) }
								value={ form.dimension_unit }
								options={ settings.dimension_units.map(
									( unit ) => ( { label: unit, value: unit } )
								) }
								onChange={ ( value ) =>
									update( 'dimension_unit', value )
								}
							/>
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
												shop_country: location.country,
												shop_state: location.state,
											}
									)
								}
								countryLabel={ __(
									'Shop country',
									'orillacart'
								) }
								stateLabel={ __( 'Shop state', 'orillacart' ) }
								anyState={ __( '— Not set —', 'orillacart' ) }
								optionalCountry
								countryError={ fieldError( 'shop_country' ) }
								stateError={ fieldError( 'shop_state' ) }
							/>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Shop postcode', 'orillacart' ) }
								help={ fieldError( 'shop_postcode' ) }
								value={ form.shop_postcode ?? '' }
								onChange={ ( value ) =>
									update(
										'shop_postcode',
										value.trim() === '' ? null : value
									)
								}
							/>
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
							<Button
								variant="primary"
								type="submit"
								isBusy={ status.kind === 'saving' }
								disabled={ status.kind === 'saving' }
							>
								{ __( 'Save settings', 'orillacart' ) }
							</Button>
						</form>
					</CardBody>
				</Card>
			) }
			<ShopPagesCard />
			<InvoicesCard />
			<DownloadDeliveryCard />
			<FiltersCard />
			<SearchEngineCard />
		</>
	);
}
