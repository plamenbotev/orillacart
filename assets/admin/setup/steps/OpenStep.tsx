import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../../shared/api';
import {
	fetchOpening,
	fetchReadiness,
	finishSetup,
	openShop,
	type ReadinessReport,
	sendTestEmail,
} from '../api';
import type { StepProps } from './types';

/**
 * The check before opening: what stops the shop from selling, then what is advised, each with the screen that
 * puts it right; a test email; and opening the shop once nothing stops it.
 * @param root0
 * @param root0.fail
 */
export function OpenStep( { fail }: StepProps ) {
	const [ report, setReport ] = useState< ReadinessReport | null >( null );
	const [ opensSoon, setOpensSoon ] = useState< boolean | null >( null );
	const [ email, setEmail ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const check = () => {
		setReport( null );
		fetchReadiness().then( setReport ).catch( fail );
		fetchOpening()
			.then( ( opening ) => setOpensSoon( opening.opens_soon ) )
			.catch( fail );
	};
	useEffect( check, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( report === null || opensSoon === null ) {
		return <Spinner />;
	}
	const link = ( fix: string | null ) =>
		fix === null ? null : (
			<>
				{ ' ' }
				<a href={ `admin.php?page=orillacart-${ fix }` }>
					{ __( 'Put it right', 'orillacart' ) }
				</a>
			</>
		);

	return (
		<>
			{ report.stops.length > 0 ? (
				<>
					<h3>{ __( 'Before the shop can sell', 'orillacart' ) }</h3>
					<ul className="orillacart-setup__stops">
						{ report.stops.map( ( stop ) => (
							<li key={ stop.key }>
								{ stop.message }
								{ link( stop.fix ) }
							</li>
						) ) }
					</ul>
				</>
			) : (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'Nothing stops the shop from selling.',
						'orillacart'
					) }
				</Notice>
			) }
			{ report.advice.length > 0 && (
				<>
					<h3>{ __( 'Advised', 'orillacart' ) }</h3>
					<ul>
						{ report.advice.map( ( advice ) => (
							<li key={ advice.key }>
								{ advice.message }
								{ link( advice.fix ) }
							</li>
						) ) }
					</ul>
				</>
			) }
			<p>
				<Button variant="secondary" onClick={ check }>
					{ __( 'Check again', 'orillacart' ) }
				</Button>{ ' ' }
				<Button
					variant="secondary"
					onClick={ () =>
						sendTestEmail()
							.then( ( sent ) =>
								setEmail(
									sent.sent
										? sprintf(
												/* translators: %s: an email address */
												__(
													'A test order confirmation went to %s: see that it arrived, and not in spam.',
													'orillacart'
												),
												sent.to
											)
										: __(
												'The site could not send the test email: it needs a working mail setup, such as an SMTP plugin.',
												'orillacart'
											)
								)
							)
							.catch( ( error: unknown ) =>
								setEmail( errorMessage( error ) )
							)
					}
				>
					{ __( 'Send a test email', 'orillacart' ) }
				</Button>
			</p>
			{ email !== null && (
				<Notice status="info" isDismissible={ false }>
					{ email }
				</Notice>
			) }
			{ opensSoon ? (
				<>
					<p>
						{ __(
							'Visitors see “Opening soon”; you see the real shop. Place a test order before you open it.',
							'orillacart'
						) }
					</p>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! report.ready }
						onClick={ () => {
							setBusy( true );
							openShop()
								.then( finishSetup )
								.then( () => setOpensSoon( false ) )
								.catch( fail )
								.finally( () => setBusy( false ) );
						} }
					>
						{ __( 'Open the shop', 'orillacart' ) }
					</Button>
				</>
			) : (
				<>
					<Notice status="success" isDismissible={ false }>
						{ __( 'The shop is open.', 'orillacart' ) }
					</Notice>
					<p>
						<a
							className="button button-primary"
							href="admin.php?page=orillacart-orders"
						>
							{ __( 'Go to the orders', 'orillacart' ) }
						</a>{ ' ' }
						<a
							className="button"
							href="admin.php?page=orillacart-status"
						>
							{ __( 'The shop’s status', 'orillacart' ) }
						</a>
					</p>
				</>
			) }
		</>
	);
}
