import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { shopDateTime } from '../shared/dates';
import { errorMessage } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import { clearProblems, fetchProblems, type ProblemPage } from './api';

/**
 * What went wrong that the shop should hear of: a payment or refund it could not record, an email that did not
 * go, a courier or add-on that failed, settings or credentials it could not read. The same problem once, with how
 * often it happened; kept a month.
 */
export function ProblemsCard() {
	const [ page, setPage ] = useState( 1 );
	const [ problems, setProblems ] = useState< ProblemPage | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ confirmation, confirm ] = useConfirm();
	const load = () =>
		fetchProblems( page )
			.then( setProblems )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);

	useEffect( () => {
		void load();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- loads again for each page
	}, [ page ] );

	const pages =
		problems === null
			? 1
			: Math.max( 1, Math.ceil( problems.total / problems.per_page ) );

	return (
		<Card className="orillacart-card">
			{ confirmation }
			<CardHeader>
				<h2>{ __( 'Problems', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'What went wrong that the shop should hear of, such as a payment or refund it could not record, an email that did not go, or a courier or add-on that failed. Each says what to do. Kept for a month.',
						'orillacart'
					) }
				</p>
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ problems !== null && problems.problems.length === 0 && (
					<p>{ __( 'No problems.', 'orillacart' ) }</p>
				) }
				{ problems !== null && problems.problems.length > 0 && (
					<>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Last seen', 'orillacart' ) }</th>
									<th>{ __( 'What', 'orillacart' ) }</th>
									<th>{ __( 'Times', 'orillacart' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ problems.problems.map( ( problem ) => (
									<tr key={ problem.id }>
										<td>
											{ shopDateTime( problem.last_at ) }
										</td>
										<td>
											{ problem.level !== 'warning' && (
												<strong>
													{ __(
														'Error:',
														'orillacart'
													) }{ ' ' }
												</strong>
											) }
											{ problem.message }
										</td>
										<td>{ problem.count }</td>
									</tr>
								) ) }
							</tbody>
						</table>
						<p>
							{ pages > 1 && (
								<>
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
										page,
										pages
									) }{ ' ' }
									<Button
										variant="secondary"
										disabled={ page >= pages }
										onClick={ () => setPage( page + 1 ) }
									>
										{ __( 'Older', 'orillacart' ) }
									</Button>{ ' ' }
								</>
							) }
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									confirm(
										__(
											'Clear the list? Problems that happen again come back.',
											'orillacart'
										),
										() =>
											void clearProblems()
												.then( () => {
													setPage( 1 );
													return load();
												} )
												.catch( ( failure: unknown ) =>
													setError(
														errorMessage( failure )
													)
												),
										__( 'Clear', 'orillacart' )
									)
								}
							>
								{ __( 'Clear the list', 'orillacart' ) }
							</Button>
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}
