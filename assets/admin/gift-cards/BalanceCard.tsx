import { Button, Flex, FlexItem, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { money } from '../orders/labels';
import type { GiftCardDetail } from './api';
import { shopDateTime } from '../shared/dates';

/**
 * A balance with every change of it, newest first, and a form to add to it or take off it with a reason: a gift
 * card's or a customer's store credit.
 *
 * @param props        The list's properties.
 * @param props.detail The card and its changes.
 */
export function BalanceEntries( { detail }: { detail: GiftCardDetail } ) {
	if ( detail.entries.length === 0 ) {
		return <p>{ __( 'Nothing yet.', 'orillacart' ) }</p>;
	}
	return (
		<table className="widefat striped orillacart-balance-entries">
			<thead>
				<tr>
					<th>{ __( 'When', 'orillacart' ) }</th>
					<th>{ __( 'Change', 'orillacart' ) }</th>
					<th>{ __( 'Why', 'orillacart' ) }</th>
					<th>{ __( 'By', 'orillacart' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ detail.entries.map( ( entry ) => (
					<tr key={ entry.id }>
						<td>{ shopDateTime( entry.at ) }</td>
						<td>
							{ entry.amount.startsWith( '-' )
								? `−${ money(
										entry.amount.slice( 1 ),
										detail.card.currency
									) }`
								: `+${ money(
										entry.amount,
										detail.card.currency
									) }` }
						</td>
						<td>
							{ entry.order_id === null ? (
								entry.note
							) : (
								<a
									href={ `admin.php?page=orillacart-orders#/orders/${ entry.order_id }` }
								>
									{ entry.note }
								</a>
							) }
						</td>
						<td>{ entry.user }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Adds to a balance, or takes off it with a minus, and says why.
 *
 * @param props          The form's properties.
 * @param props.onAdjust Makes the change; the form empties once it is made.
 */
export function AdjustForm( {
	onAdjust,
}: {
	onAdjust: ( change: {
		amount: string;
		note: string;
	} ) => Promise< unknown >;
} ) {
	const [ amount, setAmount ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				onAdjust( {
					amount: amount.replace( ',', '.' ).replace( /\s/g, '' ),
					note,
				} )
					.then( () => {
						setAmount( '' );
						setNote( '' );
					} )
					.catch( () => undefined )
					.finally( () => setBusy( false ) );
			} }
		>
			<Flex align="flex-end" wrap>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Add, or − to take off', 'orillacart' ) }
						inputMode="decimal"
						value={ amount }
						onChange={ setAmount }
						required
					/>
				</FlexItem>
				<FlexItem isBlock>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Why', 'orillacart' ) }
						value={ note }
						onChange={ setNote }
						required
					/>
				</FlexItem>
				<FlexItem>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
						__next40pxDefaultSize
					>
						{ __( 'Change the balance', 'orillacart' ) }
					</Button>
				</FlexItem>
			</Flex>
		</form>
	);
}
