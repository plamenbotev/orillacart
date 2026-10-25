import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	fetchPaymentMethods,
	type PaymentMethod,
	savePaymentMethod,
} from '../../payments/api';
import { errorMessage } from '../../shared/api';
import { SettingFields, type SettingValues } from '../../shared/SettingFields';
import { type SettingsTest, testPaymentSettings } from '../api';
import { answered, flag, text, type StepProps } from './types';

/** The ways to pay that need no account elsewhere, which the plan sets up. */
const OFFLINE = [ 'bank_transfer', 'cash_on_delivery', 'cheque' ];

/**
 * Payments: bank transfer into the shop's account, cash on delivery at home, cheques; and the gateways that
 * need an account elsewhere (PayPal, add-ons'), whose keys are saved on them at once and tried there.
 * @param root0
 * @param root0.answers
 * @param root0.save
 * @param root0.fail
 */
export function PaymentsStep( { answers, save, fail }: StepProps ) {
	const given = answered( answers, 'payments' );
	const business = answered( answers, 'business' );
	const bank = ( given.bank ?? {} ) as Record< string, unknown >;
	const [ form, setForm ] = useState( {
		bank_transfer: flag( given, 'bank_transfer', true ),
		cash_on_delivery: flag( given, 'cash_on_delivery', false ),
		cheque: flag( given, 'cheque', false ),
	} );
	const [ account, setAccount ] = useState( {
		holder: text( bank, 'holder', text( business, 'legal_name' ) ),
		iban: text( bank, 'iban' ),
		bic: text( bank, 'bic' ),
		bank_name: text( bank, 'bank_name' ),
	} );
	const [ online, setOnline ] = useState< PaymentMethod[] | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchPaymentMethods()
			.then( ( methods ) =>
				setOnline(
					methods.filter(
						( method ) => ! OFFLINE.includes( method.gateway )
					)
				)
			)
			.catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( {
					...form,
					bank:
						form.bank_transfer && account.iban !== ''
							? account
							: null,
				} )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			<CheckboxControl
				label={ __( 'Bank transfer', 'orillacart' ) }
				help={ __(
					'Customers pay into the shop’s account; the order waits for the money.',
					'orillacart'
				) }
				checked={ form.bank_transfer }
				onChange={ ( on ) => setForm( { ...form, bank_transfer: on } ) }
			/>
			{ form.bank_transfer && (
				<fieldset className="orillacart-setup__zone">
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Account holder', 'orillacart' ) }
						value={ account.holder }
						onChange={ ( value ) =>
							setAccount( { ...account, holder: value } )
						}
						required
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'IBAN', 'orillacart' ) }
						value={ account.iban }
						onChange={ ( value ) =>
							setAccount( { ...account, iban: value } )
						}
						required
					/>
					<div className="orillacart-setup__row">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'BIC', 'orillacart' ) }
							value={ account.bic }
							onChange={ ( value ) =>
								setAccount( { ...account, bic: value } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Bank', 'orillacart' ) }
							value={ account.bank_name }
							onChange={ ( value ) =>
								setAccount( { ...account, bank_name: value } )
							}
						/>
					</div>
				</fieldset>
			) }
			<CheckboxControl
				label={ __( 'Cash on delivery', 'orillacart' ) }
				help={ __(
					'Offered in the shop’s own country only.',
					'orillacart'
				) }
				checked={ form.cash_on_delivery }
				onChange={ ( on ) =>
					setForm( { ...form, cash_on_delivery: on } )
				}
			/>
			<CheckboxControl
				label={ __( 'Cheque', 'orillacart' ) }
				checked={ form.cheque }
				onChange={ ( on ) => setForm( { ...form, cheque: on } ) }
			/>
			{ online === null ? (
				<Spinner />
			) : (
				online.map( ( method ) => (
					<GatewaySetup key={ method.gateway } method={ method } />
				) )
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
 * A gateway with an account elsewhere: its keys, tried at the provider, saved on the gateway at once.
 * @param root0
 * @param root0.method
 */
function GatewaySetup( { method }: { method: PaymentMethod } ) {
	const [ saved, setSaved ] = useState( method );
	const [ values, setValues ] = useState< SettingValues >( method.settings );
	const [ test, setTest ] = useState< SettingsTest | null >( null );
	const [ problem, setProblem ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const store = ( active: boolean ) => {
		setBusy( true );
		setProblem( null );
		savePaymentMethod( saved.gateway, {
			name: saved.name,
			active,
			position: saved.position,
			countries: saved.countries,
			instructions: saved.instructions,
			receipt_note: saved.receipt_note,
			settings: values,
			fee: saved.fee,
			fee_percent: saved.fee_percent,
			fee_label: saved.fee_label,
		} )
			.then( setSaved )
			.catch( ( error: unknown ) => setProblem( errorMessage( error ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<fieldset className="orillacart-setup__zone">
			<legend>{ saved.name }</legend>
			<SettingFields
				fields={ saved.fields }
				values={ values }
				secretsSet={ saved.secrets_set }
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
						? __( 'The keys work.', 'orillacart' )
						: test.problem }
				</Notice>
			) }
			{ problem !== null && (
				<Notice status="error" isDismissible={ false }>
					{ problem }
				</Notice>
			) }
			{ saved.active && saved.not_ready === null && (
				<Notice status="success" isDismissible={ false }>
					{ __( 'Customers can pay this way.', 'orillacart' ) }
				</Notice>
			) }
			{ saved.testable && (
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => {
						setBusy( true );
						testPaymentSettings( saved.gateway, values )
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
					{ __( 'Test the keys', 'orillacart' ) }
				</Button>
			) }{ ' ' }
			<Button
				variant="secondary"
				disabled={ busy }
				onClick={ () => store( ! saved.active ) }
			>
				{ saved.active
					? __( 'Turn it off', 'orillacart' )
					: __( 'Save and turn it on', 'orillacart' ) }
			</Button>
		</fieldset>
	);
}
