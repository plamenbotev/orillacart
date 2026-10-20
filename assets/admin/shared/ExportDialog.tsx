import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { toApiError } from './api';
import {
	chooseExportColumns,
	type ExportColumn,
	type ExportKind,
	exportUrl,
	fetchExportColumns,
} from './exports';

interface ExportDialogProps {
	kind: ExportKind;
	title: string;
	/** The list's filters, as the list asks the API with them. */
	query: Record< string, unknown >;
	/** csv, and json where the kind has it. */
	formats: { value: string; label: string }[];
	onClose: () => void;
	/** Opens the address: the browser downloads the file. */
	download?: ( url: string ) => void;
}

/**
 * Exports what a list shows, as filtered: the shop manager chooses the columns, the shop's own and those add-ons
 * bring, and their order, which the shop keeps for next time.
 *
 * @param props          The dialog's properties.
 * @param props.kind     What is exported.
 * @param props.title    The dialog's title.
 * @param props.query    The list's filters.
 * @param props.formats  The file formats offered.
 * @param props.onClose  Closes the dialog.
 * @param props.download Opens the file's address; the window's location by default.
 */
export function ExportDialog( {
	kind,
	title,
	query,
	formats,
	onClose,
	download = ( url ) => window.location.assign( url ),
}: ExportDialogProps ) {
	const [ columns, setColumns ] = useState< ExportColumn[] | null >( null );
	const [ format, setFormat ] = useState( formats[ 0 ]?.value ?? 'csv' );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchExportColumns( kind )
			.then( setColumns )
			.catch( ( reason: unknown ) =>
				setError( toApiError( reason ).message )
			);
	}, [ kind ] );

	const move = ( index: number, by: number ) => {
		if ( columns === null ) {
			return;
		}
		const next = [ ...columns ];
		const [ column ] = next.splice( index, 1 );
		if ( column ) {
			next.splice( index + by, 0, column );
		}
		setColumns( next );
	};
	const chosen = ( columns ?? [] ).filter( ( column ) => column.chosen );
	const start = () => {
		setBusy( true );
		chooseExportColumns(
			kind,
			chosen.map( ( column ) => column.id )
		)
			.then( () => {
				download( exportUrl( kind, format, query ) );
				onClose();
			} )
			.catch( ( reason: unknown ) =>
				setError( toApiError( reason ).message )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal title={ title } onRequestClose={ onClose } size="medium">
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ columns === null ? (
				<Spinner />
			) : (
				<ul className="orillacart-export-columns">
					{ columns.map( ( column, index ) => (
						<li key={ column.id }>
							<Flex>
								<FlexItem isBlock>
									<CheckboxControl
										label={
											column.add_on
												? `${ column.label } (${ __(
														'add-on',
														'orillacart'
													) })`
												: column.label
										}
										checked={ column.chosen }
										onChange={ ( on ) =>
											setColumns(
												columns.map( ( other ) =>
													other.id === column.id
														? {
																...other,
																chosen: on,
															}
														: other
												)
											)
										}
									/>
								</FlexItem>
								<Button
									icon="arrow-up-alt2"
									size="small"
									label={ __( 'Move up', 'orillacart' ) }
									disabled={ index === 0 }
									onClick={ () => move( index, -1 ) }
								/>
								<Button
									icon="arrow-down-alt2"
									size="small"
									label={ __( 'Move down', 'orillacart' ) }
									disabled={ index === columns.length - 1 }
									onClick={ () => move( index, 1 ) }
								/>
							</Flex>
						</li>
					) ) }
				</ul>
			) }
			{ formats.length > 1 && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Format', 'orillacart' ) }
					value={ format }
					options={ formats }
					onChange={ setFormat }
				/>
			) }
			<Flex justify="flex-end">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'orillacart' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ start }
					isBusy={ busy }
					disabled={ busy || chosen.length === 0 }
				>
					{ __( 'Export', 'orillacart' ) }
				</Button>
			</Flex>
		</Modal>
	);
}
