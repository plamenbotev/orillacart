import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useLeaveGuard } from '../shared/useLeaveGuard';
import { errorMessage } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import { FilterModal } from './FilterModal';
import {
	deleteFilter,
	fetchAttributeNames,
	fetchFilters,
	type SearchFilter,
} from './filters';

/**
 * In the shop's order, as the storefront lists them.
 * @param filters
 */
const ordered = ( filters: SearchFilter[] ): SearchFilter[] =>
	[ ...filters ].sort(
		( a, b ) => a.position - b.position || a.label.localeCompare( b.label )
	);

/**
 * The filters shoppers see on the shop page, beside brand, price and stock. Attributes belong to one product
 * or one attribute set, so a filter names the attributes it covers; their options are matched by name.
 */
export function FiltersCard() {
	const [ filters, setFilters ] = useState< SearchFilter[] | null >( null );
	const [ names, setNames ] = useState< string[] >( [] );
	/** The filter being edited, null for a new one; undefined when the dialog is closed. */
	const [ editing, setEditingNow ] = useState< SearchFilter | null >();
	const [ leaving, guard, Guarded ] = useLeaveGuard();
	// Another row, or none, only once unsaved changes are let go; the row open stays as it is.
	const setEditing = ( next: Parameters< typeof setEditingNow >[ 0 ] ) =>
		next !== editing && guard( () => setEditingNow( next ) );
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const [ confirmation, confirm ] = useConfirm();

	useEffect( () => {
		Promise.all( [ fetchFilters(), fetchAttributeNames() ] )
			.then( ( [ loaded, attributeNames ] ) => {
				setFilters( ordered( loaded ) );
				setNames( attributeNames );
			} )
			.catch( ( reason: unknown ) =>
				setMessage( {
					status: 'error',
					text: errorMessage( reason ),
				} )
			);
	}, [] );

	const fail = ( reason: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( reason ) } );

	return (
		<Card className="orillacart-card">
			{ confirmation }
			{ leaving }
			<CardHeader>
				<h2>{ __( 'Shop filters', 'orillacart' ) }</h2>
				<Button
					variant="secondary"
					disabled={ filters === null }
					onClick={ () => setEditing( null ) }
				>
					{ __( 'Add filter', 'orillacart' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Shoppers narrow the shop page by these, with how many products each choice leaves. A filter shows only where the products in view have values for it. Every category offers these, in this order, unless it chooses its own on its edit screen:',
						'orillacart'
					) }{ ' ' }
					<a href="edit-tags.php?taxonomy=orillacart_category&post_type=orillacart_product">
						{ __( 'Products → Categories', 'orillacart' ) }
					</a>
					.
				</p>
				{ message !== null && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }
				{ filters === null && message === null && <Spinner /> }
				{ filters !== null && filters.length === 0 && (
					<p>
						<em>
							{ __(
								'No filters yet: shoppers can narrow by brand, price and stock.',
								'orillacart'
							) }
						</em>
					</p>
				) }
				{ filters !== null && filters.length > 0 && (
					<table className="widefat striped orillacart-filters">
						<thead>
							<tr>
								<th>{ __( 'Filter', 'orillacart' ) }</th>
								<th>
									{ __(
										'Attributes it covers',
										'orillacart'
									) }
								</th>
								<th className="num">
									{ __( 'Order', 'orillacart' ) }
								</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'orillacart' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ filters.map( ( filter ) => (
								<tr key={ filter.id }>
									<td>
										<strong>{ filter.label }</strong>
										<br />
										<code>{ `f[${ filter.key }]` }</code>
									</td>
									<td>{ filter.attributes.join( ', ' ) }</td>
									<td className="num">{ filter.position }</td>
									<td className="orillacart-filters__actions">
										<Button
											variant="link"
											onClick={ () =>
												setEditing( filter )
											}
										>
											{ __( 'Edit', 'orillacart' ) }
										</Button>
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												confirm(
													sprintf(
														/* translators: %s: filter label */
														__(
															'Remove the %s filter? Links that use it show every product again.',
															'orillacart'
														),
														filter.label
													),
													() =>
														void deleteFilter(
															filter.id
														)
															.then( () =>
																setFilters(
																	filters.filter(
																		(
																			current
																		) =>
																			current.id !==
																			filter.id
																	)
																)
															)
															.catch( fail ),
													__( 'Remove', 'orillacart' )
												)
											}
										>
											{ __( 'Remove', 'orillacart' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				{ editing !== undefined && filters !== null && (
					<Guarded>
						<FilterModal
							filter={ editing }
							names={ names }
							nextPosition={
								filters.reduce(
									( highest, filter ) =>
										Math.max( highest, filter.position ),
									0
								) + 1
							}
							onClose={ () => setEditing( undefined ) }
							onSaved={ ( saved ) => {
								setFilters(
									ordered( [
										...filters.filter(
											( current ) =>
												current.id !== saved.id
										),
										saved,
									] )
								);
								setEditingNow( undefined );
								setMessage( {
									status: 'success',
									text:
										editing === null
											? __(
													'Filter added.',
													'orillacart'
												)
											: __(
													'Filter saved.',
													'orillacart'
												),
								} );
							} }
						/>
					</Guarded>
				) }
			</CardBody>
		</Card>
	);
}
