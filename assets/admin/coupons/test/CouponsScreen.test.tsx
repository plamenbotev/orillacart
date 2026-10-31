import '@testing-library/jest-dom';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NEW_COUPON } from '../api';
import { CouponForm, CouponsScreen, discountLabel } from '../CouponsScreen';
import { forgetContentLanguages } from '../../shared/Translations';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const spring = {
	...NEW_COUPON,
	id: 3,
	code: 'SPRING',
	description: 'Spring sale',
	usage_limit: 100,
	uses: 12,
};

describe( 'CouponsScreen', () => {
	afterEach( () => {
		mockedFetch.mockReset();
		forgetContentLanguages();
	} );

	it( 'says what each coupon takes off', () => {
		expect(
			discountLabel( { ...NEW_COUPON, amount: '15', cap: '20' } )
		).toBe( '15 %, at most 20' );
		expect(
			discountLabel( { ...NEW_COUPON, kind: 'item_amount', amount: '2' } )
		).toBe( '2 off each item' );
	} );

	it( 'lists the coupons and adds one', async () => {
		const requests: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			requests.push( request );
			if ( request.path.startsWith( '/wp/v2/' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.method === 'POST' ) {
				return Promise.resolve( {
					...spring,
					...( request.data as object ),
					id: 4,
				} );
			}
			return Promise.resolve( {
				coupons: [ spring ],
				total: 1,
				page: 1,
				pages: 1,
			} );
		} );
		const user = userEvent.setup();
		render( <CouponsScreen /> );

		expect( await screen.findByText( '12 / 100' ) ).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Add a coupon' } )
		);
		await user.type( screen.getByLabelText( 'Code' ), 'welcome' );
		await user.click( screen.getByLabelText( 'First orders only' ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Save the coupon' } )
		);

		expect(
			await screen.findByText( 'The coupon WELCOME was saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.path ).toBe( '/orillacart/v1/coupons' );
		expect( saved?.data ).toMatchObject( {
			code: 'WELCOME',
			kind: 'percent',
			amount: '10',
			first_order_only: true,
		} );
	} );

	it( 'shows a code to shoppers only when ticked, and an automatic discount unless unticked', async () => {
		const saved: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.method === 'POST' ) {
				saved.push( request );
				return Promise.resolve( {
					...spring,
					...( request.data as object ),
				} );
			}
			return Promise.resolve( [] );
		} );
		const user = userEvent.setup();
		const onSaved = jest.fn();
		const { unmount } = render(
			<CouponForm
				coupon={ null }
				onClose={ jest.fn() }
				onSaved={ onSaved }
				onError={ jest.fn() }
			/>
		);

		const shown = screen.getByLabelText( 'Show to shoppers' );
		expect( shown ).not.toBeChecked();
		await user.type( screen.getByLabelText( 'Code' ), 'summer10' );
		await user.click( shown );
		await user.click(
			screen.getByRole( 'button', { name: 'Save the coupon' } )
		);
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( saved[ 0 ]?.data ).toMatchObject( {
			code: 'SUMMER10',
			advertised: true,
		} );
		unmount();

		render(
			<CouponForm
				automatic
				coupon={ null }
				onClose={ jest.fn() }
				onSaved={ jest.fn() }
				onError={ jest.fn() }
			/>
		);
		// The categories and brands it reads first.
		await act( () => Promise.resolve() );
		expect( screen.getByLabelText( 'Show to shoppers' ) ).toBeChecked();
	} );

	it( 'translates the description into the site’s other languages', async () => {
		const saved: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path === '/orillacart/v1/content-languages' ) {
				return Promise.resolve( [
					{
						code: 'en',
						locale: 'en_US',
						name: 'English',
						default: true,
					},
					{
						code: 'de',
						locale: 'de_DE',
						name: 'Deutsch',
						default: false,
					},
				] );
			}
			if ( request.method === 'PUT' ) {
				saved.push( request );
				return Promise.resolve( request.data );
			}
			return Promise.resolve( [] );
		} );
		const user = userEvent.setup();
		render(
			<CouponForm
				coupon={ { ...spring, translations: [] } }
				onClose={ jest.fn() }
				onSaved={ jest.fn() }
				onError={ jest.fn() }
			/>
		);

		await user.type(
			await screen.findByLabelText( 'Description in Deutsch' ),
			'Frühlingsrabatt'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save the coupon' } )
		);
		expect( saved[ 0 ]?.path ).toBe( '/orillacart/v1/coupons/3' );
		expect( saved[ 0 ]?.data ).toMatchObject( {
			translations: { description: { de_DE: 'Frühlingsrabatt' } },
		} );
	} );
} );
