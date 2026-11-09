import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import { useLeaveGuard } from '../shared/useLeaveGuard';
import {
	type Customer,
	type CustomerGroup,
	type CustomerPage,
	fetchCustomer,
	fetchCustomers,
	fetchGroups,
} from './api';
import { CustomerCard } from './CustomerCard';
import { money } from '../orders/labels';
import { useLatest } from '../shared/useLatest';
import { shopDate } from '../shared/dates';
import { EmptyState, Page, notify } from '../shared/ui';

const FIELDS: Field< Customer >[] = [
	{
		id: 'name',
		label: __( 'Customer', 'orillacart' ),
		enableHiding: false,
		enableSorting: false,
		getValue: ( { item } ) => `${ item.first_name } ${ item.last_name }`,
	},
	{ id: 'email', label: __( 'Email', 'orillacart' ), enableSorting: false },
	{ id: 'orders', label: __( 'Orders', 'orillacart' ), enableSorting: false },
	{
		id: 'spent',
		label: __( 'Spent', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) =>
			item.lifetime_value
				? money(
						item.lifetime_value.spent,
						item.lifetime_value.currency
					)
				: '',
	},
	{
		id: 'registered_at',
		label: __( 'Since', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) => shopDate( item.registered_at ),
	},
];

/**
 * The Shop → Customers screen: the customers who made an account, and one at a time to work on.
 */
export function CustomersScreen() {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		fields: [ 'email', 'orders', 'registered_at' ],
		titleField: 'name',
		layout: {},
	} );
	const [ page, setPage ] = useState< CustomerPage | null >( null );
	const [ selected, setSelectedNow ] = useState< Customer | null >( null );
	const [ leaving, guard, Guarded ] = useLeaveGuard();
	// Another row, or none, only once unsaved changes are let go; the row open stays as it is.
	const setSelected = ( next: Parameters< typeof setSelectedNow >[ 0 ] ) =>
		next !== selected && guard( () => setSelectedNow( next ) );
	const [ groups, setGroups ] = useState< CustomerGroup[] >( [] );
	const [ error, setError ] = useState< string | null >( null );

	const fail = ( failure: unknown ) => setError( errorMessage( failure ) );

	useEffect( () => {
		fetchGroups().then( setGroups ).catch( fail );
	}, [] );
	const latest = useLatest();
	const load = useCallback( () => {
		latest(
			fetchCustomers( {
				search: view.search === '' ? undefined : view.search,
				page: view.page ?? 1,
				per_page: view.perPage ?? 20,
			} )
		)
			.then( setPage )
			.catch( fail );
	}, [ view, latest ] );
	useEffect( load, [ load ] );
	// The row opened is the list's: it has no extensions' fields, which only the record's own view carries. The
	// whole customer is read when one is opened, and again after a save answered without them.
	useEffect( () => {
		if ( selected === null || selected.extensions ) {
			return;
		}
		let current = true;
		latest( fetchCustomer( selected.id ) )
			.then( ( whole ) => {
				if ( current ) {
					setSelectedNow( whole );
				}
			} )
			.catch( fail );
		return () => {
			current = false;
		};
	}, [ selected, latest ] );

	if ( selected !== null ) {
		return (
			<Page
				title={
					`${ selected.first_name } ${ selected.last_name }`.trim() ||
					selected.email
				}
				back={ {
					label: __( 'All customers', 'orillacart' ),
					onClick: () => setSelected( null ),
				} }
				error={ error }
				onDismissError={ () => setError( null ) }
			>
				{ leaving }
				<Guarded>
					<CustomerCard
						key={ selected.id }
						customer={ selected }
						groups={ groups }
						onSaved={ ( saved ) => {
							setSelectedNow( saved );
							load();
							notify( __( 'Saved.', 'orillacart' ) );
						} }
						onError={ fail }
					/>
				</Guarded>
			</Page>
		);
	}

	return (
		<Page
			title={ __( 'Customers', 'orillacart' ) }
			error={ error }
			onDismissError={ () => setError( null ) }
		>
			{ leaving }
			<DataViews< Customer >
				view={ view }
				onChangeView={ setView }
				fields={ FIELDS }
				data={ page?.customers ?? [] }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ page === null }
				paginationInfo={ {
					totalItems: page?.total ?? 0,
					totalPages: page?.pages ?? 1,
				} }
				defaultLayouts={ { table: {} } }
				searchLabel={ __( 'Search by email or name', 'orillacart' ) }
				onClickItem={ setSelected }
				isItemClickable={ () => true }
				actions={ [
					{
						id: 'edit',
						label: __( 'Edit addresses', 'orillacart' ),
						isPrimary: true,
						callback: ( [ item ] ) => setSelected( item ?? null ),
					},
				] }
				empty={
					<EmptyState
						title={ __(
							'No customers have made an account yet.',
							'orillacart'
						) }
						description={ __(
							'Customers who make an account at checkout or in the shop show here; guests are on their orders.',
							'orillacart'
						) }
					/>
				}
			/>
		</Page>
	);
}
