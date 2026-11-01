import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Drawer, ErrorNotice, Notice, PageHeader, ResponsiveTable, Stack, Tabs, Textarea, useToast } from '../core3/ui';

const STATE = {
	pending: { tone: 'warning', label: __( 'Awaiting review', 'dev3-ai-vendor-assistant' ) },
	flagged: { tone: 'info', label: __( 'Flagged', 'dev3-ai-vendor-assistant' ) },
	approved: { tone: 'success', label: __( 'Approved', 'dev3-ai-vendor-assistant' ) },
	rejected: { tone: 'danger', label: __( 'Rejected', 'dev3-ai-vendor-assistant' ) },
};
const VERDICT = { approve: 'success', review: 'warning', reject: 'danger' };

function Review( { item, onClose, onDone, onUsage } ) {
	const toast = useToast();
	const [ note, setNote ] = useState( item.note || '' );
	const [ busy, setBusy ] = useState( '' );
	const [ check, setCheck ] = useState( item.check );
	const [ error, setError ] = useState( null );

	const decide = ( action ) => {
		setBusy( action );
		setError( null );
		request( `admin/moderation/${ item.id }`, { method: 'POST', data: { action, note } } )
			.then( ( row ) => {
				toast.success( action === 'approve' ? __( 'Listing approved and published.', 'dev3-ai-vendor-assistant' ) : __( 'Listing returned to the vendor.', 'dev3-ai-vendor-assistant' ) );
				onDone( row );
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const runCheck = () => {
		setBusy( 'check' );
		setError( null );
		request( `admin/moderation/${ item.id }/check`, { method: 'POST' } )
			.then( ( res ) => {
				setCheck( res );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const state = STATE[ item.moderation ];
	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ item.title }
			description={ sprintf( /* translators: %s: store */ __( 'By %s', 'dev3-ai-vendor-assistant' ), item.vendor ) }
			footer={
				<>
					<Button variant="danger" icon="x" loading={ busy === 'reject' } disabled={ !! busy || ! note.trim() } onClick={ () => decide( 'reject' ) }>
						{ __( 'Reject', 'dev3-ai-vendor-assistant' ) }
					</Button>
					<Button variant="primary" icon="check" loading={ busy === 'approve' } disabled={ !! busy } onClick={ () => decide( 'approve' ) }>
						{ item.status === 'publish' ? __( 'Approve', 'dev3-ai-vendor-assistant' ) : __( 'Approve & publish', 'dev3-ai-vendor-assistant' ) }
					</Button>
				</>
			}
		>
			<Stack gap={ 16 }>
				<div className="dev3va-chips">
					{ state && <Badge tone={ state.tone } dot>{ state.label }</Badge> }
					{ item.ai_written && <Badge tone="brand">{ __( 'AI-written', 'dev3-ai-vendor-assistant' ) }</Badge> }
					<Badge tone="neutral">{ item.status }</Badge>
					{ item.price_html && <Badge tone="neutral">{ item.price_html }</Badge> }
				</div>
				{ item.flags.length > 0 && (
					<Notice tone="warning" title={ __( 'Blocked words found', 'dev3-ai-vendor-assistant' ) }>
						{ item.flags.join( ', ' ) }
					</Notice>
				) }
				{ item.image && <img className="dev3va-mod-image" src={ item.image } alt="" /> }
				{ item.short && <p className="dev3va-mod-short">{ item.short }</p> }
				{ /* Description HTML is limited to p/ul/ol/li/strong/em/br by wp_kses on the server. */ }
				<div className="dev3va-mod-desc" dangerouslySetInnerHTML={ { __html: item.description } } />
				{ item.tags.length > 0 && (
					<div className="dev3va-chips">
						{ item.tags.map( ( t ) => (
							<Badge key={ t } tone="neutral">
								{ t }
							</Badge>
						) ) }
					</div>
				) }
				<Card tone="ai" title={ __( 'Policy check', 'dev3-ai-vendor-assistant' ) } actions={ <AiButton size="sm" loading={ busy === 'check' } disabled={ !! busy && busy !== 'check' } onClick={ runCheck }>{ check ? __( 'Check again', 'dev3-ai-vendor-assistant' ) : __( 'Check with DEV3 AI', 'dev3-ai-vendor-assistant' ) }</AiButton> }>
					{ ! check && <p className="dev3va-muted">{ __( 'DEV3 AI reads the listing against your marketplace policy and lists anything to fix.', 'dev3-ai-vendor-assistant' ) }</p> }
					{ check && (
						<Stack gap={ 8 }>
							<div className="dev3va-chips">
								<Badge tone={ VERDICT[ check.verdict ] || 'info' }>{ check.verdict }</Badge>
								<span>{ check.summary }</span>
							</div>
							{ check.issues.length > 0 && (
								<ul className="dev3va-issues">
									{ check.issues.map( ( i ) => (
										<li key={ i }>{ i }</li>
									) ) }
								</ul>
							) }
						</Stack>
					) }
				</Card>
				{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }
				<Textarea label={ __( 'Note to the vendor', 'dev3-ai-vendor-assistant' ) } value={ note } onChange={ setNote } rows={ 3 } maxLength={ 1000 } help={ __( 'Required to reject. The vendor gets it by email and in their assistant history.', 'dev3-ai-vendor-assistant' ) } />
				<div className="dev3va-chips">
					{ item.edit_url && (
						<Button size="sm" variant="link" href={ item.edit_url } iconRight="external">
							{ __( 'Edit in WooCommerce', 'dev3-ai-vendor-assistant' ) }
						</Button>
					) }
					{ item.view_url && (
						<Button size="sm" variant="link" href={ item.view_url } iconRight="external">
							{ __( 'Preview', 'dev3-ai-vendor-assistant' ) }
						</Button>
					) }
				</div>
			</Stack>
		</Drawer>
	);
}

export default function Moderation( { onCounts, onUsage = () => {} } ) {
	const [ status, setStatus ] = useState( 'open' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ active, setActive ] = useState( null );

	const load = () => {
		setError( null );
		setData( ( d ) => ( d ? { ...d, loading: true } : null ) );
		request( `admin/moderation?status=${ status }&page=${ page }` )
			.then( ( res ) => {
				setData( res );
				if ( onCounts ) {
					onCounts( res.counts.pending + res.counts.flagged || null );
				}
			} )
			.catch( setError );
	};
	useEffect( load, [ status, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const counts = data ? data.counts : null;
	const tabs = [
		{ id: 'open', label: __( 'To review', 'dev3-ai-vendor-assistant' ), badge: counts ? counts.pending + counts.flagged : undefined },
		{ id: 'approved', label: __( 'Approved', 'dev3-ai-vendor-assistant' ) },
		{ id: 'rejected', label: __( 'Rejected', 'dev3-ai-vendor-assistant' ) },
	];
	const columns = [
		{
			key: 'title',
			label: __( 'Listing', 'dev3-ai-vendor-assistant' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3va-mod-row">
					{ r.image ? <img src={ r.image } alt="" width="40" height="40" /> : <span className="dev3va-mod-row__ph" aria-hidden="true" /> }
					<span className="dev3va-cell">
						<strong>{ r.title }</strong>
						<span className="dev3va-muted">{ r.vendor }</span>
					</span>
				</span>
			),
		},
		{ key: 'moderation', label: __( 'State', 'dev3-ai-vendor-assistant' ), render: ( r ) => ( STATE[ r.moderation ] ? <Badge tone={ STATE[ r.moderation ].tone } dot>{ STATE[ r.moderation ].label }</Badge> : r.moderation ) },
		{ key: 'flags', label: __( 'Flags', 'dev3-ai-vendor-assistant' ), hideOnMobile: true, render: ( r ) => ( r.flags.length ? r.flags.join( ', ' ) : '—' ) },
		{ key: 'price_html', label: __( 'Price', 'dev3-ai-vendor-assistant' ), align: 'end' },
		{ key: 'modified', label: __( 'Updated', 'dev3-ai-vendor-assistant' ), hideOnMobile: true, nowrap: true, render: ( r ) => new Date( r.modified ).toLocaleDateString() },
	];

	return (
		<>
			<PageHeader title={ __( 'Moderation', 'dev3-ai-vendor-assistant' ) } description={ __( 'Listings written with the AI assistant. Approve to publish, or send them back with a note.', 'dev3-ai-vendor-assistant' ) } />
			<Card flush>
				<div className="dev3va-tabs-scroll">
					<Tabs
						tabs={ tabs }
						value={ status }
						onChange={ ( v ) => {
							setPage( 1 );
							setStatus( v );
						} }
						label={ __( 'Moderation state', 'dev3-ai-vendor-assistant' ) }
					/>
				</div>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.rows : [] }
					loading={ ! data || data.loading }
					onRowClick={ setActive }
					rowActions={ ( r ) => (
						<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
							<Button size="sm" onClick={ () => setActive( r ) }>
								{ __( 'Review', 'dev3-ai-vendor-assistant' ) }
							</Button>
						</span>
					) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'check', title: status === 'open' ? __( 'Nothing to review', 'dev3-ai-vendor-assistant' ) : __( 'No listings here', 'dev3-ai-vendor-assistant' ), description: __( 'AI-written listings appear here when moderation is on (Settings → Moderation).', 'dev3-ai-vendor-assistant' ) } }
				/>
			</Card>
			{ active && (
				<Review
					item={ active }
					onClose={ () => setActive( null ) }
					onUsage={ onUsage }
					onDone={ () => {
						setActive( null );
						load();
					} }
				/>
			) }
		</>
	);
}
