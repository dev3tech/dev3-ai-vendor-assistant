import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import Dashboard from '../../src/admin/Dashboard';
import Vendors from '../../src/admin/Vendors';
import Moderation from '../../src/admin/Moderation';
import SettingsTab from '../../src/admin/SettingsTab';
import { change, click, render } from './render';

const LABELS = { listing: 'Listing writer', price: 'Price suggestions', reply: 'Buyer replies', profile: 'Store bio & SEO', insights: 'Performance tips' };
const OVERVIEW = {
	marketplace: { id: 'dokan', label: 'Dokan' },
	detected: [],
	vendors: 3,
	month: '2026-10',
	totals: { credits: 40, requests: 30, cost: 0.12, active: 2 },
	by_feature: { listing: { credits: 20, requests: 10, cost: 0.1 } },
	top: [ { id: 7, store: 'Clay & Co', credits: 30, limit: 100, cost: 0.1 } ],
	moderation: { pending: 2, flagged: 1, approved: 4, rejected: 0 },
	ai: { enabled: true, key_set: false, cap_reached: false },
	labels: LABELS,
	settings: { default_credits: 100, moderation_mode: 'review' },
};
const VENDOR = {
	id: 7,
	store: 'Clay & Co',
	name: 'Clara',
	email: 'clara@example.org',
	access: true,
	overrides: { listing: 'inherit', price: 'inherit', reply: 'inherit', profile: 'inherit', insights: 'inherit' },
	features: { listing: true, price: true, reply: true, profile: true, insights: true },
	quota: { limit: 100, used: 30, remaining: 70, unlimited: false, percent: 30 },
	has_override: false,
	override: null,
	usage: { credits: 30, requests: 20, tokens: 1000, cost: 0.1 },
	products: 4,
};
const ITEM = { id: 99, title: 'Speckled mug', status: 'pending', moderation: 'pending', note: '', flags: [ 'replica' ], ai_written: true, vendor_id: 7, vendor: 'Clay & Co', price: '24', price_html: '$24', short: 'Short', description: '<p>Desc</p>', tags: [ 'mug' ], image: '', check: null, modified: '2026-10-20T10:00:00+00:00', edit_url: '#', view_url: '#' };

let calls = [];
function router( extra = {} ) {
	calls = [];
	apiFetch.mockImplementation( ( opts ) => {
		const path = opts.url.replace( '/wp-json/dev3va/v1/', '' );
		calls.push( { path, method: opts.method || 'GET', data: opts.data } );
		const base = path.split( '?' )[ 0 ];
		if ( extra[ base ] ) {
			return Promise.resolve( extra[ base ] );
		}
		if ( base === 'admin/overview' ) {
			return Promise.resolve( OVERVIEW );
		}
		if ( base === 'admin/vendors' ) {
			return Promise.resolve( { rows: [ VENDOR ], total: 1 } );
		}
		if ( base.startsWith( 'admin/vendors/' ) ) {
			return Promise.resolve( { ...VENDOR, has_override: true, override: 250 } );
		}
		if ( base === 'admin/moderation' ) {
			return Promise.resolve( { rows: [ ITEM ], total: 1, counts: { pending: 1, flagged: 0, approved: 0, rejected: 0 } } );
		}
		if ( base.startsWith( 'admin/moderation/' ) ) {
			return Promise.resolve( { ...ITEM, moderation: 'approved', status: 'publish' } );
		}
		return Promise.resolve( {} );
	} );
}

const settle = async ( view, n = 3 ) => {
	for ( let i = 0; i < n; i++ ) {
		await view.flush();
	}
};

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
} );

describe( 'admin dashboard', () => {
	it( 'shows marketplace KPIs, the connect notice and the queue counts', async () => {
		router();
		const counts = jest.fn();
		const view = await render(
			<ToastProvider>
				<Dashboard navigate={ () => {} } onCounts={ counts } />
			</ToastProvider>
		);
		await settle( view );
		expect( view.container.textContent ).toContain( 'Dokan' );
		expect( view.container.textContent ).toContain( 'Connect DEV3 AI' );
		expect( view.container.textContent ).toContain( '2 awaiting review' );
		expect( counts ).toHaveBeenCalledWith( 3 );
		view.unmount();
	} );
} );

describe( 'vendors', () => {
	it( 'saves a custom credit limit and feature override for one vendor', async () => {
		router();
		const view = await render(
			<ToastProvider>
				<Vendors settings={ { default_credits: 100 } } />
			</ToastProvider>
		);
		await settle( view );
		expect( view.container.textContent ).toContain( '30 / 100' );
		await click( view.getByText( 'Edit' ).closest( 'button' ) );
		await settle( view );
		const toggles = view.$$( '[role="dialog"] [role="switch"]' );
		await click( toggles[ 1 ] ); // Custom limit.
		await settle( view );
		const number = view.$( '[role="dialog"] input[type="number"]' );
		await change( number, '250' );
		const selects = view.$$( '[role="dialog"] select' );
		await change( selects[ 1 ], 'off' ); // Price suggestions.
		await click( view.getByText( 'Save' ).closest( 'button' ) );
		await settle( view );
		const save = calls.find( ( c ) => c.path === 'admin/vendors/7' );
		expect( save.method ).toBe( 'POST' );
		expect( save.data.quota ).toBe( 250 );
		expect( save.data.overrides.price ).toBe( 'off' );
		expect( save.data.access ).toBe( true );
		view.unmount();
	} );
} );

describe( 'moderation', () => {
	it( 'requires a note to reject and approves with one click', async () => {
		router();
		const view = await render(
			<ToastProvider>
				<Moderation onCounts={ () => {} } />
			</ToastProvider>
		);
		await settle( view );
		await click( view.getByText( 'Review' ).closest( 'button' ) );
		await settle( view );
		expect( view.container.ownerDocument.body.textContent ).toContain( 'replica' );
		const reject = view.getByText( 'Reject' ).closest( 'button' );
		expect( reject.disabled ).toBe( true );
		await click( view.getByText( 'Approve & publish' ).closest( 'button' ) );
		await settle( view );
		const decide = calls.find( ( c ) => c.path === 'admin/moderation/99' );
		expect( decide.data.action ).toBe( 'approve' );
		view.unmount();
	} );
} );

describe( 'settings', () => {
	it( 'saves feature switches and credit settings', async () => {
		const settings = {
			marketplace: 'auto',
			features: { listing: true, price: true, reply: true, profile: true, insights: true },
			default_credits: 100,
			credit_weights: { listing: 2, price: 1, reply: 1, profile: 1, insights: 1 },
			moderation_mode: 'review',
			blocked_terms: '',
			blocked_action: 'flag',
			moderation_policy: '',
			menu_label: '',
			menu_position: 45,
			vendor_branding: true,
			tone: 'friendly',
			language: '',
			comparables: 12,
			low_stock: 3,
			reply_policies: '',
			store_seo_output: true,
			notify_vendor: true,
			max_photos: 6,
			apply_alt_text: true,
		};
		const save = jest.fn( ( patch ) => Promise.resolve( { ...settings, ...patch } ) );
		const view = await render(
			<ToastProvider>
				<SettingsTab settings={ settings } save={ save } />
			</ToastProvider>
		);
		const label = view.getByText( 'Price suggestions' );
		await click( view.$( `#${ label.getAttribute( 'for' ) }` ) );
		await change( view.$$( 'input[type="number"]' )[ 1 ], '40' );
		await click( view.$$( 'button[type="submit"]' )[ 0 ] );
		await settle( view );
		expect( save ).toHaveBeenCalled();
		const patch = save.mock.calls[ 0 ][ 0 ];
		expect( patch.features.price ).toBe( false );
		expect( patch.default_credits ).toBe( 40 );
		view.unmount();
	} );
} );
