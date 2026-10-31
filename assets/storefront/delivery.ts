/**
 * The product page's delivery estimate. The page may come from a cache: it carries the estimates of the days
 * ahead, and this shows the one that holds now, counting down to its cutoff, as the server writes it
 * (DeliveryEstimateBlock).
 */
import { __, sprintf } from '@wordpress/i18n';

export type DeliveryEstimate = {
	/** When it stops holding, in seconds since 1970. */
	until: number;
	/** The cutoff to order by for it; null when only the days moving on end it. */
	order_by: number | null;
	/** The earliest day and the latest, as the shopper's language writes them. */
	from: string;
	to: string;
};

/** A cutoff further off than this is not counted down to: the day is enough. */
const COUNTDOWN_SECONDS = 86400;

/**
 * What the shopper reads now; null when the page is older than its estimates.
 *
 * @param estimates The estimates, the earliest first.
 * @param now       Seconds since 1970.
 */
export function deliveryText(
	estimates: DeliveryEstimate[],
	now: number
): string | null {
	const estimate = estimates.find( ( candidate ) => candidate.until > now );
	if ( ! estimate ) {
		return null;
	}
	const left = estimate.order_by === null ? null : estimate.order_by - now;
	if ( left !== null && left > 0 && left <= COUNTDOWN_SECONDS ) {
		const minutes = Math.ceil( left / 60 );
		const time =
			minutes >= 60
				? sprintf(
						/* translators: 1: hours, 2: minutes, a time left to order in */
						__( '%1$d h %2$d min', 'orillacart' ),
						Math.floor( minutes / 60 ),
						minutes % 60
					)
				: sprintf(
						/* translators: %d: minutes, a time left to order in */
						__( '%d min', 'orillacart' ),
						minutes
					);
		return estimate.from === estimate.to
			? sprintf(
					/* translators: 1: a time left to order in, such as 3 h 20 min, 2: a day, such as Thursday, November 6 */
					__( 'Order within %1$s to get it by %2$s', 'orillacart' ),
					time,
					estimate.to
				)
			: sprintf(
					/* translators: 1: a time left to order in, such as 3 h 20 min, 2: the earliest day, 3: the latest */
					__(
						'Order within %1$s to get it between %2$s and %3$s',
						'orillacart'
					),
					time,
					estimate.from,
					estimate.to
				);
	}
	return estimate.from === estimate.to
		? sprintf(
				/* translators: %s: a day, such as Thursday, November 6 */
				__( 'Get it by %s', 'orillacart' ),
				estimate.to
			)
		: sprintf(
				/* translators: 1: the earliest day, such as Tuesday, November 4, 2: the latest */
				__( 'Get it between %1$s and %2$s', 'orillacart' ),
				estimate.from,
				estimate.to
			);
}

/**
 * Keeps the estimate true while the page is open: each half minute, the one that holds and the time left.
 *
 * @param element The estimate (data-orillacart-delivery).
 */
export function bindDeliveryEstimate( element: HTMLElement ): void {
	let estimates: DeliveryEstimate[];
	try {
		estimates = JSON.parse(
			element.dataset.orillacartDelivery ?? '[]'
		) as DeliveryEstimate[];
	} catch {
		return;
	}
	const text = element.querySelector( '[data-orillacart-delivery-text]' );
	const show = () => {
		const now = deliveryText( estimates, Date.now() / 1000 );
		if ( now === null ) {
			// Older than what it knows: better nothing than a wrong day, whatever is chosen later.
			element.setAttribute( 'data-orillacart-delivery-over', '' );
			element.hidden = true;
			window.clearInterval( timer );
			return;
		}
		if ( text && text.textContent !== now ) {
			text.textContent = now;
		}
	};
	const timer = window.setInterval( show, 30000 );
	show();
}
