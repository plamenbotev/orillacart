import { reloadRegions } from '../dom';

const answering = ( html: string ) =>
	jest.fn( () =>
		Promise.resolve( {
			ok: true,
			text: () => Promise.resolve( html ),
		} as Response )
	) as typeof fetch;

const region = ( total: string ) => `
	<div data-orillacart-region="summary">
		<p class="total">${ total }</p>
		<input id="coupon" type="text" value="">
		<input type="radio" name="shipping" value="a">
		<input type="radio" name="shipping" value="b">
		<button type="button" data-orillacart-remove-coupon="SPRING">×</button>
	</div>`;

describe( 'reloading a region', () => {
	beforeEach( () => {
		window.fetch = answering( region( '20.00' ) );
	} );

	it( 'keeps what the customer is typing, and the caret', async () => {
		document.body.innerHTML = region( '10.00' );
		const typed = document.querySelector( '#coupon' ) as HTMLInputElement;
		typed.value = 'GIFT-7';
		typed.focus();
		typed.setSelectionRange( 2, 2 );

		await reloadRegions( [ 'summary' ] );

		expect( document.querySelector( '.total' )?.textContent ).toBe(
			'20.00'
		);
		const field = document.querySelector( '#coupon' ) as HTMLInputElement;
		expect( field ).not.toBe( typed );
		expect( field.value ).toBe( 'GIFT-7' );
		expect( document.body.ownerDocument.activeElement ).toBe( field );
		expect( field.selectionStart ).toBe( 2 );
	} );

	it( 'puts the keyboard back on the radio button or the button it was on', async () => {
		document.body.innerHTML = region( '10.00' );
		( document.querySelector( '[value="b"]' ) as HTMLInputElement ).focus();
		await reloadRegions( [ 'summary' ] );
		expect( document.body.ownerDocument.activeElement ).toBe(
			document.querySelector( '[value="b"]' )
		);

		(
			document.querySelector(
				'[data-orillacart-remove-coupon]'
			) as HTMLButtonElement
		 ).focus();
		await reloadRegions( [ 'summary' ] );
		expect( document.body.ownerDocument.activeElement ).toBe(
			document.querySelector( '[data-orillacart-remove-coupon="SPRING"]' )
		);
	} );

	it( 'takes the new value of a field the customer did not touch', async () => {
		window.fetch = answering(
			'<div data-orillacart-region="summary"><input id="coupon" value="NEW"></div>'
		);
		document.body.innerHTML = region( '10.00' );
		await reloadRegions( [ 'summary' ] );
		expect(
			( document.querySelector( '#coupon' ) as HTMLInputElement ).value
		).toBe( 'NEW' );
	} );
} );
