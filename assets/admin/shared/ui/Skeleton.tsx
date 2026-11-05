import { __ } from '@wordpress/i18n';

/**
 * The shape of what is loading: a few grey lines, so the screen does not jump when it comes.
 *
 * @param props
 * @param props.lines  How many lines; three by default.
 * @param props.widths The lines' widths, repeated; "100%" by default.
 */
export function Skeleton( {
	lines = 3,
	widths = [ '100%' ],
}: {
	lines?: number;
	widths?: string[];
} ) {
	return (
		<div
			className="oc-skeleton"
			role="status"
			aria-label={ __( 'Loading', 'orillacart' ) }
		>
			{ Array.from( { length: lines }, ( _, index ) => (
				<span
					key={ index }
					className="oc-skeleton__line"
					style={ { width: widths[ index % widths.length ] } }
				/>
			) ) }
		</div>
	);
}
