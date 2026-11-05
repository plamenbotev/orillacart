import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import { SettingFields } from '../shared/SettingFields';
import {
	Badge,
	Field,
	Page,
	Row,
	Section,
	Skeleton,
	useSave,
} from '../shared/ui';
import {
	fetchPaymentMethods,
	type PaymentMethod,
	type PaymentMethodInput,
	type PaymentTranslations,
	savePaymentMethod,
} from './api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import { TranslationFields, useOtherLanguages } from '../shared/Translations';

/**
 * The Shop → Payments screen: the ways customers can pay, each turned on and set up here.
 */
export function PaymentsScreen() {
	const [ methods, setMethods ] = useState< PaymentMethod[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const fail = ( failure: unknown ) => setError( errorMessage( failure ) );

	useEffect( () => {
		fetchPaymentMethods().then( setMethods ).catch( fail );
	}, [] );

	return (
		<Page
			title={ __( 'Payments', 'orillacart' ) }
			description={ __(
				'Checkout offers the active methods that serve the customer’s billing country, in their order. Orders paid offline wait on hold until you have the money.',
				'orillacart'
			) }
			error={ error }
			onDismissError={ () => setError( null ) }
		>
			{ methods === null && error === null && (
				<Section>
					<Skeleton
						lines={ 4 }
						widths={ [ '30%', '60%', '60%', '20%' ] }
					/>
				</Section>
			) }
			{ methods?.map( ( method ) => (
				<MethodCard
					key={ method.gateway }
					method={ method }
					onSaved={ ( saved ) =>
						setMethods(
							methods.map( ( current ) =>
								current.gateway === saved.gateway
									? saved
									: current
							)
						)
					}
					onError={ fail }
				/>
			) ) }
		</Page>
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
	const [ busy, save ] = useSave( onError );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		save(
			() =>
				savePaymentMethod( method.gateway, {
					...draft,
					fee: draft.fee.trim() === '' ? '0' : draft.fee.trim(),
					fee_percent:
						draft.fee_percent.trim() === ''
							? '0'
							: draft.fee_percent.trim(),
				} ),
			( saved ) =>
				sprintf(
					/* translators: %s: payment method name */
					__( '%s saved.', 'orillacart' ),
					saved.name
				)
		).then( ( saved ) => {
			if ( saved ) {
				setDraft( {
					...draft,
					settings: saved.settings,
					fee: saved.fee,
					fee_percent: saved.fee_percent,
				} );
				onSaved( saved );
			}
		} );
	};
	const setSetting = ( key: string, value: string | boolean ) =>
		setDraft( {
			...draft,
			settings: { ...draft.settings, [ key ]: value },
		} );

	const formId = `orillacart-payment-${ method.gateway }`;

	return (
		<Section
			title={ method.name }
			titleAside={
				<Badge tone={ draft.active ? 'success' : 'neutral' }>
					{ draft.active
						? __( 'Active', 'orillacart' )
						: __( 'Off', 'orillacart' ) }
				</Badge>
			}
			description={
				method.notification_url !== null && (
					<>
						{ __( 'Webhook address:', 'orillacart' ) }{ ' ' }
						<code>{ method.notification_url }</code>
					</>
				)
			}
			footer={
				<Button
					variant="primary"
					type="submit"
					form={ formId }
					isBusy={ busy }
					disabled={ busy || draft.name.trim() === '' }
				>
					{ sprintf(
						/* translators: %s: what is saved: a payment method, a carrier or a stockroom */
						__( 'Save %s', 'orillacart' ),
						method.name
					) }
				</Button>
			}
		>
			{ method.active && method.not_ready !== null && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: what the payment method needs */
						__( 'Checkout does not offer it. %s', 'orillacart' ),
						method.not_ready
					) }
				</Notice>
			) }
			<form id={ formId } onSubmit={ submit }>
				<Row>
					<Field size="m">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Name at checkout', 'orillacart' ) }
							value={ draft.name }
							onChange={ ( name ) =>
								setDraft( { ...draft, name } )
							}
						/>
					</Field>
					<Field size="xs">
						<TextControl
							__next40pxDefaultSize
							type="number"
							min={ 0 }
							label={ __( 'Order', 'orillacart' ) }
							help={ __( 'Lowest first.', 'orillacart' ) }
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
					</Field>
				</Row>
				{ translate( 'name', __( 'Name at checkout', 'orillacart' ) ) }
				<CheckboxControl
					label={ __( 'Active', 'orillacart' ) }
					help={ __( 'Checkout offers it.', 'orillacart' ) }
					checked={ draft.active }
					onChange={ ( active ) => setDraft( { ...draft, active } ) }
				/>
				<Field size="m">
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
				</Field>
				<Field size="l">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Instructions at checkout', 'orillacart' ) }
						rows={ 3 }
						value={ draft.instructions }
						onChange={ ( instructions ) =>
							setDraft( { ...draft, instructions } )
						}
					/>
				</Field>
				{ translate(
					'instructions',
					__( 'Instructions at checkout', 'orillacart' ),
					true
				) }
				<Field size="l">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Note on the receipt', 'orillacart' ) }
						help={ __(
							'Such as your bank details for a bank transfer.',
							'orillacart'
						) }
						rows={ 3 }
						value={ draft.receipt_note }
						onChange={ ( receiptNote ) =>
							setDraft( { ...draft, receipt_note: receiptNote } )
						}
					/>
				</Field>
				{ translate(
					'receipt_note',
					__( 'Note on the receipt', 'orillacart' ),
					true
				) }
				<SettingFields
					fields={ method.fields }
					values={ draft.settings }
					secretsSet={ method.secrets_set }
					onChange={ setSetting }
				/>
				<h3>{ __( 'Fee', 'orillacart' ) }</h3>
				<p className="description">
					{ __(
						'What an order paid this way pays besides its items and shipping, taxed at the default tax group. Leave both at 0 for none.',
						'orillacart'
					) }
				</p>
				<Row>
					<Field size="xs">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Fixed amount', 'orillacart' ) }
							value={ draft.fee }
							onChange={ ( fee ) =>
								setDraft( { ...draft, fee } )
							}
						/>
					</Field>
					<Field size="xs">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Percentage', 'orillacart' ) }
							help={ __(
								'Of the items and shipping.',
								'orillacart'
							) }
							value={ draft.fee_percent }
							onChange={ ( percent ) =>
								setDraft( { ...draft, fee_percent: percent } )
							}
						/>
					</Field>
					<Field size="m">
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
					</Field>
				</Row>
				{ translate(
					'fee_label',
					__( 'Name in the totals', 'orillacart' )
				) }
			</form>
		</Section>
	);
}
