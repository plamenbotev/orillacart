import type { ReactNode } from 'react';

import { Skeleton } from './Skeleton';

export interface Column< T > {
	id: string;
	label: ReactNode;
	/** Numbers and money go to the end. */
	align?: 'start' | 'end';
	/** Dates and names that must not wrap. */
	nowrap?: boolean;
	width?: string;
	render: ( row: T ) => ReactNode;
}

/**
 * A table that is not a DataViews list (the stock sheet, the jobs' lanes, the keys), in the same look: light
 * header, hairlines between rows, numbers to the right and in line.
 *
 * @param props
 * @param props.columns The columns.
 * @param props.rows    The rows.
 * @param props.rowKey  A row's key.
 * @param props.loading Rows are still coming: a skeleton shows.
 * @param props.empty   What shows with no rows: an EmptyState or a sentence.
 * @param props.caption The table's name for screen readers.
 */
export function DataTable< T >( {
	columns,
	rows,
	rowKey,
	loading = false,
	empty,
	caption,
}: {
	columns: Column< T >[];
	rows: T[];
	rowKey: ( row: T ) => string | number;
	loading?: boolean;
	empty?: ReactNode;
	caption?: string;
} ) {
	const cellClass = ( column: Column< T > ) =>
		[
			column.align === 'end' ? 'oc-table__end' : '',
			column.nowrap ? 'oc-table__nowrap' : '',
		]
			.filter( Boolean )
			.join( ' ' ) || undefined;

	return (
		<div className="oc-table-wrap">
			<table className="oc-table">
				{ caption && (
					<caption className="screen-reader-text">
						{ caption }
					</caption>
				) }
				<thead>
					<tr>
						{ columns.map( ( column ) => (
							<th
								key={ column.id }
								scope="col"
								className={ cellClass( column ) }
								style={
									column.width
										? { width: column.width }
										: undefined
								}
							>
								{ column.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ loading && (
						<tr className="oc-table__loading">
							<td colSpan={ columns.length }>
								<Skeleton
									lines={ 3 }
									widths={ [ '60%', '80%', '40%' ] }
								/>
							</td>
						</tr>
					) }
					{ ! loading && rows.length === 0 && empty !== undefined && (
						<tr className="oc-table__empty">
							<td colSpan={ columns.length }>{ empty }</td>
						</tr>
					) }
					{ ! loading &&
						rows.map( ( row ) => (
							<tr key={ rowKey( row ) }>
								{ columns.map( ( column ) => (
									<td
										key={ column.id }
										className={ cellClass( column ) }
									>
										{ column.render( row ) }
									</td>
								) ) }
							</tr>
						) ) }
				</tbody>
			</table>
		</div>
	);
}
