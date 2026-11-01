import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Icon, ProgressBar, Skeleton } from '../core3/ui';
import { fromNow, ModerationBadge, useVendor } from './shared';

const ACTIONS = [
	{ id: 'listing', feature: 'listing', icon: 'file', title: __( 'Write a listing', 'dev3-ai-vendor-assistant' ), text: __( 'Upload photos and a few notes. Get a title, description, tags and SEO text.', 'dev3-ai-vendor-assistant' ) },
	{ id: 'pricing', feature: 'price', icon: 'chart', title: __( 'Check a price', 'dev3-ai-vendor-assistant' ), text: __( 'Compare with similar listings on the marketplace and get a suggested price.', 'dev3-ai-vendor-assistant' ) },
	{ id: 'messages', feature: 'reply', icon: 'inbox', title: __( 'Reply to buyers', 'dev3-ai-vendor-assistant' ), text: __( 'Reviews, order notes and questions in one place, with reply drafts.', 'dev3-ai-vendor-assistant' ) },
	{ id: 'profile', feature: 'profile', icon: 'users', title: __( 'Polish your store', 'dev3-ai-vendor-assistant' ), text: __( 'A store bio, tagline and SEO title and description.', 'dev3-ai-vendor-assistant' ) },
	{ id: 'insights', feature: 'insights', icon: 'bolt', title: __( 'Get growth tips', 'dev3-ai-vendor-assistant' ), text: __( 'Your sales, listing quality and reviews, summed up as clear next steps.', 'dev3-ai-vendor-assistant' ) },
];

const FEATURE_LABEL = {
	listing: __( 'Listing', 'dev3-ai-vendor-assistant' ),
	price: __( 'Price', 'dev3-ai-vendor-assistant' ),
	reply: __( 'Reply', 'dev3-ai-vendor-assistant' ),
	profile: __( 'Store profile', 'dev3-ai-vendor-assistant' ),
	insights: __( 'Tips', 'dev3-ai-vendor-assistant' ),
};

export default function Home() {
	const { me, navigate } = useVendor();
	const [ recent, setRecent ] = useState( null );
	useEffect( () => {
		request( 'vendor/history' )
			.then( ( res ) => setRecent( res.rows.slice( 0, 4 ) ) )
			.catch( () => setRecent( [] ) );
	}, [] );
	const actions = ACTIONS.filter( ( a ) => me.features[ a.feature ] );
	const q = me.quota;

	return (
		<div className="dev3va-vendor-stack">
			<div className="dev3va-vendor-grid">
				{ actions.map( ( a ) => (
					<button key={ a.id } type="button" className="dev3va-vendor-action" onClick={ () => navigate( a.id ) }>
						<span className="dev3va-vendor-action__icon" aria-hidden="true">
							<Icon name={ a.icon } size={ 18 } />
						</span>
						<span className="dev3va-vendor-action__title">{ a.title }</span>
						<span className="dev3va-vendor-action__text">{ a.text }</span>
					</button>
				) ) }
			</div>
			<div className="dev3va-vendor-split">
				<Card title={ __( 'AI credits this month', 'dev3-ai-vendor-assistant' ) }>
					{ q.unlimited ? (
						<p className="dev3va-vendor-muted">{ __( 'Your store has unlimited AI credits.', 'dev3-ai-vendor-assistant' ) }</p>
					) : (
						<>
							<p className="dev3va-vendor-big">
								{ q.remaining }
								<span>
									{ ' ' }
									{ sprintf(
										/* translators: %d: monthly credits */
										__( 'of %d left', 'dev3-ai-vendor-assistant' ),
										q.limit
									) }
								</span>
							</p>
							<ProgressBar value={ q.percent } tone={ q.percent > 85 ? 'warning' : 'brand' } label={ __( 'Credits used', 'dev3-ai-vendor-assistant' ) } />
							<p className="dev3va-vendor-muted">
								{ sprintf(
									/* translators: %s: date */
									__( 'Resets on %s. Basic (non-AI) drafts are always free.', 'dev3-ai-vendor-assistant' ),
									new Date( q.resets_at ).toLocaleDateString()
								) }
							</p>
						</>
					) }
					{ me.moderation === 'review' && me.features.listing && (
						<p className="dev3va-vendor-muted">
							<Icon name="info" size={ 14 } /> { __( 'Listings written with AI are reviewed by the marketplace before they go live.', 'dev3-ai-vendor-assistant' ) }
						</p>
					) }
				</Card>
				<Card
					title={ __( 'Recent drafts', 'dev3-ai-vendor-assistant' ) }
					actions={
						<Button variant="link" onClick={ () => navigate( 'history' ) }>
							{ __( 'View all', 'dev3-ai-vendor-assistant' ) }
						</Button>
					}
				>
					{ ! recent && <Skeleton lines={ 3 } /> }
					{ recent && ! recent.length && <p className="dev3va-vendor-muted">{ __( 'Nothing yet. Pick a task above to get started.', 'dev3-ai-vendor-assistant' ) }</p> }
					{ recent && recent.length > 0 && (
						<ul className="dev3va-vendor-list">
							{ recent.map( ( r ) => (
								<li key={ r.id }>
									<span className="dev3va-vendor-list__main">
										<Badge tone="neutral">{ FEATURE_LABEL[ r.feature ] || r.feature }</Badge>
										<span className="dev3va-vendor-list__title">{ r.title || '—' }</span>
									</span>
									<span className="dev3va-vendor-list__side">
										<ModerationBadge state={ r.moderation } />
										<span className="dev3va-vendor-muted">{ fromNow( r.created_at ) }</span>
									</span>
								</li>
							) ) }
						</ul>
					) }
				</Card>
			</div>
		</div>
	);
}
