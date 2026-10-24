import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	SearchControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { money } from '../orders/labels';
import { errorMessage } from '../shared/api';
import { ProductTokens } from '../shared/CatalogTokens';
import { MoneyControl } from '../shared/MoneyControl';
import { useConfirm } from '../shared/useConfirm';
import { useLeaveGuard } from '../shared/useLeaveGuard';
import {
	adjustGiftCard,
	changeGiftCard,
	fetchGiftCard,
	fetchGiftCards,
	fetchGiftCardSettings,
	type GiftCardDetail,
	type GiftCardPage,
	type GiftCardSettings,
	type IssuedGiftCard,
	issueGiftCard,
	newGiftCardCode,
	saveGiftCardSettings,
} from './api';
import { AdjustForm, BalanceEntries } from './BalanceCard';
import { fromLocal, shopDate } from '../shared/dates';

type Message = { status: 'success' | 'error'; text: string } | null;

/**
 * Shop → Gift cards: the cards the shop gave or sold, what each has left and every change of it; making one and
 * sending its code; and which products sell as gift cards.
 */
export function GiftCardsScreen() {
	const [ message, setMessage ] = useState< Message >( null );
	const [ issued, setIssued ] = useState< IssuedGiftCard | null >( null );
	const [ selected, setSelectedNow ] = useState< number | null >( null );
	const [ leaving, guard, Guarded ] = useLeaveGuard();
	// Another row, or none, only once unsaved changes are let go; the row open stays as it is.
	const setSelected = ( next: Parameters< typeof setSelectedNow >[ 0 ] ) =>
		next !== selected && guard( () => setSelectedNow( next ) );
	const [ version, setVersion ] = useState( 0 );
	const fail = ( failure: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( failure ) } );
	const shown = ( made: IssuedGiftCard ) => {
		setIssued( made );
		// The card open may have a change to its balance typed: asked about, as opening any other card is.
		setSelected( made.card.id );
		setVersion( ( current ) => current + 1 );
	};

	return (
		<>
			{ leaving }
			<h1>{ __( 'Gift cards', 'orillacart' ) }</h1>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			{ issued !== null && (
				<Notice status="success" onRemove={ () => setIssued( null ) }>
					<p>
						{ issued.sent
							? sprintf(
									/* translators: %s: email address */
									__(
										'The code went to %s. Copy it now if you need it: it is not shown again.',
										'orillacart'
									),
									issued.card.recipient_email
								)
							: __(
									'Copy the code now and give it to whom the card is for: it is not shown again.',
									'orillacart'
								) }
					</p>
					<code className="orillacart-gift-card-code">
						{ issued.code }
					</code>
				</Notice>
			) }
			<IssueCard onIssued={ shown } onError={ fail } />
			{ selected !== null && (
				<Guarded>
					<DetailCard
						key={ `${ selected }-${ version }` }
						id={ selected }
						onClose={ () => setSelected( null ) }
						onNewCode={ shown }
						onChanged={ () =>
							setVersion( ( current ) => current + 1 )
						}
						onError={ fail }
					/>
				</Guarded>
			) }
			<ListCard
				version={ version }
				onSelect={ setSelected }
				onError={ fail }
			/>
			<SettingsCard
				onSaved={ () =>
					setMessage( {
						status: 'success',
						text: __( 'Gift card settings saved.', 'orillacart' ),
					} )
				}
				onError={ fail }
			/>
		</>
	);
}

function IssueCard( {
	onIssued,
	onError,
}: {
	onIssued: ( made: IssuedGiftCard ) => void;
	onError: ( failure: unknown ) => void;
} ) {
	const [ amount, setAmount ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ text, setText ] = useState( '' );
	const [ expires, setExpires ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Make a gift card', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						setBusy( true );
						issueGiftCard( {
							amount,
							recipient_name: name,
							recipient_email: email,
							message: text,
							// The end of the day in the shop's time zone, not the browser's.
							expires_at:
								expires === ''
									? null
									: fromLocal( `${ expires }T23:59` ),
						} )
							.then( ( made ) => {
								setAmount( '' );
								setName( '' );
								setEmail( '' );
								setText( '' );
								setExpires( '' );
								onIssued( made );
							} )
							.catch( onError )
							.finally( () => setBusy( false ) );
					} }
				>
					<Flex align="flex-start" wrap>
						<FlexItem>
							<MoneyControl
								label={ __( 'Worth', 'orillacart' ) }
								value={ amount }
								onChange={ setAmount }
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'For (name)', 'orillacart' ) }
								value={ name }
								onChange={ setName }
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="email"
								label={ __( 'Their email', 'orillacart' ) }
								help={ __(
									'The code goes there. Without one, give it yourself.',
									'orillacart'
								) }
								value={ email }
								onChange={ setEmail }
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								type="date"
								label={ __(
									'Use by (optional)',
									'orillacart'
								) }
								help={ __(
									'Empty: as the settings below say.',
									'orillacart'
								) }
								value={ expires }
								onChange={ setExpires }
							/>
						</FlexItem>
					</Flex>
					<TextareaControl
						label={ __( 'Message (optional)', 'orillacart' ) }
						value={ text }
						onChange={ setText }
						rows={ 2 }
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || amount === '' }
					>
						{ __( 'Make the gift card', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}

function ListCard( {
	version,
	onSelect,
	onError,
}: {
	version: number;
	onSelect: ( id: number ) => void;
	onError: ( failure: unknown ) => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ found, setFound ] = useState< GiftCardPage | null >( null );

	useEffect( () => {
		fetchGiftCards( { search, page } ).then( setFound ).catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ search, page, version ] );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Gift cards', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __(
						'Search by whom it is for, or the last four characters of the code',
						'orillacart'
					) }
					value={ search }
					onChange={ ( value ) => {
						setSearch( value );
						setPage( 1 );
					} }
				/>
				{ found !== null && found.cards.length === 0 && (
					<p>{ __( 'No gift cards yet.', 'orillacart' ) }</p>
				) }
				{ found !== null && found.cards.length > 0 && (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Card', 'orillacart' ) }</th>
								<th>{ __( 'For', 'orillacart' ) }</th>
								<th>{ __( 'Left', 'orillacart' ) }</th>
								<th>{ __( 'Use by', 'orillacart' ) }</th>
								<th>{ __( 'Made', 'orillacart' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ found.cards.map( ( card ) => (
								<tr key={ card.id }>
									<td>
										<Button
											variant="link"
											onClick={ () =>
												onSelect( card.id )
											}
										>
											{ card.label }
										</Button>
										{ ! card.active &&
											` (${ __( 'off', 'orillacart' ) })` }
									</td>
									<td>
										{ card.recipient_name ||
											card.recipient_email ||
											'—' }
									</td>
									<td>
										{ money( card.balance, card.currency ) }{ ' ' }
										/{ ' ' }
										{ money( card.initial, card.currency ) }
									</td>
									<td>
										{ card.expires_at === null
											? '—'
											: shopDate( card.expires_at ) }
									</td>
									<td>
										{ card.order_id === null
											? __( 'By the shop', 'orillacart' )
											: sprintf(
													/* translators: %d: order number */
													__(
														'Bought, order #%d',
														'orillacart'
													),
													card.order_id
												) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				{ found !== null && found.pages > 1 && (
					<Flex justify="flex-start">
						<Button
							variant="secondary"
							disabled={ page <= 1 }
							onClick={ () => setPage( page - 1 ) }
						>
							{ __( 'Newer', 'orillacart' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ page >= found.pages }
							onClick={ () => setPage( page + 1 ) }
						>
							{ __( 'Older', 'orillacart' ) }
						</Button>
					</Flex>
				) }
			</CardBody>
		</Card>
	);
}

function DetailCard( {
	id,
	onClose,
	onNewCode,
	onChanged,
	onError,
}: {
	id: number;
	onClose: () => void;
	onNewCode: ( made: IssuedGiftCard ) => void;
	onChanged: () => void;
	onError: ( failure: unknown ) => void;
} ) {
	const [ detail, setDetail ] = useState< GiftCardDetail | null >( null );
	const [ confirmation, confirm ] = useConfirm();

	useEffect( () => {
		fetchGiftCard( id ).then( setDetail ).catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id ] );

	if ( detail === null ) {
		return null;
	}
	const card = detail.card;
	return (
		<Card className="orillacart-card">
			{ confirmation }
			<CardHeader>
				<h2>{ card.label }</h2>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'orillacart' ) }
				</Button>
			</CardHeader>
			<CardBody>
				<p>
					{ sprintf(
						/* translators: 1: what is left, 2: what it was worth */
						__( '%1$s left of %2$s.', 'orillacart' ),
						money( card.balance, card.currency ),
						money( card.initial, card.currency )
					) }{ ' ' }
					{ card.recipient_email !== '' &&
						sprintf(
							/* translators: %s: email address */
							__( 'For %s.', 'orillacart' ),
							card.recipient_email
						) }
				</p>
				<Flex align="flex-end" wrap>
					<FlexItem>
						<CheckboxControl
							label={ __( 'Pays for orders', 'orillacart' ) }
							checked={ card.active }
							onChange={ ( active ) =>
								changeGiftCard( id, {
									active,
									expires_at: card.expires_at,
								} )
									.then( onChanged )
									.catch( onError )
							}
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							onClick={ () =>
								confirm(
									__(
										'Make a new code for this card? The old one pays no more, and the new one goes to whom the card is for.',
										'orillacart'
									),
									() => {
										newGiftCardCode( id )
											.then( onNewCode )
											.catch( onError );
									},
									__( 'Make a new code', 'orillacart' )
								)
							}
						>
							{ __( 'Send a new code', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
				<h3>{ __( 'Its balance', 'orillacart' ) }</h3>
				<AdjustForm
					onAdjust={ ( change ) =>
						adjustGiftCard( id, change )
							.then( ( changed ) => {
								setDetail( changed );
								onChanged();
							} )
							.catch( ( failure: unknown ) => {
								onError( failure );
								throw failure;
							} )
					}
				/>
				<BalanceEntries detail={ detail } />
			</CardBody>
		</Card>
	);
}

function SettingsCard( {
	onSaved,
	onError,
}: {
	onSaved: () => void;
	onError: ( failure: unknown ) => void;
} ) {
	const [ settings, setSettings ] = useState< GiftCardSettings | null >(
		null
	);
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchGiftCardSettings().then( setSettings ).catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( settings === null ) {
		return null;
	}
	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Selling gift cards', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						setBusy( true );
						saveGiftCardSettings( settings )
							.then( ( saved ) => {
								setSettings( saved );
								onSaved();
							} )
							.catch( onError )
							.finally( () => setBusy( false ) );
					} }
				>
					<ProductTokens
						label={ __(
							'Products sold as gift cards',
							'orillacart'
						) }
						help={ __(
							'Once an order is paid, each of these it holds becomes a gift card worth what the customer paid for it, emailed to the customer to give on. Make them virtual and without tax: tax is charged when the card is spent.',
							'orillacart'
						) }
						ids={ settings.product_ids }
						onChange={ ( ids ) =>
							setSettings( { ...settings, product_ids: ids } )
						}
						onError={ onError }
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 120 }
						label={ __( 'A card lasts (months)', 'orillacart' ) }
						help={ __(
							"From when it is made, for cards sold and given. Empty: for ever. Some countries set the least a gift card must last. Refunds given as a gift card or store credit never expire: they are the customer's money.",
							'orillacart'
						) }
						value={
							settings.valid_months === null
								? ''
								: String( settings.valid_months )
						}
						onChange={ ( value ) =>
							setSettings( {
								...settings,
								valid_months:
									value === ''
										? null
										: Math.max( 1, Number( value ) ),
							} )
						}
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Save', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
