import { bindCheckout } from '../checkout';

describe( 'the checkout', () => {
	it( 'fills in a saved address, names and all, when the customer chooses it', () => {
		window.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				status: 204,
				json: () => Promise.resolve( null ),
				text: () => Promise.resolve( '' ),
			} as Response )
		) as typeof fetch;
		document.body.innerHTML = `
			<form data-orillacart-checkout>
				<select id="billing-saved" data-orillacart-saved-address="billing">
					<option value="a1">Home</option>
					<option value="b2">Work</option>
				</select>
				<script type="application/json" data-orillacart-saved-addresses>${ JSON.stringify(
					{
						b2: {
							first_name: 'Ada',
							last_name: 'Lovelace',
							street: '1 Vitosha Blvd',
							city: 'Sofia',
							postcode: '1000',
							phone: '+359 2 000 0000',
							country: 'BG',
							state: null,
						},
					}
				) }</script>
				<input name="billing.first_name" value="Grace">
				<input name="billing.last_name" value="Hopper">
				<input name="billing.street" value="">
				<input name="billing.city" value="">
				<input name="billing.postcode" value="">
				<input name="billing.phone" value="">
				<select name="billing.country" data-orillacart-country><option value="BG">Bulgaria</option></select>
			</form>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindCheckout( form );

		const saved = form.querySelector(
			'#billing-saved'
		) as HTMLSelectElement;
		saved.value = 'b2';
		saved.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const value = ( name: string ) =>
			(
				form.querySelector(
					`[name="billing.${ name }"]`
				) as HTMLInputElement
			 ).value;
		expect( value( 'first_name' ) ).toBe( 'Ada' );
		expect( value( 'last_name' ) ).toBe( 'Lovelace' );
		expect( value( 'street' ) ).toBe( '1 Vitosha Blvd' );
		expect( value( 'phone' ) ).toBe( '+359 2 000 0000' );
	} );
} );
