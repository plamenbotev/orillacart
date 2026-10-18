import { __ } from '@wordpress/i18n';
import {
	Bar,
	BarChart,
	CartesianGrid,
	Legend,
	Line,
	LineChart,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from 'recharts';

import type { Interval } from './api';

/** The admin's blue for this period, grey for the period compared with. */
export const CURRENT = '#2271b1';
export const PREVIOUS = '#a7aaad';
export const SECOND = '#72aee6';

/**
 * @param date     A day, Y-m-d: the first of its group.
 * @param interval How days are grouped.
 * @return The group as the chart labels it, in the browser's language.
 */
export function bucketLabel( date: string, interval: Interval ): string {
	const [ year, month, dayOfMonth ] = date.split( '-' ).map( Number );
	const at = new Date( year ?? 1970, ( month ?? 1 ) - 1, dayOfMonth ?? 1 );
	if ( interval === 'month' ) {
		return at.toLocaleDateString( undefined, {
			month: 'short',
			year: 'numeric',
		} );
	}
	return at.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
}

export interface TrendPoint {
	label: string;
	current: number;
	previous: number | null;
}

/**
 * A figure over time, this period as a line and the compared period dashed behind it.
 *
 * @param props
 * @param props.data         The points, in order.
 * @param props.format       Shows a figure, for the axis and the tooltip.
 * @param props.currentName  What the line is: "Net sales".
 * @param props.previousName What the dashed line is, or null when nothing is compared.
 */
export function TrendChart( {
	data,
	format,
	currentName,
	previousName,
}: {
	data: TrendPoint[];
	format: ( value: number ) => string;
	currentName: string;
	previousName: string | null;
} ) {
	return (
		<div
			className="orillacart-reports__chart"
			role="img"
			aria-label={ currentName }
		>
			<ResponsiveContainer width="100%" height={ 300 }>
				<LineChart
					data={ data }
					margin={ { top: 8, right: 16, bottom: 8, left: 8 } }
				>
					<CartesianGrid stroke="#f0f0f1" vertical={ false } />
					<XAxis
						dataKey="label"
						tick={ { fontSize: 12 } }
						minTickGap={ 16 }
					/>
					<YAxis
						tick={ { fontSize: 12 } }
						tickFormatter={ format }
						width={ 80 }
					/>
					<Tooltip
						formatter={ ( value ) => format( Number( value ) ) }
					/>
					<Legend />
					{ previousName !== null && (
						<Line
							type="monotone"
							dataKey="previous"
							name={ previousName }
							stroke={ PREVIOUS }
							strokeDasharray="4 4"
							dot={ false }
							isAnimationActive={ false }
						/>
					) }
					<Line
						type="monotone"
						dataKey="current"
						name={ currentName }
						stroke={ CURRENT }
						strokeWidth={ 2 }
						dot={ data.length <= 31 }
						isAnimationActive={ false }
					/>
				</LineChart>
			</ResponsiveContainer>
		</div>
	);
}

/**
 * The best few of a ranking as horizontal bars.
 *
 * @param props
 * @param props.rows   Label and figure, the largest first.
 * @param props.format Shows a figure.
 * @param props.name   What the figure is: "Net sales".
 */
export function RankingChart( {
	rows,
	format,
	name,
}: {
	rows: { label: string; value: number }[];
	format: ( value: number ) => string;
	name: string;
} ) {
	if ( rows.length === 0 ) {
		return null;
	}
	return (
		<div
			className="orillacart-reports__chart"
			role="img"
			aria-label={ name }
		>
			<ResponsiveContainer width="100%" height={ 40 + rows.length * 32 }>
				<BarChart
					data={ rows }
					layout="vertical"
					margin={ { top: 8, right: 24, bottom: 8, left: 8 } }
				>
					<CartesianGrid stroke="#f0f0f1" horizontal={ false } />
					<XAxis
						type="number"
						tickFormatter={ format }
						tick={ { fontSize: 12 } }
					/>
					<YAxis
						type="category"
						dataKey="label"
						width={ 180 }
						tick={ { fontSize: 12 } }
					/>
					<Tooltip
						formatter={ ( value ) => format( Number( value ) ) }
					/>
					<Bar
						dataKey="value"
						name={ name }
						fill={ CURRENT }
						isAnimationActive={ false }
					/>
				</BarChart>
			</ResponsiveContainer>
		</div>
	);
}

/**
 * Orders of new and returning customers over time, stacked.
 *
 * @param props
 * @param props.data The groups of days, in order.
 */
export function CustomersChart( {
	data,
}: {
	data: { label: string; new: number; returning: number }[];
} ) {
	return (
		<div
			className="orillacart-reports__chart"
			role="img"
			aria-label={ __(
				'Orders by new and returning customers',
				'orillacart'
			) }
		>
			<ResponsiveContainer width="100%" height={ 300 }>
				<BarChart
					data={ data }
					margin={ { top: 8, right: 16, bottom: 8, left: 8 } }
				>
					<CartesianGrid stroke="#f0f0f1" vertical={ false } />
					<XAxis
						dataKey="label"
						tick={ { fontSize: 12 } }
						minTickGap={ 16 }
					/>
					<YAxis allowDecimals={ false } tick={ { fontSize: 12 } } />
					<Tooltip />
					<Legend />
					<Bar
						dataKey="new"
						stackId="orders"
						name={ __( 'New customers', 'orillacart' ) }
						fill={ CURRENT }
						isAnimationActive={ false }
					/>
					<Bar
						dataKey="returning"
						stackId="orders"
						name={ __( 'Returning customers', 'orillacart' ) }
						fill={ SECOND }
						isAnimationActive={ false }
					/>
				</BarChart>
			</ResponsiveContainer>
		</div>
	);
}
