import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ComboboxControl,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { fetchCoupons } from '../coupons/api';
import { EMAIL_LABELS } from '../orders/labels';
import { errorMessage } from '../shared/api';
import { useLeaveGuard } from '../shared/useLeaveGuard';
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
	const [ leaving, guard ] = useLeaveGuard();
	const [ message, setMessage ] = useState< Message >( null );
	const [ coupons, setCoupons ] = useState< string[] >( [] );

	// The codes that match what is typed, from all of them: the newest first while nothing is.
	const [ couponSearch, setCouponSearch ] = useState( '' );
	useEffect( () => {
		const timer = window.setTimeout( () => {
			fetchCoupons( {
				search: couponSearch.trim() === '' ? undefined : couponSearch,
				page: 1,
				per_page: 20,
			} )
				.then( ( page ) =>
					setCoupons( page.coupons.map( ( coupon ) => coupon.code ) )
				)
				.catch( () => setCoupons( [] ) );
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ couponSearch ] );

	useEffect( () => {
		fetchEmailSettings()
			.then( setSettings )
			.catch( ( error: unknown ) =>
				setMessage( {
					status: 'error',
					text: errorMessage( error ),
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
				{ leaving }
				<h1>{ __( 'Emails', 'orillacart' ) }</h1>
				<TemplateEditor
					name={ editing }
					onClose={ () => guard( () => setEditing( null ) ) }
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
			cart_reminder_hours: settings.cart_reminder_hours,
			cart_reminder_coupon: settings.cart_reminder_coupon,
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
					text: errorMessage( error ),
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
						{ ! settings.disabled.includes( 'cart_reminder' ) && (
							<>
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 1 }
									max={ 168 }
									label={ __(
										'Remind of a cart left at checkout after (hours)',
										'orillacart'
									) }
									help={ __(
										'Once, to customers who ticked the box at checkout. The cart counts abandoned then, for the report.',
										'orillacart'
									) }
									value={ String(
										settings.cart_reminder_hours
									) }
									onChange={ ( value ) =>
										setSettings( {
											...settings,
											cart_reminder_hours:
												Number( value ),
										} )
									}
								/>
								<ComboboxControl
									__next40pxDefaultSize
									label={ __(
										'Coupon in the cart reminder',
										'orillacart'
									) }
									help={ __(
										'Its code shows in the email, and the link puts it in the cart. Type to find a code; clear it for none.',
										'orillacart'
									) }
									value={
										settings.cart_reminder_coupon ?? null
									}
									options={ [
										...new Set( [
											...coupons,
											...( settings.cart_reminder_coupon
												? [
														settings.cart_reminder_coupon,
													]
												: [] ),
										] ),
									].map( ( code ) => ( {
										label: code,
										value: code,
									} ) ) }
									onFilterValueChange={ setCouponSearch }
									onChange={ ( value ) =>
										setSettings( {
											...settings,
											cart_reminder_coupon: blank(
												value ?? ''
											),
										} )
									}
								/>
							</>
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
