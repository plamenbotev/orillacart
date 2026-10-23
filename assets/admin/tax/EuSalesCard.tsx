import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { money } from '../orders/labels';
import { useBusy } from '../shared/useBusy';
import { fetchEuSalesReport, saveEuThreshold, type EuSalesReport } from './api';

/**
 * A shop in the EU against the EU's threshold of distance sales: what it sold to consumers in other EU countries
 * this year and last, against €10,000. Shown only to a shop in the EU.
 * @param props
 * @param props.onSaved
 * @param props.onError
 */
export function EuSalesCard( {
	onSaved,
	onError,
}: {
	onSaved: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ report, setReport ] = useState< EuSalesReport | null >( null );
	const [ threshold, setThreshold ] = useState( '' );
	const [ busy, run ] = useBusy();
	// Loaded once: the screen's handler is a new function on each render.
	const failed = useRef( onError );
	failed.current = onError;

	const load = () =>
		fetchEuSalesReport().then( ( loaded ) => {
			setReport( loaded );
			setThreshold( loaded.threshold );
		} );

	useEffect( () => {
		load().catch( ( error: unknown ) => failed.current( error ) );
	}, [] );

	if ( report === null || ! report.applies ) {
		return null;
	}

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'EU distance sales', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Once your sales to consumers in other EU countries pass €10,000 in a calendar year, you charge each customer their own country’s VAT and declare it through the One-Stop-Shop (OSS), from the sale that passes it and all the next year. Below it, you may charge your own. Sales here are before tax; sales to businesses under the reverse charge are not counted.',
						'orillacart'
					) }
				</p>
				{ report.pending > 0 && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Some orders are not counted yet; the numbers catch up in a moment.',
							'orillacart'
						) }
					</Notice>
				) }
				{ report.crossed && report.taxed_at_origin && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Your sales passed the threshold, but tax goes by your shop’s address. Base tax on “EU customers’ own country (OSS)” above, and add the rates of the countries you sell to.',
							'orillacart'
						) }
					</Notice>
				) }
				{ report.converted && (
					<p className="description">
						{ __(
							'Sales in your other currencies are counted at today’s exchange rates.',
							'orillacart'
						) }
					</p>
				) }
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Country', 'orillacart' ) }</th>
							<th>{ __( 'This year', 'orillacart' ) }</th>
							<th>{ __( 'Last year', 'orillacart' ) }</th>
							<th>{ __( 'Status', 'orillacart' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ report.countries.length === 0 && (
							<tr>
								<td colSpan={ 4 }>
									{ __(
										'No sales to consumers in other EU countries yet.',
										'orillacart'
									) }
								</td>
							</tr>
						) }
						{ report.countries.map( ( country ) => (
							<tr key={ country.country }>
								<td>{ country.name }</td>
								<td>
									{ money(
										country.this_year,
										report.currency
									) }
								</td>
								<td>
									{ money(
										country.last_year,
										report.currency
									) }
								</td>
								<td>
									{ country.collecting
										? __( 'Has a rate', 'orillacart' )
										: __( 'No rate', 'orillacart' ) }
								</td>
							</tr>
						) ) }
					</tbody>
					<tfoot>
						<tr>
							<th>{ __( 'All', 'orillacart' ) }</th>
							<th>
								{ money(
									report.this_year.sales,
									report.currency
								) }
							</th>
							<th>
								{ money(
									report.last_year.sales,
									report.currency
								) }
							</th>
							<th>
								{ report.crossed
									? __( 'Passed', 'orillacart' )
									: sprintf(
											/* translators: %s: the threshold */
											__( 'Under %s', 'orillacart' ),
											money(
												report.threshold,
												report.currency
											)
										) }
							</th>
						</tr>
					</tfoot>
				</table>
				<Flex align="flex-end">
					<FlexItem isBlock>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Threshold', 'orillacart' ) }
							help={ __(
								'€10,000, in your shop’s currency when it is not the euro.',
								'orillacart'
							) }
							inputMode="decimal"
							value={ threshold }
							onChange={ setThreshold }
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy || threshold === report.threshold }
							onClick={ () =>
								run( () =>
									saveEuThreshold( threshold.trim() )
										.then( load )
										.then( onSaved )
										.catch( onError )
								)
							}
						>
							{ __( 'Save threshold', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardBody>
		</Card>
	);
}
