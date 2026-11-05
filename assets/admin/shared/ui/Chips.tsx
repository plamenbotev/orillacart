/**
 * Quick views above a list: "All", "Needs attention", "Today". One is selected; choosing another changes the
 * list's filters.
 *
 * @param props
 * @param props.items    The views.
 * @param props.selected The selected one's id.
 * @param props.onSelect Receives the chosen one's id.
 * @param props.label    What the group is, for screen readers.
 */
export function Chips( {
	items,
	selected,
	onSelect,
	label,
}: {
	items: { id: string; label: string; count?: number }[];
	selected: string | null;
	onSelect: ( id: string ) => void;
	label: string;
} ) {
	return (
		<div className="oc-chips" role="group" aria-label={ label }>
			{ items.map( ( item ) => (
				<button
					key={ item.id }
					type="button"
					className="oc-chip"
					aria-pressed={ item.id === selected }
					onClick={ () => onSelect( item.id ) }
				>
					{ item.label }
					{ item.count !== undefined && (
						<span className="oc-chip__count">{ item.count }</span>
					) }
				</button>
			) ) }
		</div>
	);
}
