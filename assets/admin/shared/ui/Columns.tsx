import type { ReactNode } from 'react';

/**
 * A detail view in two columns on a wide screen: what one works on in the main column, the facts beside it.
 * Below 1280 px the side comes after the main column.
 *
 * @param props
 * @param props.side     The side column: payment, customer, addresses.
 * @param props.children The main column: items, shipments, history.
 */
export function Columns( {
	side,
	children,
}: {
	side: ReactNode;
	children: ReactNode;
} ) {
	return (
		<div className="oc-columns">
			<div className="oc-columns__main">{ children }</div>
			<aside className="oc-columns__side">{ side }</aside>
		</div>
	);
}
