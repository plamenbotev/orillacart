/**
 * Design 75: a shopper's agent prepares an order through UCP, and the buyer pays at the shop's own checkout. The
 * agent makes a checkout session of the lamp, gives the buyer and where it ships, chooses the way to ship, and
 * hands the buyer its continue_url; the buyer's checkout opens filled, they pay by transfer, and the agent sees
 * the session completed with the order.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';

import { type Shop, setUpShop, tearDownShop } from './shop';

const AGENT = 'profile="https://agent.e2e.test/profile.json"';
const SESSIONS = '/wp-json/orillacart/v1/ucp/checkout-sessions';

let shop: Shop;

test.beforeAll( async ( { requestUtils } ) => {
	shop = await setUpShop( requestUtils );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/agent-commerce',
		data: { on: true },
	} );
} );

test.afterAll( async ( { requestUtils } ) => {
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/agent-commerce',
		data: { on: false },
	} );
	await tearDownShop( requestUtils );
} );

// The buyer is a visitor; the agent calls server to server, with no cookies.
test.use( { storageState: { cookies: [], origins: [] } } );

type Session = {
	id: string;
	status: string;
	continue_url?: string;
	order?: { id: string };
	fulfillment?: {
		methods: { groups?: { options: { id: string; title: string }[] }[] }[];
	};
};

async function ucp(
	request: APIRequestContext,
	method: 'POST' | 'PUT' | 'GET',
	path: string,
	data?: unknown
): Promise< Session > {
	const response = await request.fetch( path, {
		method,
		headers: {
			'UCP-Agent': AGENT,
			'Idempotency-Key': `e2e-${ Date.now() }-${ Math.random() }`,
			'Request-Id': 'e2e',
			'Content-Type': 'application/json',
		},
		...( data === undefined ? {} : { data } ),
	} );
	expect( response.ok(), await response.text() ).toBe( true );
	return ( await response.json() ) as Session;
}

test( "a shopper's agent prepares the order and the buyer pays at the shop's checkout", async ( {
	page,
	request,
} ) => {
	const checkout = {
		line_items: [ { item: { id: `prod_${ shop.lamp }` }, quantity: 1 } ],
		buyer: {
			email: 'agent-buyer@example.com',
			first_name: 'Agent',
			last_name: 'Buyer',
			phone_number: '+359888000111',
		},
		fulfillment: {
			methods: [
				{
					type: 'shipping',
					destinations: [
						{
							id: 'home',
							street_address: '1 Vitosha Blvd',
							address_locality: 'Sofia',
							postal_code: '1000',
							address_country: 'BG',
						},
					],
				},
			],
		},
	};
	const made = await ucp( request, 'POST', SESSIONS, checkout );
	expect( made.status ).toBe( 'incomplete' );
	const option =
		made.fulfillment?.methods[ 0 ]?.groups?.[ 0 ]?.options[ 0 ]?.id ?? '';
	expect( option ).not.toBe( '' );

	const methods = checkout.fulfillment.methods.map( ( method ) => ( {
		...method,
		groups: [ { selected_option_id: option } ],
	} ) );
	const ready = await ucp( request, 'PUT', `${ SESSIONS }/${ made.id }`, {
		...checkout,
		fulfillment: { methods },
	} );
	expect( ready.status ).toBe( 'requires_escalation' );
	expect( ready.continue_url ).toBeTruthy();

	// The buyer opens the link: the shop's checkout, with the cart and what the agent gave.
	await page.goto( ready.continue_url ?? '/' );
	await expect( page ).toHaveURL( shop.urls.checkout );
	await expect( page.getByLabel( 'Email' ) ).toHaveValue(
		'agent-buyer@example.com'
	);
	await expect( page.locator( '#billing-street' ) ).toHaveValue(
		'1 Vitosha Blvd'
	);
	await expect( page.locator( '#billing-first_name' ) ).toHaveValue(
		'Agent'
	);
	await expect( page.locator( '.orillacart-checkout' ) ).toContainText(
		'E2E Lamp'
	);
	await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
	await page.getByRole( 'button', { name: 'Place order' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Thank you for your order' } )
	).toBeVisible();

	const done = await ucp( request, 'GET', `${ SESSIONS }/${ made.id }` );
	expect( done.status ).toBe( 'completed' );
	expect( done.order?.id ).toMatch( /^\d+$/ );
} );

test( 'a customer who opens the link logged in keeps the session going and pays at their checkout', async ( {
	page,
	request,
	requestUtils,
} ) => {
	const email = `e2e-agent-customer-${ Date.now() }@example.com`;
	const user = await requestUtils.createUser( {
		username: email.replace( /@.*/, '' ),
		email,
		firstName: 'Logged',
		lastName: 'Buyer',
		password: 'e2e-password-123',
		roles: [ 'orillacart_customer' ],
	} );
	try {
		const checkout = {
			line_items: [
				{ item: { id: `prod_${ shop.lamp }` }, quantity: 1 },
			],
			buyer: {
				email,
				first_name: 'Logged',
				last_name: 'Buyer',
				phone_number: '+359888000222',
			},
			fulfillment: {
				methods: [
					{
						type: 'shipping',
						destinations: [
							{
								id: 'home',
								first_name: 'Logged',
								last_name: 'Buyer',
								street_address: '2 Vitosha Blvd',
								address_locality: 'Sofia',
								postal_code: '1000',
								address_country: 'BG',
							},
						],
					},
				],
			},
		};
		const made = await ucp( request, 'POST', SESSIONS, checkout );
		const option =
			made.fulfillment?.methods[ 0 ]?.groups?.[ 0 ]?.options[ 0 ]?.id ??
			'';
		const methods = checkout.fulfillment.methods.map( ( method ) => ( {
			...method,
			groups: [ { selected_option_id: option } ],
		} ) );
		const ready = await ucp( request, 'PUT', `${ SESSIONS }/${ made.id }`, {
			...checkout,
			fulfillment: { methods },
		} );
		expect( ready.status ).toBe( 'requires_escalation' );

		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( email.replace( /@.*/, '' ) );
		await page.locator( '#user_pass' ).fill( 'e2e-password-123' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL(
			( url ) => ! url.pathname.endsWith( 'wp-login.php' )
		);

		// Logged in, the agent's cart becomes their account's; the session follows it.
		await page.goto( ready.continue_url ?? '/' );
		await expect( page ).toHaveURL( shop.urls.checkout );
		await expect( page.locator( '.orillacart-checkout' ) ).toContainText(
			'E2E Lamp'
		);
		await expect( page.locator( '#billing-street' ) ).toHaveValue(
			'2 Vitosha Blvd'
		);
		const meanwhile = await ucp(
			request,
			'GET',
			`${ SESSIONS }/${ made.id }`
		);
		expect( meanwhile.status ).toBe( 'requires_escalation' );

		// Opened again, the link opens their checkout with the cart still there.
		await page.goto( ready.continue_url ?? '/' );
		await expect( page ).toHaveURL( shop.urls.checkout );
		await expect( page.locator( '.orillacart-checkout' ) ).toContainText(
			'E2E Lamp'
		);
		await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();

		const done = await ucp( request, 'GET', `${ SESSIONS }/${ made.id }` );
		expect( done.status ).toBe( 'completed' );
		expect( done.order?.id ).toMatch( /^\d+$/ );
	} finally {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/users/${ user.id }`,
			params: { force: true, reassign: 1 },
		} );
	}
} );
