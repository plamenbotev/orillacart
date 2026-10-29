import { Button, Notice } from '@wordpress/components';
import { Children, isValidElement, useState } from '@wordpress/element';
import type { ReactElement, ReactNode } from 'react';

import type { HostNotice } from './host';
import './product-data.css';

type SectionProps = { name: string; title: string; children: ReactNode };

/**
 * One part of the product's data, a tab of the box.
 *
 * @param props          The section's name and title, which the box lists, and its fields.
 * @param props.children The fields.
 */
export function Section( { children }: SectionProps ) {
	return <>{ children }</>;
}

/**
 * The product's data box: its sections listed beside the one shown, as wide as the editor gives. Every section
 * stays rendered, the others hidden, so what is typed in one is kept while another is open.
 *
 * @param props          The box's properties.
 * @param props.notices  What went right or wrong, shown above the sections.
 * @param props.children The sections.
 */
export function ProductSections( {
	notices = [],
	children,
}: {
	notices?: HostNotice[];
	children: ReactNode;
} ) {
	const sections = Children.toArray( children ).filter(
		( child ): child is ReactElement< SectionProps > =>
			isValidElement< SectionProps >( child ) && child.type === Section
	);
	const [ open, setOpen ] = useState( sections[ 0 ]?.props.name ?? '' );

	return (
		<div className="orillacart-product-data">
			{ notices.map( ( notice ) => (
				<Notice
					key={ notice.message }
					status={ notice.status }
					isDismissible={ false }
				>
					{ notice.message }
				</Notice>
			) ) }
			<div className="orillacart-product-data__body">
				<div
					className="orillacart-product-data__tabs"
					role="tablist"
					aria-orientation="vertical"
				>
					{ sections.map( ( section ) => (
						<Button
							key={ section.props.name }
							role="tab"
							id={ `${ section.props.name }-tab` }
							aria-controls={ `${ section.props.name }-panel` }
							aria-selected={ open === section.props.name }
							className="orillacart-product-data__tab"
							onClick={ () => setOpen( section.props.name ) }
						>
							{ section.props.title }
						</Button>
					) ) }
				</div>
				{ sections.map( ( section ) => (
					<div
						key={ section.props.name }
						role="tabpanel"
						id={ `${ section.props.name }-panel` }
						aria-labelledby={ `${ section.props.name }-tab` }
						className="orillacart-product-data__panel"
						hidden={ open !== section.props.name }
					>
						{ section }
					</div>
				) ) }
			</div>
		</div>
	);
}
