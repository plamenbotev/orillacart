import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { answered, flag, text, type StepProps } from './types';

type OrderButton = 'buy_now' | 'order_and_pay' | 'place_order';

/**
 * The button's words as the server keeps them.
 *
 * @param value The answer given.
 */
function orderButton( value: string ): OrderButton {
	return value === 'buy_now' || value === 'order_and_pay'
		? value
		: 'place_order';
}

/**
 * Legal: what the law of the shop's country asks, the pages the setup drafts for it, and what checkout asks
 * before an order.
 * @param root0
 * @param root0.answers
 * @param root0.profile
 * @param root0.save
 * @param root0.fail
 */
export function LegalStep( { answers, profile, save, fail }: StepProps ) {
	const given = answered( answers, 'legal' );
	const consumers = profile?.consumer_checkout ?? false;
	const [ pages, setPages ] = useState( flag( given, 'pages', true ) );
	const [ accept, setAccept ] = useState(
		flag( given, 'accept_terms', consumers )
	);
	const [ button, setButton ] = useState< OrderButton >(
		orderButton(
			text( given, 'order_button', consumers ? 'buy_now' : 'place_order' )
		)
	);
	const [ busy, setBusy ] = useState( false );

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( { pages, accept_terms: accept, order_button: button } )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			{ profile !== null && (
				<ul className="orillacart-setup__facts">
					{ profile.withdrawal_days !== null && (
						<li>
							{ sprintf(
								/* translators: %d: days */
								__(
									'Consumers may withdraw from a purchase within %d days, and must be told how before they order.',
									'orillacart'
								),
								profile.withdrawal_days
							) }
						</li>
					) }
					{ profile.legal_notice && (
						<li>
							{ sprintf(
								/* translators: %s: the page's name, such as Impressum */
								__(
									'The website must name the seller, with its address and registration (%s).',
									'orillacart'
								),
								profile.legal_notice_title
							) }
						</li>
					) }
					{ consumers && (
						<li>
							{ __(
								'The order button must say that ordering means paying.',
								'orillacart'
							) }
						</li>
					) }
					{ profile.e_invoices !== null && (
						<li>
							{ sprintf(
								/* translators: %s: from when, and how */
								__(
									'Invoices to businesses must be electronic from %s.',
									'orillacart'
								),
								profile.e_invoices
							) }
						</li>
					) }
				</ul>
			) }
			<CheckboxControl
				label={ __(
					'Draft the legal pages the shop has none of',
					'orillacart'
				) }
				help={ __(
					'Terms of sale and the right of withdrawal as drafts for you to check and publish; shipping and payment, and the legal notice, kept up to date from the shop’s settings.',
					'orillacart'
				) }
				checked={ pages }
				onChange={ setPages }
			/>
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'The drafts are a starting point, not legal advice: check them against the law that applies to your shop, or have a lawyer check them.',
					'orillacart'
				) }
			</Notice>
			<CheckboxControl
				label={ __(
					'Checkout asks customers to accept the terms of sale',
					'orillacart'
				) }
				checked={ accept }
				onChange={ setAccept }
			/>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'The order button says', 'orillacart' ) }
				value={ button }
				options={ [
					{ value: 'buy_now', label: __( 'Buy now', 'orillacart' ) },
					{
						value: 'order_and_pay',
						label: __(
							'Order with obligation to pay',
							'orillacart'
						),
					},
					{
						value: 'place_order',
						label: __( 'Place order', 'orillacart' ),
					},
				] }
				onChange={ setButton }
			/>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Save and go on', 'orillacart' ) }
			</Button>
		</form>
	);
}
