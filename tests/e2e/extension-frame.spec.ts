/**
 * Design 73: an extension's admin screen runs its own JavaScript in a frame of its own. The screen's script calls
 * its extension's routes through the bridge, and nothing else: not the shop's other routes, not the admin's page,
 * not the cookies, not the network.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

import { remove } from './shop';

const SCRIPT = `
extension "framed" {
  name: "Framed"
  needs: { assets: [serve, scripts] }
}
route POST "/ping" { who: manager } fn(request) { return { pong: request.body.n + 1 } }
admin_page "/" { title: "Framed", template: "screen.html.twig" } fn(request) { return { hello: "Ada" } }
`;

const SCREEN = `(async () => {
  const data = JSON.parse(document.querySelector('script[type="application/json"]').textContent);
  const ping = await window.orillacart.api('POST', '/x/framed/ping', { n: data.n });
  const other = await window.orillacart.api('GET', '/orders');
  let page = 'blocked';
  try { page = window.parent.document.title; } catch (e) {}
  let cookies = 'blocked';
  try { cookies = document.cookie; } catch (e) {}
  let network = 'blocked';
  try { await fetch('/wp-json/'); network = 'open'; } catch (e) {}
  document.getElementById('out').textContent = [ping.data.pong, other.status, page, cookies, network].join(' ');
  window.orillacart.notice('Pinged.', 'success');
})();`;

test.beforeAll( async ( { requestUtils } ) => {
	await remove( requestUtils, '/orillacart/v1/extensions/framed' ).catch(
		() => undefined
	);
	await requestUtils.rest( {
		path: '/orillacart/v1/extensions',
		method: 'POST',
		data: {
			id: 'framed',
			name: 'Framed',
			files: {
				'main.ocs': SCRIPT,
				'templates/screen.html.twig':
					'<p id="hello">Hello {{ hello }}</p><p id="out"></p>{{ json_data({ n: 41 }) }}{{ asset("screen.js") }}',
				'assets/screen.js': SCREEN,
			},
		},
	} );
	await requestUtils.rest( {
		path: '/orillacart/v1/extensions/framed/enable',
		method: 'POST',
		data: { grant: [ 'assets', 'assets:scripts' ] },
	} );
} );

test.afterAll( async ( { requestUtils } ) => {
	await remove( requestUtils, '/orillacart/v1/extensions/framed' );
} );

test( "an extension's screen runs in a frame that reaches only its own routes", async ( {
	admin,
	page,
} ) => {
	await admin.visitAdminPage( 'admin.php', 'page=orillacart-x-framed' );

	const frame = page.frameLocator( 'iframe[data-orillacart-frame]' );
	await expect( frame.locator( '#hello' ) ).toHaveText( 'Hello Ada' );
	// Its own route answered; the shop's others refused; the admin's page, the cookies and the network out of reach.
	await expect( frame.locator( '#out' ) ).toHaveText(
		'42 403 blocked blocked blocked',
		{ timeout: 20000 }
	);
	await expect(
		page.locator( '.notice-success' ).filter( { hasText: 'Pinged.' } )
	).toBeVisible();
	await expect(
		page.locator( 'iframe[data-orillacart-frame]' )
	).toHaveAttribute( 'sandbox', 'allow-scripts' );
} );
