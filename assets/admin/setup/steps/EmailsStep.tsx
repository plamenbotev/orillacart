import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { answered, text, type StepProps } from './types';

/**
 * Emails: who they come from, and where new orders go. The test email is sent at the end, once the plan is
 * applied, so it goes from the new sender.
 * @param root0
 * @param root0.answers
 * @param root0.save
 * @param root0.fail
 */
export function EmailsStep( { answers, save, fail }: StepProps ) {
	const given = answered( answers, 'emails' );
	const business = answered( answers, 'business' );
	const [ form, setForm ] = useState( {
		from_name: text( given, 'from_name', text( business, 'legal_name' ) ),
		from_address: text( given, 'from_address', text( business, 'email' ) ),
		shop_address: text( given, 'shop_address' ),
	} );
	const [ busy, setBusy ] = useState( false );

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( { ...form, shop_address: form.shop_address || null } )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Emails come from', 'orillacart' ) }
				value={ form.from_name }
				onChange={ ( value ) =>
					setForm( { ...form, from_name: value } )
				}
				required
			/>
			<TextControl
				__next40pxDefaultSize
				type="email"
				label={ __( 'Their address', 'orillacart' ) }
				help={ __(
					'An address of the site’s own domain lands in inboxes; another may land in spam.',
					'orillacart'
				) }
				value={ form.from_address }
				onChange={ ( value ) =>
					setForm( { ...form, from_address: value } )
				}
				required
			/>
			<TextControl
				__next40pxDefaultSize
				type="email"
				label={ __( 'New orders go to', 'orillacart' ) }
				help={ __( 'Empty: the address above.', 'orillacart' ) }
				value={ form.shop_address }
				onChange={ ( value ) =>
					setForm( { ...form, shop_address: value } )
				}
			/>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Save and go on', 'orillacart' ) }
			</Button>
		</form>
	);
}
