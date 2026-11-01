import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, EmptyState, ErrorNotice, Notice, ResponsiveTable, SkeletonCard, Stat, StatGrid } from '../core3/ui';
import { AiStateNotice, money, quotaEmpty, useCall, useVendor } from './shared';

const PRIORITY = { high: 'danger', medium: 'warning', low: 'neutral' };
const ACTION_ROUTE = { listing: 'listing', pricing: 'pricing', messages: 'messages', profile: 'profile' };
const ISSUE = {
	no_image: __( 'No photo', 'dev3-ai-vendor-assistant' ),
	thin_description: __( 'Short description', 'dev3-ai-vendor-assistant' ),
	no_price: __( 'No price', 'dev3-ai-vendor-assistant' ),
	no_category: __( 'No category', 'dev3-ai-vendor-assistant' ),
};

function Tips( { tips, onGo, features } ) {
	return (
		<ul className="dev3va-vendor-tips">
			{ tips.map( ( t, i ) => {
				const route = ACTION_ROUTE[ t.action ];
				const allowed = route && ( route !== 'pricing' || features.price ) && ( route !== 'listing' || features.listing ) && ( route !== 'messages' || features.reply ) && ( route !== 'profile' || features.profile );
				return (
					<li key={ i } className={ `is-${ t.priority }` }>
						<div className="dev3va-vendor-tips__head">
							<Badge tone={ PRIORITY[ t.priority ] || 'neutral' }>{ t.priority }</Badge>
							<strong>{ t.title }</strong>
						</div>
						<p>{ t.detail }</p>
						{ allowed && (
							<Button size="sm" variant="link" iconRight="chevronRight" onClick={ () => onGo( route ) }>
								{ __( 'Do it now', 'dev3-ai-vendor-assistant' ) }
							</Button>
						) }
					</li>
				);
			} ) }
		</ul>
	);
}

export default function Insights() {
	const { me, navigate } = useVendor();
	const { busy, error, run, setError } = useCall();
	const [ report, setReport ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ summary, setSummary ] = useState( null );

	const load = () =>
		request( 'vendor/insights' )
			.then( setReport )
			.catch( setLoadError );
	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const summarize = () =>
		run( 'ai', 'vendor/insights/ai', {} )
			.then( ( res ) => {
				setSummary( res );
				if ( res.report && res.report.stats ) {
					setReport( res.report );
				}
			} )
			.catch( () => {} );

	if ( loadError ) {
		return <ErrorNotice error={ loadError } onRetry={ load } />;
	}
	if ( ! report ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const s = report.stats;
	const change = s.revenue_change;
	const aiOk = me.ai.available && ! quotaEmpty( me, 'insights' );
	const columns = [
		{ key: 'title', label: __( 'Product', 'dev3-ai-vendor-assistant' ), primary: true },
		{ key: 'qty', label: __( 'Sold', 'dev3-ai-vendor-assistant' ), align: 'end', sortable: true },
		{ key: 'revenue', label: __( 'Revenue', 'dev3-ai-vendor-assistant' ), align: 'end', sortable: true, render: ( r ) => money( r.revenue, s.currency ) },
	];
	const quality = [
		{ key: 'title', label: __( 'Listing', 'dev3-ai-vendor-assistant' ), primary: true },
		{
			key: 'issues',
			label: __( 'What to fix', 'dev3-ai-vendor-assistant' ),
			render: ( r ) => (
				<span className="dev3va-vendor-chips">
					{ r.issues.map( ( i ) => (
						<Badge key={ i } tone="warning">
							{ ISSUE[ i ] || i }
						</Badge>
					) ) }
				</span>
			),
		},
	];

	return (
		<div className="dev3va-vendor-stack">
			<StatGrid min={ 150 }>
				<Stat
					label={ sprintf( /* translators: %d: days */ __( 'Revenue, last %d days', 'dev3-ai-vendor-assistant' ), s.days ) }
					value={ money( s.revenue, s.currency ) }
					tone="brand"
					trend={ change === null ? undefined : { value: `${ Math.abs( change ) }%`, direction: change > 0 ? 'up' : change < 0 ? 'down' : 'flat', good: change >= 0 } }
					hint={ change === null ? __( 'No sales in the previous period', 'dev3-ai-vendor-assistant' ) : __( 'vs previous period', 'dev3-ai-vendor-assistant' ) }
				/>
				<Stat label={ __( 'Orders', 'dev3-ai-vendor-assistant' ) } value={ s.orders } hint={ sprintf( /* translators: %d: orders */ __( '%d before', 'dev3-ai-vendor-assistant' ), s.orders_prev ) } />
				<Stat label={ __( 'Average order', 'dev3-ai-vendor-assistant' ) } value={ money( s.aov, s.currency ) } />
				<Stat label={ __( 'Rating', 'dev3-ai-vendor-assistant' ) } value={ s.reviews ? `${ s.rating } ★` : '—' } hint={ sprintf( /* translators: %d: reviews */ __( '%d reviews', 'dev3-ai-vendor-assistant' ), s.reviews ) } />
			</StatGrid>
			<AiStateNotice feature="insights" />
			<Card
				tone={ summary ? 'ai' : undefined }
				title={ summary ? summary.summary.headline || __( 'Your growth plan', 'dev3-ai-vendor-assistant' ) : __( 'Tips for your store', 'dev3-ai-vendor-assistant' ) }
				description={ summary ? __( 'Written from your numbers below. Only your own store data was used.', 'dev3-ai-vendor-assistant' ) : __( 'Checks of your listings, stock, reviews and sales.', 'dev3-ai-vendor-assistant' ) }
				actions={
					aiOk && ! summary ? (
						<AiButton loading={ busy === 'ai' } onClick={ summarize }>
							{ me.branding ? __( 'Summarize with DEV3 AI', 'dev3-ai-vendor-assistant' ) : __( 'Summarize with AI', 'dev3-ai-vendor-assistant' ) }
						</AiButton>
					) : null
				}
			>
				{ error && <ErrorNotice error={ error } onRetry={ summarize } onDismiss={ () => setError( null ) } /> }
				<Tips tips={ summary ? summary.summary.tips : report.tips } onGo={ navigate } features={ me.features } />
				{ summary && summary.meta.cached && <p className="dev3va-vendor-muted">{ __( 'Same numbers as earlier today: this summary was reused at no cost.', 'dev3-ai-vendor-assistant' ) }</p> }
			</Card>
			<div className="dev3va-vendor-split">
				<Card title={ __( 'Top products', 'dev3-ai-vendor-assistant' ) } flush>
					<ResponsiveTable columns={ columns } rows={ s.top_products } empty={ { icon: 'chart', title: __( 'No sales in this period yet', 'dev3-ai-vendor-assistant' ) } } />
				</Card>
				<Card title={ __( 'Listing quality', 'dev3-ai-vendor-assistant' ) } flush>
					<ResponsiveTable columns={ quality } rows={ s.quality } pageSize={ 6 } empty={ { icon: 'check', title: __( 'All listings look complete', 'dev3-ai-vendor-assistant' ) } } />
				</Card>
			</div>
			{ s.low_stock.length > 0 && (
				<Notice tone="warning" title={ __( 'Low stock', 'dev3-ai-vendor-assistant' ) }>
					{ s.low_stock.map( ( p ) => `${ p.title } (${ p.stock })` ).join( ', ' ) }
				</Notice>
			) }
			{ s.products === 0 && <EmptyState icon="box" title={ __( 'No published listings yet', 'dev3-ai-vendor-assistant' ) } description={ __( 'Publish a listing to see performance tips.', 'dev3-ai-vendor-assistant' ) } /> }
		</div>
	);
}
