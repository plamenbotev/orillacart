import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../../shared/api';
import {
	applyPlan,
	fetchPlan,
	type Plan,
	saveAnswers,
	type SetupResult,
} from '../api';
import { answered, flag, type StepProps } from './types';

const STEP_NAMES: Record< string, string > = {
	opening: __( 'Opening', 'orillacart' ),
	business: __( 'Your business', 'orillacart' ),
	selling: __( 'Customers', 'orillacart' ),
	tax: __( 'Tax', 'orillacart' ),
	shipping: __( 'Shipping', 'orillacart' ),
	payments: __( 'Payments', 'orillacart' ),
	legal: __( 'Legal', 'orillacart' ),
	emails: __( 'Emails', 'orillacart' ),
};

/**
 * What the answers would change, in words, before anything is saved; then applying it, keeping what there is
 * unless the owner chooses to replace it.
 * @param root0
 * @param root0.answers
 * @param root0.next
 * @param root0.fail
 */
export function ReviewStep( { answers, next, fail }: StepProps ) {
	const [ soon, setSoon ] = useState(
		flag( answered( answers, 'opening' ), 'opens_soon', true )
	);
	const [ plan, setPlan ] = useState< Plan | null >( null );
	const [ replace, setReplace ] = useState< string[] >( [] );
	const [ result, setResult ] = useState< SetupResult | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = ( opensSoon: boolean ) => {
		setPlan( null );
		saveAnswers( 'opening', { opens_soon: opensSoon } )
			.then( fetchPlan )
			.then( setPlan )
			.catch( fail );
	};
	useEffect( () => {
		load( soon );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( plan === null ) {
		return <Spinner />;
	}
	const steps = Array.from(
		new Set( plan.changes.map( ( change ) => change.step ) )
	);
	const warnings = plan.changes.filter(
		( change ) => change.warning !== null
	).length;

	return (
		<>
			<CheckboxControl
				label={ __(
					'Visitors see “Opening soon” until I open the shop',
					'orillacart'
				) }
				checked={ soon }
				onChange={ ( on ) => {
					setSoon( on );
					load( on );
				} }
			/>
			{ plan.changes.length === 0 ? (
				<p>
					{ __(
						'Nothing to change: the shop is as the answers say.',
						'orillacart'
					) }
				</p>
			) : (
				<p>
					{ warnings > 0
						? sprintf(
								/* translators: %d: how many changes need a look */
								__(
									'Nothing is saved yet. Look at what the setup would do; %d of them need a look.',
									'orillacart'
								),
								warnings
							)
						: __(
								'Nothing is saved yet. Look at what the setup would do.',
								'orillacart'
							) }
				</p>
			) }
			{ steps.map( ( step ) => (
				<section key={ step } className="orillacart-setup__plan">
					<h3>{ STEP_NAMES[ step ] ?? step }</h3>
					<ul>
						{ plan.changes
							.filter( ( change ) => change.step === step )
							.map( ( change ) => (
								<li key={ change.key }>
									<p>{ change.summary }</p>
									{ change.details.length > 0 && (
										<ul>
											{ change.details.map(
												( detail ) => (
													<li key={ detail }>
														{ detail }
													</li>
												)
											) }
										</ul>
									) }
									{ change.warning !== null && (
										<Notice
											status="warning"
											isDismissible={ false }
										>
											{ change.warning }
										</Notice>
									) }
									{ change.existing !== null && (
										<CheckboxControl
											label={ sprintf(
												/* translators: %s: what the shop has now */
												__(
													'%s Replace it.',
													'orillacart'
												),
												change.existing
											) }
											checked={ replace.includes(
												change.key
											) }
											onChange={ ( on ) =>
												setReplace( ( current ) =>
													on
														? [
																...current,
																change.key,
															]
														: current.filter(
																( key ) =>
																	key !==
																	change.key
															)
												)
											}
										/>
									) }
								</li>
							) ) }
					</ul>
				</section>
			) ) }
			{ result?.refused && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: why the change was refused */
						__(
							'The setup stopped at a change it could not make: %s Put it right, then apply again: what is done stays done.',
							'orillacart'
						),
						result.refused.reason
					) }
				</Notice>
			) }
			<Button
				variant="primary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => {
					if ( plan.changes.length === 0 ) {
						next();
						return;
					}
					setBusy( true );
					applyPlan( plan.fingerprint, replace )
						.then( ( applied ) => {
							setResult( applied );
							if ( applied.refused === null ) {
								next();
							}
						} )
						.catch( ( error: unknown ) => {
							// A plan the shop moved on from: show the new one.
							fail( errorMessage( error ) );
							load( soon );
						} )
						.finally( () => setBusy( false ) );
				} }
			>
				{ plan.changes.length === 0
					? __( 'Go on', 'orillacart' )
					: __( 'Apply the setup', 'orillacart' ) }
			</Button>
		</>
	);
}
