import {
	Button,
	CheckboxControl,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	changeSearchEngine,
	type Engine,
	fetchSearchEngine,
	rebuildSearchIndex,
	type SearchEngine,
} from './search';

/**
 * Which engine the shop page searches with, and how far behind its index is. OpenSearch is checked before the
 * shop switches to it; the database answers until its index is built.
 */
export function SearchEngineCard() {
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
			.catch( ( reason: unknown ) =>
				setError( toApiError( reason ).message )
			);
	}, [] );
	const run = ( work: Promise< SearchEngine > ) => {
		setBusy( true );
		setError( null );
		work.then( show )
			.catch( ( reason: unknown ) =>
				setError( toApiError( reason ).message )
			)
			.finally( () => setBusy( false ) );
	};
	const parked = Object.entries( state?.parked ?? {} );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Search', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ error !== null && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				{ state === null ? (
					! error && <Spinner />
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
						{ engine === 'opensearch' && (
							<>
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
								<TextControl
									__next40pxDefaultSize
									label={ __( 'User name', 'orillacart' ) }
									value={ username }
									onChange={ setUsername }
								/>
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
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Index', 'orillacart' ) }
									value={ index }
									onChange={ setIndex }
								/>
							</>
						) }
						<p>
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
									) }
						</p>
						<p>
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
								{ __(
									'See the background jobs',
									'orillacart'
								) }
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
						<Flex justify="flex-start">
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
										} )
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
								onClick={ () => run( rebuildSearchIndex() ) }
							>
								{ __( 'Rebuild the index', 'orillacart' ) }
							</Button>
						</Flex>
					</>
				) }
			</CardBody>
		</Card>
	);
}
