import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';

import { errorMessage } from '../shared/api';
import {
	type Answers,
	type CountryProfile,
	fetchAnswers,
	fetchCountryProfile,
	saveAnswers,
} from './api';
import { BusinessStep } from './steps/BusinessStep';
import { EmailsStep } from './steps/EmailsStep';
import { LegalStep } from './steps/LegalStep';
import { OpenStep } from './steps/OpenStep';
import { PaymentsStep } from './steps/PaymentsStep';
import { ProductsStep } from './steps/ProductsStep';
import { ReviewStep } from './steps/ReviewStep';
import { SellingStep } from './steps/SellingStep';
import { ShippingStep } from './steps/ShippingStep';
import { TaxStep } from './steps/TaxStep';
import type { StepProps } from './steps/types';

/** The steps in order; key is the step whose answers the server keeps, or null for one that keeps none. */
const STEPS: {
	key: string;
	label: string;
	Step: ( props: StepProps ) => ReactElement;
}[] = [
	{
		key: 'business',
		label: __( 'Your business', 'orillacart' ),
		Step: BusinessStep,
	},
	{
		key: 'selling',
		label: __( 'Customers', 'orillacart' ),
		Step: SellingStep,
	},
	{ key: 'tax', label: __( 'Tax', 'orillacart' ), Step: TaxStep },
	{
		key: 'shipping',
		label: __( 'Shipping', 'orillacart' ),
		Step: ShippingStep,
	},
	{
		key: 'payments',
		label: __( 'Payments', 'orillacart' ),
		Step: PaymentsStep,
	},
	{ key: 'legal', label: __( 'Legal', 'orillacart' ), Step: LegalStep },
	{ key: 'emails', label: __( 'Emails', 'orillacart' ), Step: EmailsStep },
	{
		key: 'products',
		label: __( 'Products', 'orillacart' ),
		Step: ProductsStep,
	},
	{ key: 'review', label: __( 'Review', 'orillacart' ), Step: ReviewStep },
	{ key: 'open', label: __( 'Open', 'orillacart' ), Step: OpenStep },
];

/** The steps whose answers the server keeps. */
const ASKED = [
	'business',
	'selling',
	'tax',
	'shipping',
	'payments',
	'legal',
	'emails',
];

/**
 * Shop → Setup: the business, who and where it sells to, tax, shipping, payments, the law, emails and products,
 * asked as questions. Answers are kept as they are given, so the setup can be left and taken up again; nothing
 * in the shop changes until the review applies them, after saying what it would do. Then the check before
 * opening, and opening the shop.
 */
export function SetupScreen() {
	const [ answers, setAnswers ] = useState< Answers | null >( null );
	const [ profile, setProfile ] = useState< CountryProfile | null >( null );
	const [ step, setStep ] = useState( 0 );
	const [ error, setError ] = useState< string | null >( null );
	const fail = ( failure: unknown ) => {
		setError( errorMessage( failure ) );
		// The message is above the step: the owner sees why it did not go on.
		window.scrollTo( { top: 0 } );
	};

	useEffect( () => {
		fetchAnswers()
			.then( ( found ) => {
				setAnswers( found );
				// Take up the setup where it was left: the first step not answered yet.
				const first = ASKED.findIndex( ( key ) => ! found[ key ] );
				setStep( first === -1 ? STEPS.length - 2 : first );
			} )
			.catch( fail );
	}, [] );
	const country = answers?.business?.country;
	useEffect( () => {
		if ( typeof country === 'string' && country !== '' ) {
			fetchCountryProfile( country ).then( setProfile ).catch( fail );
		}
	}, [ country ] );

	const waiting =
		typeof country === 'string' &&
		country !== '' &&
		profile?.country !== country;
	if ( answers === null || waiting ) {
		return error === null ? (
			<Spinner />
		) : (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	const current = STEPS[ step ];
	if ( current === undefined ) {
		return null;
	}
	const next = () => {
		setError( null );
		setStep( ( at ) => Math.min( at + 1, STEPS.length - 1 ) );
		window.scrollTo( { top: 0 } );
	};
	const save = ( given: Record< string, unknown > ) =>
		saveAnswers( current.key, given ).then( ( saved ) => {
			setAnswers( saved );
			next();
		} );

	return (
		<>
			<h1>{ __( 'Set up the shop', 'orillacart' ) }</h1>
			<nav aria-label={ __( 'Setup steps', 'orillacart' ) }>
				<ol className="orillacart-setup__steps">
					{ STEPS.map( ( { key, label }, index ) => (
						<li key={ key }>
							<Button
								variant="link"
								aria-current={
									index === step ? 'step' : undefined
								}
								onClick={ () => {
									setError( null );
									setStep( index );
								} }
							>
								{ label }
								{ ASKED.includes( key ) &&
									!! answers[ key ] && (
										<span className="screen-reader-text">
											{ __( '(answered)', 'orillacart' ) }
										</span>
									) }
							</Button>
						</li>
					) ) }
				</ol>
			</nav>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ current.label }</h2>
				</CardHeader>
				<CardBody>
					<current.Step
						key={ current.key }
						answers={ answers }
						profile={ profile }
						save={ save }
						next={ next }
						fail={ fail }
					/>
				</CardBody>
			</Card>
			{ step > 0 && (
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
