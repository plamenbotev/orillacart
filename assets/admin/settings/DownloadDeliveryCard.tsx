import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import {
	changeDownloadDelivery,
	type DeliveryMethod,
	type DownloadDelivery,
	fetchDownloadDelivery,
} from './delivery';

const METHODS: { value: DeliveryMethod; label: string }[] = [
	{
		value: 'php',
		label: __( 'PHP reads the file (works everywhere)', 'orillacart' ),
	},
	{
		value: 'x_sendfile',
		label: __(
			'X-Sendfile (Apache with mod_xsendfile, LiteSpeed)',
			'orillacart'
		),
	},
	{
		value: 'x_accel_redirect',
		label: __( 'X-Accel-Redirect (Nginx)', 'orillacart' ),
	},
];

/**
 * How bought files are sent: by PHP, or by the web server once PHP has checked the link. A method is kept only
 * after a test file went through it.
 */
export function DownloadDeliveryCard() {
	const [ delivery, setDelivery ] = useState< DownloadDelivery | null >(
		null
	);
	const [ method, setMethod ] = useState< DeliveryMethod >( 'php' );
	const [ prefix, setPrefix ] = useState( '' );
	const [ testing, setTesting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const show = ( loaded: DownloadDelivery ) => {
		setDelivery( loaded );
		setPrefix( loaded.internal_prefix );
	};

	useEffect( () => {
		fetchDownloadDelivery()
			.then( ( loaded ) => {
				show( loaded );
				setMethod( loaded.method );
			} )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const test = () => {
		setTesting( true );
		setError( null );
		changeDownloadDelivery( method, prefix )
			.then( show )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			)
			.finally( () => setTesting( false ) );
	};

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Sending downloads', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ delivery === null ? (
					error === null && <Spinner />
				) : (
					<>
						<p>
							{ __(
								'The web server can send bought files once the shop has checked the link, which frees PHP during long downloads. A way is kept only after a test file went through it.',
								'orillacart'
							) }
						</p>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Who sends the files', 'orillacart' ) }
							value={ method }
							options={ METHODS }
							onChange={ ( value ) =>
								setMethod( value as DeliveryMethod )
							}
						/>
						{ method === 'x_accel_redirect' && (
							<TextControl
								__next40pxDefaultSize
								label={ __(
									'Internal location',
									'orillacart'
								) }
								help={ __(
									'The path Nginx maps to the downloads folder, such as /orillacart-files/.',
									'orillacart'
								) }
								value={ prefix }
								onChange={ setPrefix }
							/>
						) }
						{ delivery.works !== null && (
							<Notice
								status={
									delivery.works ? 'success' : 'warning'
								}
								isDismissible={ false }
							>
								{ delivery.message }
							</Notice>
						) }
						{ delivery.works === false &&
							delivery.server_rule !== '' && (
								<pre className="orillacart-server-rule">
									{ delivery.server_rule }
								</pre>
							) }
						<Button
							variant="primary"
							isBusy={ testing }
							disabled={ testing }
							onClick={ test }
						>
							{ __( 'Test and use', 'orillacart' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}
