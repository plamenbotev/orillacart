import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
} from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useConfirm } from '../shared/useConfirm';
import { errorMessage } from '../shared/api';
import { shopClock } from '../shared/dates';
import {
	type Choice,
	deleteFailedJobs,
	type FailedJob,
	type FailedJobPage,
	fetchFailedJobs,
	fetchOverview,
	type JobCounts,
	type Overview,
	restartWorkers,
	retryFailedJobs,
} from './api';
import { laneLabel, typeLabel } from './labels';
import './jobs.css';
import { useLatest } from '../shared/useLatest';

type Message = { status: 'success' | 'error'; text: string } | null;

/** How often the screen asks again, in milliseconds. */
const REFRESH = 5000;

/**
 * How long ago a moment was, or how long until it, in words.
 *
 * @param at  The moment, ISO 8601.
 * @param now The server's now, ISO 8601, so a wrong clock in the browser does not matter.
 */
/**
 * A span of time in its largest whole unit, such as "3 minutes".
 *
 * @param span The span in seconds.
 */
function spanWords( span: number ): string {
	if ( span < 60 ) {
		return sprintf(
			/* translators: %d: seconds */
			_n( '%d second', '%d seconds', span, 'orillacart' ),
			span
		);
	}
	if ( span < 3600 ) {
		const minutes = Math.floor( span / 60 );
		return sprintf(
			/* translators: %d: minutes */
			_n( '%d minute', '%d minutes', minutes, 'orillacart' ),
			minutes
		);
	}
	if ( span < 86400 ) {
		const hours = Math.floor( span / 3600 );
		return sprintf(
			/* translators: %d: hours */
			_n( '%d hour', '%d hours', hours, 'orillacart' ),
			hours
		);
	}
	const days = Math.floor( span / 86400 );
	/* translators: %d: days */
	return sprintf( _n( '%d day', '%d days', days, 'orillacart' ), days );
}

export function since( at: string, now: string ): string {
	const seconds = Math.round(
		( new Date( now ).getTime() - new Date( at ).getTime() ) / 1000
	);
	const span = Math.abs( seconds );
	const words = spanWords( span );

	return seconds >= 0
		? /* translators: %s: a span of time, such as "3 minutes" */
			sprintf( __( '%s ago', 'orillacart' ), words )
		: /* translators: %s: a span of time, such as "3 minutes" */
			sprintf( __( 'in %s', 'orillacart' ), words );
}

const megabytes = ( bytes: number ) =>
	sprintf(
		/* translators: %d: megabytes */
		__( '%d MB', 'orillacart' ),
		Math.round( bytes / 1048576 )
	);

/**
 * The Shop → Background jobs screen: what waits and runs in each lane, the workers, the jobs that failed, what is
 * scheduled ahead and what the jobs did lately. It refreshes itself every few seconds.
 */
export function JobsScreen() {
	const [ overview, setOverview ] = useState< Overview | null >( null );
	const [ message, setMessage ] = useState< Message >( null );
	const [ failedChanged, setFailedChanged ] = useState( 0 );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( error ) } );

	const latestOverview = useLatest();
	const load = useCallback( () => {
		latestOverview( fetchOverview() ).then( setOverview ).catch( fail );
	}, [ latestOverview ] );
	useEffect( () => {
		load();
		const timer = window.setInterval( load, REFRESH );
		return () => window.clearInterval( timer );
	}, [ load ] );

	const done = ( text: string ) => {
		setMessage( { status: 'success', text } );
		setFailedChanged( ( count ) => count + 1 );
		load();
	};

	return (
		<>
			<h1>{ __( 'Background jobs', 'orillacart' ) }</h1>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			{ overview !== null && (
				<>
					<Health overview={ overview } />
					<LanesCard overview={ overview } />
					<WorkersCard
						overview={ overview }
						onRestart={ () =>
							restartWorkers()
								.then( ( fresh ) => {
									setOverview( fresh );
									setMessage( {
										status: 'success',
										text: __(
											'The workers stop after their batch; their supervisor starts them again.',
											'orillacart'
										),
									} );
								} )
								.catch( fail )
						}
					/>
					<FailedCard
						overview={ overview }
						changed={ failedChanged }
						onDone={ done }
						onError={ fail }
					/>
					<HistoryCard overview={ overview } />
					<ScheduledCard overview={ overview } />
				</>
			) }
		</>
	);
}

function Health( { overview }: { overview: Overview } ) {
	if ( overview.runner === 'none' && overview.cron_disabled ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'WP-Cron is turned off on this site (DISABLE_WP_CRON) and no worker is running, so nothing runs the jobs. Have the server run "wp cron event run --due-now" every minute, or run workers (wp orillacart jobs work --loop).',
					'orillacart'
				) }
			</Notice>
		);
	}
	if ( overview.runner === 'none' ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ overview.cron_ran_at === null
					? __(
							'Nothing has run the jobs yet: no worker is running and WP-Cron has not run. Run workers (wp orillacart jobs work --loop), or make sure WP-Cron runs every minute.',
							'orillacart'
						)
					: sprintf(
							/* translators: %s: how long ago, such as "12 minutes ago" */
							__(
								'Nothing runs the jobs: no worker is running and WP-Cron last ran %s. Run workers (wp orillacart jobs work --loop), or make sure WP-Cron runs every minute.',
								'orillacart'
							),
							since( overview.cron_ran_at, overview.now )
						) }
			</Notice>
		);
	}
	if ( overview.runner === 'cron' ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'WordPress runs the jobs after each request and every minute. For a busy shop, run workers: wp orillacart jobs work --loop.',
					'orillacart'
				) }
			</Notice>
		);
	}
	return null;
}

function LanesCard( { overview }: { overview: Overview } ) {
	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Now', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<table className="widefat striped orillacart-jobs__table">
					<thead>
						<tr>
							<th>{ __( 'Lane', 'orillacart' ) }</th>
							<th>{ __( 'Waiting', 'orillacart' ) }</th>
							<th>{ __( 'Running', 'orillacart' ) }</th>
							<th>{ __( 'Scheduled', 'orillacart' ) }</th>
							<th>{ __( 'Longest wait', 'orillacart' ) }</th>
							<th>{ __( 'Workers', 'orillacart' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ overview.lanes.map( ( lane ) => (
							<tr key={ lane.queue }>
								<td>
									<strong>{ laneLabel( lane.queue ) }</strong>
									<br />
									<span className="orillacart-jobs__muted">
										{ lane.types
											.map( typeLabel )
											.join( ', ' ) }
									</span>
								</td>
								<td>{ lane.due }</td>
								<td>{ lane.running }</td>
								<td>{ lane.later }</td>
								<td>
									{ lane.oldest_at === null
										? '—'
										: since(
												lane.oldest_at,
												overview.now
											) }
								</td>
								<td>{ lane.workers }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</CardBody>
		</Card>
	);
}

function WorkersCard( {
	overview,
	onRestart,
}: {
	overview: Overview;
	onRestart: () => void;
} ) {
	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Workers', 'orillacart' ) }</h2>
				{ overview.workers.length > 0 && (
					<Button variant="secondary" onClick={ onRestart }>
						{ __( 'Restart workers', 'orillacart' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				{ overview.workers.length === 0 ? (
					<p>
						{ overview.cron_ran_at === null
							? __( 'No worker is running.', 'orillacart' )
							: sprintf(
									/* translators: %s: how long ago */
									__(
										'No worker is running. WP-Cron last ran the jobs %s.',
										'orillacart'
									),
									since( overview.cron_ran_at, overview.now )
								) }
					</p>
				) : (
					<table className="widefat striped orillacart-jobs__table">
						<thead>
							<tr>
								<th>{ __( 'Worker', 'orillacart' ) }</th>
								<th>{ __( 'Lanes', 'orillacart' ) }</th>
								<th>{ __( 'Now on', 'orillacart' ) }</th>
								<th>{ __( 'Jobs done', 'orillacart' ) }</th>
								<th>{ __( 'Memory', 'orillacart' ) }</th>
								<th>{ __( 'Started', 'orillacart' ) }</th>
								<th>{ __( 'Last seen', 'orillacart' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ overview.workers.map( ( worker ) => (
								<tr key={ worker.name }>
									<td>
										<code>{ worker.name }</code>
									</td>
									<td>
										{ worker.queues.length === 0
											? __( 'All', 'orillacart' )
											: worker.queues
													.map( laneLabel )
													.join( ', ' ) }
									</td>
									<td>
										{ worker.working === null
											? __( 'Waiting', 'orillacart' )
											: worker.working
													.split( ', ' )
													.map( typeLabel )
													.join( ', ' ) }
									</td>
									<td>{ worker.jobs_done }</td>
									<td>{ megabytes( worker.memory ) }</td>
									<td>
										{ since(
											worker.started_at,
											overview.now
										) }
									</td>
									<td>
										{ since(
											worker.seen_at,
											overview.now
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
}

interface FailedCardProps {
	overview: Overview;
	changed: number;
	onDone: ( text: string ) => void;
	onError: ( error: unknown ) => void;
}

function FailedCard( { overview, changed, onDone, onError }: FailedCardProps ) {
	const [ confirmation, confirm ] = useConfirm();
	const [ type, setType ] = useState( '' );
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 20,
		fields: [ 'type', 'error', 'attempts', 'failed_at' ],
		titleField: 'about',
		layout: {},
	} );
	const [ page, setPage ] = useState< FailedJobPage | null >( null );
	const [ open, setOpen ] = useState< FailedJob | null >( null );

	// Again when the shop retried or deleted some, or when more failed.
	const latest = useLatest();
	useEffect( () => {
		latest(
			fetchFailedJobs( {
				type: type === '' ? undefined : type,
				page: view.page ?? 1,
				per_page: view.perPage ?? 20,
			} )
		)
			.then( setPage )
			.catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ view.page, view.perPage, type, changed, overview.failed ] );

	const retry = ( choice: Choice ) =>
		retryFailedJobs( choice )
			.then( ( { retried } ) =>
				onDone(
					sprintf(
						/* translators: %d: number of jobs */
						_n(
							'%d job is queued again.',
							'%d jobs are queued again.',
							retried,
							'orillacart'
						),
						retried
					)
				)
			)
			.catch( onError );
	const remove = ( choice: Choice ) =>
		deleteFailedJobs( choice )
			.then( ( { deleted } ) =>
				onDone(
					sprintf(
						/* translators: %d: number of jobs */
						_n(
							'%d failed job deleted.',
							'%d failed jobs deleted.',
							deleted,
							'orillacart'
						),
						deleted
					)
				)
			)
			.catch( onError );
	const ids = ( items: FailedJob[] ) => ( {
		ids: items.map( ( item ) => item.id ),
	} );

	const fields: Field< FailedJob >[] = [
		{
			id: 'about',
			label: __( 'Job', 'orillacart' ),
			enableHiding: false,
			enableSorting: false,
		},
		{
			id: 'type',
			label: __( 'Type', 'orillacart' ),
			enableSorting: false,
			getValue: ( { item } ) => typeLabel( item.type ),
		},
		{
			id: 'error',
			label: __( 'Error', 'orillacart' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span className="orillacart-jobs__error">{ item.error }</span>
			),
		},
		{
			id: 'attempts',
			label: __( 'Attempts', 'orillacart' ),
			enableSorting: false,
		},
		{
			id: 'failed_at',
			label: __( 'Failed', 'orillacart' ),
			enableSorting: false,
			render: ( { item } ) => since( item.failed_at, overview.now ),
		},
	];

	return (
		<>
			<Card className="orillacart-card">
				<CardHeader>
					<h2>
						{ sprintf(
							/* translators: %d: number of failed jobs */
							__( 'Failed (%d)', 'orillacart' ),
							overview.failed
						) }
					</h2>
					{ overview.failed > 0 && (
						<div>
							<Button
								variant="secondary"
								onClick={ () => retry( { all: true } ) }
							>
								{ __( 'Retry all', 'orillacart' ) }
							</Button>{ ' ' }
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									confirm(
										__(
											'Delete every failed job? They are not run again, and what they were for is not done.',
											'orillacart'
										),
										() => remove( { all: true } ),
										__( 'Delete all', 'orillacart' )
									)
								}
							>
								{ __( 'Delete all', 'orillacart' ) }
							</Button>
							{ confirmation }
						</div>
					) }
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A job that failed every attempt waits here, with its error, for 30 days. Retry it once the cause is fixed, such as the mail server.',
							'orillacart'
						) }
					</p>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Type', 'orillacart' ) }
						value={ type }
						options={ [
							{
								value: '',
								label: __( 'All types', 'orillacart' ),
							},
							...overview.types.map( ( jobType ) => ( {
								value: jobType.type,
								label: `${ typeLabel( jobType.type ) } (${ jobType.failed })`,
							} ) ),
						] }
						onChange={ ( value ) => {
							setType( value );
							setView( { ...view, page: 1 } );
						} }
					/>
					{ open !== null && (
						<div className="orillacart-jobs__details">
							<h3>{ open.about }</h3>
							<p className="orillacart-jobs__error">
								{ open.error }
							</p>
							<pre>
								{ JSON.stringify( open.payload, null, 2 ) }
							</pre>
							<Button
								variant="tertiary"
								onClick={ () => setOpen( null ) }
							>
								{ __( 'Close', 'orillacart' ) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>
			<DataViews< FailedJob >
				view={ view }
				onChangeView={ setView }
				fields={ fields }
				data={ page?.jobs ?? [] }
				getItemId={ ( item ) => item.id }
				isLoading={ page === null }
				paginationInfo={ {
					totalItems: page?.total ?? 0,
					totalPages: page?.pages ?? 1,
				} }
				defaultLayouts={ { table: {} } }
				search={ false }
				onClickItem={ setOpen }
				isItemClickable={ () => true }
				actions={ [
					{
						id: 'retry',
						label: __( 'Retry', 'orillacart' ),
						isPrimary: true,
						supportsBulk: true,
						callback: ( items ) => retry( ids( items ) ),
					},
					{
						id: 'delete',
						label: __( 'Delete', 'orillacart' ),
						supportsBulk: true,
						callback: ( items ) => remove( ids( items ) ),
					},
				] }
				empty={ <p>{ __( 'No job failed.', 'orillacart' ) }</p> }
			/>
		</>
	);
}

interface Totals {
	done: number;
	retried: number;
	failed: number;
	ms: number;
}

const add = ( totals: Totals | undefined, counts: JobCounts ): Totals => {
	const jobs = counts.done + counts.retried + counts.failed;
	return {
		done: ( totals?.done ?? 0 ) + counts.done,
		retried: ( totals?.retried ?? 0 ) + counts.retried,
		failed: ( totals?.failed ?? 0 ) + counts.failed,
		ms: ( totals?.ms ?? 0 ) + counts.average_ms * jobs,
	};
};

const average = ( totals: Totals ) => {
	const jobs = totals.done + totals.retried + totals.failed;
	return jobs === 0 ? 0 : Math.round( totals.ms / jobs );
};

function HistoryCard( { overview }: { overview: Overview } ) {
	// The last 24 hours, every type together, an empty hour included.
	const byHour = new Map< string, Totals >();
	overview.hours.forEach( ( counts ) =>
		byHour.set( counts.at, add( byHour.get( counts.at ), counts ) )
	);
	const now = new Date( overview.now );
	now.setUTCMinutes( 0, 0, 0 );
	const hours = Array.from( { length: 24 }, ( _, index ) => {
		const at = new Date( now.getTime() - ( 23 - index ) * 3600000 );
		const key = at.toISOString().replace( '.000Z', '+00:00' );
		return { at, totals: byHour.get( key ) };
	} );
	const highest = Math.max(
		1,
		...hours.map(
			( { totals } ) =>
				( totals?.done ?? 0 ) +
				( totals?.retried ?? 0 ) +
				( totals?.failed ?? 0 )
		)
	);

	// The last 30 days per type.
	const byType = new Map< string, Totals >();
	overview.days.forEach( ( counts ) =>
		byType.set( counts.type, add( byType.get( counts.type ), counts ) )
	);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'History', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<h3>{ __( 'The last 24 hours', 'orillacart' ) }</h3>
				<div
					className="orillacart-jobs__chart"
					role="img"
					aria-label={ __( 'Jobs per hour', 'orillacart' ) }
				>
					{ hours.map( ( { at, totals } ) => {
						const height = ( value: number ) =>
							`${ ( value / highest ) * 100 }%`;
						return (
							<div
								key={ at.getTime() }
								className="orillacart-jobs__hour"
								title={ sprintf(
									/* translators: 1: hour, 2: done, 3: retried, 4: failed */
									__(
										'%1$s: %2$d done, %3$d to retry, %4$d failed',
										'orillacart'
									),
									shopClock( at.toISOString() ),
									totals?.done ?? 0,
									totals?.retried ?? 0,
									totals?.failed ?? 0
								) }
							>
								<span
									className="orillacart-jobs__bar is-done"
									style={ {
										height: height( totals?.done ?? 0 ),
									} }
								/>
								<span
									className="orillacart-jobs__bar is-retried"
									style={ {
										height: height( totals?.retried ?? 0 ),
									} }
								/>
								<span
									className="orillacart-jobs__bar is-failed"
									style={ {
										height: height( totals?.failed ?? 0 ),
									} }
								/>
							</div>
						);
					} ) }
				</div>
				<p className="orillacart-jobs__legend">
					<span className="is-done">
						{ __( 'Done', 'orillacart' ) }
					</span>
					<span className="is-retried">
						{ __( 'To retry', 'orillacart' ) }
					</span>
					<span className="is-failed">
						{ __( 'Failed', 'orillacart' ) }
					</span>
				</p>
				<h3>{ __( 'The last 30 days', 'orillacart' ) }</h3>
				{ byType.size === 0 ? (
					<p>{ __( 'No job ran yet.', 'orillacart' ) }</p>
				) : (
					<table className="widefat striped orillacart-jobs__table">
						<thead>
							<tr>
								<th>{ __( 'Type', 'orillacart' ) }</th>
								<th>{ __( 'Done', 'orillacart' ) }</th>
								<th>{ __( 'Retried', 'orillacart' ) }</th>
								<th>{ __( 'Failed', 'orillacart' ) }</th>
								<th>{ __( 'Average time', 'orillacart' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ [ ...byType ].map( ( [ type, totals ] ) => (
								<tr key={ type }>
									<td>{ typeLabel( type ) }</td>
									<td>{ totals.done }</td>
									<td>{ totals.retried }</td>
									<td>{ totals.failed }</td>
									<td>
										{ sprintf(
											/* translators: %d: milliseconds */
											__( '%d ms', 'orillacart' ),
											average( totals )
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>
		</Card>
	);
}

function ScheduledCard( { overview }: { overview: Overview } ) {
	if ( overview.scheduled.length === 0 ) {
		return null;
	}
	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Scheduled', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Jobs that wait for their time, such as a product to index again when its sale starts, and jobs to retry.',
						'orillacart'
					) }
				</p>
				<table className="widefat striped orillacart-jobs__table">
					<thead>
						<tr>
							<th>{ __( 'Job', 'orillacart' ) }</th>
							<th>{ __( 'Type', 'orillacart' ) }</th>
							<th>{ __( 'Runs', 'orillacart' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ overview.scheduled.map( ( job ) => (
							<tr key={ job.id }>
								<td>{ job.about }</td>
								<td>{ typeLabel( job.type ) }</td>
								<td>
									<time dateTime={ job.at }>
										{ since( job.at, overview.now ) }
									</time>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</CardBody>
		</Card>
	);
}
