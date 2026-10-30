import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import { SettingFields } from '../shared/SettingFields';
import {
	fetchPaymentMethods,
	type PaymentMethod,
	type PaymentMethodInput,
	type PaymentTranslations,
	savePaymentMethod,
} from './api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import { TranslationFields, useOtherLanguages } from '../shared/Translations';

type Message = { status: 'success' | 'error'; text: string } | null;

/**
 * The Shop → Payments screen: the ways customers can pay, each turned on and set up here.
 */
export function PaymentsScreen() {
	const [ methods, setMethods ] = useState< PaymentMethod[] | null >( null );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( error ) } );

	useEffect( () => {
		fetchPaymentMethods().then( setMethods ).catch( fail );
	}, [] );

	const notice = message !== null && (
		<Notice status={ message.status } onRemove={ () => setMessage( null ) }>
			{ message.text }
		</Notice>
	);

	return (
		<>
			<h1>{ __( 'Payments', 'orillacart' ) }</h1>
			<p>
				{ __(
					'Checkout offers the active methods that serve the customer’s billing country, in their order. Orders paid offline wait on hold until you have the money.',
					'orillacart'
				) }
			</p>
			{ methods === null && message === null && <Spinner /> }
			{ notice }
			{ methods?.map( ( method ) => (
				<MethodCard
					key={ method.gateway }
					method={ method }
					onSaved={ ( saved ) => {
						setMethods(
							methods.map( ( current ) =>
								current.gateway === saved.gateway
									? saved
									: current
							)
						);
						setMessage( {
							status: 'success',
							text: sprintf(
								/* translators: %s: payment method name */
								__( '%s saved.', 'orillacart' ),
								saved.name
							),
						} );
					} }
					onError={ fail }
				/>
			) ) }
		</>
	);
}

interface MethodCardProps {
	method: PaymentMethod;
	onSaved: ( method: PaymentMethod ) => void;
	onError: ( error: unknown ) => void;
}

const inputOf = ( method: PaymentMethod ): PaymentMethodInput => ( {
	name: method.name,
	active: method.active,
	position: method.position,
	countries: method.countries,
	instructions: method.instructions,
	receipt_note: method.receipt_note,
	settings: method.settings,
	fee: method.fee,
	fee_percent: method.fee_percent,
	fee_label: method.fee_label,
	...( method.translations && { translations: method.translations } ),
} );

function MethodCard( { method, onSaved, onError }: MethodCardProps ) {
	const [ draft, setDraft ] = useState< PaymentMethodInput >(
		inputOf( method )
	);
	// The fees compare as numbers: "2.5" typed is "2.50" saved.
	useUnsavedChanges(
		differs( draft, inputOf( method ), [ 'fee', 'fee_percent' ] )
	);
	const languages = useOtherLanguages();
	const translate = (
		field: keyof PaymentTranslations,
		label: string,
		multiline = false
	) => (
		<TranslationFields
			label={ label }
			languages={ languages }
			multiline={ multiline }
			value={ draft.translations?.[ field ] }
			onChange={ ( text ) =>
				setDraft( {
					...draft,
					translations: { ...draft.translations, [ field ]: text },
				} )
			}
		/>
	);
	const [ countries, setCountries ] = useState(
		method.countries.join( ', ' )
	);

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		savePaymentMethod( method.gateway, {
			...draft,
			fee: draft.fee.trim() === '' ? '0' : draft.fee.trim(),
			fee_percent:
				draft.fee_percent.trim() === ''
					? '0'
					: draft.fee_percent.trim(),
		} )
			.then( ( saved ) => {
				setDraft( {
					...draft,
					settings: saved.settings,
					fee: saved.fee,
					fee_percent: saved.fee_percent,
				} );
				onSaved( saved );
			} )
			.catch( onError );
	};
	const setSetting = ( key: string, value: string | boolean ) =>
		setDraft( {
			...draft,
			settings: { ...draft.settings, [ key ]: value },
		} );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ method.name }</h2>
				{ method.active && method.not_ready !== null && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: what the payment method needs */
							__(
								'Checkout does not offer it. %s',
								'orillacart'
							),
							method.not_ready
						) }
					</Notice>
				) }
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Name at checkout', 'orillacart' ) }
								value={ draft.name }
								onChange={ ( name ) =>
									setDraft( { ...draft, name } )
								}
							/>
							{ translate(
								'name',
								__( 'Name at checkout', 'orillacart' )
							) }
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
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Billing countries', 'orillacart' ) }
						help={ __(
							'Country codes such as BG, separated by commas. Empty for every country.',
							'orillacart'
						) }
						value={ countries }
						onChange={ ( value ) => {
							setCountries( value );
							setDraft( {
								...draft,
								countries: value
									.split( ',' )
									.map( ( code ) =>
										code.trim().toUpperCase()
									)
									.filter( ( code ) => code !== '' ),
							} );
						} }
					/>
					<TextareaControl
						label={ __( 'Instructions at checkout', 'orillacart' ) }
						value={ draft.instructions }
						onChange={ ( instructions ) =>
							setDraft( { ...draft, instructions } )
						}
					/>
					{ translate(
						'instructions',
						__( 'Instructions at checkout', 'orillacart' ),
						true
					) }
					<TextareaControl
						label={ __( 'Note on the receipt', 'orillacart' ) }
						help={ __(
							'Such as your bank details for a bank transfer.',
							'orillacart'
						) }
						value={ draft.receipt_note }
						onChange={ ( receiptNote ) =>
							setDraft( { ...draft, receipt_note: receiptNote } )
						}
					/>
					{ translate(
						'receipt_note',
						__( 'Note on the receipt', 'orillacart' ),
						true
					) }
					<fieldset className="orillacart-fee">
						<legend>{ __( 'Fee', 'orillacart' ) }</legend>
						<p className="description">
							{ __(
								'What an order paid this way pays besides its items and shipping, taxed at the default tax group: a fixed amount and a percentage of the items and shipping. Leave both at 0 for none.',
								'orillacart'
							) }
						</p>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Fixed amount', 'orillacart' ) }
							value={ draft.fee }
							onChange={ ( fee ) =>
								setDraft( { ...draft, fee } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Percentage', 'orillacart' ) }
							value={ draft.fee_percent }
							onChange={ ( percent ) =>
								setDraft( { ...draft, fee_percent: percent } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Name in the totals', 'orillacart' ) }
							help={ __(
								'Such as "Cash on delivery fee". Empty for the method\'s name.',
								'orillacart'
							) }
							value={ draft.fee_label }
							onChange={ ( label ) =>
								setDraft( { ...draft, fee_label: label } )
							}
						/>
						{ translate(
							'fee_label',
							__( 'Name in the totals', 'orillacart' )
						) }
					</fieldset>
					<SettingFields
						fields={ method.fields }
						values={ draft.settings }
						secretsSet={ method.secrets_set }
						onChange={ setSetting }
					/>
					{ method.notification_url !== null && (
						<p>
							{ __( 'Webhook address:', 'orillacart' ) }{ ' ' }
							<code>{ method.notification_url }</code>
						</p>
					) }
					<Button
						variant="primary"
						type="submit"
						disabled={ draft.name.trim() === '' }
					>
						{ sprintf(
							/* translators: %s: what is saved: a payment method, a carrier or a stockroom */
							__( 'Save %s', 'orillacart' ),
							method.name
						) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
