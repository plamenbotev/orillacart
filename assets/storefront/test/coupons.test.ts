import { api } from '../api';
import { bindCoupons } from '../coupons';

jest.mock( '../api' );
const mockedApi = api as jest.MockedFunction< typeof api >;

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const page = () => `
	<form data-orillacart-checkout>
		<div data-orillacart-coupons>
			<p class="orillacart-coupon"><strong>SPRING</strong> <button type="button" data-orillacart-remove-coupon="SPRING">×</button></p>
			<input data-orillacart-coupon-code>
			<button type="button" data-orillacart-apply-coupon>Apply</button>
			<p data-orillacart-message></p>
		</div>
	</form>`;

describe( 'coupon codes', () => {
	afterEach( () => mockedApi.mockReset() );

	it( 'applies a code on Enter without placing the order, and shows the prices anew', async () => {
		document.body.innerHTML = page();
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		const submitted = jest.fn( ( event: Event ) => event.preventDefault() );
		form.addEventListener( 'submit', submitted );
		mockedApi.mockResolvedValue( { ok: true, status: 200, data: {} } );
		const refresh = jest.fn( () => Promise.resolve() );
		bindCoupons( form, refresh );

		const field = form.querySelector( 'input' ) as HTMLInputElement;
		field.value = ' summer ';
		field.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 'Enter',
				bubbles: true,
				cancelable: true,
			} )
		);
		await flush();

		expect( mockedApi ).toHaveBeenCalledWith( 'POST', '/cart/coupons', {
			code: 'summer',
		} );
		expect( refresh ).toHaveBeenCalled();
		expect( submitted ).not.toHaveBeenCalled();
	} );

	it( 'says why a code does not hold, and removes one', async () => {
		document.body.innerHTML = page();
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		mockedApi.mockResolvedValueOnce( {
			ok: false,
			status: 422,
			code: 'orillacart_coupon_refused',
			message: 'This code has expired.',
			violations: [],
		} );
		const refresh = jest.fn( () => Promise.resolve() );
		bindCoupons( form, refresh );

		( form.querySelector( 'input' ) as HTMLInputElement ).value = 'OLD';
		(
			form.querySelector(
				'[data-orillacart-apply-coupon]'
			) as HTMLButtonElement
		 ).click();
		await flush();
		expect(
			form.querySelector( '[data-orillacart-message]' )?.textContent
		).toBe( 'This code has expired.' );
		expect( refresh ).not.toHaveBeenCalled();

		mockedApi.mockResolvedValueOnce( { ok: true, status: 200, data: {} } );
		(
			form.querySelector(
				'[data-orillacart-remove-coupon]'
			) as HTMLButtonElement
		 ).click();
		await flush();
		expect( mockedApi ).toHaveBeenLastCalledWith(
			'DELETE',
			'/cart/coupons/SPRING'
		);
		expect( refresh ).toHaveBeenCalled();
	} );
} );
