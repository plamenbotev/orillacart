import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { DeliverySchedule } from './api';

const WEEKDAYS: [ number, string ][] = [
	[ 1, __( 'Monday', 'orillacart' ) ],
	[ 2, __( 'Tuesday', 'orillacart' ) ],
	[ 3, __( 'Wednesday', 'orillacart' ) ],
	[ 4, __( 'Thursday', 'orillacart' ) ],
	[ 5, __( 'Friday', 'orillacart' ) ],
	[ 6, __( 'Saturday', 'orillacart' ) ],
	[ 7, __( 'Sunday', 'orillacart' ) ],
];

const WORKING_WEEK: DeliverySchedule = {
	weekdays: [ 1, 2, 3, 4, 5 ],
	lead_days: 1,
	cutoff: null,
	days_ahead: 14,
	closed: [],
	slots: [],
	required: false,
};

/** A new slot's id: stays the same while it is edited, as orders count against it. */
const slotId = (): string =>
	`slot-${ Date.now().toString( 36 ) }${ Math.floor(
		Math.random() * 1296
	).toString( 36 ) }`;

const whole = ( value: string, least: number ): number =>
	Math.max( least, Number.parseInt( value, 10 ) || least );

/**
 * The days, and times of day, a carrier lets the customer choose for delivery at checkout.
 *
 * @param props          The fields' properties.
 * @param props.value    The schedule; null when the customer chooses no day.
 * @param props.onChange Receives the changed schedule.
 */
export function DeliveryScheduleFields( {
	value,
	onChange,
}: {
	value: DeliverySchedule | null;
	onChange: ( schedule: DeliverySchedule | null ) => void;
} ) {
	// As typed: the dates are read from it, the commas and spaces stay.
	const [ closedText, setClosedText ] = useState(
		value?.closed.join( ', ' ) ?? ''
	);
	const set = ( change: Partial< DeliverySchedule > ) =>
		value !== null && onChange( { ...value, ...change } );

	return (
		<fieldset className="orillacart-delivery-schedule">
			<CheckboxControl
				label={ __(
					'Customers choose the day of delivery',
					'orillacart'
				) }
				checked={ value !== null }
				onChange={ ( on ) => onChange( on ? WORKING_WEEK : null ) }
			/>
			{ value !== null && (
				<>
					<Flex wrap justify="flex-start">
						{ WEEKDAYS.map( ( [ day, name ] ) => (
							<FlexItem key={ day }>
								<CheckboxControl
									label={ name }
									checked={ value.weekdays.includes( day ) }
									onChange={ ( on ) =>
										set( {
											weekdays: on
												? [
														...value.weekdays,
														day,
													].sort()
												: value.weekdays.filter(
														( kept ) => kept !== day
													),
										} )
									}
								/>
							</FlexItem>
						) ) }
					</Flex>
					<Flex align="flex-end" wrap>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								label={ __( 'Days it needs', 'orillacart' ) }
								help={ __(
									'1 delivers tomorrow at the earliest.',
									'orillacart'
								) }
								value={ String( value.lead_days ) }
								onChange={ ( days ) =>
									set( { lead_days: whole( days, 0 ) } )
								}
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								type="time"
								label={ __(
									'Orders after this count from the next day',
									'orillacart'
								) }
								value={ value.cutoff ?? '' }
								onChange={ ( cutoff ) =>
									set( {
										cutoff: cutoff === '' ? null : cutoff,
									} )
								}
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 1 }
								label={ __(
									'Days to choose among',
									'orillacart'
								) }
								value={ String( value.days_ahead ) }
								onChange={ ( days ) =>
									set( { days_ahead: whole( days, 1 ) } )
								}
							/>
						</FlexItem>
					</Flex>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Closed on', 'orillacart' ) }
						help={ __(
							'Dates it does not deliver on, written year-month-day and separated by commas.',
							'orillacart'
						) }
						value={ closedText }
						onChange={ ( closed ) => {
							setClosedText( closed );
							set( {
								closed: closed
									.split( ',' )
									.map( ( date ) => date.trim() )
									.filter( ( date ) => date !== '' ),
							} );
						} }
					/>
					{ value.slots.map( ( slot, index ) => (
						<Flex key={ slot.id } align="flex-end" wrap>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									type="time"
									label={ sprintf(
										/* translators: %d: the time slot's number, from 1 */
										__( 'Slot %d from', 'orillacart' ),
										index + 1
									) }
									value={ slot.from }
									onChange={ ( from ) =>
										set( {
											slots: value.slots.map( ( kept ) =>
												kept.id === slot.id
													? { ...kept, from }
													: kept
											),
										} )
									}
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									type="time"
									label={ sprintf(
										/* translators: %d: the time slot's number, from 1 */
										__( 'Slot %d to', 'orillacart' ),
										index + 1
									) }
									value={ slot.to }
									onChange={ ( to ) =>
										set( {
											slots: value.slots.map( ( kept ) =>
												kept.id === slot.id
													? { ...kept, to }
													: kept
											),
										} )
									}
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 1 }
									label={ sprintf(
										/* translators: %d: the time slot's number, from 1 */
										__(
											'Slot %d: orders a day',
											'orillacart'
										),
										index + 1
									) }
									help={ __(
										'Empty for no limit.',
										'orillacart'
									) }
									value={
										slot.limit === null
											? ''
											: String( slot.limit )
									}
									onChange={ ( limit ) =>
										set( {
											slots: value.slots.map( ( kept ) =>
												kept.id === slot.id
													? {
															...kept,
															limit:
																limit === ''
																	? null
																	: whole(
																			limit,
																			1
																		),
														}
													: kept
											),
										} )
									}
								/>
							</FlexItem>
							<FlexItem>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										set( {
											slots: value.slots.filter(
												( kept ) => kept.id !== slot.id
											),
										} )
									}
								>
									{ __( 'Remove the slot', 'orillacart' ) }
								</Button>
							</FlexItem>
						</Flex>
					) ) }
					<Button
						variant="secondary"
						onClick={ () =>
							set( {
								slots: [
									...value.slots,
									{
										id: slotId(),
										from: '09:00',
										to: '12:00',
										limit: null,
									},
								],
							} )
						}
					>
						{ __( 'Add a time slot', 'orillacart' ) }
					</Button>
					<CheckboxControl
						label={ __(
							'The customer must choose a day',
							'orillacart'
						) }
						checked={ value.required }
						onChange={ ( required ) => set( { required } ) }
					/>
				</>
			) }
		</fieldset>
	);
}
