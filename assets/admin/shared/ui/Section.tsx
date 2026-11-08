import { Card, CardBody, CardFooter, CardHeader } from '@wordpress/components';
import type { ReactNode } from 'react';

export interface SectionProps {
	title?: ReactNode;
	/** Something beside the title: a status badge. */
	titleAside?: ReactNode;
	/** One line about what the section holds. */
	description?: ReactNode;
	/** Its action, in the header: Close, Delete, Add. */
	action?: ReactNode;
	/** Its buttons: Save, and what goes with it. */
	footer?: ReactNode;
	className?: string;
	children?: ReactNode;
}

/**
 * A section of a screen (design 52): a card with a title, one line about it, an action in its header and its
 * buttons in its footer. A form inside it keeps its fields in rows.
 *
 * @param props
 * @param props.title       The section's title.
 * @param props.titleAside  Something beside the title.
 * @param props.description One line about what the section holds.
 * @param props.action      Its action, in the header.
 * @param props.footer      Its buttons.
 * @param props.className   Extra classes on the card.
 * @param props.children    The section's content.
 */
export function Section( {
	title,
	titleAside,
	description,
	action,
	footer,
	className,
	children,
}: SectionProps ) {
	return (
		<Card
			className={ [ 'oc-section', 'orillacart-card', className ]
				.filter( Boolean )
				.join( ' ' ) }
		>
			{ ( title || action ) && (
				<CardHeader>
					<div className="oc-section__heading">
						{ title && (
							<div className="oc-section__title">
								<h2>{ title }</h2>
								{ titleAside }
							</div>
						) }
						{ description && (
							<p className="oc-section__description">
								{ description }
							</p>
						) }
					</div>
					{ action && (
						<div className="oc-section__action">{ action }</div>
					) }
				</CardHeader>
			) }
			<CardBody>{ children }</CardBody>
			{ footer && <CardFooter>{ footer }</CardFooter> }
		</Card>
	);
}
