import {
	Card,
	CardBody,
	CardHeader,
	Notice,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	fetchReviewSettings,
	type ReviewSettings,
	saveReviewSettings,
} from './reviews';

const POLICIES = [
	{
		value: 'anyone',
		label: __(
			'Anyone may review; reviews from buyers are marked',
			'orillacart'
		),
	},
	{
		value: 'buyers',
		label: __( 'Only customers who bought the product', 'orillacart' ),
	},
	{ value: 'off', label: __( 'No reviews', 'orillacart' ) },
];

/**
 * Who may review the shop's products. Reviews are comments: the site's discussion settings moderate them, and
 * the Comments screen lists them.
 */
export function ReviewsCard() {
	const [ settings, setSettings ] = useState< ReviewSettings | null >( null );
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const fail = ( failure: unknown ) =>
		setMessage( { status: 'error', text: toApiError( failure ).message } );

	useEffect( () => {
		fetchReviewSettings().then( setSettings ).catch( fail );
	}, [] );

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Reviews', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
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
					<>
						<RadioControl
							label={ __(
								'Who may review products',
								'orillacart'
							) }
							selected={ settings.policy }
							options={ POLICIES }
							onChange={ ( policy ) =>
								saveReviewSettings( {
									policy: policy as ReviewSettings[ 'policy' ],
								} )
									.then( ( saved ) => {
										setSettings( saved );
										setMessage( {
											status: 'success',
											text: __( 'Saved.', 'orillacart' ),
										} );
									} )
									.catch( fail )
							}
						/>
						<p>
							{ __(
								'A customer who bought the product, by their account or from the link in their order’s emails, is a verified buyer. Settings → Discussion decides whether reviews wait for approval; approve and answer them on the Comments screen. A product’s “Allow comments” box turns its reviews on or off.',
								'orillacart'
							) }
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}
