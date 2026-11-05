import type { ReactNode } from 'react';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

/**
 * A status one can scan: a word on a tinted ground.
 *
 * @param props
 * @param props.tone     What it means: neutral, info, success, warning, danger.
 * @param props.children The word.
 */
export function Badge( {
	tone = 'neutral',
	children,
}: {
	tone?: Tone;
	children: ReactNode;
} ) {
	return (
		<span className={ `oc-badge oc-badge--${ tone }` }>{ children }</span>
	);
}

/** The tone of each status the shop has, in one place, so a status looks the same on every screen. */
const TONES: Record< string, Tone > = {
	// Orders
	pending_payment: 'warning',
	on_hold: 'warning',
	processing: 'info',
	shipped: 'info',
	completed: 'success',
	cancelled: 'neutral',
	failed: 'danger',
	refunded: 'neutral',
	// Payments and refunds
	paid: 'success',
	unpaid: 'warning',
	pending: 'warning',
	refused: 'danger',
	// Jobs
	waiting: 'neutral',
	running: 'info',
	done: 'success',
	// Coupons, rules, carriers, methods
	active: 'success',
	off: 'neutral',
	inactive: 'neutral',
	expired: 'neutral',
	scheduled: 'info',
	// Checks
	good: 'success',
	recommended: 'warning',
	critical: 'danger',
};

/**
 * A status by its key, with the tone the shop gives it; an unknown key is neutral.
 *
 * @param props
 * @param props.status The status key, such as "on_hold" or "active".
 * @param props.label  What it says; the key when left out.
 */
export function StatusBadge( {
	status,
	label,
}: {
	status: string;
	label?: string;
} ) {
	return (
		<Badge tone={ TONES[ status ] ?? 'neutral' }>{ label ?? status }</Badge>
	);
}

/**
 * The tone of a status key, for a row's edge or a dot.
 *
 * @param status The status key.
 */
export function toneOf( status: string ): Tone {
	return TONES[ status ] ?? 'neutral';
}
