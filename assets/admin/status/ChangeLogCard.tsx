import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { shopDateTime } from '../shared/dates';
import { errorMessage } from '../shared/api';
import { type ChangeLogPage, type ChangeRecord, fetchChangeLog } from './api';

/**
 * How a change came, in words: through the AI assistant or an API key, and which key.
 *
 * @param via     The channel the record names.
 * @param keyName The key's name, when the record has it.
 */
function viaWords(
	via: NonNullable< ChangeRecord[ 'via' ] >,
	keyName: string | null
): string {
	if ( via === 'assistant' ) {
		return keyName
			? sprintf(
					/* translators: %s: the name of an API key */
					__( 'through the AI assistant, key "%s"', 'orillacart' ),
					keyName
				)
			: __( 'through the AI assistant', 'orillacart' );
	}

	return keyName
		? sprintf(
				/* translators: %s: the name of an API key */
				__( 'with the API key "%s"', 'orillacart' ),
				keyName
			)
		: __( 'with an API key', 'orillacart' );
}

/**
 * The changes shop managers made through the shop's screens and API, newest first, with what they sent
 * (secrets blanked out); kept for a year.
 */
export function ChangeLogCard() {
	const [ area, setArea ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ log, setLog ] = useState< ChangeLogPage | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let current = true;
		fetchChangeLog( area, page )
			.then( ( loaded ) => current && setLog( loaded ) )
			.catch(
				( failure: unknown ) =>
					current && setError( errorMessage( failure ) )
			);
		return () => {
			current = false;
		};
	}, [ area, page ] );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Changes', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'What shop managers changed in the shop’s screens and through its API, with what they sent; passwords and keys are not kept. Kept for a year.',
						'orillacart'
					) }
				</p>
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ log !== null && (
					<>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Of', 'orillacart' ) }
							value={ area }
							options={ [
								{
									value: '',
									label: __( 'Everything', 'orillacart' ),
								},
								...log.areas.map( ( name ) => ( {
									value: name,
									label: name,
								} ) ),
							] }
							onChange={ ( value ) => {
								setArea( value );
								setPage( 1 );
							} }
						/>
						{ log.records.length === 0 ? (
							<p>{ __( 'No changes yet.', 'orillacart' ) }</p>
						) : (
							<table className="widefat striped">
								<thead>
									<tr>
										<th>{ __( 'When', 'orillacart' ) }</th>
										<th>{ __( 'Who', 'orillacart' ) }</th>
										<th>{ __( 'What', 'orillacart' ) }</th>
										<th>{ __( 'Sent', 'orillacart' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ log.records.map( ( record ) => (
										<tr key={ record.id }>
											<td>
												{ shopDateTime(
													record.made_at
												) }
											</td>
											<td>
												{ record.user ||
													( record.user_id
														? `#${ record.user_id }`
														: '—' ) }
												{ record.via && (
													<>
														<br />
														<small>
															{ viaWords(
																record.via,
																record.key_name
															) }
														</small>
													</>
												) }
											</td>
											<td>
												<code>{ record.route }</code>
												<br />
												<small>{ record.path }</small>
											</td>
											<td>
												<details>
													<summary>
														{ __(
															'Show',
															'orillacart'
														) }
													</summary>
													<pre className="orillacart-change__values">
														{ JSON.stringify(
															record.values,
															null,
															2
														) }
													</pre>
												</details>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
						{ log.pages > 1 && (
							<p>
								<Button
									variant="secondary"
									disabled={ page <= 1 }
									onClick={ () => setPage( page - 1 ) }
								>
									{ __( 'Newer', 'orillacart' ) }
								</Button>{ ' ' }
								{ sprintf(
									/* translators: 1: the page, 2: how many pages */
									__( 'Page %1$d of %2$d', 'orillacart' ),
									log.page,
									log.pages
								) }{ ' ' }
								<Button
									variant="secondary"
									disabled={ page >= log.pages }
									onClick={ () => setPage( page + 1 ) }
								>
									{ __( 'Older', 'orillacart' ) }
								</Button>
							</p>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}
