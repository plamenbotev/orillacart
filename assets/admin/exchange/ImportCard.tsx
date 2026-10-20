import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FormFileUpload,
	Notice,
	ProgressBar,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	discardImport,
	fetchImport,
	latestImport,
	type ProductImport,
	startImport,
	uploadImport,
} from './api';

/** How often a running import is looked at again, in milliseconds. */
const POLL = 2000;

/**
 * Imports a products file, as the export writes it: the shop manager uploads it, sees which columns it changes
 * and which it leaves, and starts it; the shop imports it in the background and the card shows how far it got,
 * then what came of each row it refused.
 *
 * @param props          The card's properties.
 * @param props.interval How often a running import is looked at again, in milliseconds.
 */
export function ImportCard( { interval = POLL }: { interval?: number } ) {
	const [ current, setCurrent ] = useState< ProductImport | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const fail = ( reason: unknown ) =>
		setError( toApiError( reason ).message );

	useEffect( () => {
		latestImport().then( setCurrent ).catch( fail );
	}, [] );
	useEffect( () => {
		if ( current?.status !== 'running' ) {
			return undefined;
		}
		const timer = window.setTimeout(
			() => fetchImport( current.id ).then( setCurrent ).catch( fail ),
			interval
		);

		return () => window.clearTimeout( timer );
	}, [ current, interval ] );

	const run = ( work: () => Promise< unknown > ) => {
		setBusy( true );
		setError( null );
		work()
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Import products', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ error !== null && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				<p>
					{ __(
						'A CSV file as the export writes it. A row finds its product by variation_id, id or SKU and changes only the file’s columns; an empty cell clears what may be empty. A row that finds nothing adds a product. Variations are made on the product’s screen; rows change them.',
						'orillacart'
					) }
				</p>
				{ current?.status === 'ready' && (
					<>
						<p>
							{ sprintf(
								/* translators: 1: file name, 2: number of rows */
								_n(
									'%1$s has %2$d row. It changes:',
									'%1$s has %2$d rows. It changes:',
									current.rows,
									'orillacart'
								),
								current.filename,
								current.rows
							) }
						</p>
						<ul className="orillacart-import-columns">
							{ current.columns.map( ( column ) => (
								<li key={ column.id }>
									{ column.label }
									{ column.importable
										? ''
										: ` (${ __( 'finds the row', 'orillacart' ) })` }
								</li>
							) ) }
						</ul>
						{ current.ignored.length > 0 && (
							<p>
								{ sprintf(
									/* translators: %s: column names */
									__( 'Left as they are: %s.', 'orillacart' ),
									current.ignored.join( ', ' )
								) }
							</p>
						) }
						<Flex justify="flex-start">
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy }
								onClick={ () =>
									run( () =>
										startImport( current.id ).then(
											setCurrent
										)
									)
								}
							>
								{ __( 'Start the import', 'orillacart' ) }
							</Button>
							<Button
								variant="tertiary"
								disabled={ busy }
								onClick={ () =>
									run( () =>
										discardImport( current.id ).then( () =>
											setCurrent( null )
										)
									)
								}
							>
								{ __( 'Choose another file', 'orillacart' ) }
							</Button>
						</Flex>
					</>
				) }
				{ current?.status === 'running' && (
					<>
						<p>
							{ sprintf(
								/* translators: 1: rows imported, 2: rows in all */
								__(
									'Importing: %1$d of %2$d rows.',
									'orillacart'
								),
								current.done,
								current.rows
							) }
						</p>
						<ProgressBar
							value={
								current.rows === 0
									? 0
									: ( 100 * current.done ) / current.rows
							}
						/>
					</>
				) }
				{ current?.status === 'done' && (
					<>
						<Notice
							status={
								current.failed > 0 ? 'warning' : 'success'
							}
							isDismissible={ false }
						>
							{ sprintf(
								/* translators: 1: file name, 2: products added, 3: rows updated, 4: rows refused */
								__(
									'%1$s is imported: %2$d added, %3$d updated, %4$d refused.',
									'orillacart'
								),
								current.filename,
								current.created,
								current.updated,
								current.failed
							) }
						</Notice>
						{ current.errors.length > 0 && (
							<ul className="orillacart-import-errors">
								{ current.errors.map( ( message, index ) => (
									<li key={ index }>{ message }</li>
								) ) }
							</ul>
						) }
					</>
				) }
				{ current?.status !== 'ready' &&
					current?.status !== 'running' && (
						<FormFileUpload
							accept=".csv,text/csv"
							disabled={ busy }
							onChange={ ( event ) => {
								const file = event.currentTarget.files?.[ 0 ];
								if ( file !== undefined ) {
									run( () =>
										uploadImport( file ).then( setCurrent )
									);
								}
							} }
						>
							{ busy
								? __( 'Reading the file…', 'orillacart' )
								: __( 'Choose a products file', 'orillacart' ) }
						</FormFileUpload>
					) }
			</CardBody>
		</Card>
	);
}
