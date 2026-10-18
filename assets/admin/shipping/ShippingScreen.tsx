import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { toApiError } from '../shared/api';
import { SettingFields } from '../shared/SettingFields';
import { fetchGroups, type TaxGroup } from '../tax/api';
import {
	type Carrier,
	type CarrierInput,
	deleteCarrier,
	fetchCarriers,
	fetchShippingClasses,
	fetchShippingMethods,
	type Measure,
	MEASURES,
	type Range,
	saveCarrier,
	type ShippingClass,
	type ShippingMethod,
	type ShippingRateInput,
	WITHOUT_CLASS,
} from './api';
import { ShippingClassesCard } from './ShippingClassesCard';

type ListField =
	'regions' | 'postcodes' | 'excluded_regions' | 'excluded_postcodes';

type Message = { status: 'success' | 'error'; text: string } | null;

const EMPTY_RATE: ShippingRateInput = {
	name: '',
	price: '0',
	per_item: false,
	regions: [],
	weight: null,
	volume: null,
	length: null,
	width: null,
	height: null,
	subtotal: null,
	taxable: true,
	tax_group_id: null,
	shipping_classes: [],
	postcodes: [],
	excluded_regions: [],
	excluded_postcodes: [],
};

const MEASURE_LABELS: Record< Measure, string > = {
	weight: __( 'Weight', 'orillacart' ),
	volume: __( 'Volume', 'orillacart' ),
	length: __( 'Length', 'orillacart' ),
	width: __( 'Width', 'orillacart' ),
	height: __( 'Height', 'orillacart' ),
	subtotal: __( 'Order value', 'orillacart' ),
};

/**
 * The Shop → Shipping screen: carriers and their rates. Checkout offers the rates of active carriers whose
 * regions and limits take the order.
 */
export function ShippingScreen() {
	const [ carriers, setCarriers ] = useState< Carrier[] | null >( null );
	const [ groups, setGroups ] = useState< TaxGroup[] >( [] );
	const [ classes, setClasses ] = useState< ShippingClass[] >( [] );
	const [ methods, setMethods ] = useState< ShippingMethod[] >( [] );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );

	useEffect( () => {
		Promise.all( [
			fetchCarriers(),
			fetchGroups(),
			fetchShippingClasses(),
			fetchShippingMethods(),
		] )
			.then(
				( [ loaded, taxGroups, shippingClasses, shippingMethods ] ) => {
					setCarriers( loaded );
					setGroups( taxGroups );
					setClasses( shippingClasses );
					setMethods( shippingMethods );
				}
			)
			.catch( fail );
	}, [] );

	const notice = message !== null && (
		<Notice status={ message.status } onRemove={ () => setMessage( null ) }>
			{ message.text }
		</Notice>
	);

	if ( carriers === null ) {
		return (
			<>
				<h1>{ __( 'Shipping', 'orillacart' ) }</h1>
				{ message !== null ? notice : <Spinner /> }
			</>
		);
	}

	const replace = ( saved: Carrier ) =>
		setCarriers(
			[
				...carriers.filter( ( current ) => current.id !== saved.id ),
				saved,
			].sort(
				( a, b ) =>
					a.position - b.position || a.name.localeCompare( b.name )
			)
		);

	return (
		<>
			<h1>{ __( 'Shipping', 'orillacart' ) }</h1>
			<p>
				{ __(
					'Checkout offers every rate whose regions include the address and whose limits the order fits, carriers in their order. Limits include both ends; leave the highest value empty for no end. Weight, volume and sizes add up over every item that ships.',
					'orillacart'
				) }
			</p>
			{ notice }
			{ carriers.map( ( carrier ) => (
				<CarrierCard
					key={ carrier.id }
					carrier={ carrier }
					groups={ groups }
					classes={ classes }
					methods={ methods }
					onSaved={ ( saved ) => {
						replace( saved );
						setMessage( {
							status: 'success',
							text: __( 'Carrier saved.', 'orillacart' ),
						} );
					} }
					onDeleted={ () =>
						setCarriers(
							carriers.filter(
								( current ) => current.id !== carrier.id
							)
						)
					}
					onError={ fail }
				/>
			) ) }
			<CarrierCard
				key={ `new-${ carriers.length }` }
				carrier={ null }
				groups={ groups }
				classes={ classes }
				methods={ methods }
				onSaved={ ( saved ) => {
					replace( saved );
					setMessage( {
						status: 'success',
						text: __( 'Carrier added.', 'orillacart' ),
					} );
				} }
				onDeleted={ () => undefined }
				onError={ fail }
			/>
			<ShippingClassesCard
				classes={ classes }
				onChange={ setClasses }
				onError={ fail }
			/>
		</>
	);
}

interface CarrierCardProps {
	/** Null for the form that adds a carrier. */
	carrier: Carrier | null;
	groups: TaxGroup[];
	classes: ShippingClass[];
	/** The shipping methods add-ons bring, which can price a carrier instead of its rates. */
	methods: ShippingMethod[];
	onSaved: ( carrier: Carrier ) => void;
	onDeleted: () => void;
	onError: ( error: unknown ) => void;
}

function CarrierCard( {
	carrier,
	groups,
	classes,
	methods,
	onSaved,
	onDeleted,
	onError,
}: CarrierCardProps ) {
	const [ draft, setDraft ] = useState< CarrierInput >(
		carrier ?? {
			name: '',
			active: true,
			position: 0,
			rates: [],
			method: null,
			method_settings: {},
			method_taxable: true,
			method_tax_group_id: null,
		}
	);
	const method = methods.find( ( found ) => found.id === draft.method );
	const [ confirming, setConfirming ] = useState( false );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		// Only the fields the API takes: a loaded carrier also has its id.
		saveCarrier( carrier?.id ?? null, {
			name: draft.name,
			active: draft.active,
			position: draft.position,
			rates: draft.method === null ? draft.rates : [],
			method: draft.method,
			method_settings: draft.method === null ? {} : draft.method_settings,
			method_taxable: draft.method_taxable,
			method_tax_group_id: draft.method_tax_group_id,
		} )
			.then( ( saved ) => {
				setDraft( saved );
				onSaved( saved );
			} )
			.catch( onError );
	};
	const setRate = ( index: number, rate: ShippingRateInput ) =>
		setDraft( {
			...draft,
			rates: draft.rates.map( ( current, i ) =>
				i === index ? rate : current
			),
		} );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>
					{ carrier?.name ?? __( 'Add a carrier', 'orillacart' ) }
				</h2>
				{ carrier !== null &&
					( confirming ? (
						<Flex justify="flex-end" expanded={ false }>
							<Button
								variant="primary"
								isDestructive
								onClick={ () =>
									deleteCarrier( carrier.id )
										.then( onDeleted )
										.catch( onError )
								}
							>
								{ __(
									'Delete the carrier and its rates',
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
							{ __( 'Delete carrier', 'orillacart' ) }
						</Button>
					) ) }
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Name', 'orillacart' ) }
								value={ draft.name }
								onChange={ ( name ) =>
									setDraft( { ...draft, name } )
								}
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								label={ __( 'Order', 'orillacart' ) }
								value={ String( draft.position ) }
								onChange={ ( value ) =>
									setDraft( {
										...draft,
										position: Math.max(
											0,
											Number.parseInt( value, 10 ) || 0
										),
									} )
								}
							/>
						</FlexItem>
					</Flex>
					<CheckboxControl
						label={ __( 'Active', 'orillacart' ) }
						checked={ draft.active }
						onChange={ ( active ) =>
							setDraft( { ...draft, active } )
						}
					/>
					{ ( methods.length > 0 || draft.method !== null ) && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Prices from', 'orillacart' ) }
							value={ draft.method ?? '' }
							options={ [
								{
									value: '',
									label: __( 'Its own rates', 'orillacart' ),
								},
								...methods.map( ( found ) => ( {
									value: found.id,
									label: found.name,
								} ) ),
							] }
							onChange={ ( value ) =>
								setDraft( {
									...draft,
									method: value === '' ? null : value,
									method_settings: {},
								} )
							}
						/>
					) }
					{ draft.method !== null && (
						<>
							{ method === undefined && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'The add-on that brings this shipping method is not active, so checkout leaves the carrier out.',
										'orillacart'
									) }
								</Notice>
							) }
							<SettingFields
								fields={ method?.fields ?? [] }
								values={ draft.method_settings }
								secretsSet={ carrier?.secrets_set ?? [] }
								onChange={ ( key, value ) =>
									setDraft( {
										...draft,
										method_settings: {
											...draft.method_settings,
											[ key ]: value,
										},
									} )
								}
							/>
							<Flex align="flex-end">
								<FlexItem>
									<CheckboxControl
										label={ __( 'Taxed', 'orillacart' ) }
										checked={ draft.method_taxable }
										onChange={ ( taxable ) =>
											setDraft( {
												...draft,
												method_taxable: taxable,
											} )
										}
									/>
								</FlexItem>
								<FlexItem isBlock>
									<SelectControl
										__next40pxDefaultSize
										label={ __(
											'Tax group',
											'orillacart'
										) }
										disabled={ ! draft.method_taxable }
										value={ String(
											draft.method_tax_group_id ?? ''
										) }
										options={ [
											{
												value: '',
												label: __(
													'The shop’s default',
													'orillacart'
												),
											},
											...groups.map( ( group ) => ( {
												value: String( group.id ),
												label: group.name,
											} ) ),
										] }
										onChange={ ( value ) =>
											setDraft( {
												...draft,
												method_tax_group_id:
													value === ''
														? null
														: Number.parseInt(
																value,
																10
															),
											} )
										}
									/>
								</FlexItem>
							</Flex>
						</>
					) }
					{ draft.method === null &&
						draft.rates.map( ( rate, index ) => (
							<RateFields
								key={ rate.id ?? `new-${ index }` }
								rate={ rate }
								groups={ groups }
								classes={ classes }
								onChange={ ( changed ) =>
									setRate( index, changed )
								}
								onRemove={ () =>
									setDraft( {
										...draft,
										rates: draft.rates.filter(
											( _, i ) => i !== index
										),
									} )
								}
							/>
						) ) }
					<Flex justify="flex-start">
						{ draft.method === null && (
							<Button
								variant="secondary"
								onClick={ () =>
									setDraft( {
										...draft,
										rates: [ ...draft.rates, EMPTY_RATE ],
									} )
								}
							>
								{ __( 'Add a rate', 'orillacart' ) }
							</Button>
						) }
						<Button
							variant="primary"
							type="submit"
							disabled={ draft.name.trim() === '' }
						>
							{ carrier === null
								? __( 'Add carrier', 'orillacart' )
								: sprintf(
										/* translators: %s: carrier name */
										__( 'Save %s', 'orillacart' ),
										carrier.name
									) }
						</Button>
					</Flex>
				</form>
			</CardBody>
		</Card>
	);
}

interface RateFieldsProps {
	rate: ShippingRateInput;
	groups: TaxGroup[];
	classes: ShippingClass[];
	onChange: ( rate: ShippingRateInput ) => void;
	onRemove: () => void;
}

function RateFields( {
	rate,
	groups,
	classes,
	onChange,
	onRemove,
}: RateFieldsProps ) {
	const carries = ( id: number, carried: boolean ) =>
		onChange( {
			...rate,
			shipping_classes: carried
				? [ ...rate.shipping_classes, id ]
				: rate.shipping_classes.filter( ( current ) => current !== id ),
		} );
	// What the admin typed, kept as typed; the rate gets the parsed list.
	const [ typed, setTyped ] = useState< Record< ListField, string > >( {
		regions: rate.regions.join( ', ' ),
		postcodes: rate.postcodes.join( ', ' ),
		excluded_regions: rate.excluded_regions.join( ', ' ),
		excluded_postcodes: rate.excluded_postcodes.join( ', ' ),
	} );
	const listField = ( field: ListField, label: string, help: string ) => (
		<TextControl
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ typed[ field ] }
			onChange={ ( value ) => {
				setTyped( { ...typed, [ field ]: value } );
				onChange( {
					...rate,
					[ field ]: value
						.split( ',' )
						.map( ( item ) => item.trim() )
						.map( ( item ) =>
							field.endsWith( 'regions' )
								? item.toUpperCase()
								: item
						)
						.filter( ( item ) => item !== '' ),
				} );
			} }
		/>
	);
	const setRange = ( measure: Measure, range: Range ) =>
		onChange( {
			...rate,
			[ measure ]: range.min === '0' && range.max === null ? null : range,
		} );

	return (
		<fieldset className="orillacart-rate">
			<legend>
				{ rate.name === ''
					? __( 'New rate', 'orillacart' )
					: rate.name }
			</legend>
			<Flex align="flex-end">
				<FlexItem isBlock>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Rate name', 'orillacart' ) }
						value={ rate.name }
						onChange={ ( name ) => onChange( { ...rate, name } ) }
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Price', 'orillacart' ) }
						value={ rate.price }
						onChange={ ( price ) => onChange( { ...rate, price } ) }
					/>
				</FlexItem>
			</Flex>
			<CheckboxControl
				label={ __( 'Price is for each item shipped', 'orillacart' ) }
				checked={ rate.per_item }
				onChange={ ( perItem ) =>
					onChange( { ...rate, per_item: perItem } )
				}
			/>
			{ listField(
				'regions',
				__( 'Ships to', 'orillacart' ),
				__(
					'Country codes such as BG, or states such as US-CA, separated by commas. Empty for everywhere.',
					'orillacart'
				)
			) }
			{ listField(
				'excluded_regions',
				__( 'Except to', 'orillacart' ),
				__(
					'Countries or states it does not ship to, such as US-HI when it ships to US.',
					'orillacart'
				)
			) }
			{ listField(
				'postcodes',
				__( 'Postcodes', 'orillacart' ),
				__(
					'Postcodes such as 1000, starts such as SW1*, or ranges such as 1000–1999, separated by commas. Empty for any postcode in the regions.',
					'orillacart'
				)
			) }
			{ listField(
				'excluded_postcodes',
				__( 'Except postcodes', 'orillacart' ),
				__(
					'Postcodes, starts or ranges it does not ship to.',
					'orillacart'
				)
			) }
			{ classes.length > 0 && (
				<fieldset className="orillacart-rate-classes">
					<legend>{ __( 'Carries', 'orillacart' ) }</legend>
					<CheckboxControl
						label={ __( 'Every item', 'orillacart' ) }
						checked={ rate.shipping_classes.length === 0 }
						onChange={ ( every ) =>
							onChange( {
								...rate,
								shipping_classes: every
									? []
									: [ WITHOUT_CLASS ],
							} )
						}
					/>
					{ rate.shipping_classes.length > 0 &&
						[
							{
								id: WITHOUT_CLASS,
								name: __(
									'Items without a class',
									'orillacart'
								),
							},
							...classes,
						].map( ( shippingClass ) => (
							<CheckboxControl
								key={ shippingClass.id }
								label={ shippingClass.name }
								checked={ rate.shipping_classes.includes(
									shippingClass.id
								) }
								onChange={ ( carried ) =>
									carries( shippingClass.id, carried )
								}
							/>
						) ) }
					<p className="description">
						{ __(
							'An order gets this rate only when the rate carries every item in it.',
							'orillacart'
						) }
					</p>
				</fieldset>
			) }
			<table className="orillacart-limits">
				<thead>
					<tr>
						<th>{ __( 'Limit', 'orillacart' ) }</th>
						<th>{ __( 'From', 'orillacart' ) }</th>
						<th>{ __( 'To', 'orillacart' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ MEASURES.map( ( measure ) => {
						const range = rate[ measure ] ?? {
							min: '0',
							max: null,
						};
						return (
							<tr key={ measure }>
								<th scope="row">
									{ MEASURE_LABELS[ measure ] }
								</th>
								<td>
									<TextControl
										__next40pxDefaultSize
										hideLabelFromVision
										label={ sprintf(
											/* translators: %s: weight, volume, length... */
											__( '%s from', 'orillacart' ),
											MEASURE_LABELS[ measure ]
										) }
										value={ range.min }
										onChange={ ( min ) =>
											setRange( measure, {
												...range,
												min: min === '' ? '0' : min,
											} )
										}
									/>
								</td>
								<td>
									<TextControl
										__next40pxDefaultSize
										hideLabelFromVision
										label={ sprintf(
											/* translators: %s: weight, volume, length... */
											__( '%s to', 'orillacart' ),
											MEASURE_LABELS[ measure ]
										) }
										value={ range.max ?? '' }
										onChange={ ( max ) =>
											setRange( measure, {
												...range,
												max: max === '' ? null : max,
											} )
										}
									/>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			<Flex align="flex-end">
				<FlexItem>
					<CheckboxControl
						label={ __( 'Taxed', 'orillacart' ) }
						checked={ rate.taxable }
						onChange={ ( taxable ) =>
							onChange( { ...rate, taxable } )
						}
					/>
				</FlexItem>
				<FlexItem isBlock>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Tax group', 'orillacart' ) }
						disabled={ ! rate.taxable }
						value={ String( rate.tax_group_id ?? '' ) }
						options={ [
							{
								value: '',
								label: __( 'The shop’s default', 'orillacart' ),
							},
							...groups.map( ( group ) => ( {
								value: String( group.id ),
								label: group.name,
							} ) ),
						] }
						onChange={ ( value ) =>
							onChange( {
								...rate,
								tax_group_id:
									value === ''
										? null
										: Number.parseInt( value, 10 ),
							} )
						}
					/>
				</FlexItem>
				<FlexItem>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ onRemove }
					>
						{ __( 'Remove rate', 'orillacart' ) }
					</Button>
				</FlexItem>
			</Flex>
		</fieldset>
	);
}
