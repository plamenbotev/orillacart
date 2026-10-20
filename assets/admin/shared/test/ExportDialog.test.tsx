import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ExportDialog } from '../ExportDialog';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const columns = [
	{
		id: 'number',
		label: 'Order',
		add_on: false,
		chosen: true,
		importable: false,
	},
	{
		id: 'total',
		label: 'Total',
		add_on: false,
		chosen: true,
		importable: false,
	},
	{
		id: 'warehouse',
		label: 'Warehouse',
		add_on: true,
		chosen: false,
		importable: false,
	},
];

describe( 'ExportDialog', () => {
	beforeEach( () => {
		window.orillacartExport = {
			url: 'https://shop.test/wp-admin/admin-post.php',
			nonce: 'n0nce',
		};
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				( options as { method?: string } ).method === 'PUT'
					? []
					: columns
			)
		);
	} );
	afterEach( () => mockedFetch.mockReset() );

	it( 'exports the chosen columns in their order, with the list’s filters', async () => {
		const download = jest.fn();
		const onClose = jest.fn();
		const user = userEvent.setup();
		render(
			<ExportDialog
				kind="orders"
				title="Export"
				query={ {
					status: 'processing',
					search: undefined,
					page: 3,
					per_page: 20,
				} }
				formats={ [
					{ value: 'csv', label: 'CSV' },
					{ value: 'json', label: 'JSON' },
				] }
				onClose={ onClose }
				download={ download }
			/>
		);

		await user.click(
			await screen.findByLabelText( 'Warehouse (add-on)' )
		);
		await user.click(
			screen.getAllByRole( 'button', { name: 'Move up' } )[ 2 ]!
		);
		await user.click( screen.getByLabelText( 'Order' ) );
		await user.selectOptions( screen.getByLabelText( 'Format' ), 'json' );
		await user.click( screen.getByRole( 'button', { name: 'Export' } ) );

		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/exports/orders/columns',
			method: 'PUT',
			data: { columns: [ 'warehouse', 'total' ] },
		} );
		await screen.findByRole( 'button', { name: 'Export' } );
		expect( download ).toHaveBeenCalledWith(
			'https://shop.test/wp-admin/admin-post.php?status=processing&action=orillacart_export_orders&format=json&_wpnonce=n0nce'
		);
		expect( onClose ).toHaveBeenCalled();
	} );
} );
