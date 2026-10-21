import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	type CurrencySettings,
	fetchCurrencies,
	type OfferedCurrency,
	saveCurrencies,
} from './currencies';

const ROUNDINGS = [
	{ value: 'decimals', label: __( 'To the cent', 'orillacart' ) },
	{ value: 'whole', label: __( 'To whole units (37)', 'orillacart' ) },
	{
		value: 'ninety_nine',
		label: __( 'Just under the next unit (36.99)', 'orillacart' ),
	},
];

/**
 * The currencies shoppers may choose besides the shop's own: each with the shop's rate for it and how its
 * converted prices are rounded. Shoppers choose with the Currency switcher block, and pay in what they chose.
 *
 * @param props          The card's properties.
 * @param props.currency The shop's own currency.
 */
export function CurrenciesCard( { currency }: { currency: string } ) {
	const [ settings, setSettings ] = useState< CurrencySettings | null >(
		null
	);
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const fail = ( failure: unknown ) =>
		setMessage( { status: 'error', text: toApiError( failure ).message } );

	useEffect( () => {
		fetchCurrencies().then( setSettings ).catch( fail );
	}, [] );

	const change = ( index: number, changed: Partial< OfferedCurrency > ) =>
		settings !== null &&
		setSettings( {
			offered: settings.offered.map( ( offered, at ) =>
				at === index ? { ...offered, ...changed } : offered
			),
		} );

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Currencies', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ sprintf(
						/* translators: %s: the shop's currency */
						__(
							'Prices stay in %s. A shopper who chooses another currency with the Currency switcher block sees them converted at your rate, and orders and pays in it. Change a rate here when you want it to follow the market; orders keep the prices they were placed at.',
							'orillacart'
						),
						currency
					) }
				</p>
				{ message !== null && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }
				{ settings === null ? (
					message === null && <Spinner />
				) : (
					<form
						onSubmit={ ( event ) => {
							event.preventDefault();
							setBusy( true );
							saveCurrencies( settings )
								.then( ( saved ) => {
									setSettings( saved );
									setMessage( {
										status: 'success',
										text: __(
											'Currencies saved.',
											'orillacart'
										),
									} );
								} )
								.catch( fail )
								.finally( () => setBusy( false ) );
						} }
					>
						{ settings.offered.map( ( offered, index ) => (
							<Flex key={ index } align="flex-end" wrap>
								<FlexItem>
									<TextControl
										__next40pxDefaultSize
										label={ __(
											'Offered currency',
											'orillacart'
										) }
										help={ __(
											'Such as USD',
											'orillacart'
										) }
										maxLength={ 3 }
										value={ offered.code }
										onChange={ ( code ) =>
											change( index, {
												code: code.toUpperCase(),
											} )
										}
									/>
								</FlexItem>
								<FlexItem>
									<TextControl
										__next40pxDefaultSize
										label={ sprintf(
											/* translators: 1: the shop's currency, 2: the other currency */
											__(
												'1 %1$s is … %2$s',
												'orillacart'
											),
											currency,
											offered.code || '…'
										) }
										inputMode="decimal"
										value={ offered.rate }
										onChange={ ( rate ) =>
											change( index, {
												rate: rate.replace( ',', '.' ),
											} )
										}
									/>
								</FlexItem>
								<FlexItem>
									<SelectControl
										__next40pxDefaultSize
										label={ __(
											'Round prices',
											'orillacart'
										) }
										value={ offered.rounding }
										options={ ROUNDINGS }
										onChange={ ( rounding ) =>
											change( index, {
												rounding:
													rounding as OfferedCurrency[ 'rounding' ],
											} )
										}
									/>
								</FlexItem>
								<FlexItem>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											setSettings( {
												offered:
													settings.offered.filter(
														( _, at ) =>
															at !== index
													),
											} )
										}
									>
										{ __( 'Remove', 'orillacart' ) }
									</Button>
								</FlexItem>
							</Flex>
						) ) }
						<Flex justify="flex-start">
							<Button
								variant="secondary"
								onClick={ () =>
									setSettings( {
										offered: [
											...settings.offered,
											{
												code: '',
												rate: '',
												rounding: 'decimals',
											},
										],
									} )
								}
							>
								{ __( 'Add a currency', 'orillacart' ) }
							</Button>
							<Button
								variant="primary"
								type="submit"
								isBusy={ busy }
								disabled={ busy }
							>
								{ __( 'Save currencies', 'orillacart' ) }
							</Button>
						</Flex>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
