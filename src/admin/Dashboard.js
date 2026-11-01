import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ProgressBar, ResponsiveTable, SkeletonCard, Stat, StatGrid, formatMoney, formatNumber } from '../core3/ui';

export default function Dashboard( { navigate, onCounts } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		request( 'admin/overview' )
			.then( ( res ) => {
				setData( res );
				if ( onCounts ) {
					onCounts( res.moderation.pending + res.moderation.flagged || null );
				}
			} )
			.catch( setError );
	};
	useEffect( load, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const features = Object.keys( data.labels );
	const maxCredits = Math.max( 1, ...features.map( ( f ) => ( data.by_feature[ f ] ? data.by_feature[ f ].credits : 0 ) ) );
	const open = data.moderation.pending + data.moderation.flagged;
	const topCols = [
		{ key: 'store', label: __( 'Store', 'dev3-ai-vendor-assistant' ), primary: true },
		{ key: 'credits', label: __( 'Credits used', 'dev3-ai-vendor-assistant' ), align: 'end', render: ( r ) => ( r.limit ? `${ formatNumber( r.credits ) } / ${ formatNumber( r.limit ) }` : formatNumber( r.credits ) ) },
		{ key: 'cost', label: __( 'Est. cost', 'dev3-ai-vendor-assistant' ), align: 'end', render: ( r ) => formatMoney( r.cost ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-vendor-assistant' ) }
				description={ __( 'How your vendors use the AI assistant this month.', 'dev3-ai-vendor-assistant' ) }
				actions={
					<Button icon="users" onClick={ () => navigate( 'vendors' ) }>
						{ __( 'Manage vendors', 'dev3-ai-vendor-assistant' ) }
					</Button>
				}
			/>
			{ ! data.marketplace && (
				<Notice tone="warning" title={ __( 'No marketplace plugin found', 'dev3-ai-vendor-assistant' ) }>
					{ __( 'Install and activate Dokan (Lite or Pro) or WCFM Marketplace. The assistant appears in their vendor dashboards.', 'dev3-ai-vendor-assistant' ) }
				</Notice>
			) }
			{ ! data.ai.key_set && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI', 'dev3-ai-vendor-assistant' ) }
					actions={
						<Button size="sm" variant="primary" href="#/api">
							{ __( 'Add your API key', 'dev3-ai-vendor-assistant' ) }
						</Button>
					}
				>
					{ __( 'Vendors can already use the basic (non-AI) helpers. Add a Google Gemini key to turn on AI writing, pricing and replies.', 'dev3-ai-vendor-assistant' ) }
				</Notice>
			) }
			<StatGrid>
				<Stat label={ __( 'Marketplace', 'dev3-ai-vendor-assistant' ) } value={ data.marketplace ? data.marketplace.label : '—' } icon="box" hint={ sprintf( /* translators: %d: vendors */ __( '%d vendors', 'dev3-ai-vendor-assistant' ), data.vendors ) } />
				<Stat label={ __( 'Vendors using AI', 'dev3-ai-vendor-assistant' ) } value={ formatNumber( data.totals.active ) } icon="users" tone="brand" hint={ data.month } />
				<Stat label={ __( 'Credits used', 'dev3-ai-vendor-assistant' ) } value={ formatNumber( data.totals.credits ) } icon="bolt" hint={ sprintf( /* translators: %d: requests */ __( '%d AI requests', 'dev3-ai-vendor-assistant' ), data.totals.requests ) } />
				<Stat label={ __( 'Est. vendor AI cost', 'dev3-ai-vendor-assistant' ) } value={ formatMoney( data.totals.cost ) } icon="chart" hint={ __( 'This month, USD', 'dev3-ai-vendor-assistant' ) } />
				<Stat label={ __( 'Listings to review', 'dev3-ai-vendor-assistant' ) } value={ formatNumber( open ) } icon="check" tone={ open ? 'warning' : 'success' } hint={ sprintf( /* translators: %s: moderation mode */ __( 'Moderation: %s', 'dev3-ai-vendor-assistant' ), data.settings.moderation_mode ) } />
			</StatGrid>
			<Columns min={ 320 }>
				<Card title={ __( 'Credits by feature', 'dev3-ai-vendor-assistant' ) } description={ __( 'This month, all vendors.', 'dev3-ai-vendor-assistant' ) }>
					<ul className="dev3va-feature-bars">
						{ features.map( ( f ) => {
							const row = data.by_feature[ f ] || { credits: 0, requests: 0, cost: 0 };
							return (
								<li key={ f }>
									<div className="dev3va-feature-bars__top">
										<span>{ data.labels[ f ] }</span>
										<span className="dev3va-feature-bars__num">
											{ sprintf( /* translators: 1: credits, 2: requests */ __( '%1$s credits · %2$s requests', 'dev3-ai-vendor-assistant' ), formatNumber( row.credits ), formatNumber( row.requests ) ) }
										</span>
									</div>
									<ProgressBar value={ ( row.credits / maxCredits ) * 100 } label={ data.labels[ f ] } />
								</li>
							);
						} ) }
					</ul>
				</Card>
				<Card title={ __( 'Top vendors', 'dev3-ai-vendor-assistant' ) } flush actions={ <Button size="sm" variant="link" onClick={ () => navigate( 'activity' ) }>{ __( 'Activity', 'dev3-ai-vendor-assistant' ) }</Button> }>
					<ResponsiveTable columns={ topCols } rows={ data.top } empty={ { icon: 'users', title: __( 'No AI use yet this month', 'dev3-ai-vendor-assistant' ), description: __( 'Vendors find the assistant in their dashboard menu.', 'dev3-ai-vendor-assistant' ) } } />
				</Card>
			</Columns>
			<Card
				title={ __( 'Moderation queue', 'dev3-ai-vendor-assistant' ) }
				actions={
					<Button size="sm" variant={ open ? 'primary' : 'secondary' } onClick={ () => navigate( 'moderation' ) }>
						{ __( 'Open queue', 'dev3-ai-vendor-assistant' ) }
					</Button>
				}
			>
				{ open ? (
					<div className="dev3va-chips">
						<Badge tone="warning" dot>
							{ sprintf( /* translators: %d: count */ __( '%d awaiting review', 'dev3-ai-vendor-assistant' ), data.moderation.pending ) }
						</Badge>
						<Badge tone="info" dot>
							{ sprintf( /* translators: %d: count */ __( '%d flagged', 'dev3-ai-vendor-assistant' ), data.moderation.flagged ) }
						</Badge>
						<Badge tone="success" dot>
							{ sprintf( /* translators: %d: count */ __( '%d approved', 'dev3-ai-vendor-assistant' ), data.moderation.approved ) }
						</Badge>
						<Badge tone="danger" dot>
							{ sprintf( /* translators: %d: count */ __( '%d rejected', 'dev3-ai-vendor-assistant' ), data.moderation.rejected ) }
						</Badge>
					</div>
				) : (
					<EmptyState compact icon="check" title={ __( 'Nothing to review', 'dev3-ai-vendor-assistant' ) } description={ __( 'AI-written listings appear here when moderation is on.', 'dev3-ai-vendor-assistant' ) } />
				) }
			</Card>
		</>
	);
}
