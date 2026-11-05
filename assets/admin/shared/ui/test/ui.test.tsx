import '@testing-library/jest-dom';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
	Badge,
	DataTable,
	EmptyState,
	More,
	Page,
	Section,
	StatusBadge,
	toneOf,
	useSave,
} from '../index';

const NOTICE = { selector: '.components-notice__content' };
const TOAST = { selector: '.components-snackbar__content' };

describe( 'The design system', () => {
	it( 'frames a page: title, description, actions, tabs and an error', async () => {
		const onSelectTab = jest.fn();
		const onDismissError = jest.fn();
		const user = userEvent.setup();
		render(
			<Page
				title="Coupons"
				description="Codes customers enter."
				actions={ <button>Add a coupon</button> }
				tabs={ [
					{ id: 'levels', label: 'Levels' },
					{ id: 'history', label: 'History' },
				] }
				selectedTab="levels"
				onSelectTab={ onSelectTab }
				error="The shop did not answer."
				onDismissError={ onDismissError }
			>
				<p>Content</p>
			</Page>
		);

		expect(
			screen.getByRole( 'heading', { level: 1, name: 'Coupons' } )
		).toBeVisible();
		expect( screen.getByText( 'Codes customers enter.' ) ).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Add a coupon' } )
		).toBeVisible();
		expect(
			screen.getByRole( 'tab', { name: 'Levels', selected: true } )
		).toBeVisible();
		await user.click( screen.getByRole( 'tab', { name: 'History' } ) );
		expect( onSelectTab ).toHaveBeenCalledWith( 'history' );
		expect(
			screen.getByText( 'The shop did not answer.', NOTICE )
		).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Close' } )
		);
		expect( onDismissError ).toHaveBeenCalled();
	} );

	it( 'links a tab that has somewhere to go, and marks the current one', () => {
		render(
			<Page
				title="Catalog"
				tabs={ [
					{ id: 'stock', label: 'Stock', href: '?page=stock' },
					{ id: 'rules', label: 'Rules', href: '?page=rules' },
				] }
				selectedTab="rules"
			/>
		);

		const current = screen.getByRole( 'link', { name: 'Rules' } );
		expect( current ).toHaveAttribute( 'aria-current', 'page' );
		expect(
			screen.getByRole( 'link', { name: 'Stock' } )
		).not.toHaveAttribute( 'aria-current' );
	} );

	it( 'keeps a badge beside a heading out of its name', () => {
		render(
			<Section
				title="PayPal"
				titleAside={ <Badge tone="success">Active</Badge> }
				description="Takes cards too."
				action={ <button>Close</button> }
				footer={ <button>Save</button> }
			>
				<p>Fields</p>
			</Section>
		);

		expect(
			screen.getByRole( 'heading', { level: 2, name: 'PayPal' } )
		).toBeVisible();
		expect( screen.getByText( 'Active' ) ).toHaveClass(
			'oc-badge--success'
		);
		expect( screen.getByText( 'Takes cards too.' ) ).toBeVisible();
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeVisible();
	} );

	it( 'gives every status of the shop one tone', () => {
		render( <StatusBadge status="on_hold" label="On hold" /> );
		expect( screen.getByText( 'On hold' ) ).toHaveClass(
			'oc-badge--warning'
		);
		expect( toneOf( 'completed' ) ).toBe( 'success' );
		expect( toneOf( 'failed' ) ).toBe( 'danger' );
		expect( toneOf( 'processing' ) ).toBe( 'info' );
		expect( toneOf( 'something_new' ) ).toBe( 'neutral' );
	} );

	it( 'opens the rare options on demand', async () => {
		const user = userEvent.setup();
		render(
			<More>
				<p>Price decimals</p>
			</More>
		);

		expect(
			screen.queryByText( 'Price decimals' )
		).not.toBeInTheDocument();
		await user.click(
			screen.getByRole( 'button', { name: 'More options' } )
		);
		expect( screen.getByText( 'Price decimals' ) ).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'More options' } )
		).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'lays a table out with numbers at the end, a skeleton while loading and an empty state after', () => {
		const columns = [
			{
				id: 'name',
				label: 'Lane',
				render: ( row: { name: string; waiting: number } ) => row.name,
			},
			{
				id: 'waiting',
				label: 'Waiting',
				align: 'end' as const,
				render: ( row: { waiting: number } ) => String( row.waiting ),
			},
		];
		const { rerender } = render(
			<DataTable
				columns={ columns }
				rows={ [] }
				rowKey={ ( row ) => row.name }
				loading
			/>
		);
		expect(
			screen.getByRole( 'status', { name: 'Loading' } )
		).toBeInTheDocument();

		rerender(
			<DataTable
				columns={ columns }
				rows={ [] }
				rowKey={ ( row ) => row.name }
				empty={
					<EmptyState
						title="No lanes."
						description="Jobs make them."
					/>
				}
			/>
		);
		expect( screen.getByText( 'No lanes.' ) ).toBeVisible();
		expect( screen.getByText( 'Jobs make them.' ) ).toBeVisible();

		rerender(
			<DataTable
				columns={ columns }
				rows={ [ { name: 'mail', waiting: 3 } ] }
				rowKey={ ( row ) => row.name }
			/>
		);
		expect( screen.getByRole( 'cell', { name: '3' } ) ).toHaveClass(
			'oc-table__end'
		);
		expect(
			screen.getByRole( 'columnheader', { name: 'Waiting' } )
		).toHaveClass( 'oc-table__end' );
	} );

	it( 'saves once however often the button is pressed, says so, and hands an error back', async () => {
		const onError = jest.fn();
		let resolve: ( value: string ) => void = () => {};
		const work = jest.fn(
			() =>
				new Promise< string >( ( done ) => {
					resolve = done;
				} )
		);
		function Form() {
			const [ busy, save ] = useSave( onError );
			return (
				<Page title="Payments">
					<button
						disabled={ busy }
						onClick={ () =>
							save( work, ( name ) => `${ name } saved.` )
						}
					>
						Save
					</button>
					<button
						onClick={ () =>
							save( () => Promise.reject( new Error( 'No' ) ) )
						}
					>
						Fail
					</button>
				</Page>
			);
		}
		const user = userEvent.setup();
		render( <Form /> );

		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( work ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		await act( async () => {
			resolve( 'PayPal' );
		} );
		expect(
			await screen.findByText( 'PayPal saved.', TOAST )
		).toBeVisible();
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		await user.click( screen.getByRole( 'button', { name: 'Fail' } ) );
		expect( onError ).toHaveBeenCalledWith( expect.any( Error ) );
	} );
} );
