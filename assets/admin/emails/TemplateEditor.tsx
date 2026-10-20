import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { EMAIL_LABELS } from '../orders/labels';
import { toApiError } from '../shared/api';
import { useBusy } from '../shared/useBusy';
import { useConfirm } from '../shared/useConfirm';
import {
	type EmailPreview,
	type EmailTemplateSource,
	fetchEmailTemplate,
	previewEmail,
	type PreviewInput,
	resetEmailTemplate,
	saveEmailTemplate,
	sendTestEmail,
} from './api';
import { isPart, templateLabel } from './templates';

type Message = { status: 'success' | 'error'; text: string } | null;

interface TemplateEditorProps {
	name: string;
	onClose: () => void;
}

/**
 * One email template in Twig, as the shop writes it: saved in place of the theme's or the plugin's file, checked
 * first, and shown before it is saved with a real order (the latest, or one the shop names). A test goes to
 * whoever asks; resetting goes back to the file.
 *
 * @param props         The editor's properties.
 * @param props.name    The template, as the files name it: "order-shipped".
 * @param props.onClose Goes back to the list.
 */
export function TemplateEditor( { name, onClose }: TemplateEditorProps ) {
	const [ template, setTemplate ] = useState< EmailTemplateSource | null >(
		null
	);
	const [ source, setSource ] = useState( '' );
	const [ kind, setKind ] = useState< string | null >( null );
	const [ order, setOrder ] = useState( '' );
	const [ preview, setPreview ] = useState< EmailPreview | null >( null );
	const [ message, setMessage ] = useState< Message >( null );
	const [ busy, run ] = useBusy();
	const [ dialog, confirm ] = useConfirm();
	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );

	useEffect( () => {
		fetchEmailTemplate( name )
			.then( ( loaded ) => {
				setTemplate( loaded );
				setSource( loaded.source );
			} )
			.catch( fail );
	}, [ name ] );

	if ( template === null ) {
		return message === null ? (
			<Spinner />
		) : (
			<Notice status="error" isDismissible={ false }>
				{ message.text }
			</Notice>
		);
	}

	const input = ( draft: boolean ): PreviewInput => ( {
		source: draft ? source : null,
		kind,
		order: order === '' ? null : Number( order ),
	} );
	const loaded = ( saved: EmailTemplateSource, text: string ) => {
		setTemplate( saved );
		setSource( saved.source );
		setMessage( { status: 'success', text } );
	};
	const save = () =>
		run( () =>
			saveEmailTemplate( name, source )
				.then( ( saved ) =>
					loaded( saved, __( 'Template saved.', 'orillacart' ) )
				)
				.catch( fail )
		);
	const show = () =>
		run( () =>
			previewEmail( name, input( true ) )
				.then( ( shown ) => {
					setPreview( shown );
					setMessage( null );
				} )
				.catch( ( error: unknown ) => {
					setPreview( null );
					fail( error );
				} )
		);
	const test = () =>
		run( () =>
			sendTestEmail( name, input( true ) )
				.then( ( sent ) =>
					setMessage(
						sent.sent
							? {
									status: 'success',
									text: sprintf(
										/* translators: %s: email address */
										__( 'Test sent to %s.', 'orillacart' ),
										sent.to
									),
								}
							: {
									status: 'error',
									text: __(
										'The mail system refused the test.',
										'orillacart'
									),
								}
					)
				)
				.catch( fail )
		);
	const reset = () =>
		confirm(
			__(
				'Your own template is deleted and the file’s is used again.',
				'orillacart'
			),
			() =>
				run( () =>
					resetEmailTemplate( name )
						.then( ( saved ) =>
							loaded(
								saved,
								__(
									'Back to the file’s template.',
									'orillacart'
								)
							)
						)
						.catch( fail )
				),
			__( 'Reset', 'orillacart' )
		);
	const changed = source !== template.source;

	return (
		<>
			{ dialog }
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ templateLabel( name ) }</h2>
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Back to the emails', 'orillacart' ) }
					</Button>
				</CardHeader>
				<CardBody>
					{ message !== null && (
						<Notice
							status={ message.status }
							onRemove={ () => setMessage( null ) }
						>
							{ message.text }
						</Notice>
					) }
					<p>
						{ template.edited
							? __(
									'The shop’s own template, in place of the file’s.',
									'orillacart'
								)
							: __(
									'The file’s template. Saving keeps your own in its place; a theme’s file of the same name no longer applies.',
									'orillacart'
								) }
					</p>
					<TextareaControl
						className="orillacart-template-source"
						label={ __( 'Template (Twig)', 'orillacart' ) }
						help={ __(
							'Emails run in a sandbox: Twig’s tags and filters, the shop’s (__, money, shop_date, address_lines, slot), and the values the email is given.',
							'orillacart'
						) }
						rows={ 24 }
						value={ source }
						onChange={ setSource }
					/>
					<Flex justify="flex-start" wrap>
						{ isPart( name ) && (
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Show in', 'orillacart' ) }
								value={ kind ?? template.previews }
								options={ Object.entries( EMAIL_LABELS ).map(
									( [ value, label ] ) => ( {
										value,
										label,
									} )
								) }
								onChange={ setKind }
							/>
						) }
						<TextControl
							__next40pxDefaultSize
							type="number"
							min={ 1 }
							label={ __( 'Order', 'orillacart' ) }
							help={ __( 'Empty: the latest.', 'orillacart' ) }
							value={ order }
							onChange={ setOrder }
						/>
					</Flex>
					<Flex justify="flex-start">
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ busy }
							disabled={ busy || ! changed }
						>
							{ __( 'Save', 'orillacart' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ show }
							disabled={ busy }
						>
							{ __( 'Preview', 'orillacart' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ test }
							disabled={ busy }
						>
							{ __( 'Send me a test', 'orillacart' ) }
						</Button>
						{ template.edited && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ reset }
								disabled={ busy }
							>
								{ __( 'Reset to the file', 'orillacart' ) }
							</Button>
						) }
					</Flex>
				</CardBody>
			</Card>
			{ preview !== null && (
				<Card className="orillacart-card">
					<CardHeader>
						<h2>
							{ preview.subject }
							<small>
								{ ' ' }
								{ sprintf(
									/* translators: %s: email address */
									__( 'to %s', 'orillacart' ),
									preview.to
								) }
							</small>
						</h2>
					</CardHeader>
					<CardBody>
						<iframe
							className="orillacart-email-preview"
							title={ __( 'Preview', 'orillacart' ) }
							sandbox=""
							srcDoc={ preview.html }
						/>
					</CardBody>
				</Card>
			) }
		</>
	);
}
