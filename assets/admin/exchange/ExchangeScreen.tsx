import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ExportDialog } from '../shared/ExportDialog';

/**
 * The Shop → Import and export screen: the products file, every product and variation with the columns the shop
 * manager chooses (add-ons' among them), to edit in a spreadsheet and import again.
 */
export function ExchangeScreen() {
	const [ exporting, setExporting ] = useState( false );

	return (
		<>
			<h1>{ __( 'Import and export', 'orillacart' ) }</h1>
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ __( 'Export products', 'orillacart' ) }</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A CSV file with a row for each product and one for each of its variations. Its first row names the columns as the import reads them.',
							'orillacart'
						) }
					</p>
					<Button
						variant="primary"
						onClick={ () => setExporting( true ) }
					>
						{ __( 'Export products', 'orillacart' ) }
					</Button>
				</CardBody>
			</Card>
			{ exporting && (
				<ExportDialog
					kind="products"
					title={ __( 'Export products', 'orillacart' ) }
					query={ {} }
					formats={ [ { value: 'csv', label: 'CSV' } ] }
					onClose={ () => setExporting( false ) }
				/>
			) }
		</>
	);
}
