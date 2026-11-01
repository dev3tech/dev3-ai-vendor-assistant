import apiFetch from '@wordpress/api-fetch';
import App from '../../src/vendor/App';
import { fromNow, htmlToText, money, quotaEmpty, textToHtml } from '../../src/vendor/shared';
import { act, change, click, render } from './render';

const ME = {
	vendor_id: 7,
	store_name: 'Clay & Co',
	store_url: 'http://example.org/store/clay',
	marketplace: { id: 'dokan', label: 'Dokan' },
	features: { listing: true, price: true, reply: true, profile: true, insights: true },
	labels: { listing: 'Listing writer', price: 'Price suggestions', reply: 'Buyer replies', profile: 'Store bio & SEO', insights: 'Performance tips' },
	quota: { limit: 100, used: 12, remaining: 88, unlimited: false, percent: 12, month: '2026-10', resets_at: '2026-11-01T00:00:00+00:00' },
	weights: { listing: 2, price: 1, reply: 1, profile: 1, insights: 1 },
	ai: { available: true },
	branding: true,
	moderation: 'review',
	max_photos: 6,
	tone: 'friendly',
	categories: [ { id: 15, name: 'Mugs' }, { id: 16, name: 'Bowls' } ],
	currency: '$',
};

const LISTING = {
	draft_id: 41,
	draft: { title: 'Speckled stoneware mug', short_description: 'A hand-thrown mug.', description: '<p>Made by hand.</p>', tags: 'mug, stoneware', categories: [ 'Mugs' ], category_ids: [ 15 ], attributes: [ { name: 'Capacity', value: '350 ml' } ], seo_title: 'Stoneware mug', seo_description: 'Hand-thrown mug', alt_texts: [] },
	original: { title: '', short_description: '', description: '', tags: '', seo_title: '', seo_description: '' },
	product_id: 0,
	flags: [],
	ai: true,
	fallback: null,
	meta: { model: 'gemini-2.5-flash', tokens: 900, cached: false },
	moderation: 'pending',
};

const MESSAGES = {
	items: [ { source: 'reviews', source_label: 'Product review', id: 91, buyer: 'Ana', subject: 'Speckled mug', text: 'Lovely mug but it arrived late.', rating: 4, product_id: 30, product: 'Speckled mug', order_id: 0, date: '2026-10-20T10:00:00+00:00', replied: false, reply: '' } ],
	sources: [ { id: 'reviews', label: 'Product review' } ],
	counts: { unanswered: 1 },
};

let calls = [];

function router( overrides = {} ) {
	calls = [];
	apiFetch.mockImplementation( ( opts ) => {
		const path = opts.url.replace( '/wp-json/dev3va/v1/', '' );
		calls.push( { path, method: opts.method || 'GET', data: opts.data, body: opts.body } );
		const base = path.split( '?' )[ 0 ];
		if ( overrides[ base ] ) {
			return Promise.resolve( typeof overrides[ base ] === 'function' ? overrides[ base ]( opts ) : overrides[ base ] );
		}
		switch ( base ) {
			case 'vendor/me':
				return Promise.resolve( ME );
			case 'vendor/history':
				return Promise.resolve( { rows: [], total: 0, quota: ME.quota } );
			case 'vendor/products':
				return Promise.resolve( { rows: [ { id: 30, title: 'Speckled mug', status: 'publish', price: '24', type: 'simple', image: '', moderation: '', note: '', edit_url: '#' } ] } );
			case 'vendor/listing/draft':
				return Promise.resolve( LISTING );
			case 'vendor/listing/apply':
				return Promise.resolve( { product_id: 99, created: true, status: 'pending', moderation: 'pending', flags: [], edit_url: 'http://example.org/dashboard/products/?product_id=99', view_url: '' } );
			case 'vendor/price':
				return Promise.resolve( {
					draft_id: 50,
					product: { id: 30, title: 'Speckled mug', price: 24 },
					goal: 'competitive',
					comparables: [ { id: 1, title: 'Blue mug', price: 20, rating: 4.5, reviews: 3, store: 'Other', url: '#', own: false } ],
					stats: { count: 5, min: 18, p25: 20, median: 26, p75: 30, max: 40, average: 27 },
					basic: { price: 26, low: 20, high: 30, rationale: 'Based on 5 listings', confidence: 'medium' },
					suggestion: { price: 27.5, low: 22, high: 31, rationale: 'Handmade mugs sell near the median.', confidence: 'high' },
					ai: true,
					fallback: null,
					meta: { model: 'gemini-2.5-flash', tokens: 300 },
					currency: '$',
				} );
			case 'vendor/price/apply':
				return Promise.resolve( { product_id: 30, old_price: 24, price: 27.5 } );
			case 'vendor/messages':
				return Promise.resolve( MESSAGES );
			case 'vendor/messages/draft':
				return Promise.resolve( { draft_id: 61, message: MESSAGES.items[ 0 ], draft: { reply: 'Hi Ana, thank you!', intent: 'shipping', sentiment: 'neutral', summary: 'Late delivery' }, ai: false, fallback: null, meta: {}, can_send: true } );
			case 'vendor/messages/send':
				return Promise.resolve( { ...MESSAGES.items[ 0 ], replied: true, reply: 'Hi Ana, thank you!' } );
			case 'vendor/insights':
				return Promise.resolve( {
					stats: { days: 30, orders: 4, orders_prev: 2, revenue: 120, revenue_prev: 100, aov: 30, items_sold: 5, revenue_change: 20, top_products: [ { id: 30, title: 'Speckled mug', qty: 3, revenue: 72 } ], products: 3, no_sales: [], low_stock: [], quality: [ { id: 31, title: 'Bowl', issues: [ 'no_image' ] } ], rating: 4.5, reviews: 2, unanswered_reviews: 1, currency: '$' },
					tips: [ { title: '1 listing has no photo', detail: 'Start with: Bowl.', priority: 'high', action: 'listing' } ],
				} );
			case 'vendor/insights/ai':
				return Promise.resolve( { summary: { headline: 'Sales are up 20%', tips: [ { title: 'Add photos to Bowl', detail: 'Photos sell.', priority: 'high', action: 'listing' } ] }, meta: { model: 'm', tokens: 10, cached: false }, report: null } );
			case 'vendor/profile':
				return Promise.resolve( { profile: { bio: '<p>Old bio</p>', tagline: '', seo_title: '', seo_description: '', seo_keywords: '' }, store_name: 'Clay & Co', store_url: '#', facts: { categories: [ 'Mugs' ], products: [ 'Speckled mug' ], count: 1 }, policies: '' } );
			case 'vendor/profile/draft':
				return Promise.resolve( { draft_id: 70, draft: { bio: '<p>First para.</p><p>Second para.</p>', tagline: 'Mugs made by hand', seo_title: 'Clay & Co', seo_description: 'Handmade mugs', seo_keywords: 'mugs' }, original: { bio: '<p>Old bio</p>', tagline: '', seo_title: '', seo_description: '', seo_keywords: '' }, ai: true, fallback: null, meta: {} } );
			case 'vendor/profile/apply':
				return Promise.resolve( { profile: { bio: '<p>First para.</p>', tagline: 'Mugs made by hand', seo_title: '', seo_description: '', seo_keywords: '' }, store_name: 'Clay & Co', store_url: '#', facts: { categories: [], products: [], count: 0 }, policies: '' } );
		}
		return Promise.resolve( {} );
	} );
}

const go = ( route ) => {
	window.location.hash = `#/${ route }`;
};

afterEach( () => {
	document.body.innerHTML = '';
	window.location.hash = '';
	apiFetch.mockReset();
} );

const settle = async ( view, n = 3 ) => {
	for ( let i = 0; i < n; i++ ) {
		await view.flush();
	}
};

describe( 'vendor helpers', () => {
	it( 'detects an empty quota per feature cost', () => {
		expect( quotaEmpty( { quota: { unlimited: false, remaining: 1 }, weights: { listing: 2, reply: 1 } }, 'listing' ) ).toBe( true );
		expect( quotaEmpty( { quota: { unlimited: false, remaining: 1 }, weights: { listing: 2, reply: 1 } }, 'reply' ) ).toBe( false );
		expect( quotaEmpty( { quota: { unlimited: true, remaining: null }, weights: {} }, 'listing' ) ).toBe( false );
	} );
	it( 'round-trips listing HTML through editable text', () => {
		const html = '<p>Made by hand &amp; fired twice.</p><ul><li>350 ml</li><li>Dishwasher safe</li></ul>';
		const text = htmlToText( html );
		expect( text ).toBe( 'Made by hand & fired twice.\n\n- 350 ml\n- Dishwasher safe' );
		expect( textToHtml( text ) ).toBe( html );
		expect( textToHtml( 'Line one\nline <b>two</b>' ) ).toBe( '<p>Line one<br>line &lt;b&gt;two&lt;/b&gt;</p>' );
	} );

	it( 'formats money and relative dates', () => {
		expect( money( 27.5, '$' ) ).toBe( '$27.50' );
		expect( money( 30, '€' ) ).toBe( '€30' );
		expect( fromNow( new Date().toISOString() ) ).toBe( 'just now' );
		expect( fromNow( '' ) ).toBe( '' );
	} );
} );

describe( 'vendor app shell', () => {
	it( 'shows the store, credits and only enabled features', async () => {
		router( { 'vendor/me': { ...ME, features: { ...ME.features, price: false } } } );
		const view = await render( <App boot={ { menuLabel: 'AI Assistant' } } /> );
		await settle( view );
		expect( view.queryByText( 'Clay & Co' ) ).not.toBeNull();
		expect( view.container.textContent ).toContain( '88 of 100 credits left' );
		const tabs = view.$$( '[role="tab"]' ).map( ( t ) => t.textContent );
		expect( tabs ).toContain( 'Listing writer' );
		expect( tabs ).not.toContain( 'Pricing' );
		view.unmount();
	} );

	it( 'hides the AI button when AI is unavailable and keeps the basic helper', async () => {
		router( { 'vendor/me': { ...ME, ai: { available: false } } } );
		go( 'listing' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		expect( view.queryByText( 'Generate with DEV3 AI' ) ).toBeNull();
		expect( view.queryByText( 'Create a draft' ) ).not.toBeNull();
		view.unmount();
	} );

	it( 'uses neutral AI labels when branding is off', async () => {
		router( { 'vendor/me': { ...ME, branding: false } } );
		go( 'listing' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		expect( view.queryByText( 'Generate with AI' ) ).not.toBeNull();
		expect( view.queryByText( 'Generate with DEV3 AI' ) ).toBeNull();
		view.unmount();
	} );
} );

describe( 'listing writer', () => {
	it( 'drafts from notes and creates the listing with the edited extras', async () => {
		router();
		go( 'listing' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		await change( view.$( 'textarea' ), 'Speckled mug\nCapacity: 350 ml' );
		await click( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ) );
		await settle( view );
		const draftCall = calls.find( ( c ) => c.path === 'vendor/listing/draft' );
		expect( draftCall.data.mode ).toBe( 'ai' );
		expect( draftCall.data.notes ).toContain( 'Capacity' );
		expect( view.container.textContent ).toContain( 'Speckled stoneware mug' );
		await click( view.getByText( 'Create listing' ).closest( 'button' ) );
		await settle( view );
		const apply = calls.find( ( c ) => c.path === 'vendor/listing/apply' );
		expect( apply.data.draft_id ).toBe( 41 );
		expect( apply.data.fields.category_ids ).toEqual( [ 15 ] );
		expect( apply.data.fields.attributes ).toEqual( [ { name: 'Capacity', value: '350 ml' } ] );
		expect( view.container.textContent ).toContain( 'waiting for the marketplace to review' );
		view.unmount();
	} );

	it( 'uploads photos as multipart form data', async () => {
		router( { 'vendor/photos': { id: 501, url: '/a.jpg', thumb: '/a-150.jpg', alt: '' } } );
		go( 'listing' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		const input = view.$( '#dev3va-photo-input' );
		const file = new window.File( [ 'x' ], 'mug.jpg', { type: 'image/jpeg' } );
		Object.defineProperty( input, 'files', { value: [ file ], configurable: true } );
		await act( async () => {
			input.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		} );
		await settle( view );
		const up = calls.find( ( c ) => c.path === 'vendor/photos' );
		expect( up.body ).toBeInstanceOf( window.FormData );
		expect( view.$( '.dev3va-vendor-thumbs img' ).getAttribute( 'src' ) ).toBe( '/a-150.jpg' );
		view.unmount();
	} );
} );

describe( 'pricing', () => {
	it( 'suggests from comparables and applies the price to the product', async () => {
		router();
		go( 'pricing/30' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		await click( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ) );
		await settle( view );
		expect( calls.find( ( c ) => c.path === 'vendor/price' ).data.product_id ).toBe( 30 );
		expect( view.container.textContent ).toContain( '$27.50' );
		expect( view.container.textContent ).toContain( 'Blue mug' );
		await click( view.getByText( 'Apply price' ).closest( 'button' ) );
		await settle( view );
		const apply = calls.find( ( c ) => c.path === 'vendor/price/apply' );
		expect( apply.data ).toEqual( { product_id: 30, price: 27.5, draft_id: 50 } );
		view.unmount();
	} );
} );

describe( 'buyer messages', () => {
	it( 'lists unanswered messages, drafts a reply and sends it back to the source', async () => {
		router();
		go( 'messages' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		expect( calls.find( ( c ) => c.path.startsWith( 'vendor/messages?' ) ).path ).toContain( 'filter=unanswered' );
		await click( view.$( '.dev3va-vendor-msg' ) );
		await settle( view );
		expect( view.container.textContent ).toContain( 'Lovely mug but it arrived late.' );
		await click( view.getByText( 'Basic draft (no AI)' ).closest( 'button' ) );
		await settle( view );
		expect( calls.find( ( c ) => c.path === 'vendor/messages/draft' ).data ).toMatchObject( { source: 'reviews', id: 91, mode: 'basic' } );
		await click( view.getByText( 'Send reply' ).closest( 'button' ) );
		await settle( view );
		expect( calls.find( ( c ) => c.path === 'vendor/messages/send' ).data ).toEqual( { source: 'reviews', id: 91, reply: 'Hi Ana, thank you!', draft_id: 61 } );
		view.unmount();
	} );
} );

describe( 'profile and insights', () => {
	it( 'turns the edited bio back into paragraphs when saving', async () => {
		router();
		go( 'profile' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		await click( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ) );
		await settle( view );
		await click( view.getByText( 'Save to my store' ).closest( 'button' ) );
		await settle( view );
		const apply = calls.find( ( c ) => c.path === 'vendor/profile/apply' );
		expect( apply.data.fields.bio ).toBe( '<p>First para.</p><p>Second para.</p>' );
		expect( apply.data.draft_id ).toBe( 70 );
		view.unmount();
	} );

	it( 'shows stats and rule tips, then the AI summary', async () => {
		router();
		go( 'insights' );
		const view = await render( <App boot={ {} } /> );
		await settle( view );
		expect( view.container.textContent ).toContain( '1 listing has no photo' );
		expect( view.container.textContent ).toContain( '$120' );
		await click( view.getByText( 'Summarize with DEV3 AI' ).closest( 'button' ) );
		await settle( view );
		expect( view.container.textContent ).toContain( 'Sales are up 20%' );
		expect( view.container.textContent ).toContain( 'Add photos to Bowl' );
		view.unmount();
	} );
} );
