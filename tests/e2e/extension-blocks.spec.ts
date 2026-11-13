/**
 * Design 73: an extension's site block in the editor edits its settings in a frame of its own, holds the blocks the
 * owner places in it, and brings its script to the page; its pattern is the editor's to insert.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

import { remove } from './shop';

const SCRIPT = `
extension "panels" {
  name: "Panels"
  needs: { assets: [serve, scripts] }
}
site_block "panel" {
  title: "E2E Panel", inner: true, assets: ["panel.js"], editor: "panel-editor.js"
  settings: { heading: text { label: "Heading" }, accent: color { label: "Accent" } }
  fn render(settings, context) {
    return "<section class='e2e-panel'><h2>" ~ html.escape(settings.heading ?? "No heading") ~ "</h2>" ~ context.content ~ "</section>"
  }
}
pattern "intro" { title: "E2E Intro", file: "patterns/intro.html" }
`;

// The editing screen sets the heading from the frame, through the bridge.
const EDITOR = `document.getElementById('editor').innerHTML = '<button id="set">Set the heading</button>';
document.getElementById('set').addEventListener('click', () => window.orillacart.block.set({ heading: 'From the frame', nope: 'kept out' }));`;

const PAGE = `document.querySelectorAll('.e2e-panel').forEach((panel) => panel.setAttribute('data-ran', 'yes'));`;

test.beforeAll( async ( { requestUtils } ) => {
	await remove( requestUtils, '/orillacart/v1/extensions/panels' ).catch(
		() => undefined
	);
	await requestUtils.rest( {
		path: '/orillacart/v1/extensions',
		method: 'POST',
		data: {
			id: 'panels',
			name: 'Panels',
			files: {
				'main.ocs': SCRIPT,
				'assets/panel.js': PAGE,
				'assets/panel-editor.js': EDITOR,
				'patterns/intro.html':
					'<!-- wp:paragraph --><p>E2E pattern words</p><!-- /wp:paragraph -->',
			},
		},
	} );
	await requestUtils.rest( {
		path: '/orillacart/v1/extensions/panels/enable',
		method: 'POST',
		data: { grant: [ 'assets', 'assets:scripts' ] },
	} );
} );

test.afterAll( async ( { requestUtils } ) => {
	const pages = await requestUtils.rest<
		{ id: number; title: { raw: string } }[]
	>( {
		path: '/wp/v2/pages?search=E2E&status=any&per_page=100&context=edit&_fields=id,title',
	} );
	for ( const { id } of pages.filter( ( item ) =>
		[ 'E2E Panels', 'E2E Pattern' ].includes( item.title.raw )
	) ) {
		await remove( requestUtils, `/wp/v2/pages/${ id }?force=true` );
	}
	await remove( requestUtils, '/orillacart/v1/extensions/panels' );
} );

test( "an extension's block is edited in its frame, holds blocks, and runs its script on the page", async ( {
	admin,
	editor,
	page,
} ) => {
	await admin.createNewPost( { postType: 'page', title: 'E2E Panels' } );
	await editor.insertBlock( { name: 'orillacart-addon/panel' } );
	const panel = editor.canvas.locator(
		'[data-type="orillacart-addon/panel"]'
	);
	await expect( panel.locator( 'h2' ) ).toHaveText( 'No heading' );

	// Its own screen in the sidebar sets the heading; the canvas shows it as the server renders it.
	await editor.openDocumentSettingsSidebar();
	const frame = page
		.locator( 'iframe[sandbox="allow-scripts"]' )
		.contentFrame();
	await frame.getByRole( 'button', { name: 'Set the heading' } ).click();
	await expect( panel.locator( 'h2' ) ).toHaveText( 'From the frame', {
		timeout: 20000,
	} );

	// A block the owner places inside it: the appender starts a paragraph there.
	await panel.getByRole( 'button', { name: /^Add/ } ).first().click();
	await page.keyboard.type( 'Inside the panel' );

	const post = await editor.publishPost();
	expect( post ).toBeTruthy();
	const link = await page
		.locator( '.post-publish-panel__postpublish-buttons a' )
		.first()
		.getAttribute( 'href' );
	await page.goto( link ?? '/' );
	const shown = page.locator( '.e2e-panel' );
	await expect( shown.locator( 'h2' ) ).toHaveText( 'From the frame' );
	await expect( shown ).toContainText( 'Inside the panel' );
	await expect( shown ).toHaveAttribute( 'data-ran', 'yes' );
} );

test( "an extension's pattern is offered by the editor", async ( {
	admin,
	editor,
	page,
} ) => {
	await admin.createNewPost( { postType: 'page', title: 'E2E Pattern' } );
	await page.getByRole( 'button', { name: 'Block Inserter' } ).click();
	await page.getByRole( 'tab', { name: 'Patterns' } ).click();
	await page.getByPlaceholder( 'Search' ).fill( 'E2E Intro' );
	await page.getByRole( 'option', { name: 'E2E Intro' } ).click();
	await expect(
		editor.canvas.getByText( 'E2E pattern words' )
	).toBeVisible();
} );
