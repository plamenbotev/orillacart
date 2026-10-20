import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { EMAIL_LABELS } from '../orders/labels';
import { toApiError } from '../shared/api';
import {
	type EmailSettings,
	type EmailTemplate,
	fetchEmailSettings,
	fetchEmailTemplates,
	saveEmailSettings,
} from './api';
import './emails.css';
import { TemplateEditor } from './TemplateEditor';
import { templateLabel } from './templates';

type Message = { status: 'success' | 'error'; text: string } | null;

/**
 * The Shop → Emails screen: who the shop's emails come from, where the shop's own go, which are sent, and the
 * templates they are written from, which the shop can write itself (a theme can too, in orillacart/emails/).
 */
export function EmailsScreen() {
	const [ settings, setSettings ] = useState< EmailSettings | null >( null );
	const [ templates, setTemplates ] = useState< EmailTemplate[] >( [] );
	const [ editing, setEditing ] = useState< string | null >( null );
	const [ message, setMessage ] = useState< Message >( null );

	useEffect( () => {
		fetchEmailSettings()
			.then( setSettings )
			.catch( ( error: unknown ) =>
				setMessage( {
					status: 'error',
					text: toApiError( error ).message,
				} )
			);
	}, [] );
	useEffect( () => {
		if ( editing === null ) {
			fetchEmailTemplates()
				.then( setTemplates )
				.catch( () => setTemplates( [] ) );
		}
	}, [ editing ] );

	if ( editing !== null ) {
		return (
			<>
				<h1>{ __( 'Emails', 'orillacart' ) }</h1>
				<TemplateEditor
					name={ editing }
					onClose={ () => setEditing( null ) }
				/>
			</>
		);
	}

	const notice = message !== null && (
		<Notice status={ message.status } onRemove={ () => setMessage( null ) }>
			{ message.text }
		</Notice>
	);
	if ( settings === null ) {
		return (
			<>
				<h1>{ __( 'Emails', 'orillacart' ) }</h1>
				{ message !== null ? notice : <Spinner /> }
			</>
		);
	}

	const text = ( value: string | null ) => value ?? '';
	const blank = ( value: string ) => ( value.trim() === '' ? null : value );
	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		saveEmailSettings( {
			from_name: settings.from_name,
			from_address: settings.from_address,
			shop_address: settings.shop_address,
			disabled: settings.disabled,
			reminder_days: settings.reminder_days,
		} )
			.then( ( saved ) => {
				setSettings( saved );
				setMessage( {
					status: 'success',
					text: __( 'Email settings saved.', 'orillacart' ),
				} );
			} )
			.catch( ( error: unknown ) =>
				setMessage( {
					status: 'error',
					text: toApiError( error ).message,
				} )
			);
	};

	return (
		<>
			<h1>{ __( 'Emails', 'orillacart' ) }</h1>
			{ notice }
			<form onSubmit={ submit }>
				<Card className="orillacart-card">
					<CardHeader>
						<h2>{ __( 'Sender', 'orillacart' ) }</h2>
					</CardHeader>
					<CardBody>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'From name', 'orillacart' ) }
							placeholder={ settings.default_name }
							value={ text( settings.from_name ) }
							onChange={ ( value ) =>
								setSettings( {
									...settings,
									from_name: blank( value ),
								} )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							type="email"
							label={ __( 'From address', 'orillacart' ) }
							placeholder={ settings.default_address }
							value={ text( settings.from_address ) }
							onChange={ ( value ) =>
								setSettings( {
									...settings,
									from_address: blank( value ),
								} )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							type="email"
							label={ __(
								'The shop’s own emails go to',
								'orillacart'
							) }
							placeholder={ settings.default_address }
							value={ text( settings.shop_address ) }
							onChange={ ( value ) =>
								setSettings( {
									...settings,
									shop_address: blank( value ),
								} )
							}
						/>
					</CardBody>
				</Card>
				<Card className="orillacart-card">
					<CardHeader>
						<h2>{ __( 'Emails sent', 'orillacart' ) }</h2>
					</CardHeader>
					<CardBody>
						{ settings.kinds.map( ( kind ) => (
							<CheckboxControl
								key={ kind }
								label={ EMAIL_LABELS[ kind ] ?? kind }
								checked={ ! settings.disabled.includes( kind ) }
								onChange={ ( on ) =>
									setSettings( {
										...settings,
										disabled: on
											? settings.disabled.filter(
													( current ) =>
														current !== kind
												)
											: [ ...settings.disabled, kind ],
									} )
								}
							/>
						) ) }
						{ ! settings.disabled.includes(
							'payment_reminder'
						) && (
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 1 }
								max={ 60 }
								label={ __(
									'Remind to pay after (days)',
									'orillacart'
								) }
								help={ __(
									'Orders on hold for a bank transfer or cheque; not cash on delivery.',
									'orillacart'
								) }
								value={ String( settings.reminder_days ) }
								onChange={ ( value ) =>
									setSettings( {
										...settings,
										reminder_days: Number( value ),
									} )
								}
							/>
						) }
					</CardBody>
				</Card>
				<Button variant="primary" type="submit">
					{ __( 'Save', 'orillacart' ) }
				</Button>
			</form>
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ __( 'Templates', 'orillacart' ) }</h2>
				</CardHeader>
				<CardBody>
					<table className="widefat striped">
						<tbody>
							{ templates.map( ( template ) => (
								<tr key={ template.name }>
									<td>
										{ templateLabel( template.name ) }
										{ template.edited && (
											<em>
												{ ' — ' }
												{ __(
													'the shop’s own',
													'orillacart'
												) }
											</em>
										) }
									</td>
									<td>
										<Button
											variant="link"
											onClick={ () =>
												setEditing( template.name )
											}
										>
											{ __( 'Edit', 'orillacart' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</CardBody>
			</Card>
		</>
	);
}
