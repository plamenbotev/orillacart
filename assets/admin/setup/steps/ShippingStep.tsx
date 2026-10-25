import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { SettingFields, type SettingValues } from '../../shared/SettingFields';
import { errorMessage } from '../../shared/api';
import {
	fetchShippingMethods,
	saveCarrier,
	type ShippingMethod,
} from '../../shipping/api';
import {
	fetchShippingZones,
	type SettingsTest,
	type ShippingZone,
	testShippingSettings,
} from '../api';
import { answered, text, type StepProps } from './types';

interface ZonePrices {
	zone: string;
	prices: 'flat' | 'free_above' | 'weight' | 'none';
	price: string;
	free_above: string;
	weights: { up_to: string; price: string }[];
}

const ZONE_NAMES: Record< ShippingZone[ 'id' ], string > = {
	home: __( 'Your country', 'orillacart' ),
	eu: __( 'The rest of the EU', 'orillacart' ),
	europe: __( 'The rest of Europe', 'orillacart' ),
	world: __( 'The rest of the world', 'orillacart' ),
	listed: __( 'The other countries', 'orillacart' ),
};

/**
 * Shipping: one way to ship priced for each zone the shop sells to, a place customers collect from, and the
 * couriers' own prices that add-ons bring, set up on their carriers with their keys.
 * @param root0
 * @param root0.answers
 * @param root0.profile
 * @param root0.save
 * @param root0.fail
 */
export function ShippingStep( { answers, profile, save, fail }: StepProps ) {
	const given = answered( answers, 'shipping' );
	const [ zones, setZones ] = useState< ShippingZone[] | null >( null );
	const [ name, setName ] = useState(
		text( given, 'name', __( 'Standard shipping', 'orillacart' ) )
	);
	const [ prices, setPrices ] = useState< ZonePrices[] >( [] );
	const savedPickup =
		typeof given.pickup === 'object' && given.pickup !== null
			? ( given.pickup as Record< string, string > )
			: null;
	const business = answered( answers, 'business' );
	const [ pickup, setPickup ] = useState( savedPickup !== null );
	const [ place, setPlace ] = useState( {
		name: savedPickup?.name ?? __( 'Our shop', 'orillacart' ),
		address:
			savedPickup?.address ??
			[
				text( business, 'street' ),
				[ text( business, 'postcode' ), text( business, 'city' ) ]
					.filter( ( part ) => part !== '' )
					.join( ' ' ),
			]
				.filter( ( part ) => part !== '' )
				.join( ', ' ),
		hours: savedPickup?.hours ?? '',
		fee: savedPickup?.fee ?? '0',
	} );
	const [ methods, setMethods ] = useState< ShippingMethod[] >( [] );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchShippingZones()
			.then( ( found ) => {
				setZones( found );
				const before = Array.isArray( given.zones )
					? ( given.zones as ZonePrices[] )
					: [];
				setPrices(
					found.map(
						( zone ) =>
							before.find(
								( saved ) => saved.zone === zone.id
							) ?? {
								zone: zone.id,
								prices:
									zone.id === 'home' ? 'free_above' : 'flat',
								price: zone.id === 'home' ? '4.90' : '12.90',
								free_above: zone.id === 'home' ? '50' : '',
								weights: [ { up_to: '2', price: '4.90' } ],
							}
					)
				);
			} )
			.catch( fail );
		fetchShippingMethods().then( setMethods ).catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( zones === null ) {
		return <Spinner />;
	}
	const change = ( zone: string, changed: Partial< ZonePrices > ) =>
		setPrices( ( current ) =>
			current.map( ( row ) =>
				row.zone === zone ? { ...row, ...changed } : row
			)
		);
	const unit = profile?.weight_unit ?? 'kg';

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( {
					name,
					zones: prices.map( ( row ) => ( {
						zone: row.zone,
						prices: row.prices,
						price: row.price || '0',
						free_above:
							row.prices === 'free_above'
								? row.free_above || '0'
								: null,
						weights: row.prices === 'weight' ? row.weights : [],
					} ) ),
					pickup: pickup ? place : null,
				} )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'What checkout calls it', 'orillacart' ) }
				value={ name }
				onChange={ setName }
				required
			/>
			{ prices.map( ( row ) => (
				<fieldset key={ row.zone } className="orillacart-setup__zone">
					<legend>
						{ ZONE_NAMES[ row.zone as ShippingZone[ 'id' ] ] }
					</legend>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Price', 'orillacart' ) }
						value={ row.prices }
						options={ [
							{
								value: 'flat',
								label: __( 'One price', 'orillacart' ),
							},
							{
								value: 'free_above',
								label: __(
									'A price, free from an amount',
									'orillacart'
								),
							},
							{
								value: 'weight',
								label: __( 'By weight', 'orillacart' ),
							},
							{
								value: 'none',
								label: __( 'No shipping there', 'orillacart' ),
							},
						] }
						onChange={ ( value ) =>
							change( row.zone, {
								prices: value as ZonePrices[ 'prices' ],
							} )
						}
					/>
					{ ( row.prices === 'flat' ||
						row.prices === 'free_above' ) && (
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Costs', 'orillacart' ) }
							inputMode="decimal"
							value={ row.price }
							onChange={ ( value ) =>
								change( row.zone, { price: value } )
							}
						/>
					) }
					{ row.prices === 'free_above' && (
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Free for orders from', 'orillacart' ) }
							inputMode="decimal"
							value={ row.free_above }
							onChange={ ( value ) =>
								change( row.zone, { free_above: value } )
							}
						/>
					) }
					{ row.prices === 'weight' && (
						<>
							{ row.weights.map( ( band, index ) => (
								<div
									key={ index }
									className="orillacart-setup__row"
								>
									<TextControl
										__next40pxDefaultSize
										label={ `${ __(
											'Up to',
											'orillacart'
										) } (${ unit })` }
										inputMode="decimal"
										value={ band.up_to }
										onChange={ ( value ) =>
											change( row.zone, {
												weights: row.weights.map(
													( other, at ) =>
														at === index
															? {
																	...other,
																	up_to: value,
																}
															: other
												),
											} )
										}
									/>
									<TextControl
										__next40pxDefaultSize
										label={ __( 'Costs', 'orillacart' ) }
										inputMode="decimal"
										value={ band.price }
										onChange={ ( value ) =>
											change( row.zone, {
												weights: row.weights.map(
													( other, at ) =>
														at === index
															? {
																	...other,
																	price: value,
																}
															: other
												),
											} )
										}
									/>
								</div>
							) ) }
							<Button
								variant="secondary"
								onClick={ () =>
									change( row.zone, {
										weights: [
											...row.weights,
											{ up_to: '', price: '' },
										],
									} )
								}
							>
								{ __( 'Add a weight', 'orillacart' ) }
							</Button>
						</>
					) }
				</fieldset>
			) ) }
			<CheckboxControl
				label={ __(
					'Customers can also collect their orders',
					'orillacart'
				) }
				checked={ pickup }
				onChange={ setPickup }
			/>
			{ pickup && (
				<fieldset className="orillacart-setup__zone">
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Place', 'orillacart' ) }
						value={ place.name }
						onChange={ ( value ) =>
							setPlace( { ...place, name: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Address', 'orillacart' ) }
						value={ place.address }
						onChange={ ( value ) =>
							setPlace( { ...place, address: value } )
						}
						required
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Opening hours', 'orillacart' ) }
						value={ place.hours }
						onChange={ ( value ) =>
							setPlace( { ...place, hours: value } )
						}
					/>
				</fieldset>
			) }
			{ methods.length > 0 && (
				<>
					<h3>{ __( 'Couriers from add-ons', 'orillacart' ) }</h3>
					{ methods.map( ( method ) => (
						<CourierSetup key={ method.id } method={ method } />
					) ) }
				</>
			) }
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Save and go on', 'orillacart' ) }
			</Button>
		</form>
	);
}

/**
 * A carrier priced by a courier's add-on: its settings, tried at the courier, saved on the carrier at once, with
 * its keys, never in the setup's answers.
 * @param root0
 * @param root0.method
 */
function CourierSetup( { method }: { method: ShippingMethod } ) {
	const [ values, setValues ] = useState< SettingValues >( {} );
	const [ test, setTest ] = useState< SettingsTest | null >( null );
	const [ status, setStatus ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	return (
		<fieldset className="orillacart-setup__zone">
			<legend>{ method.name }</legend>
			<SettingFields
				fields={ method.fields }
				values={ values }
				secretsSet={ [] }
				onChange={ ( key, value ) =>
					setValues( ( current ) => ( {
						...current,
						[ key ]: value,
					} ) )
				}
			/>
			{ test !== null && (
				<Notice
					status={ test.works ? 'success' : 'error' }
					isDismissible={ false }
				>
					{ test.works
						? __( 'The courier took the settings.', 'orillacart' )
						: test.problem }
				</Notice>
			) }
			{ status !== null && (
				<Notice status="info" isDismissible={ false }>
					{ status }
				</Notice>
			) }
			{ method.testable && (
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => {
						setBusy( true );
						testShippingSettings( method.id, values, null )
							.then( setTest )
							.catch( ( error: unknown ) =>
								setTest( {
									works: false,
									problem: errorMessage( error ),
								} )
							)
							.finally( () => setBusy( false ) );
					} }
				>
					{ __( 'Test', 'orillacart' ) }
				</Button>
			) }{ ' ' }
			<Button
				variant="secondary"
				disabled={ busy }
				onClick={ () => {
					setBusy( true );
					saveCarrier( null, {
						name: method.name,
						active: true,
						position: 0,
						rates: [],
						method: method.id,
						method_settings: values,
						method_taxable: true,
						method_tax_group_id: null,
						pickup_locations: [],
						delivery_schedule: null,
						classes_kept_to_their_rates: false,
					} )
						.then( () =>
							setStatus(
								__(
									'Saved as a carrier: checkout offers its prices.',
									'orillacart'
								)
							)
						)
						.catch( ( error: unknown ) =>
							setStatus( errorMessage( error ) )
						)
						.finally( () => setBusy( false ) );
				} }
			>
				{ __( 'Ship with it', 'orillacart' ) }
			</Button>
		</fieldset>
	);
}
