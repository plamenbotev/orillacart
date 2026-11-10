import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import {
	TranslationFields,
	type ContentLanguage,
} from '../shared/Translations';
import {
	Badge,
	DataTable,
	EmptyState,
	Field,
	Page,
	Row,
	Section,
	Skeleton,
	notify,
	type Tone,
} from '../shared/ui';
import {
	checkExtension,
	checkFiles,
	deleteFile,
	disableExtension,
	enableExtension,
	fetchArchive,
	fetchExtension,
	fetchExtensions,
	fetchOriginal,
	fetchReference,
	fetchRuns,
	withContents,
	fetchTranslations,
	fetchVersions,
	importExtension,
	installExtension,
	uploadExtension,
	restoreVersion,
	runFunction,
	runTests,
	saveSettings,
	saveTranslations,
	uninstallExtension,
	writeFile,
	type Extension,
	type ExtensionFile,
	type ExtensionRun,
	type ExtensionSetting,
	type ExtensionVersion,
	type ExtensionWords,
	type Finding,
	type Reference,
	type RunResult,
	type TestResult,
} from './api';
import { CodeEditor } from './editor/CodeEditor';
import { Diff } from './editor/Diff';

const STATES: Record< Extension[ 'state' ], { label: string; tone: Tone } > = {
	draft: { label: __( 'Draft', 'orillacart' ), tone: 'neutral' },
	enabled: { label: __( 'Enabled', 'orillacart' ), tone: 'success' },
	disabled: { label: __( 'Disabled', 'orillacart' ), tone: 'neutral' },
	stopped: { label: __( 'Stopped', 'orillacart' ), tone: 'danger' },
};

const STARTER = `extension "my_extension" {
  name: "My extension"
  version: "0.1.0"
  needs: { }
}

fn hello(name) {
  return "Hello, " ~ name ~ "!"
}
`;

/**
 * Settings › Extensions: the extensions written in the shop's script language (design 56). The list, then one
 * extension's files in an editor with the static check's findings, its permissions and settings, a console to run
 * one of its functions, and the buttons that enable, disable, export and uninstall it.
 */
export function ExtensionsScreen() {
	const [ extensions, setExtensions ] = useState< Extension[] | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );
	const [ open, setOpen ] = useState< Extension | null >( null );
	const [ adding, setAdding ] = useState( false );

	const fail = ( e: unknown ) => setError( errorMessage( e ) );
	const reload = () => fetchExtensions().then( setExtensions ).catch( fail );

	useEffect( () => {
		reload();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const show = ( answered: Extension ) => {
		// An answer to a save carries the saved file's content only: the others are as this screen read them.
		const extension = withContents( answered, open );
		setOpen( extension );
		setExtensions( ( list ) =>
			list === null
				? list
				: list.map( ( item ) =>
						item.id === extension.id ? extension : item
					)
		);
	};

	if ( open !== null ) {
		return (
			<ExtensionEditor
				extension={ open }
				onChange={ show }
				onBack={ () => {
					setOpen( null );
					reload();
				} }
				onRemoved={ () => {
					setOpen( null );
					reload();
				} }
			/>
		);
	}

	return (
		<Page
			title={ __( 'Extensions', 'orillacart' ) }
			error={ error }
			onDismissError={ () => setError( null ) }
			actions={
				<>
					<ImportButton
						onImported={ ( extension ) => show( extension ) }
						onError={ fail }
					/>
					<Button
						variant="primary"
						onClick={ () => setAdding( true ) }
					>
						{ __( 'New extension', 'orillacart' ) }
					</Button>
				</>
			}
		>
			{ adding && (
				<NewExtensionDialog
					onClose={ () => setAdding( false ) }
					onCreated={ ( extension ) => {
						setAdding( false );
						show( extension );
					} }
				/>
			) }
			{ extensions === null ? (
				<Skeleton lines={ 3 } />
			) : (
				<DataTable
					caption={ __( 'Extensions', 'orillacart' ) }
					rows={ extensions }
					rowKey={ ( row ) => row.id }
					empty={
						<EmptyState
							title={ __( 'No extensions yet', 'orillacart' ) }
							description={ __(
								'An extension adds to this shop in its script language: a payment or shipping provider, endpoints and pages, records and fields, templates. Write one here, or import one.',
								'orillacart'
							) }
						/>
					}
					columns={ [
						{
							id: 'name',
							label: __( 'Extension', 'orillacart' ),
							render: ( row ) => (
								<Button
									variant="link"
									onClick={ () =>
										fetchExtension( row.id )
											.then( show )
											.catch( fail )
									}
								>
									{ row.name }
								</Button>
							),
						},
						{
							id: 'id',
							label: __( 'Id', 'orillacart' ),
							render: ( row ) => <code>{ row.id }</code>,
						},
						{
							id: 'version',
							label: __( 'Version', 'orillacart' ),
							render: ( row ) => row.version || '—',
						},
						{
							id: 'state',
							label: __( 'State', 'orillacart' ),
							render: ( row ) => (
								<>
									<Badge tone={ STATES[ row.state ].tone }>
										{ STATES[ row.state ].label }
									</Badge>
									{ row.stale && (
										<>
											{ ' ' }
											<Badge tone="warning">
												{ __(
													'Changed since enabled',
													'orillacart'
												) }
											</Badge>
										</>
									) }
								</>
							),
						},
						{
							id: 'problem',
							label: __( 'Last problem', 'orillacart' ),
							render: ( row ) => row.last_problem ?? '—',
						},
					] }
				/>
			) }
		</Page>
	);
}

function NewExtensionDialog( {
	onClose,
	onCreated,
}: {
	onClose: () => void;
	onCreated: ( extension: Extension ) => void;
} ) {
	const [ id, setId ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const create = () => {
		setBusy( true );
		installExtension( id, name, {
			'main.ocs': STARTER.replace( 'my_extension', id ).replace(
				'My extension',
				name
			),
		} )
			.then( onCreated )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={ __( 'New extension', 'orillacart' ) }
			onRequestClose={ onClose }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Name', 'orillacart' ) }
				value={ name }
				onChange={ ( value ) => {
					setName( value );
					if ( id === '' || id === slugOf( name ) ) {
						setId( slugOf( value ) );
					}
				} }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Id', 'orillacart' ) }
				help={ __(
					'Lowercase letters, digits and underscores; names its routes, objects and settings. It cannot change later.',
					'orillacart'
				) }
				value={ id }
				onChange={ setId }
			/>
			<div className="oc-actions">
				<Button
					variant="primary"
					onClick={ create }
					isBusy={ busy }
					disabled={ busy || id === '' || name === '' }
				>
					{ __( 'Create', 'orillacart' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'orillacart' ) }
				</Button>
			</div>
		</Modal>
	);
}

function ImportButton( {
	onImported,
	onError,
}: {
	onImported: ( extension: Extension ) => void;
	onError: ( e: unknown ) => void;
} ) {
	return (
		<>
			<Button
				variant="secondary"
				onClick={ () =>
					document
						.getElementById( 'orillacart-extension-import' )
						?.click()
				}
			>
				{ __( 'Import', 'orillacart' ) }
			</Button>
			<input
				id="orillacart-extension-import"
				type="file"
				accept="application/zip,.zip,application/json,.json"
				className="oc-visually-hidden"
				aria-label={ __( 'Import an extension file', 'orillacart' ) }
				onChange={ ( event ) => {
					const file = event.target.files?.[ 0 ];
					event.target.value = '';
					if ( ! file ) {
						return;
					}
					const imported = file.name.toLowerCase().endsWith( '.json' )
						? file
								.text()
								.then( ( text ) =>
									importExtension( JSON.parse( text ) )
								)
								.then( ( extension ) => ( {
									extension,
									skipped: [] as string[],
								} ) )
						: uploadExtension( file );
					imported
						.then( ( { extension, skipped } ) => {
							notify(
								skipped.length === 0
									? sprintf(
											/* translators: %s: the extension's name */
											__( 'Imported %s.', 'orillacart' ),
											extension.name
										)
									: sprintf(
											/* translators: 1: the extension's name; 2: files of the zip, such as "LICENSE, package.json" */
											__(
												'Imported %1$s. Left out what is not an extension’s file: %2$s.',
												'orillacart'
											),
											extension.name,
											skipped.join( ', ' )
										)
							);
							onImported( extension );
						} )
						.catch( onError );
				} }
			/>
		</>
	);
}

function ExtensionEditor( {
	extension,
	onChange,
	onBack,
	onRemoved,
}: {
	extension: Extension;
	onChange: ( extension: Extension ) => void;
	onBack: () => void;
	onRemoved: () => void;
} ) {
	const files = useMemo( () => extension.files ?? [], [ extension.files ] );
	const [ path, setPath ] = useState( files[ 0 ]?.path ?? '' );
	const [ content, setContent ] = useState( files[ 0 ]?.content ?? '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ newPath, setNewPath ] = useState( '' );
	const [ granting, setGranting ] = useState< string[] | null >( null );
	const [ removing, setRemoving ] = useState( false );
	const [ live, setLive ] = useState< Finding[] | null >( null );
	const reference = useReference();
	const current = files.find( ( file ) => file.path === path );
	const dirty = current !== undefined && current.content !== content;
	// The files as saved with this buffer in place of its file: what the check and the tests run over.
	const edited = useMemo( () => {
		const sources: Record< string, string > = {};
		for ( const file of files ) {
			sources[ file.path ] =
				file.path === path ? content : ( file.content ?? '' );
		}
		return sources;
	}, [ files, path, content ] );

	// The static check as the manager types: the files as saved with this buffer in place of its file.
	const checking = useRef( 0 );
	useEffect( () => {
		if ( ! path.endsWith( '.ocs' ) || ! dirty ) {
			setLive( null );
			return undefined;
		}
		const run = ++checking.current;
		const timer = setTimeout( () => {
			checkFiles( edited, extension.id )
				.then( ( answer ) => {
					if ( checking.current === run ) {
						setLive( answer.findings );
					}
				} )
				.catch( () => undefined );
		}, 400 );
		return () => clearTimeout( timer );
	}, [ path, dirty, edited, extension.id ] );

	const act = ( work: Promise< Extension >, message?: string ) => {
		setBusy( true );
		return work
			.then( ( next ) => {
				onChange( next );
				if ( message ) {
					notify( message );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const openFile = ( next: string ) => {
		const file = files.find( ( item ) => item.path === next );
		setPath( next );
		setContent( file?.content ?? '' );
	};

	const state = STATES[ extension.state ];

	return (
		<Page
			title={ extension.name }
			error={ error }
			onDismissError={ () => setError( null ) }
			actions={
				<>
					<Button variant="tertiary" onClick={ onBack }>
						{ __( 'All extensions', 'orillacart' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () =>
							fetchArchive( extension.id )
								.then( ( archive ) =>
									download(
										archive.filename,
										Uint8Array.from(
											atob( archive.content ),
											( c ) => c.charCodeAt( 0 )
										),
										archive.mime_type
									)
								)
								.catch( ( e ) => setError( errorMessage( e ) ) )
						}
					>
						{ __( 'Export', 'orillacart' ) }
					</Button>
					{ extension.state === 'enabled' ? (
						<Button
							variant="secondary"
							onClick={ () =>
								act(
									disableExtension( extension.id ),
									__( 'Extension disabled.', 'orillacart' )
								)
							}
							disabled={ busy }
						>
							{ __( 'Disable', 'orillacart' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={ () => setGranting( extension.needs ) }
							disabled={
								busy || ( extension.findings ?? [] ).length > 0
							}
						>
							{ extension.stale || extension.state === 'stopped'
								? __( 'Enable again', 'orillacart' )
								: __( 'Enable', 'orillacart' ) }
						</Button>
					) }
					{ extension.state === 'enabled' && extension.stale && (
						<Button
							variant="primary"
							onClick={ () => setGranting( extension.needs ) }
							disabled={ busy }
						>
							{ __( 'Enable the new files', 'orillacart' ) }
						</Button>
					) }
				</>
			}
		>
			<h2>{ extension.name }</h2>
			<p className="oc-muted">
				<code>{ extension.id }</code>{ ' ' }
				{ extension.version && `· ${ extension.version } ` }·{ ' ' }
				<Badge tone={ state.tone }>{ state.label }</Badge>
				{ extension.state === 'enabled' && (
					<>
						{ ' ' }
						· { __( 'Granted:', 'orillacart' ) }{ ' ' }
						{ extension.granted.length > 0
							? extension.granted.join( ', ' )
							: __( 'nothing', 'orillacart' ) }
					</>
				) }
			</p>
			{ extension.last_problem && (
				<Notice
					status={
						extension.state === 'stopped' ? 'error' : 'warning'
					}
					isDismissible={ false }
				>
					{ extension.state === 'stopped'
						? sprintf(
								/* translators: 1: how many times, 2: the problem */
								__(
									'Stopped after failing %1$d times in a row: %2$s',
									'orillacart'
								),
								extension.failures,
								extension.last_problem
							)
						: sprintf(
								/* translators: %s: the problem */
								__( 'Last problem: %s', 'orillacart' ),
								extension.last_problem
							) }
				</Notice>
			) }

			<Section
				title={ __( 'Files', 'orillacart' ) }
				description={ __(
					'Scripts end in .ocs and compile together; templates go under templates/, and scripts, styles and SVGs for the browser under assets/. A change to an enabled extension runs once it is enabled again.',
					'orillacart'
				) }
			>
				<Row>
					<Field size="m">
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'File', 'orillacart' ) }
							value={ path }
							options={ files.map( ( file ) => ( {
								value: file.path,
								label: file.stale
									? sprintf(
											/* translators: %s: a file's path */
											__(
												'%s (stale override)',
												'orillacart'
											),
											file.path
										)
									: file.path,
							} ) ) }
							onChange={ openFile }
						/>
					</Field>
					<Field size="m">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'New file', 'orillacart' ) }
							placeholder="templates/support.html.twig"
							value={ newPath }
							onChange={ setNewPath }
						/>
					</Field>
					<Field size="xs" className="oc-field--aligned">
						<Button
							variant="secondary"
							disabled={ newPath === '' || busy }
							onClick={ () =>
								act(
									writeFile( extension.id, newPath, '' )
								).then( () => {
									openFile( newPath );
									setNewPath( '' );
								} )
							}
						>
							{ __( 'Add file', 'orillacart' ) }
						</Button>
					</Field>
				</Row>
				<Field size="full">
					<CodeEditor
						label={ path || __( 'Contents', 'orillacart' ) }
						value={ content }
						onChange={ setContent }
						path={ path }
						findings={ ( live ?? [] ).filter(
							( finding ) => finding.file === path
						) }
						reference={ reference }
						routes={ extension.routes ?? null }
						disabled={ current === undefined }
					/>
				</Field>
				<div className="oc-actions">
					<Button
						variant="primary"
						disabled={ ! dirty || busy }
						onClick={ () =>
							act(
								writeFile( extension.id, path, content ),
								__( 'File saved.', 'orillacart' )
							)
						}
					>
						{ __( 'Save file', 'orillacart' ) }
					</Button>
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => act( checkExtension( extension.id ) ) }
					>
						{ __( 'Check', 'orillacart' ) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						disabled={ current === undefined || busy }
						onClick={ () =>
							act( deleteFile( extension.id, path ) ).then( () =>
								openFile(
									files.find( ( f ) => f.path !== path )
										?.path ?? ''
								)
							)
						}
					>
						{ __( 'Delete file', 'orillacart' ) }
					</Button>
				</div>
				<Findings
					findings={
						live === null
							? extension.findings
							: live.map( describeFinding )
					}
					unsaved={ live !== null }
				/>
				{ current?.overrides && (
					<Override
						extension={ extension }
						file={ current }
						edited={ content }
					/>
				) }
			</Section>

			<Versions
				extension={ extension }
				onChange={ onChange }
				onError={ setError }
			/>

			<Runs extension={ extension } />

			{ extension.settings !== null && extension.settings.length > 0 && (
				<SettingsSection
					extension={ extension }
					onChange={ onChange }
					onError={ setError }
				/>
			) }

			<Translations extension={ extension } onError={ setError } />

			<Console
				extension={ extension }
				files={ edited }
				onError={ setError }
			/>

			<Section
				title={ __( 'Uninstall', 'orillacart' ) }
				description={ __(
					'Removes the extension with its files and settings. Export it first to keep a copy.',
					'orillacart'
				) }
			>
				{ removing ? (
					<div className="oc-actions">
						<Button
							variant="primary"
							isDestructive
							onClick={ () =>
								uninstallExtension( extension.id )
									.then( () => {
										notify(
											__(
												'Extension uninstalled.',
												'orillacart'
											)
										);
										onRemoved();
									} )
									.catch( ( e ) =>
										setError( errorMessage( e ) )
									)
							}
						>
							{ __( 'Yes, uninstall it', 'orillacart' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setRemoving( false ) }
						>
							{ __( 'Keep it', 'orillacart' ) }
						</Button>
					</div>
				) : (
					<Button
						variant="secondary"
						isDestructive
						onClick={ () => setRemoving( true ) }
					>
						{ __( 'Uninstall', 'orillacart' ) }
					</Button>
				) }
			</Section>

			{ granting !== null && (
				<GrantDialog
					extension={ extension }
					onClose={ () => setGranting( null ) }
					onGrant={ ( grant ) => {
						setGranting( null );
						act(
							enableExtension( extension.id, grant ),
							__( 'Extension enabled.', 'orillacart' )
						);
					} }
				/>
			) }
		</Page>
	);
}

/**
 * A finding as the saved check writes it: file, message, line and column.
 *
 * @param finding The finding as the site answered it.
 */
function describeFinding( finding: Finding ): string {
	return `${ finding.file ? finding.file + ': ' : '' }${
		finding.message
	} (line ${ finding.line }, column ${ finding.column })`;
}

/** The site's script reference, fetched once per screen; null until it arrives or when it cannot. */
function useReference(): Reference | null {
	const [ reference, setReference ] = useState< Reference | null >( null );
	useEffect( () => {
		let live = true;
		fetchReference()
			.then( ( answer ) => live && setReference( answer ) )
			.catch( () => undefined );
		return () => {
			live = false;
		};
	}, [] );
	return reference;
}

function Findings( {
	findings,
	unsaved = false,
}: {
	findings: string[] | null;
	unsaved?: boolean;
} ) {
	if ( findings === null ) {
		return null;
	}
	if ( findings.length === 0 ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ unsaved
					? __( 'The scripts compile as edited.', 'orillacart' )
					: __( 'The scripts compile.', 'orillacart' ) }
			</Notice>
		);
	}
	return (
		<Notice status="error" isDismissible={ false }>
			<ul>
				{ findings.map( ( finding ) => (
					<li key={ finding }>{ finding }</li>
				) ) }
			</ul>
		</Notice>
	);
}

function GrantDialog( {
	extension,
	onClose,
	onGrant,
}: {
	extension: Extension;
	onClose: () => void;
	onGrant: ( grant: string[] ) => void;
} ) {
	const [ agreed, setAgreed ] = useState( extension.needs.length === 0 );
	return (
		<Modal
			title={ __( 'Enable the extension', 'orillacart' ) }
			onRequestClose={ onClose }
		>
			{ extension.needs.length === 0 ? (
				<p>
					{ __(
						'It asks for no permissions: it can read nothing of the shop and call nothing outside it.',
						'orillacart'
					) }
				</p>
			) : (
				<>
					<p>
						{ __(
							'It asks for these permissions. It will have them, and nothing more, as long as it runs:',
							'orillacart'
						) }
					</p>
					<ul>
						{ extension.needs.map( ( need ) => (
							<li key={ need }>
								<code>{ need }</code>
							</li>
						) ) }
					</ul>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'I grant these permissions',
							'orillacart'
						) }
						checked={ agreed }
						onChange={ setAgreed }
					/>
				</>
			) }
			<div className="oc-actions">
				<Button
					variant="primary"
					disabled={ ! agreed }
					onClick={ () => onGrant( extension.needs ) }
				>
					{ __( 'Enable', 'orillacart' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'orillacart' ) }
				</Button>
			</div>
		</Modal>
	);
}

function SettingsSection( {
	extension,
	onChange,
	onError,
}: {
	extension: Extension;
	onChange: ( extension: Extension ) => void;
	onError: ( message: string ) => void;
} ) {
	const declared = extension.settings ?? [];
	const [ values, setValues ] = useState<
		Record< string, string | boolean >
	>( () => initial( declared ) );
	const [ busy, setBusy ] = useState( false );
	useEffect(
		() => setValues( initial( declared ) ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ extension.id, extension.updated_at ]
	);

	const save = () => {
		setBusy( true );
		saveSettings( extension.id, values )
			.then( ( next ) => {
				onChange( next );
				notify( __( 'Settings saved.', 'orillacart' ) );
			} )
			.catch( ( e ) => onError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Section
			title={ __( 'Settings', 'orillacart' ) }
			description={ __(
				'What the extension declares; secrets are kept sealed and never shown again.',
				'orillacart'
			) }
		>
			{ declared.map( ( setting ) => (
				<SettingField
					key={ setting.key }
					setting={ setting }
					value={ values[ setting.key ] }
					onChange={ ( value ) =>
						setValues( { ...values, [ setting.key ]: value } )
					}
				/>
			) ) }
			<div className="oc-actions">
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Save settings', 'orillacart' ) }
				</Button>
			</div>
		</Section>
	);
}

function SettingField( {
	setting,
	value,
	onChange,
}: {
	setting: ExtensionSetting;
	value: string | boolean | undefined;
	onChange: ( value: string | boolean ) => void;
} ) {
	if ( setting.type === 'boolean' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ setting.label }
				help={ setting.help }
				checked={ value === true }
				onChange={ onChange }
			/>
		);
	}
	if ( setting.type === 'choice' ) {
		return (
			<Field size="m">
				<SelectControl
					__next40pxDefaultSize
					label={ setting.label }
					help={ setting.help }
					value={ String( value ?? '' ) }
					options={ [
						{ value: '', label: '—' },
						...Object.entries( setting.choices ).map(
							( [ v, label ] ) => ( { value: v, label } )
						),
					] }
					onChange={ onChange }
				/>
			</Field>
		);
	}
	return (
		<Field size="m">
			<TextControl
				__next40pxDefaultSize
				type={ INPUT_TYPES[ setting.type ] ?? 'text' }
				label={ setting.label }
				help={
					setting.type === 'secret' && setting.set
						? sprintf(
								/* translators: %s: the setting's help text */
								__(
									'%s Set; leave empty to keep it.',
									'orillacart'
								),
								setting.help
							).trim()
						: setting.help
				}
				placeholder={
					setting.type === 'secret' && setting.set
						? '••••••••'
						: undefined
				}
				value={ String( value ?? '' ) }
				onChange={ onChange }
			/>
		</Field>
	);
}

/** The input type of each setting type; text for the rest. */
const INPUT_TYPES: Record< string, 'password' | 'number' > = {
	secret: 'password',
	number: 'number',
};

function initial(
	declared: ExtensionSetting[]
): Record< string, string | boolean > {
	const values: Record< string, string | boolean > = {};
	for ( const setting of declared ) {
		if ( setting.type === 'boolean' ) {
			values[ setting.key ] = setting.value === true;
		} else if ( setting.type === 'secret' ) {
			values[ setting.key ] = '';
		} else {
			values[ setting.key ] = String( setting.value ?? '' );
		}
	}
	return values;
}

function Console( {
	extension,
	files,
	onError,
}: {
	extension: Extension;
	files: Record< string, string >;
	onError: ( message: string ) => void;
} ) {
	const [ fn, setFn ] = useState( '' );
	const [ args, setArgs ] = useState( '[]' );
	const [ result, setResult ] = useState< RunResult | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ tests, setTests ] = useState< TestResult[] | null >( null );
	const [ testing, setTesting ] = useState( false );

	const test = () => {
		setTesting( true );
		runTests( extension.id, files )
			.then( ( answer ) => setTests( answer.tests ) )
			.catch( ( e ) => onError( errorMessage( e ) ) )
			.finally( () => setTesting( false ) );
	};

	const run = () => {
		let parsed: unknown;
		try {
			parsed = JSON.parse( args || '[]' );
		} catch {
			onError(
				__(
					'The arguments are a JSON list, such as ["Ada", 3].',
					'orillacart'
				)
			);
			return;
		}
		if ( ! Array.isArray( parsed ) ) {
			onError(
				__(
					'The arguments are a JSON list, such as ["Ada", 3].',
					'orillacart'
				)
			);
			return;
		}
		setBusy( true );
		runFunction( extension.id, fn, parsed )
			.then( setResult )
			.catch( ( e ) => onError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Section
			title={ __( 'Console', 'orillacart' ) }
			description={ __(
				'Runs one top-level function of the extension and shows what it returned and what the run used. An enabled extension runs the files it was enabled with, under its granted permissions; a draft or a disabled one runs its saved files with nothing granted.',
				'orillacart'
			) }
		>
			<Row>
				<Field size="s">
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Function', 'orillacart' ) }
						value={ fn }
						onChange={ setFn }
						placeholder="hello"
					/>
				</Field>
				<Field size="m">
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Arguments (JSON list)', 'orillacart' ) }
						value={ args }
						onChange={ setArgs }
					/>
				</Field>
				<Field size="xs" className="oc-field--aligned">
					<Button
						variant="secondary"
						onClick={ run }
						isBusy={ busy }
						disabled={ busy || fn === '' }
					>
						{ __( 'Run', 'orillacart' ) }
					</Button>
				</Field>
			</Row>
			{ result && (
				<Notice
					status={ result.ok ? 'success' : 'warning' }
					isDismissible={ false }
				>
					<pre className="oc-code">
						{ result.ok
							? JSON.stringify( result.result, null, 2 )
							: ( result.stopped ??
								`${ result.failure?.kind }: ${ result.failure?.message }` ) }
					</pre>
					<p className="oc-muted">
						{ sprintf(
							/* translators: 1: steps, 2: calls, 3: seconds */
							__(
								'Used %1$d steps, %2$d calls, %3$s s.',
								'orillacart'
							),
							result.used.steps ?? 0,
							result.used.calls ?? 0,
							String( result.used.seconds ?? 0 )
						) }
					</p>
				</Notice>
			) }
			<Row>
				<Field size="xs" className="oc-field--aligned">
					<Button
						variant="secondary"
						onClick={ test }
						isBusy={ testing }
						disabled={ testing }
					>
						{ __( 'Run tests', 'orillacart' ) }
					</Button>
				</Field>
				<Field size="l">
					<p className="oc-muted">
						{ __(
							"Runs the test declarations of the files as edited, with HTTP answered from each test's map and what it writes rolled back.",
							'orillacart'
						) }
					</p>
				</Field>
			</Row>
			{ tests && <TestResults tests={ tests } /> }
		</Section>
	);
}

function TestResults( { tests }: { tests: TestResult[] } ) {
	if ( tests.length === 0 ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'The extension declares no tests. Add test "name" { } fn() { assert … } to a script.',
					'orillacart'
				) }
			</Notice>
		);
	}
	const failed = tests.filter( ( t ) => ! t.ok ).length;
	return (
		<Notice
			status={ failed === 0 ? 'success' : 'warning' }
			isDismissible={ false }
		>
			<p>
				{ failed === 0
					? sprintf(
							/* translators: %d: how many tests */
							_n(
								'%d test passed.',
								'%d tests passed.',
								tests.length,
								'orillacart'
							),
							tests.length
						)
					: sprintf(
							/* translators: 1: how many failed, 2: how many tests */
							__( '%1$d of %2$d tests failed.', 'orillacart' ),
							failed,
							tests.length
						) }
			</p>
			<ul className="oc-tests">
				{ tests.map( ( t ) => (
					<li
						key={ t.name }
						className={ `oc-tests__line oc-tests__line--${ t.ok ? 'ok' : 'failed' }` }
					>
						<Badge tone={ t.ok ? 'success' : 'danger' }>
							{ t.ok
								? __( 'ok', 'orillacart' )
								: __( 'failed', 'orillacart' ) }
						</Badge>{ ' ' }
						<strong>{ t.name }</strong>
						{ t.message && (
							<span>
								{ ': ' + t.message }
								{ t.line !== null &&
									` (${ t.file ? t.file + ' ' : '' }${ sprintf(
										/* translators: %d: a line number */
										__( 'line %d', 'orillacart' ),
										t.line
									) })` }
							</span>
						) }
						{ t.http.length > 0 && (
							<span className="oc-muted">
								{ ' · ' +
									t.http
										.map(
											( h ) =>
												`${ h.method } ${ h.url } → ${ h.status }`
										)
										.join( ', ' ) }
							</span>
						) }
					</li>
				) ) }
			</ul>
		</Notice>
	);
}

/**
 * What a template file overrides: the shop's template it replaces, a stale mark when a release changed that
 * template since, and the diff against it on request.
 *
 * @param props
 * @param props.extension The extension.
 * @param props.file      The file as saved.
 * @param props.edited    The buffer, so the diff is of what is on screen.
 */
function Override( {
	extension,
	file,
	edited,
}: {
	extension: Extension;
	file: ExtensionFile;
	edited: string;
} ) {
	const [ original, setOriginal ] = useState< string | null | undefined >(
		undefined
	);
	useEffect( () => setOriginal( undefined ), [ extension.id, file.path ] );
	const compare = () =>
		fetchOriginal( extension.id, file.path )
			.then( ( answer ) => setOriginal( answer.original ) )
			.catch( () => setOriginal( null ) );
	let compared;
	if ( original === undefined ) {
		compared = (
			<Button variant="secondary" onClick={ compare }>
				{ __( "Compare with the shop's template", 'orillacart' ) }
			</Button>
		);
	} else if ( original === null ) {
		compared = (
			<p className="oc-muted">
				{ __(
					'The shop has no such template any more.',
					'orillacart'
				) }
			</p>
		);
	} else {
		compared = <Diff original={ original } edited={ edited } />;
	}
	return (
		<Notice
			status={ file.stale ? 'warning' : 'info' }
			isDismissible={ false }
		>
			<p>
				{ sprintf(
					/* translators: %s: a template's name */
					__(
						"This file overrides the shop's template %s.",
						'orillacart'
					),
					file.overrides ?? ''
				) }{ ' ' }
				{ file.stale &&
					__(
						'A release changed that template since this override was saved: compare them and bring the change over.',
						'orillacart'
					) }
			</p>
			{ compared }
		</Notice>
	);
}

/**
 * The versions kept of the extension's files, with a restore for each.
 *
 * @param props
 * @param props.extension The extension.
 * @param props.onChange  Takes the extension as a restore answers it.
 * @param props.onError   Takes a message when a request fails.
 */
function Versions( {
	extension,
	onChange,
	onError,
}: {
	extension: Extension;
	onChange: ( extension: Extension ) => void;
	onError: ( message: string ) => void;
} ) {
	const [ versions, setVersions ] = useState< ExtensionVersion[] | null >(
		null
	);
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		setVersions( null );
		fetchVersions( extension.id )
			.then( ( answer ) => setVersions( answer.versions ) )
			.catch( ( e ) => onError( errorMessage( e ) ) );
	}, [ extension.id, extension.updated_at, onError ] );
	const restore = ( number: number ) => {
		setBusy( true );
		restoreVersion( extension.id, number )
			.then( ( restored ) => {
				onChange( restored );
				notify(
					sprintf(
						/* translators: %d: a version's number */
						__( 'Version %d restored.', 'orillacart' ),
						number
					)
				);
			} )
			.catch( ( e ) => onError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Section
			title={ __( 'Versions', 'orillacart' ) }
			description={ __(
				'The files as they stood after each save, delete, import and restore; the last thirty are kept. Restoring makes a version the newest; an enabled extension runs what it was enabled with until enabled again.',
				'orillacart'
			) }
		>
			<DataTable
				columns={ [
					{
						id: 'number',
						label: __( 'Version', 'orillacart' ),
						width: '6rem',
						render: ( v: ExtensionVersion ) => (
							<>
								{ v.number }
								{ v.current && (
									<>
										{ ' ' }
										<Badge tone="success">
											{ __( 'current', 'orillacart' ) }
										</Badge>
									</>
								) }
							</>
						),
					},
					{
						id: 'note',
						label: __( 'Change', 'orillacart' ),
						render: ( v: ExtensionVersion ) => v.note,
					},
					{
						id: 'files',
						label: __( 'Files', 'orillacart' ),
						render: ( v: ExtensionVersion ) => v.files.length,
						align: 'end',
					},
					{
						id: 'at',
						label: __( 'When', 'orillacart' ),
						nowrap: true,
						render: ( v: ExtensionVersion ) =>
							new Date( v.created_at ).toLocaleString(),
					},
					{
						id: 'restore',
						label: '',
						align: 'end',
						render: ( v: ExtensionVersion ) =>
							v.current ? null : (
								<Button
									variant="tertiary"
									disabled={ busy }
									onClick={ () => restore( v.number ) }
								>
									{ __( 'Restore', 'orillacart' ) }
								</Button>
							),
					},
				] }
				rows={ versions ?? [] }
				rowKey={ ( v ) => v.number }
				loading={ versions === null }
				empty={ __( 'No versions kept yet.', 'orillacart' ) }
			/>
		</Section>
	);
}

/**
 * The extension's words in the site's other languages: each word with a field per language, a plural with one per
 * form the language has. Nothing on a site in one language, or for an extension without words.
 *
 * @param props
 * @param props.extension The extension.
 * @param props.onError   Takes a message when a request fails.
 */
function Translations( {
	extension,
	onError,
}: {
	extension: Extension;
	onError: ( message: string ) => void;
} ) {
	const [ shown, setShown ] = useState< ExtensionWords | null >( null );
	const [ edited, setEdited ] = useState<
		Record< string, Record< string, string[] > >
	>( {} );
	const [ busy, setBusy ] = useState( false );
	const show = ( answer: ExtensionWords ) => {
		setShown( answer );
		setEdited(
			Object.fromEntries(
				answer.words.map( ( word ) => [ word.key, word.translations ] )
			)
		);
	};
	useEffect( () => {
		setShown( null );
		fetchTranslations( extension.id )
			.then( show )
			.catch( ( e ) => onError( errorMessage( e ) ) );
	}, [ extension.id, extension.updated_at, onError ] );
	if (
		shown === null ||
		shown.languages.length === 0 ||
		shown.words.length === 0
	) {
		return null;
	}
	const save = () => {
		setBusy( true );
		saveTranslations( extension.id, edited )
			.then( ( answer ) => {
				show( answer );
				notify( __( 'Translations saved.', 'orillacart' ) );
			} )
			.catch( ( e ) => onError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const languages = ( forms: number ): ContentLanguage[] =>
		shown.languages
			.filter( ( language ) => language.forms > forms )
			.map( ( language ) => ( {
				code: language.locale,
				locale: language.locale,
				name: language.name,
				default: false,
			} ) );
	/**
	 * Takes one form of a word in each language, keeping each language's forms as many as it has.
	 *
	 * @param word  The word.
	 * @param form  Which of its forms.
	 * @param value The form by locale.
	 */
	const change = (
		word: ExtensionWords[ 'words' ][ number ],
		form: number,
		value: Record< string, string >
	) => {
		const next = { ...( edited[ word.key ] ?? {} ) };
		for ( const language of shown.languages ) {
			const count = word.plural === null ? 1 : language.forms;
			if ( form >= count ) {
				continue;
			}
			const forms = Array.from(
				{ length: count },
				( _, i ) => next[ language.locale ]?.[ i ] ?? ''
			);
			forms[ form ] = value[ language.locale ] ?? '';
			next[ language.locale ] = forms;
		}
		setEdited( { ...edited, [ word.key ]: next } );
	};
	const mostForms = Math.max(
		...shown.languages.map( ( language ) => language.forms )
	);
	return (
		<Section
			title={ __( 'Translations', 'orillacart' ) }
			description={ __(
				'The words the extension shows people, from its declarations and its __() calls, in the site’s other languages. A word left empty shows the shop’s own translation of it, or the words as written.',
				'orillacart'
			) }
		>
			{ shown.words.map( ( word ) => {
				const forms = word.plural === null ? 1 : mostForms;
				return (
					<div key={ word.key } className="oc-field">
						<strong>
							{ word.plural === null
								? word.text
								: `${ word.text } / ${ word.plural }` }
						</strong>
						{ word.context !== '' && (
							<span className="oc-muted">
								{ ' ' }
								({ word.context })
							</span>
						) }
						<p className="oc-muted">{ word.where.join( '; ' ) }</p>
						{ Array.from( { length: forms }, ( _, form ) => (
							<TranslationFields
								key={ form }
								label={
									word.plural === null
										? word.text
										: sprintf(
												/* translators: 1: words, such as "%d items"; 2: the number of one of a language's plural forms */
												__(
													'%1$s, form %2$d',
													'orillacart'
												),
												form === 0
													? word.text
													: word.plural,
												form + 1
											)
								}
								languages={ languages( form ) }
								value={ Object.fromEntries(
									Object.entries(
										edited[ word.key ] ?? {}
									).map( ( [ locale, values ] ) => [
										locale,
										values[ form ] ?? '',
									] )
								) }
								onChange={ ( value ) =>
									change( word, form, value )
								}
							/>
						) ) }
					</div>
				);
			} ) }
			<div className="oc-actions">
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Save translations', 'orillacart' ) }
				</Button>
			</div>
		</Section>
	);
}

const OUTCOME_TONES: Record< ExtensionRun[ 'outcome' ], Tone > = {
	ok: 'success',
	refused: 'info',
	unavailable: 'warning',
	error: 'danger',
	stopped: 'danger',
};

/**
 * The latest runs of the extension's handlers and console functions.
 *
 * @param props
 * @param props.extension The extension.
 */
function Runs( { extension }: { extension: Extension } ) {
	const [ runs, setRuns ] = useState< ExtensionRun[] | null >( null );
	const load = () => {
		setRuns( null );
		fetchRuns( extension.id )
			.then( ( answer ) => setRuns( answer.runs ) )
			.catch( () => setRuns( [] ) );
	};
	useEffect( load, [ extension.id ] );
	return (
		<Section
			title={ __( 'Recent runs', 'orillacart' ) }
			description={ __(
				'What the extension ran lately, how each run ended and the budget it used. Kept for as long as the shop keeps its history.',
				'orillacart'
			) }
			action={
				<Button variant="tertiary" onClick={ load }>
					{ __( 'Refresh', 'orillacart' ) }
				</Button>
			}
		>
			<DataTable
				columns={ [
					{
						id: 'at',
						label: __( 'When', 'orillacart' ),
						nowrap: true,
						render: ( r: ExtensionRun ) =>
							new Date( r.at ).toLocaleString(),
					},
					{
						id: 'what',
						label: __( 'What ran', 'orillacart' ),
						render: ( r: ExtensionRun ) => <code>{ r.what }</code>,
					},
					{
						id: 'outcome',
						label: __( 'Outcome', 'orillacart' ),
						render: ( r: ExtensionRun ) => (
							<>
								<Badge tone={ OUTCOME_TONES[ r.outcome ] }>
									{ r.outcome }
								</Badge>
								{ r.message && (
									<span className="oc-muted">
										{ ' ' + r.message }
									</span>
								) }
							</>
						),
					},
					{
						id: 'used',
						label: __( 'Used', 'orillacart' ),
						nowrap: true,
						align: 'end',
						render: ( r: ExtensionRun ) =>
							sprintf(
								/* translators: 1: steps, 2: calls, 3: seconds */
								__(
									'%1$d steps, %2$d calls, %3$s s',
									'orillacart'
								),
								r.used.steps ?? 0,
								r.used.calls ?? 0,
								String( r.used.seconds ?? 0 )
							),
					},
				] }
				rows={ runs ?? [] }
				rowKey={ ( r ) => r.at + r.what }
				loading={ runs === null }
				empty={ __( 'Nothing has run yet.', 'orillacart' ) }
			/>
		</Section>
	);
}

function slugOf( name: string ): string {
	return name
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^[^a-z]+|_+$/g, '' )
		.slice( 0, 40 );
}

function download(
	filename: string,
	content: string | Uint8Array< ArrayBuffer >,
	type = 'application/json'
) {
	const url = URL.createObjectURL( new Blob( [ content ], { type } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	URL.revokeObjectURL( url );
}
