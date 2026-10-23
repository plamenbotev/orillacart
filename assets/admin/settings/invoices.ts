import apiFetch from '@wordpress/api-fetch';

/** Who issues the shop's invoices, and how their numbers read. */
export interface InvoiceSettings {
	legal_name: string;
	address: string;
	vat_number: string;
	company_number: string;
	bank_details: string;
	footer: string;
	invoice_prefix: string;
	credit_note_prefix: string;
	/** Numbering restarts every year, with the year in the number. */
	yearly: boolean;
	/** The customer's emails carry the invoice, or the credit note, as a PDF. */
	attach_to_emails: boolean;
	/** Also as EN 16931 XML: UBL (Peppol BIS 3.0) or CII (XRechnung). */
	e_invoices: 'none' | 'ubl' | 'cii';
	/** Who the customer reaches about an invoice; XRechnung needs the phone. */
	phone: string;
	/** The shop's email when empty. */
	email: string;
	/** The seller's Peppol participant ID, such as 0208:0123456789; the VAT number's when empty. */
	peppol_id: string;
}

const PATH = '/orillacart/v1/invoice-settings';

export function fetchInvoiceSettings(): Promise< InvoiceSettings > {
	return apiFetch< InvoiceSettings >( { path: PATH } );
}

export function saveInvoiceSettings(
	settings: InvoiceSettings
): Promise< InvoiceSettings > {
	return apiFetch< InvoiceSettings >( {
		path: PATH,
		method: 'PUT',
		data: settings,
	} );
}
