import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { DragEvent, ReactNode } from 'react';

import './sortable.css';

/**
 * Moves the item at `from` to `to`, returning a new list.
 * @param items
 * @param from
 * @param to
 */
export function move< T >( items: T[], from: number, to: number ): T[] {
	if ( from === to || to < 0 || to >= items.length ) {
		return items;
	}
	const next = [ ...items ];
	const [ item ] = next.splice( from, 1 );
	next.splice( to, 0, item as T );
	return next;
}

interface Props {
	/** Items of one group move among themselves only: "attribute", or "option-<attribute index>". */
	group: string;
	index: number;
	count: number;
	title: string;
	/** Saved items start closed, new ones open. */
	initiallyOpen: boolean;
	onMove: ( from: number, to: number ) => void;
	/** Header controls after the title, such as a remove button. */
	actions?: ReactNode;
	children: ReactNode;
	className?: string;
}

/**
 * One item of a list the admin reorders and folds: dragged by its handle, or moved with the up and down
 * buttons (keyboards and screen readers); its body opens and closes from the header.
 *
 * @param props The item's properties.
 */
export function SortableItem( props: Props ) {
	const {
		group,
		index,
		count,
		title,
		initiallyOpen,
		onMove,
		actions,
		children,
		className,
	} = props;
	const [ open, setOpen ] = useState( initiallyOpen );
	// Only the handle starts a drag, so text in the fields can still be selected.
	const [ draggable, setDraggable ] = useState( false );
	const [ over, setOver ] = useState( false );
	const type = `application/x-orillacart-${ group }`;

	const onDragStart = ( event: DragEvent ) => {
		event.stopPropagation();
		event.dataTransfer.setData( type, String( index ) );
		event.dataTransfer.effectAllowed = 'move';
	};
	const accepts = ( event: DragEvent ) =>
		event.dataTransfer.types.includes( type );
	const onDragOver = ( event: DragEvent ) => {
		if ( accepts( event ) ) {
			event.preventDefault();
			event.stopPropagation();
			setOver( true );
		}
	};
	const onDrop = ( event: DragEvent ) => {
		setOver( false );
		if ( ! accepts( event ) ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		onMove( Number( event.dataTransfer.getData( type ) ), index );
	};

	const label = title || __( '(no name)', 'orillacart' );

	return (
		<div
			className={ [
				'orillacart-sortable',
				className,
				over ? 'is-drop-target' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			draggable={ draggable }
			onDragStart={ onDragStart }
			onDragEnd={ () => setDraggable( false ) }
			onDragOver={ onDragOver }
			onDragLeave={ () => setOver( false ) }
			onDrop={ onDrop }
		>
			<div className="orillacart-sortable__header">
				<span
					className="orillacart-sortable__handle"
					aria-hidden="true"
					title={ __( 'Drag to reorder', 'orillacart' ) }
					onMouseDown={ () => setDraggable( true ) }
					onMouseUp={ () => setDraggable( false ) }
				>
					⠿
				</span>
				<Button
					className="orillacart-sortable__toggle"
					variant="link"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					<span aria-hidden="true">{ open ? '▾' : '▸' }</span>{ ' ' }
					{ label }
				</Button>
				<Button
					size="small"
					icon="arrow-up-alt2"
					label={ sprintf(
						/* translators: %s: name */ __(
							'Move %s up',
							'orillacart'
						),
						label
					) }
					disabled={ index === 0 }
					onClick={ () => onMove( index, index - 1 ) }
				/>
				<Button
					size="small"
					icon="arrow-down-alt2"
					label={ sprintf(
						/* translators: %s: name */ __(
							'Move %s down',
							'orillacart'
						),
						label
					) }
					disabled={ index === count - 1 }
					onClick={ () => onMove( index, index + 1 ) }
				/>
				{ actions }
			</div>
			{ open && (
				<div className="orillacart-sortable__body">{ children }</div>
			) }
		</div>
	);
}
