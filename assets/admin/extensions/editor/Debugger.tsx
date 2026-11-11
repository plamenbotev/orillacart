import { Button, Notice } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Badge, Section } from '../../shared/ui';
import {
	commandForKey,
	backwards,
	linesOf,
	outcomeTone,
	outputLine,
	perform,
	toggled,
	type Command,
} from './debugging';
import {
	breakpoint,
	children,
	describe,
	Replay,
	type RecordedValue,
	type Recording,
} from './replay';
import { SourceView } from './SourceView';
import './debugger.css';

/** The toolbar, in order: what each button does, its words and its key. */
const TOOLBAR: { command: Command; label: string; keys?: string }[] = [
	{ command: 'start', label: __( 'To start', 'orillacart' ) },
	{
		command: 'reverse',
		label: __( 'Reverse continue', 'orillacart' ),
		keys: 'Shift+F5',
	},
	{
		command: 'backOver',
		label: __( 'Step back over', 'orillacart' ),
		keys: 'Shift+F8',
	},
	{ command: 'back', label: __( 'Step back', 'orillacart' ), keys: 'F8' },
	{ command: 'in', label: __( 'Step in', 'orillacart' ), keys: 'F11' },
	{ command: 'over', label: __( 'Step over', 'orillacart' ), keys: 'F10' },
	{
		command: 'out',
		label: __( 'Step out', 'orillacart' ),
		keys: 'Shift+F11',
	},
	{ command: 'continue', label: __( 'Continue', 'orillacart' ), keys: 'F5' },
	{ command: 'end', label: __( 'To end', 'orillacart' ) },
];

/**
 * The debugger of a recorded run (design 71): the files as they ran with the line it is at, breakpoints in the
 * gutter, a toolbar and keys that step forwards and backwards, a timeline over every stop, the call stack, the
 * variables of the frame chosen and what the run asked the shop up to here. The run already happened: stepping
 * moves over its recording and changes nothing.
 *
 * @param props
 * @param props.recording The recording.
 * @param props.onClose   Called by the close button.
 */
export function Debugger( {
	recording,
	onClose,
}: {
	recording: Recording;
	onClose: () => void;
} ) {
	const replay = useMemo( () => new Replay( recording ), [ recording ] );
	const [ index, setIndex ] = useState( 0 );
	const [ chosen, setChosen ] = useState( 0 );
	const [ tab, setTab ] = useState< string | null >( null );
	const [ breakpoints, setBreakpoints ] = useState< Set< string > >(
		() => new Set()
	);
	const panel = useRef< HTMLDivElement >( null );

	useEffect( () => {
		replay.goTo( 0 );
		setIndex( 0 );
		setChosen( 0 );
		setTab( null );
		panel.current?.scrollIntoView?.( { block: 'start' } );
		panel.current?.focus();
	}, [ replay ] );

	const frames = useMemo(
		() => replay.frames(),
		// The replay moves in place; index is its position.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ replay, index ]
	);
	const step = replay.step;
	const frame = frames[ chosen ] ?? frames[ 0 ] ?? null;
	const file = frame?.file || step?.file || null;
	const line = frame?.line || step?.line || null;
	const files = Object.keys( recording.sources );
	const shown =
		tab ??
		( file && file in recording.sources ? file : null ) ??
		files[ 0 ] ??
		null;
	const points = useMemo(
		() => ( shown ? linesOf( breakpoints, shown ) : [] ),
		[ breakpoints, shown ]
	);

	const move = ( command: Command ) => {
		perform( replay, command, breakpoints );
		setIndex( replay.index );
		setChosen( 0 );
		setTab( null );
	};
	const toggleHere = () => {
		if ( file && line ) {
			setBreakpoints( ( current ) => toggled( current, file, line ) );
		}
	};

	// The keys while the debugger has focus; a div takes them through a listener, as it is no control itself.
	const keys = useRef( { move, toggleHere } );
	keys.current = { move, toggleHere };
	useEffect( () => {
		const element = panel.current;
		if ( ! element ) {
			return undefined;
		}
		const listen = ( event: KeyboardEvent ) => {
			const command = commandForKey( event );
			if ( command === null ) {
				return;
			}
			event.preventDefault();
			if ( command === 'toggle' ) {
				keys.current.toggleHere();
			} else {
				keys.current.move( command );
			}
		};
		element.addEventListener( 'keydown', listen );
		return () => element.removeEventListener( 'keydown', listen );
	}, [] );

	const empty = replay.length === 0;
	const output = replay
		.output( empty ? recording.steps.length - 1 : replay.at )
		.map( outputLine );
	const where = file && line ? breakpoint( file, line ) : '';
	const failed = step?.k === 'error';

	return (
		<div
			ref={ panel }
			tabIndex={ -1 }
			className="oc-debugger"
			aria-label={ __( 'Debugger', 'orillacart' ) }
			role="group"
		>
			<Section
				title={ __( 'Debugger', 'orillacart' ) }
				titleAside={
					<Badge tone={ outcomeTone( recording.outcome ) }>
						{ recording.outcome }
					</Badge>
				}
				description={
					<>
						<code>{ recording.what }</code>
						{ recording.message && ` · ${ recording.message }` }
					</>
				}
				action={
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Close the debugger', 'orillacart' ) }
					</Button>
				}
			>
				<p className="oc-visually-hidden" aria-live="polite">
					{ where &&
						sprintf(
							/* translators: 1: a file and line, such as "main.ocs:6"; 2: the step's number; 3: how many */
							__( 'At %1$s, step %2$d of %3$d.', 'orillacart' ),
							where,
							index + 1,
							replay.length
						) }
					{ failed && ` ${ step?.message ?? '' }` }
				</p>
				{ recording.cut && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The run made more steps than a recording keeps: its end is missing, and the outcome is what the run ended with.',
							'orillacart'
						) }
					</Notice>
				) }
				{ empty && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'The run reached no line of a script: there is nothing to step through, only what it asked the shop.',
							'orillacart'
						) }
					</Notice>
				) }

				<div
					className="oc-debugger__toolbar"
					role="toolbar"
					aria-label={ __( 'Stepping', 'orillacart' ) }
				>
					{ TOOLBAR.map( ( item ) => (
						<Button
							key={ item.command }
							variant="secondary"
							size="compact"
							aria-keyshortcuts={ item.keys }
							disabled={
								empty ||
								( backwards( item.command )
									? replay.atStart
									: replay.atEnd )
							}
							onClick={ () => move( item.command ) }
						>
							{ item.label }
						</Button>
					) ) }
					<Button
						variant="tertiary"
						size="compact"
						aria-keyshortcuts="F9"
						disabled={ ! where }
						onClick={ toggleHere }
					>
						{ breakpoints.has( where )
							? __( 'Remove the breakpoint here', 'orillacart' )
							: __( 'Add a breakpoint here', 'orillacart' ) }
					</Button>
				</div>

				{ ! empty && (
					<div className="oc-debugger__timeline">
						<input
							type="range"
							min={ 0 }
							max={ replay.length - 1 }
							value={ index }
							aria-label={ __( 'Step', 'orillacart' ) }
							aria-valuetext={ sprintf(
								/* translators: 1: the step's number; 2: how many; 3: a file and line */
								__( 'Step %1$d of %2$d, %3$s', 'orillacart' ),
								index + 1,
								replay.length,
								where
							) }
							onChange={ ( event ) => {
								replay.goTo( Number( event.target.value ) );
								setIndex( replay.index );
								setChosen( 0 );
								setTab( null );
							} }
						/>
						<span>
							{ sprintf(
								/* translators: 1: the step's number; 2: how many */
								__( 'Step %1$d of %2$d', 'orillacart' ),
								index + 1,
								replay.length
							) }
							{ where && (
								<>
									{ ' · ' }
									<code>{ where }</code>
								</>
							) }
						</span>
					</div>
				) }
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: the error */
							__( 'The run ended here: %s', 'orillacart' ),
							step?.message ?? recording.message ?? ''
						) }
					</Notice>
				) }

				<div className="oc-debugger__body">
					<div className="oc-debugger__main">
						<div
							className="oc-debugger__tabs"
							role="tablist"
							aria-label={ __(
								'The files as they ran',
								'orillacart'
							) }
						>
							{ files.map( ( path ) => (
								<Button
									key={ path }
									role="tab"
									size="compact"
									variant={
										path === shown ? 'primary' : 'tertiary'
									}
									aria-selected={ path === shown }
									onClick={ () => setTab( path ) }
								>
									{ path }
								</Button>
							) ) }
						</div>
						{ shown !== null ? (
							<div role="tabpanel" aria-label={ shown }>
								<SourceView
									key={ shown }
									path={ shown }
									value={ recording.sources[ shown ] ?? '' }
									label={ sprintf(
										/* translators: %s: a file's path */
										__( '%s as it ran', 'orillacart' ),
										shown
									) }
									line={ shown === file ? line : null }
									breakpoints={ points }
									onToggle={ ( clicked ) =>
										setBreakpoints( ( current ) =>
											toggled( current, shown, clicked )
										)
									}
								/>
							</div>
						) : (
							<p className="oc-muted">
								{ __(
									'The recording holds no files.',
									'orillacart'
								) }
							</p>
						) }
						{ file !== null && ! ( file in recording.sources ) && (
							<p className="oc-muted">
								{ sprintf(
									/* translators: %s: a file's path */
									__(
										'The recording holds no source of %s.',
										'orillacart'
									),
									file
								) }
							</p>
						) }
						{ breakpoints.size > 0 && (
							<div className="oc-debugger__points">
								<h3>{ __( 'Breakpoints', 'orillacart' ) }</h3>
								<ul>
									{ [ ...breakpoints ]
										.sort()
										.map( ( point ) => (
											<li key={ point }>
												<code>{ point }</code>{ ' ' }
												<Button
													variant="link"
													isDestructive
													label={ sprintf(
														/* translators: %s: a file and line, such as "main.ocs:6" */
														__(
															'Remove the breakpoint at %s',
															'orillacart'
														),
														point
													) }
													onClick={ () =>
														setBreakpoints(
															( current ) => {
																const next =
																	new Set(
																		current
																	);
																next.delete(
																	point
																);
																return next;
															}
														)
													}
												>
													{ __(
														'Remove',
														'orillacart'
													) }
												</Button>
											</li>
										) ) }
								</ul>
							</div>
						) }
					</div>

					<div className="oc-debugger__side">
						<h3>{ __( 'Call stack', 'orillacart' ) }</h3>
						{ frames.length === 0 ? (
							<p className="oc-muted">—</p>
						) : (
							<ol className="oc-debugger__stack">
								{ frames.map( ( item, position ) => (
									<li key={ `${ item.at }-${ position }` }>
										<Button
											variant={
												item === frame
													? 'primary'
													: 'tertiary'
											}
											size="compact"
											aria-pressed={ item === frame }
											onClick={ () => {
												setChosen( position );
												setTab( null );
											} }
										>
											<span>
												<strong>{ item.fn }</strong>{ ' ' }
												<span className="oc-debugger__where">
													{ `${ item.file }:${ item.line }` }
												</span>
											</span>
										</Button>
									</li>
								) ) }
							</ol>
						) }

						<h3>{ __( 'Variables', 'orillacart' ) }</h3>
						{ frame && Object.keys( frame.vars ).length > 0 ? (
							<ul className="oc-debugger__tree">
								{ Object.entries( frame.vars ).map(
									( [ name, value ] ) => (
										<Variable
											key={ name }
											name={ name }
											value={ value }
										/>
									)
								) }
							</ul>
						) : (
							<p className="oc-muted">
								{ __( 'None here.', 'orillacart' ) }
							</p>
						) }
					</div>
				</div>

				<h3>{ __( 'Output', 'orillacart' ) }</h3>
				<p className="oc-muted">
					{ __(
						'What the run asked the shop, with the answers, and the values given to debug(), up to this step.',
						'orillacart'
					) }
				</p>
				{ output.length === 0 ? (
					<p className="oc-muted">
						{ __( 'Nothing yet.', 'orillacart' ) }
					</p>
				) : (
					<ol className="oc-debugger__output oc-code">
						{ output.map( ( item, position ) => (
							<li
								key={ position }
								className={ `oc-debugger__out oc-debugger__out--${ item.kind }` }
							>
								<span>{ item.label }</span>
								{ item.error !== null ? (
									<span className="oc-debugger__error">
										{ ` ✕ ${ item.error }` }
									</span>
								) : (
									<span>
										{ item.kind === 'call'
											? ` → ${ item.value }`
											: ` ${ item.value }` }
									</span>
								) }
								{ item.where && (
									<span className="oc-debugger__where">
										{ `  ${ item.where }` }
									</span>
								) }
							</li>
						) ) }
					</ol>
				) }

				<details className="oc-debugger__help">
					<summary>{ __( 'Keys and help', 'orillacart' ) }</summary>
					<p>
						{ __(
							'The run already happened: the debugger moves over its recording, forwards and backwards, and changes nothing in the shop. The keys work while the debugger has focus: F10 steps over, F11 steps in, Shift+F11 steps out, F8 steps back, Shift+F8 steps back over, F5 continues to the next breakpoint, Shift+F5 goes back to the one before, F9 adds or removes a breakpoint at the line the run is at. Click a line’s number to add or remove a breakpoint there. The timeline goes to any step; Home and End on it go to the start and the end.',
							'orillacart'
						) }
					</p>
					<p>
						{ __(
							'The files are the ones the run ran, which may differ from the files in the editor now.',
							'orillacart'
						) }
					</p>
				</details>
			</Section>
		</div>
	);
}

/**
 * A variable in the tree: its name and value, opening into its items or keys when it has any.
 *
 * @param props
 * @param props.name  The name, a list's index or a map's key.
 * @param props.value The recorded value.
 */
function Variable( { name, value }: { name: string; value: RecordedValue } ) {
	const [ open, setOpen ] = useState( false );
	const parts = children( value );
	return (
		<li>
			{ parts.length > 0 ? (
				<Button
					variant="tertiary"
					size="small"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					<span aria-hidden="true">{ open ? '▾' : '▸' }</span>{ ' ' }
					<code>{ name }</code>
				</Button>
			) : (
				<code className="oc-debugger__name">{ name }</code>
			) }
			{ ': ' }
			<span className="oc-debugger__value">{ describe( value ) }</span>
			{ open && (
				<ul>
					{ parts.map( ( [ key, part ] ) => (
						<Variable key={ key } name={ key } value={ part } />
					) ) }
				</ul>
			) }
		</li>
	);
}
