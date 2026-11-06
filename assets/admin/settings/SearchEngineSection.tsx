import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import {
	changeSearchEngine,
	type Engine,
	fetchSearchEngine,
	rebuildSearchIndex,
	type SearchEngine,
} from './search';
import { Field, notify, Section, Skeleton } from '../shared/ui';

/**
 * Which engine the shop page searches with, and how far behind its index is. OpenSearch is checked before the
 * shop switches to it, so the button checks and switches rather than saves; the database answers until the index
 * is built.
 */
export function SearchEngineSection() {
	const [ state, setState ] = useState< SearchEngine | null >( null );
	const [ engine, setEngine ] = useState< Engine >( 'database' );
	const [ host, setHost ] = useState( '' );
	const [ username, setUsername ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ clearPassword, setClearPassword ] = useState( false );
	const [ index, setIndex ] = useState( 'orillacart' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const show = ( loaded: SearchEngine ) => {
		setState( loaded );
		setEngine( loaded.engine );
		setHost( loaded.host );
		setUsername( loaded.username );
		setIndex( loaded.index );
		setPassword( '' );
	};
	useEffect( () => {
		fetchSearchEngine()
			.then( show )
			.catch( ( reason: unknown ) => setError( errorMessage( reason ) ) );
	}, [] );
	const run = ( work: Promise< SearchEngine >, done: string ) => {
		setBusy( true );
		setError( null );
		work.then( ( result ) => {
			show( result );
			if ( result.works !== false ) {
				notify( done );
			}
		} )
			.catch( ( reason: unknown ) => setError( errorMessage( reason ) ) )
			.finally( () => setBusy( false ) );
	};
	const parked = Object.entries( state?.parked ?? {} );

	return (
		<Section
			title={ __( 'Search', 'orillacart' ) }
			description={ __(
				'What answers the shop page’s search box. The database needs no setup; OpenSearch forgives typos and suits big catalogs.',
				'orillacart'
			) }
			footer={
				state !== null && (
					<>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () =>
								run(
									changeSearchEngine( {
										engine,
										host,
										username,
										password,
										index,
										clear_password: clearPassword,
									} ),
									__( 'Search settings saved.', 'orillacart' )
								)
							}
						>
							{ engine === 'opensearch'
								? __( 'Check and switch', 'orillacart' )
								: __( 'Save', 'orillacart' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () =>
								run(
									rebuildSearchIndex(),
									__(
										'The index is being rebuilt.',
										'orillacart'
									)
								)
							}
						>
							{ __( 'Rebuild the index', 'orillacart' ) }
						</Button>
					</>
				)
			}
		>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ state === null ? (
				! error && (
					<Skeleton lines={ 3 } widths={ [ '60%', '90%', '40%' ] } />
				)
			) : (
				<>
					{ state.works !== null && state.message !== '' && (
						<Notice
							status={ state.works ? 'success' : 'error' }
							isDismissible={ false }
						>
							{ state.message }
						</Notice>
					) }
					<Field size="l">
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Search with', 'orillacart' ) }
							value={ engine }
							options={ [
								{
									value: 'database',
									label: __(
										'The shop’s database (no setup)',
										'orillacart'
									),
								},
								{
									value: 'opensearch',
									label: __(
										'OpenSearch (typos forgiven, for big catalogs)',
										'orillacart'
									),
								},
							] }
							onChange={ ( value ) =>
								setEngine( value as Engine )
							}
						/>
					</Field>
					{ engine === 'opensearch' && (
						<>
							<Field size="l">
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Address', 'orillacart' ) }
									help={ __(
										'Such as https://search.example.com:9200',
										'orillacart'
									) }
									value={ host }
									onChange={ setHost }
								/>
							</Field>
							<Field size="m">
								<TextControl
									__next40pxDefaultSize
									label={ __( 'User name', 'orillacart' ) }
									value={ username }
									onChange={ setUsername }
								/>
							</Field>
							<Field size="m">
								<TextControl
									__next40pxDefaultSize
									type="password"
									autoComplete="off"
									label={ __( 'Password', 'orillacart' ) }
									help={
										state.password_set
											? __(
													'Saved. Leave empty to keep it.',
													'orillacart'
												)
											: ''
									}
									disabled={ clearPassword }
									value={ clearPassword ? '' : password }
									onChange={ setPassword }
								/>
							</Field>
							{ state.password_set && (
								<CheckboxControl
									label={ __(
										'Clear the saved one',
										'orillacart'
									) }
									checked={ clearPassword }
									onChange={ setClearPassword }
								/>
							) }
							<Field size="s">
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Index', 'orillacart' ) }
									value={ index }
									onChange={ setIndex }
								/>
							</Field>
						</>
					) }
					<p className="oc-muted">
						{ state.engine === 'opensearch' && ! state.ready
							? __(
									'OpenSearch’s index is being built; the shop searches its database until it is.',
									'orillacart'
								)
							: sprintf(
									/* translators: 1: products to index now, 2: products waiting for a sale to start or end */
									__(
										'%1$d products are waiting to be indexed; %2$d wait for a sale to start or end.',
										'orillacart'
									),
									state.due,
									state.later
								) }{ ' ' }
						{ state.workers > 0
							? sprintf(
									/* translators: %d: number of workers */
									__(
										'%d workers build the index as products change.',
										'orillacart'
									),
									state.workers
								)
							: __(
									'WordPress builds the index after each change and every minute. For a busy shop, run workers: wp orillacart jobs work --loop.',
									'orillacart'
								) }{ ' ' }
						<a href="admin.php?page=orillacart-jobs">
							{ __( 'See the background jobs', 'orillacart' ) }
						</a>
					</p>
					{ parked.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'These products could not be indexed; they are tried again when they change, or when you retry them under Background jobs:',
								'orillacart'
							) }
							<ul>
								{ parked.map( ( [ product, reason ] ) => (
									<li key={ product }>
										#{ product }: { reason }
									</li>
								) ) }
							</ul>
						</Notice>
					) }
				</>
			) }
		</Section>
	);
}
