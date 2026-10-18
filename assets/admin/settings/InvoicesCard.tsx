import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import { useBusy } from '../shared/useBusy';
import {
	fetchInvoiceSettings,
	type InvoiceSettings,
	saveInvoiceSettings,
} from './invoices';

/**
 * The seller's details the shop's invoices show, as the law asks, and how their numbers read. A paid order gets
 * the next number; a refund gets a credit note from its own series.
 */
export function InvoicesCard() {
	const [ settings, setSettings ] = useState< InvoiceSettings | null >(
		null
	);
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const [ busy, run ] = useBusy();

	useEffect( () => {
		fetchInvoiceSettings()
			.then( setSettings )
			.catch( ( failure: unknown ) =>
				setMessage( {
					status: 'error',
					text: toApiError( failure ).message,
				} )
			);
	}, [] );

	const field = < K extends keyof InvoiceSettings >(
		key: K,
		value: InvoiceSettings[ K ]
	) =>
		setSettings( ( current ) => current && { ...current, [ key ]: value } );

	const save = () =>
		run( () =>
			saveInvoiceSettings( settings as InvoiceSettings )
				.then( ( saved ) => {
					setSettings( saved );
					setMessage( {
						status: 'success',
						text: __(
							'The invoice settings are saved.',
							'orillacart'
						),
					} );
				} )
				.catch( ( failure: unknown ) =>
					setMessage( {
						status: 'error',
						text: toApiError( failure ).message,
					} )
				)
		);

	const year = new Date().getFullYear();
	const example = ( prefix: string ) =>
		`${ prefix }${ settings?.yearly ? `${ year }-` : '' }00001`;

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Invoices', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ message !== null && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }
				{ settings === null ? (
					message === null && <Spinner />
				) : (
					<>
						<p>
							{ __(
								'A paid order gets an invoice with the next number, and money given back a credit note. They show these details as they were when issued; customers print them or save them as PDF.',
								'orillacart'
							) }
						</p>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Legal name', 'orillacart' ) }
							value={ settings.legal_name }
							onChange={ ( value ) =>
								field( 'legal_name', value )
							}
						/>
						<TextareaControl
							label={ __( 'Seller’s address', 'orillacart' ) }
							value={ settings.address }
							onChange={ ( value ) => field( 'address', value ) }
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'VAT number', 'orillacart' ) }
							value={ settings.vat_number }
							onChange={ ( value ) =>
								field( 'vat_number', value )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Company number', 'orillacart' ) }
							value={ settings.company_number }
							onChange={ ( value ) =>
								field( 'company_number', value )
							}
						/>
						<TextareaControl
							label={ __( 'Bank details', 'orillacart' ) }
							value={ settings.bank_details }
							onChange={ ( value ) =>
								field( 'bank_details', value )
							}
						/>
						<TextareaControl
							label={ __( 'Footer', 'orillacart' ) }
							help={ __(
								'Such as the registered office or the terms of payment.',
								'orillacart'
							) }
							value={ settings.footer }
							onChange={ ( value ) => field( 'footer', value ) }
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Invoice number prefix',
								'orillacart'
							) }
							help={ sprintf(
								/* translators: %s: an invoice number */
								__(
									'The next one reads like %s.',
									'orillacart'
								),
								example( settings.invoice_prefix )
							) }
							value={ settings.invoice_prefix }
							onChange={ ( value ) =>
								field( 'invoice_prefix', value )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Credit note number prefix',
								'orillacart'
							) }
							help={ sprintf(
								/* translators: %s: a credit note number */
								__(
									'The next one reads like %s.',
									'orillacart'
								),
								example( settings.credit_note_prefix )
							) }
							value={ settings.credit_note_prefix }
							onChange={ ( value ) =>
								field( 'credit_note_prefix', value )
							}
						/>
						<CheckboxControl
							label={ __(
								'Start the numbers again every year',
								'orillacart'
							) }
							checked={ settings.yearly }
							onChange={ ( value ) => field( 'yearly', value ) }
						/>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ save }
						>
							{ __( 'Save the invoice settings', 'orillacart' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}
