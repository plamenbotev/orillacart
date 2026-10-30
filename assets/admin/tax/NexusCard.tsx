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
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { money } from '../orders/labels';
import { useBusy } from '../shared/useBusy';
import { useSavedCopy } from '../shared/useUnsavedChanges';
import { fetchStates, type State } from '../shared/geography';
import {
	fetchNexusReport,
	fetchNexusThresholds,
	type NexusReport,
	type NexusThreshold,
	type NexusThresholds,
	type NexusWindow,
	saveNexusThresholds,
} from './api';

/**
 * Where the shop may owe US states sales tax: its sales and orders in each state against the thresholds it
 * goes by, which it keeps here as states change them.
 * @param props
 * @param props.onSaved
 * @param props.onError
 */
export function NexusCard( {
	onSaved,
	onError,
}: {
	onSaved: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ report, setReport ] = useState< NexusReport | null >( null );
	const [ draft, setDraft ] = useState< NexusThresholds | null >( null );
	const [ states, setStates ] = useState< State[] >( [] );
	const [ busy, run ] = useBusy();
	const loaded = useSavedCopy( draft );
	// Loaded once: the screen's handler is a new function on each render.
	const failed = useRef( onError );
	failed.current = onError;

	useEffect( () => {
		Promise.all( [
			fetchNexusReport(),
			fetchNexusThresholds(),
			fetchStates( 'US' ),
		] )
			.then( ( [ loadedReport, loadedThresholds, loadedStates ] ) => {
				setReport( loadedReport );
				setDraft( loadedThresholds );
				loaded( loadedThresholds );
				setStates( loadedStates );
			} )
			.catch( ( error: unknown ) => failed.current( error ) );
	}, [ loaded ] );

	if ( report === null || draft === null ) {
		return (
			<Card className="orillacart-card">
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	const measured = ( { sales, orders }: NexusWindow ) =>
		sprintf(
			/* translators: 1: sales, 2: number of orders */
			__( '%1$s · %2$d orders', 'orillacart' ),
			money( sales, report.currency ),
			orders
		);
	const limit = ( { sales, orders }: NexusThreshold ) => {
		if ( sales !== null && orders !== null ) {
			return sprintf(
				/* translators: 1: sales, 2: number of orders */
				__( '%1$s or %2$d orders', 'orillacart' ),
				money( sales, report.currency ),
				orders
			);
		}
		if ( sales !== null ) {
			return money( sales, report.currency );
		}
		return orders !== null
			? sprintf(
					/* translators: %d: number of orders */
					__( '%d orders', 'orillacart' ),
					orders
				)
			: __( 'none set', 'orillacart' );
	};
	const change = (
		index: number | null,
		changes: Partial< NexusThreshold >
	) =>
		setDraft(
			index === null
				? { ...draft, everywhere: { ...draft.everywhere, ...changes } }
				: {
						...draft,
						states: draft.states.map( ( state, place ) =>
							place === index ? { ...state, ...changes } : state
						),
					}
		);
	const fields = ( threshold: NexusThreshold, index: number | null ) => (
		<>
			<FlexItem isBlock>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Sales in a year', 'orillacart' ) }
					value={ threshold.sales ?? '' }
					onChange={ ( sales ) =>
						change( index, {
							sales: sales.trim() === '' ? null : sales.trim(),
						} )
					}
				/>
			</FlexItem>
			<FlexItem isBlock>
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					label={ __( 'Or orders in a year', 'orillacart' ) }
					value={ threshold.orders ?? '' }
					onChange={ ( orders ) =>
						change( index, {
							orders:
								orders === ''
									? null
									: Number.parseInt( orders, 10 ),
						} )
					}
				/>
			</FlexItem>
		</>
	);
	const unlisted = states.filter(
		( state ) => ! draft.states.some( ( own ) => own.state === state.code )
	);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'US economic nexus', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'A US state may tax your sales into it once they cross its threshold in a year, even without an office there; you then register with it and collect its tax. States measure over the last twelve months, or this or last calendar year. Sales here are before tax and refunds.',
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
				{ report.states.length === 0 ? (
					<p>{ __( 'No sales to US states yet.', 'orillacart' ) }</p>
				) : (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'State', 'orillacart' ) }</th>
								<th>
									{ __( 'Last twelve months', 'orillacart' ) }
								</th>
								<th>{ __( 'This year', 'orillacart' ) }</th>
								<th>{ __( 'Last year', 'orillacart' ) }</th>
								<th>{ __( 'Threshold', 'orillacart' ) }</th>
								<th>{ __( 'Status', 'orillacart' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ report.states.map( ( state ) => (
								<tr key={ state.state }>
									<td>{ state.name }</td>
									<td>
										{ measured( state.last_twelve_months ) }
									</td>
									<td>{ measured( state.this_year ) }</td>
									<td>{ measured( state.last_year ) }</td>
									<td>{ limit( state.threshold ) }</td>
									<td>
										{ state.crossed &&
											! state.collecting &&
											! report.tax_service && (
												<strong>
													{ __(
														'Crossed: register and collect',
														'orillacart'
													) }
												</strong>
											) }
										{ state.crossed &&
											( state.collecting ||
												report.tax_service ) &&
											__(
												'Crossed: collecting',
												'orillacart'
											) }
										{ ! state.crossed &&
											__( 'Below', 'orillacart' ) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				{ report.tax_service && (
					<p>
						{ __(
							'A tax service works out your taxes: it collects in the states you registered with it.',
							'orillacart'
						) }
					</p>
				) }
				<h3>{ __( 'Thresholds', 'orillacart' ) }</h3>
				<p>
					{ __(
						'Most states count $100,000 of sales a year; some also count 200 orders, and a few count more sales. Check each state’s own, or ask your tax adviser.',
						'orillacart'
					) }
				</p>
				<Flex align="flex-end">
					<FlexItem>
						<strong>{ __( 'Every state', 'orillacart' ) }</strong>
					</FlexItem>
					{ fields( draft.everywhere, null ) }
				</Flex>
				{ draft.states.map( ( own, index ) => (
					<Flex key={ own.state } align="flex-end">
						<FlexItem>
							<strong>
								{ states.find(
									( state ) => state.code === own.state
								)?.name ?? own.state }
							</strong>
						</FlexItem>
						{ fields( own, index ) }
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								setDraft( {
									...draft,
									states: draft.states.filter(
										( _state, place ) => place !== index
									),
								} )
							}
						>
							{ __( 'Remove', 'orillacart' ) }
						</Button>
					</Flex>
				) ) }
				<Flex align="flex-end">
					<FlexItem isBlock>
						<SelectControl
							__next40pxDefaultSize
							label={ __(
								'A state with its own threshold',
								'orillacart'
							) }
							value=""
							options={ [
								{
									label: __( 'Choose a state', 'orillacart' ),
									value: '',
								},
								...unlisted.map( ( state ) => ( {
									label: state.name,
									value: state.code,
								} ) ),
							] }
							onChange={ ( code ) =>
								code !== '' &&
								setDraft( {
									...draft,
									states: [
										...draft.states,
										{
											state: code,
											sales: draft.everywhere.sales,
											orders: draft.everywhere.orders,
										},
									],
								} )
							}
						/>
					</FlexItem>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () =>
							run( () =>
								saveNexusThresholds( draft )
									.then( ( saved ) => {
										setDraft( saved );
										loaded( saved );
										onSaved();
										return fetchNexusReport().then(
											setReport
										);
									} )
									.catch( onError )
							)
						}
					>
						{ __( 'Save thresholds', 'orillacart' ) }
					</Button>
				</Flex>
			</CardBody>
		</Card>
	);
}
