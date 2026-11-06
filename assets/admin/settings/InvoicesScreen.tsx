import {
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import {
	fetchInvoiceSettings,
	type InvoiceSettings,
	saveInvoiceSettings,
} from './invoices';
import {
	Field,
	Page,
	Row,
	SaveBar,
	Section,
	Skeleton,
	useSave,
} from '../shared/ui';

const FORM_ID = 'orillacart-invoices';

/**
 * The seller's details the shop's invoices show, as the law asks, how their numbers read, and how they are sent.
 * A paid order gets the next number; a refund gets a credit note from its own series.
 */
export function InvoicesScreen() {
	const [ saved, setSaved ] = useState< InvoiceSettings | null >( null );
	const [ form, setForm ] = useState< InvoiceSettings | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, save ] = useSave( ( failure ) =>
		setError( errorMessage( failure ) )
	);
	const dirty = form !== null && saved !== null && differs( form, saved );
	useUnsavedChanges( dirty );

	useEffect( () => {
		fetchInvoiceSettings()
			.then( ( found ) => {
				setSaved( found );
				setForm( found );
			} )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const field = < K extends keyof InvoiceSettings >(
		key: K,
		value: InvoiceSettings[ K ]
	) => setForm( ( current ) => current && { ...current, [ key ]: value } );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( form === null ) {
			return;
		}
		setError( null );
		void save(
			async () => {
				const next = await saveInvoiceSettings( form );
				setSaved( next );
				setForm( next );
			},
			__( 'Invoice settings saved.', 'orillacart' )
		);
	};

	const year = new Date().getFullYear();
	const example = ( prefix: string ) =>
		`${ prefix }${ form?.yearly ? `${ year }-` : '' }00001`;

	return (
		<Page
			title={ __( 'Invoices', 'orillacart' ) }
			error={ error }
			onDismissError={ () => setError( null ) }
		>
			{ form === null ? (
				error === null && (
					<Skeleton lines={ 3 } widths={ [ '60%', '90%', '40%' ] } />
				)
			) : (
				<form id={ FORM_ID } onSubmit={ submit }>
					<Section
						title={ __( 'Seller', 'orillacart' ) }
						description={ __(
							'Who issues the invoice. Invoices and credit notes show these details as they were when issued; customers print them or save them as PDF.',
							'orillacart'
						) }
					>
						<Field size="l">
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Legal name', 'orillacart' ) }
								value={ form.legal_name }
								onChange={ ( value ) =>
									field( 'legal_name', value )
								}
							/>
						</Field>
						<Field size="l">
							<TextareaControl
								label={ __( 'Seller’s address', 'orillacart' ) }
								rows={ 3 }
								value={ form.address }
								onChange={ ( value ) =>
									field( 'address', value )
								}
							/>
						</Field>
						<Row>
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									label={ __( 'VAT number', 'orillacart' ) }
									value={ form.vat_number }
									onChange={ ( value ) =>
										field( 'vat_number', value )
									}
								/>
							</Field>
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Company number',
										'orillacart'
									) }
									value={ form.company_number }
									onChange={ ( value ) =>
										field( 'company_number', value )
									}
								/>
							</Field>
						</Row>
						<Row>
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									type="tel"
									label={ __( 'Phone', 'orillacart' ) }
									help={ __(
										'For customers with a question about an invoice. XRechnung needs it.',
										'orillacart'
									) }
									value={ form.phone }
									onChange={ ( value ) =>
										field( 'phone', value )
									}
								/>
							</Field>
							<Field size="m">
								<TextControl
									__next40pxDefaultSize
									type="email"
									label={ __(
										'Invoice email',
										'orillacart'
									) }
									help={ __(
										'The shop’s email when empty.',
										'orillacart'
									) }
									value={ form.email }
									onChange={ ( value ) =>
										field( 'email', value )
									}
								/>
							</Field>
						</Row>
					</Section>
					<Section
						title={ __( 'On the invoice', 'orillacart' ) }
						description={ __(
							'What every invoice and credit note says below the lines.',
							'orillacart'
						) }
					>
						<Field size="l">
							<TextareaControl
								label={ __( 'Bank details', 'orillacart' ) }
								help={ __(
									'Where a bank transfer goes: the account holder, the bank, the IBAN or account number.',
									'orillacart'
								) }
								rows={ 4 }
								value={ form.bank_details }
								onChange={ ( value ) =>
									field( 'bank_details', value )
								}
							/>
						</Field>
						<Field size="l">
							<TextareaControl
								label={ __( 'Footer', 'orillacart' ) }
								help={ __(
									'Such as the registered office or the terms of payment.',
									'orillacart'
								) }
								rows={ 3 }
								value={ form.footer }
								onChange={ ( value ) =>
									field( 'footer', value )
								}
							/>
						</Field>
					</Section>
					<Section
						title={ __( 'Numbering', 'orillacart' ) }
						description={ __(
							'A paid order gets an invoice with the next number, and money given back a credit note. Each series counts up without gaps.',
							'orillacart'
						) }
					>
						<Row>
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Invoice number prefix',
										'orillacart'
									) }
									help={ sprintf(
										/* translators: %s: the next invoice or credit note number */
										__(
											'The next one reads like %s.',
											'orillacart'
										),
										example( form.invoice_prefix )
									) }
									value={ form.invoice_prefix }
									onChange={ ( value ) =>
										field( 'invoice_prefix', value )
									}
								/>
							</Field>
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									label={ __(
										'Credit note number prefix',
										'orillacart'
									) }
									help={ sprintf(
										/* translators: %s: the next invoice or credit note number */
										__(
											'The next one reads like %s.',
											'orillacart'
										),
										example( form.credit_note_prefix )
									) }
									value={ form.credit_note_prefix }
									onChange={ ( value ) =>
										field( 'credit_note_prefix', value )
									}
								/>
							</Field>
						</Row>
						<CheckboxControl
							label={ __(
								'Start the numbers again every year',
								'orillacart'
							) }
							help={ __(
								'The year goes into the number, so each year’s series starts at 1.',
								'orillacart'
							) }
							checked={ form.yearly }
							onChange={ ( value ) => field( 'yearly', value ) }
						/>
					</Section>
					<Section
						title={ __( 'Sending', 'orillacart' ) }
						description={ __(
							'How customers get their invoices, besides their order page.',
							'orillacart'
						) }
					>
						<CheckboxControl
							label={ __(
								'Attach the invoice to the customer’s emails, as a PDF',
								'orillacart'
							) }
							help={ __(
								'The order confirmation and the completed order carry the invoice; a refund carries its credit note.',
								'orillacart'
							) }
							checked={ form.attach_to_emails }
							onChange={ ( value ) =>
								field( 'attach_to_emails', value )
							}
						/>
						<Field size="m">
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'E-invoices', 'orillacart' ) }
								help={ __(
									'Each invoice and credit note also as an EN 16931 XML file, as business customers and public bodies in the EU increasingly ask for: downloaded from its page, and with the PDF on the customer’s emails. UBL suits Peppol (Belgium, the Netherlands, the Nordics); CII suits Germany’s XRechnung and France. Sending through Peppol needs an access point: an add-on can take the file from here.',
									'orillacart'
								) }
								value={ form.e_invoices }
								options={ [
									{
										label: __( 'None', 'orillacart' ),
										value: 'none',
									},
									{
										label: __(
											'UBL (Peppol BIS Billing 3.0)',
											'orillacart'
										),
										value: 'ubl',
									},
									{
										label: __(
											'CII (XRechnung)',
											'orillacart'
										),
										value: 'cii',
									},
								] }
								onChange={ ( value ) =>
									field(
										'e_invoices',
										value as InvoiceSettings[ 'e_invoices' ]
									)
								}
							/>
						</Field>
						{ form.e_invoices === 'ubl' && (
							<Field size="m">
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Peppol ID', 'orillacart' ) }
									help={ __(
										'Your Peppol participant ID: its scheme, a colon and the identifier, such as 0208:0123456789 for a Belgian company. When empty, your VAT number’s is used. An invoice says it is for Peppol only when both you and the customer have one.',
										'orillacart'
									) }
									value={ form.peppol_id }
									onChange={ ( value ) =>
										field( 'peppol_id', value )
									}
								/>
							</Field>
						) }
					</Section>
				</form>
			) }
			{ form !== null && (
				<SaveBar
					dirty={ dirty }
					busy={ busy }
					form={ FORM_ID }
					label={ __( 'Save invoice settings', 'orillacart' ) }
				/>
			) }
		</Page>
	);
}
