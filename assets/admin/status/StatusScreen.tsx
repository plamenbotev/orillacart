import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import { type Check, fetchStatus, reportText, type Status } from './api';
import { ChangeLogCard } from './ChangeLogCard';
import { ProblemsCard } from './ProblemsCard';

const LABELS: Record< Check[ 'status' ], string > = {
	good: __( 'Good', 'orillacart' ),
	recommended: __( 'Worth a look', 'orillacart' ),
	critical: __( 'Needs fixing', 'orillacart' ),
};

/**
 * Shop → Status: the shop's checks (as Site Health and `wp orillacart doctor` run them), then what the shop,
 * WordPress and the server are, with a report to copy into a support request, and the changes shop managers made.
 */
export function StatusScreen() {
	const [ status, setStatus ] = useState< Status | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		fetchStatus()
			.then( setStatus )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const copy = () => {
		if ( status === null ) {
			return;
		}
		void navigator.clipboard
			?.writeText( reportText( status ) )
			.then( () => setCopied( true ) );
	};

	return (
		<>
			<h1>{ __( 'Status', 'orillacart' ) }</h1>
			{ error !== null && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ status === null && error === null && <Spinner /> }
			{ status !== null && (
				<>
					<Card className="orillacart-card">
						<CardHeader>
							<h2>{ __( 'Checks', 'orillacart' ) }</h2>
							<Button variant="secondary" onClick={ copy }>
								{ copied
									? __( 'Copied', 'orillacart' )
									: __(
											'Copy the report for support',
											'orillacart'
										) }
							</Button>
						</CardHeader>
						<CardBody>
							<ul className="orillacart-checks">
								{ status.checks.map( ( check ) => (
									<li
										key={ check.id }
										className={ `orillacart-check orillacart-check--${ check.status }` }
									>
										<span className="orillacart-check__status">
											{ LABELS[ check.status ] }
										</span>
										<strong>{ check.finding }</strong>
										<small>{ check.check }</small>
										{ check.description !== '' && (
											<p>{ check.description }</p>
										) }
										{ check.action_url !== '' && (
											<a href={ check.action_url }>
												{ check.action_label }
											</a>
										) }
									</li>
								) ) }
							</ul>
						</CardBody>
					</Card>
					{ status.sections.map( ( section ) => (
						<Card key={ section.title } className="orillacart-card">
							<CardHeader>
								<h2>{ section.title }</h2>
							</CardHeader>
							<CardBody>
								<table className="widefat striped">
									<tbody>
										{ section.fields.map( ( field ) => (
											<tr key={ field.label }>
												<th scope="row">
													{ field.label }
												</th>
												<td>{ field.value }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</CardBody>
						</Card>
					) ) }
					<ProblemsCard />
					<ChangeLogCard />
				</>
			) }
		</>
	);
}
