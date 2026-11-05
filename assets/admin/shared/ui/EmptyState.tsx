import { Icon } from '@wordpress/components';
import type { IconType } from '@wordpress/components';
import type { ReactNode } from 'react';

/**
 * Why there is nothing here, and what to do about it.
 *
 * @param props
 * @param props.icon        An icon from @wordpress/icons.
 * @param props.title       One short line: "No coupons yet."
 * @param props.description One sentence more, when it helps.
 * @param props.action      The button that makes the first one.
 */
export function EmptyState( {
	icon,
	title,
	description,
	action,
}: {
	icon?: IconType;
	title: string;
	description?: ReactNode;
	action?: ReactNode;
} ) {
	return (
		<div className="oc-empty">
			{ icon && (
				<span className="oc-empty__icon">
					<Icon icon={ icon } size={ 32 } />
				</span>
			) }
			<p className="oc-empty__title">{ title }</p>
			{ description && (
				<p className="oc-empty__description">{ description }</p>
			) }
			{ action && <div className="oc-empty__action">{ action }</div> }
		</div>
	);
}
