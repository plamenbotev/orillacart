import { __ } from '@wordpress/i18n';

/** What each job type does, as the shop calls it; an add-on's own types show by name. */
export const TYPE_LABELS: Record< string, string > = {
	'search.index': __( 'Search index', 'orillacart' ),
	'mail.order': __( 'Order emails', 'orillacart' ),
	'shipping.track': __( 'Shipment tracking', 'orillacart' ),
	'reports.order': __( 'Report figures', 'orillacart' ),
	'reports.rebuild': __( 'Counting every order again', 'orillacart' ),
	'exchange.import_products': __( 'Products import', 'orillacart' ),
	'newsletter.sync': __( 'Mailing lists', 'orillacart' ),
	'webhook.deliver': __( 'Webhooks', 'orillacart' ),
	'platform.upgrade_site': __( 'Network site upgrades', 'orillacart' ),
};

export const LANE_LABELS: Record< string, string > = {
	search: __( 'Search', 'orillacart' ),
	mail: __( 'Mail', 'orillacart' ),
	shipping: __( 'Shipping', 'orillacart' ),
	reports: __( 'Reports', 'orillacart' ),
	imports: __( 'Imports', 'orillacart' ),
	webhooks: __( 'Webhooks', 'orillacart' ),
	upgrades: __( 'Upgrades', 'orillacart' ),
};

export const typeLabel = ( type: string ) => TYPE_LABELS[ type ] ?? type;
export const laneLabel = ( lane: string ) => LANE_LABELS[ lane ] ?? lane;
