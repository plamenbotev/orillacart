import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchPaymentMethods,
	type PaymentMethod,
	savePaymentMethod,
} from '../payments/api';
import {
	fetchSettings,
	saveSettings,
	type Settings,
	settingsInput,
} from '../settings/api';
import { fetchCarriers } from '../shipping/api';
import { toApiError } from '../shared/api';
import {
	type Country,
	fetchCountries,
	fetchStates,
	type State,
} from '../shared/geography';
import {
	addFlatRate,
	addSampleData,
	fetchSampleData,
	finishSetup,
	removeSampleData,
} from './api';

const STEPS = [
	__( 'Your shop', 'orillacart' ),
	__( 'Payments', 'orillacart' ),
	__( 'Shipping', 'orillacart' ),
	__( 'Products', 'orillacart' ),
	__( 'Done', 'orillacart' ),
];

/** The ways to pay the shop has without an account elsewhere. */
const OFFLINE = [ 'bank_transfer', 'cash_on_delivery', 'cheque' ];

/**
 * Shop → Setup: where the shop sells from and in what currency, how customers pay, how it ships, and its first
 * products, step by step. Each step saves as it goes; every choice can be changed later on its own screen.
 */
export function SetupScreen() {
	const [ step, setStep ] = useState( 0 );
	const [ error, setError ] = useState< string | null >( null );
	const fail = ( failure: unknown ) =>
		setError( toApiError( failure ).message );

	return (
		<>
			<h1>{ __( 'Set up the shop', 'orillacart' ) }</h1>
			<ol className="orillacart-setup__steps">
				{ STEPS.map( ( label, index ) => (
					<li
						key={ label }
						aria-current={ index === step ? 'step' : undefined }
					>
						{ label }
					</li>
				) ) }
			</ol>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ STEPS[ step ] }</h2>
				</CardHeader>
				<CardBody>
					{ step === 0 && (
						<ShopStep next={ () => setStep( 1 ) } fail={ fail } />
					) }
					{ step === 1 && (
						<PaymentsStep
							next={ () => setStep( 2 ) }
							fail={ fail }
						/>
					) }
					{ step === 2 && (
						<ShippingStep
							next={ () => setStep( 3 ) }
							fail={ fail }
						/>
					) }
					{ step === 3 && (
						<ProductsStep
							next={ () => setStep( 4 ) }
							fail={ fail }
						/>
					) }
					{ step === 4 && <DoneStep fail={ fail } /> }
				</CardBody>
			</Card>
			{ step > 0 && step < 4 && (
				<Button
					variant="tertiary"
					onClick={ () => setStep( step - 1 ) }
				>
					{ __( 'Back', 'orillacart' ) }
				</Button>
			) }
		</>
	);
}

type StepProps = { next: () => void; fail: ( failure: unknown ) => void };

function ShopStep( { next, fail }: StepProps ) {
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ currency, setCurrency ] = useState< string | null >( null );
	const [ countries, setCountries ] = useState< Country[] >( [] );
	const [ states, setStates ] = useState< State[] >( [] );

	useEffect( () => {
		fetchSettings()
			.then( ( loaded ) => {
				setSettings( loaded );
				setCurrency( loaded.currency );
			} )
			.catch( fail );
		fetchCountries().then( setCountries ).catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	useEffect( () => {
		if ( settings?.shop_country ) {
			fetchStates( settings.shop_country )
				.then( setStates )
				.catch( () => setStates( [] ) );
		}
	}, [ settings?.shop_country ] );

	if ( settings === null ) {
		return <Spinner />;
	}
	const change = ( changed: Partial< Settings > ) =>
		setSettings( { ...settings, ...changed } );

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				saveSettings( {
					...settingsInput( settings ),
					// Fewer decimals were the old currency's choice.
					fewer_decimals:
						settings.currency === currency
							? settings.fewer_decimals
							: null,
				} )
					.then( next )
					.catch( fail );
			} }
		>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Where the shop sells from', 'orillacart' ) }
				value={ settings.shop_country ?? '' }
				options={ [
					{
						value: '',
						label: __( 'Choose a country', 'orillacart' ),
					},
					...countries.map( ( country ) => ( {
						value: country.code,
						label: country.name,
					} ) ),
				] }
				onChange={ ( country ) =>
					change( {
						shop_country: country || null,
						shop_state: null,
					} )
				}
			/>
			{ states.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'State', 'orillacart' ) }
					value={ settings.shop_state ?? '' }
					options={ [
						{ value: '', label: '—' },
						...states.map( ( state ) => ( {
							value: state.code,
							label: state.name,
						} ) ),
					] }
					onChange={ ( state ) =>
						change( { shop_state: state || null } )
					}
				/>
			) }
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Currency', 'orillacart' ) }
				help={ __(
					'Its three-letter code: EUR, USD, GBP…',
					'orillacart'
				) }
				value={ settings.currency }
				maxLength={ 3 }
				onChange={ ( code ) =>
					change( { currency: code.toUpperCase() } )
				}
			/>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Weights in', 'orillacart' ) }
				value={ settings.weight_unit }
				options={ settings.weight_units.map( ( unit ) => ( {
					value: unit,
					label: unit,
				} ) ) }
				onChange={ ( unit ) => change( { weight_unit: unit } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Sizes in', 'orillacart' ) }
				value={ settings.dimension_unit }
				options={ settings.dimension_units.map( ( unit ) => ( {
					value: unit,
					label: unit,
				} ) ) }
				onChange={ ( unit ) => change( { dimension_unit: unit } ) }
			/>
			<Button variant="primary" type="submit">
				{ __( 'Save and go on', 'orillacart' ) }
			</Button>
		</form>
	);
}

function PaymentsStep( { next, fail }: StepProps ) {
	const [ methods, setMethods ] = useState< PaymentMethod[] | null >( null );

	useEffect( () => {
		fetchPaymentMethods().then( setMethods ).catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( methods === null ) {
		return <Spinner />;
	}
	const toggle = ( method: PaymentMethod, active: boolean ) =>
		savePaymentMethod( method.gateway, {
			name: method.name,
			active,
			position: method.position,
			countries: method.countries,
			instructions: method.instructions,
			receipt_note: method.receipt_note,
			settings: method.settings,
			fee: method.fee,
			fee_percent: method.fee_percent,
			fee_label: method.fee_label,
		} )
			.then( ( saved ) =>
				setMethods(
					methods.map( ( other ) =>
						other.gateway === saved.gateway ? saved : other
					)
				)
			)
			.catch( fail );

	return (
		<>
			<p>
				{ __(
					'How customers pay. Those that need an account elsewhere, such as PayPal, take its keys on the Payments screen.',
					'orillacart'
				) }
			</p>
			{ methods.map( ( method ) =>
				OFFLINE.includes( method.gateway ) ? (
					<CheckboxControl
						key={ method.gateway }
						label={ method.name }
						checked={ method.active }
						onChange={ ( active ) => void toggle( method, active ) }
					/>
				) : (
					<p key={ method.gateway }>
						{ method.name }:{ ' ' }
						<a href="admin.php?page=orillacart-payments">
							{ method.active
								? __(
										'on; change it on the Payments screen',
										'orillacart'
									)
								: __(
										'set it up on the Payments screen',
										'orillacart'
									) }
						</a>
					</p>
				)
			) }
			<Button variant="primary" onClick={ next }>
				{ __( 'Go on', 'orillacart' ) }
			</Button>
		</>
	);
}

function ShippingStep( { next, fail }: StepProps ) {
	const [ carriers, setCarriers ] = useState< number | null >( null );
	const [ country, setCountry ] = useState< string | null >( null );
	const [ name, setName ] = useState< string >(
		__( 'Standard shipping', 'orillacart' )
	);
	const [ price, setPrice ] = useState( '5' );

	useEffect( () => {
		fetchCarriers()
			.then( ( found ) => setCarriers( found.length ) )
			.catch( fail );
		fetchSettings()
			.then( ( settings ) => setCountry( settings.shop_country ) )
			.catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( carriers === null ) {
		return <Spinner />;
	}
	if ( carriers > 0 ) {
		return (
			<>
				<p>
					{ sprintf(
						/* translators: %d: how many carriers */
						__(
							'The shop ships with %d carriers. Change their rates on the Shipping screen.',
							'orillacart'
						),
						carriers
					) }
				</p>
				<Button variant="primary" onClick={ next }>
					{ __( 'Go on', 'orillacart' ) }
				</Button>
			</>
		);
	}

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				if ( country !== null ) {
					addFlatRate( name, price, country )
						.then( next )
						.catch( fail );
				}
			} }
		>
			<p>
				{ country === null
					? __(
							'Choose where the shop sells from first, to ship there.',
							'orillacart'
						)
					: __(
							'A rate to start with, to your own country; add more countries, weights and carriers on the Shipping screen.',
							'orillacart'
						) }
			</p>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'What checkout calls it', 'orillacart' ) }
				value={ name }
				onChange={ setName }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'What it costs', 'orillacart' ) }
				inputMode="decimal"
				value={ price }
				onChange={ setPrice }
			/>
			<Button
				variant="primary"
				type="submit"
				disabled={ country === null }
			>
				{ __( 'Add the rate and go on', 'orillacart' ) }
			</Button>{ ' ' }
			<Button variant="tertiary" onClick={ next }>
				{ __( 'Skip', 'orillacart' ) }
			</Button>
		</form>
	);
}

function ProductsStep( { next, fail }: StepProps ) {
	const [ present, setPresent ] = useState< boolean | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchSampleData()
			.then( ( found ) => setPresent( found.present ) )
			.catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( present === null ) {
		return <Spinner />;
	}

	return (
		<>
			<p>
				{ __(
					'Add your first product, import a file of them, or try the shop with eight sample products first: shirts and hoodies in sizes and colours, lamps and mugs. They go again with one click.',
					'orillacart'
				) }
			</p>
			<p>
				<a
					className="button"
					href="post-new.php?post_type=orillacart_product"
				>
					{ __( 'Add a product', 'orillacart' ) }
				</a>{ ' ' }
				<a className="button" href="admin.php?page=orillacart-exchange">
					{ __( 'Import products', 'orillacart' ) }
				</a>{ ' ' }
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => {
						setBusy( true );
						( present ? removeSampleData() : addSampleData() )
							.then( ( result ) => setPresent( result.present ) )
							.catch( fail )
							.finally( () => setBusy( false ) );
					} }
				>
					{ present
						? __( 'Remove the sample products', 'orillacart' )
						: __( 'Add sample products', 'orillacart' ) }
				</Button>
			</p>
			<Button variant="primary" onClick={ next }>
				{ __( 'Go on', 'orillacart' ) }
			</Button>
		</>
	);
}

function DoneStep( { fail }: Pick< StepProps, 'fail' > ) {
	useEffect( () => {
		finishSetup().catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<>
			<p>
				{ __(
					'The shop is set up. Orders come to the Orders screen, and the Status screen says how the shop stands.',
					'orillacart'
				) }
			</p>
			<p>
				<a
					className="button button-primary"
					href="admin.php?page=orillacart-orders"
				>
					{ __( 'Go to the orders', 'orillacart' ) }
				</a>{ ' ' }
				<a className="button" href="admin.php?page=orillacart-settings">
					{ __( 'More settings', 'orillacart' ) }
				</a>
			</p>
		</>
	);
}
