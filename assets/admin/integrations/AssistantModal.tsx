import {
	Button,
	CheckboxControl,
	Flex,
	Modal,
	Notice,
	RadioControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import {
	type ApiKey,
	type AssistantChoices,
	type AssistantCatalogue,
	fetchAssistantChoices,
	saveAssistantChoices,
} from './api';

/** The shop's areas as the owner knows them; an add-on's tools come under the add-on's own name. */
const AREAS: Record< string, string > = {
	Audit: __( 'Change log and problems', 'orillacart' ),
	Catalog: __( 'Products', 'orillacart' ),
	Checkout: __( 'Orders the shop takes', 'orillacart' ),
	Coupons: __( 'Coupons and discounts', 'orillacart' ),
	Customers: __( 'Customers', 'orillacart' ),
	Exchange: __( 'Import and export', 'orillacart' ),
	Forms: __( 'Forms', 'orillacart' ),
	GiftCards: __( 'Gift cards and store credit', 'orillacart' ),
	Inventory: __( 'Stock and stockrooms', 'orillacart' ),
	Invoicing: __( 'Invoices', 'orillacart' ),
	Jobs: __( 'Background jobs', 'orillacart' ),
	Newsletter: __( 'Newsletter', 'orillacart' ),
	Notifications: __( 'Emails', 'orillacart' ),
	Ordering: __( 'Orders', 'orillacart' ),
	Payment: __( 'Payments and refunds', 'orillacart' ),
	Reports: __( 'Reports', 'orillacart' ),
	Returns: __( 'Returns', 'orillacart' ),
	Reviews: __( 'Reviews', 'orillacart' ),
	Search: __( 'Search', 'orillacart' ),
	Settings: __( 'Settings', 'orillacart' ),
	Setup: __( 'Setup', 'orillacart' ),
	Shipping: __( 'Shipping', 'orillacart' ),
	Storefront: __( 'Storefront', 'orillacart' ),
	Tax: __( 'Tax', 'orillacart' ),
	WordPress: __( 'Product pages, categories and images', 'orillacart' ),
};

const RISKS = [
	{
		value: 'money',
		label: __(
			'Money moves: refunds, orders marked paid, gift card balances',
			'orillacart'
		),
	},
	{ value: 'deleting', label: __( 'Something is deleted', 'orillacart' ) },
	{
		value: 'customers',
		label: __(
			'Customers hear of it: emails, returns decided',
			'orillacart'
		),
	},
	{
		value: 'opening',
		label: __(
			'The shop opens or closes, or many settings change at once',
			'orillacart'
		),
	},
	{
		value: 'publishing',
		label: __(
			'A page customers read goes live, changes or comes down: terms, shipping and payment',
			'orillacart'
		),
	},
];

interface Props {
	apiKey: ApiKey;
	catalogue: AssistantCatalogue;
	onClose: () => void;
}

/**
 * What one key's AI assistant is offered: the shop's areas and add-ons' tools, how they are listed, and what
 * waits for the owner's yes.
 *
 * @param props           The key, the tools there are, and closing.
 * @param props.apiKey    The key.
 * @param props.catalogue The tools there are, by area.
 * @param props.onClose   Closes the dialog.
 */
export function AssistantModal( { apiKey, catalogue, onClose }: Props ) {
	const [ choices, setChoices ] = useState< AssistantChoices | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		fetchAssistantChoices( apiKey.id )
			.then( setChoices )
			.catch( ( failure ) => setError( errorMessage( failure ) ) );
	}, [ apiKey.id ] );

	const toggle = < T, >( list: T[], value: T, on: boolean ): T[] =>
		on
			? [ ...list.filter( ( item ) => item !== value ), value ]
			: list.filter( ( item ) => item !== value );

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: the name of an API key */
				__( 'AI assistant with the key "%s"', 'orillacart' ),
				apiKey.name
			) }
			onRequestClose={ onClose }
			size="medium"
		>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ choices !== null && (
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						setSaving( true );
						saveAssistantChoices( apiKey.id, choices )
							.then( onClose )
							.catch( ( failure ) =>
								setError( errorMessage( failure ) )
							)
							.finally( () => setSaving( false ) );
					} }
				>
					<h3>{ __( 'Ask me first when', 'orillacart' ) }</h3>
					<p>
						{ __(
							'The assistant asks you in its own window before it does these, when it can ask; when it cannot, it is refused.',
							'orillacart'
						) }
					</p>
					{ RISKS.map( ( risk ) => (
						<CheckboxControl
							key={ risk.value }
							__nextHasNoMarginBottom
							label={ risk.label }
							checked={ choices.ask.includes( risk.value ) }
							onChange={ ( on ) =>
								setChoices( {
									...choices,
									ask: toggle( choices.ask, risk.value, on ),
								} )
							}
						/>
					) ) }

					<h3>{ __( 'What it may use', 'orillacart' ) }</h3>
					{ apiKey.scope === 'read' && (
						<p>
							{ __(
								'This key only reads, so the assistant is offered only what reads.',
								'orillacart'
							) }
						</p>
					) }
					{ catalogue.areas.map( ( area ) => (
						<div
							key={ area.area }
							className="orillacart-assistant-area"
						>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ sprintf(
									/* translators: 1: an area of the shop or an add-on's name, 2: how many tools it has */
									__( '%1$s (%2$d tools)', 'orillacart' ),
									AREAS[ area.area ] ?? area.area,
									area.tools.length
								) }
								checked={
									! choices.off_areas.includes( area.area )
								}
								onChange={ ( on ) =>
									setChoices( {
										...choices,
										off_areas: toggle(
											choices.off_areas,
											area.area,
											! on
										),
									} )
								}
							/>
							{ area.add_on &&
								! choices.off_areas.includes( area.area ) &&
								area.tools.map( ( tool ) => (
									<CheckboxControl
										key={ tool.name }
										className="orillacart-assistant-area__tool"
										__nextHasNoMarginBottom
										label={ tool.title }
										help={ tool.description }
										checked={
											! choices.off.includes( tool.name )
										}
										onChange={ ( on ) =>
											setChoices( {
												...choices,
												off: toggle(
													choices.off,
													tool.name,
													! on
												),
											} )
										}
									/>
								) ) }
						</div>
					) ) }

					<RadioControl
						label={ __( 'How the tools are listed', 'orillacart' ) }
						selected={ choices.list }
						options={ [
							{
								value: 'all',
								label: __(
									'All of them (Claude and most clients)',
									'orillacart'
								),
							},
							{
								value: 'find',
								label: __(
									'Three tools that find the others (for clients that take few tools)',
									'orillacart'
								),
							},
						] }
						onChange={ ( value ) =>
							setChoices( {
								...choices,
								list: value === 'find' ? 'find' : 'all',
							} )
						}
					/>
					<Flex justify="flex-end">
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'orillacart' ) }
						</Button>
						<Button
							variant="primary"
							type="submit"
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save', 'orillacart' ) }
						</Button>
					</Flex>
				</form>
			) }
		</Modal>
	);
}
