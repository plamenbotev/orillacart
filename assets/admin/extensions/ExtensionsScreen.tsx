import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
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
	deleteFile,
	disableExtension,
	enableExtension,
	exportExtension,
	fetchExtension,
	fetchExtensions,
	importExtension,
	installExtension,
	runFunction,
	saveSettings,
	uninstallExtension,
	writeFile,
	type Extension,
	type ExtensionSetting,
	type RunResult,
} from './api';

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
	}, [] );

	const show = ( extension: Extension ) => {
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
				accept="application/json,.json"
				className="oc-visually-hidden"
				aria-label={ __( 'Import an extension file', 'orillacart' ) }
				onChange={ ( event ) => {
					const file = event.target.files?.[ 0 ];
					event.target.value = '';
					if ( ! file ) {
						return;
					}
					file.text()
						.then( ( text ) =>
							importExtension( JSON.parse( text ) )
						)
						.then( ( extension ) => {
							notify(
								sprintf(
									__( 'Imported %s.', 'orillacart' ),
									extension.name
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
	const files = extension.files ?? [];
	const [ path, setPath ] = useState( files[ 0 ]?.path ?? '' );
	const [ content, setContent ] = useState( files[ 0 ]?.content ?? '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ newPath, setNewPath ] = useState( '' );
	const [ granting, setGranting ] = useState< string[] | null >( null );
	const [ removing, setRemoving ] = useState( false );
	const current = files.find( ( file ) => file.path === path );
	const dirty = current !== undefined && current.content !== content;

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
							exportExtension( extension.id )
								.then( ( payload ) =>
									download(
										`${ extension.id }.extension.json`,
										JSON.stringify( payload, null, 2 )
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
								__(
									'Stopped after failing %d times in a row: %s',
									'orillacart'
								),
								extension.failures,
								extension.last_problem
							)
						: sprintf(
								__( 'Last problem: %s', 'orillacart' ),
								extension.last_problem
							) }
				</Notice>
			) }

			<Section
				title={ __( 'Files', 'orillacart' ) }
				description={ __(
					'Scripts end in .ocs and compile together; templates go under templates/. A change to an enabled extension runs once it is enabled again.',
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
								label: file.path,
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
					<TextareaControl
						__nextHasNoMarginBottom
						label={ path || __( 'Contents', 'orillacart' ) }
						value={ content }
						onChange={ setContent }
						rows={ 24 }
						className="oc-code"
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
				<Findings findings={ extension.findings } />
			</Section>

			{ extension.settings !== null && extension.settings.length > 0 && (
				<SettingsSection
					extension={ extension }
					onChange={ onChange }
					onError={ setError }
				/>
			) }

			<Console extension={ extension } onError={ setError } />

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

function Findings( { findings }: { findings: string[] | null } ) {
	if ( findings === null ) {
		return null;
	}
	if ( findings.length === 0 ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ __( 'The scripts compile.', 'orillacart' ) }
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
		[ extension.id, extension.updated_at ]
	); // eslint-disable-line react-hooks/exhaustive-deps

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
				type={
					setting.type === 'secret'
						? 'password'
						: setting.type === 'number'
							? 'number'
							: 'text'
				}
				label={ setting.label }
				help={
					setting.type === 'secret' && setting.set
						? sprintf(
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

function initial(
	declared: ExtensionSetting[]
): Record< string, string | boolean > {
	const values: Record< string, string | boolean > = {};
	for ( const setting of declared ) {
		values[ setting.key ] =
			setting.type === 'boolean'
				? setting.value === true
				: setting.type === 'secret'
					? ''
					: String( setting.value ?? '' );
	}
	return values;
}

function Console( {
	extension,
	onError,
}: {
	extension: Extension;
	onError: ( message: string ) => void;
} ) {
	const [ fn, setFn ] = useState( '' );
	const [ args, setArgs ] = useState( '[]' );
	const [ result, setResult ] = useState< RunResult | null >( null );
	const [ busy, setBusy ] = useState( false );

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
				'Runs one top-level function of the extension with its granted permissions, and shows what it returned and what the run used.',
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
							__(
								'Used %d steps, %d calls, %s s.',
								'orillacart'
							),
							result.used.steps ?? 0,
							result.used.calls ?? 0,
							String( result.used.seconds ?? 0 )
						) }
					</p>
				</Notice>
			) }
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

function download( filename: string, text: string ) {
	const url = URL.createObjectURL(
		new Blob( [ text ], { type: 'application/json' } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	URL.revokeObjectURL( url );
}
