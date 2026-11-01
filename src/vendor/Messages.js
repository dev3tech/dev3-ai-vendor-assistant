import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, Card, EmptyState, ErrorNotice, Notice, SkeletonCard, Tabs, TextField, Textarea, useToast } from '../core3/ui';
import { AiStateNotice, FallbackNotice, GenerateActions, ToneSelect, draftTitle, fromNow, useCall, useVendor } from './shared';

const SENTIMENT = { positive: 'success', neutral: 'neutral', negative: 'danger' };

function Stars( { rating } ) {
	if ( ! rating ) {
		return null;
	}
	return (
		<span className="dev3va-vendor-stars" aria-label={ `${ rating } / 5` }>
			{ '★★★★★'.slice( 0, rating ) }
			<span aria-hidden="true">{ '★★★★★'.slice( rating ) }</span>
		</span>
	);
}

function Composer( { message, onSent } ) {
	const { me } = useVendor();
	const toast = useToast();
	const { busy, error, run, setError } = useCall();
	const [ tone, setTone ] = useState( me.tone || 'friendly' );
	const [ instructions, setInstructions ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ lastMode, setLastMode ] = useState( 'ai' );

	const draft = ( mode ) => {
		setLastMode( mode );
		const body = message.source === 'manual' ? { source: 'manual', text: message.text, buyer: message.buyer, tone, instructions, mode } : { source: message.source, id: message.id, tone, instructions, mode };
		run( mode, 'vendor/messages/draft', body )
			.then( setResult )
			.catch( () => {} );
	};

	const send = ( text ) => {
		if ( ! result.can_send ) {
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( text ).then( () => toast.success( __( 'Reply copied to the clipboard.', 'dev3-ai-vendor-assistant' ) ) );
			}
			return;
		}
		run( 'send', 'vendor/messages/send', { source: message.source, id: message.id, reply: text, draft_id: result.draft_id } )
			.then( ( updated ) => {
				toast.success( __( 'Reply sent.', 'dev3-ai-vendor-assistant' ) );
				setResult( null );
				onSent( updated );
			} )
			.catch( () => {} );
	};

	return (
		<div className="dev3va-vendor-stack">
			<div className="dev3va-vendor-row">
				<ToneSelect value={ tone } onChange={ setTone } />
				<TextField label={ __( 'Instructions (optional)', 'dev3-ai-vendor-assistant' ) } value={ instructions } onChange={ setInstructions } maxLength={ 500 } placeholder={ __( 'e.g. offer a replacement, mention dispatch on Monday', 'dev3-ai-vendor-assistant' ) } />
			</div>
			<GenerateActions feature="reply" busy={ busy === 'ai' || busy === 'basic' ? busy : '' } onAi={ () => draft( 'ai' ) } onBasic={ () => draft( 'basic' ) } />
			{ error && <ErrorNotice error={ error } onRetry={ () => draft( lastMode ) } onDismiss={ () => setError( null ) } /> }
			{ result && (
				<>
					<FallbackNotice fallback={ result.fallback } />
					<div className="dev3va-vendor-chips">
						<Badge tone={ SENTIMENT[ result.draft.sentiment ] || 'neutral' }>{ result.draft.sentiment }</Badge>
						<Badge tone="info">{ String( result.draft.intent ).replace( '_', ' ' ) }</Badge>
						{ result.draft.summary && <span className="dev3va-vendor-muted">{ result.draft.summary }</span> }
					</div>
					<AiDraftReview
						title={ draftTitle( me, result.ai ) }
						draft={ result.draft.reply }
						meta={ result.meta }
						onAccept={ send }
						onReject={ () => {
							request( `vendor/drafts/${ result.draft_id }/reject`, { method: 'POST' } ).catch( () => {} );
							setResult( null );
						} }
						onRegenerate={ () => draft( lastMode ) }
						regenerating={ busy === 'ai' || busy === 'basic' }
						accepting={ busy === 'send' }
						acceptLabel={ result.can_send ? __( 'Send reply', 'dev3-ai-vendor-assistant' ) : __( 'Copy reply', 'dev3-ai-vendor-assistant' ) }
					/>
				</>
			) }
		</div>
	);
}

function Detail( { message, onSent } ) {
	return (
		<Card
			title={ message.subject || message.source_label }
			description={ [ message.source_label, message.buyer, fromNow( message.date ) ].filter( Boolean ).join( ' · ' ) }
			actions={ message.replied ? <Badge tone="success" dot>{ __( 'Replied', 'dev3-ai-vendor-assistant' ) }</Badge> : <Badge tone="warning" dot>{ __( 'Needs reply', 'dev3-ai-vendor-assistant' ) }</Badge> }
		>
			<div className="dev3va-vendor-stack">
				<blockquote className="dev3va-vendor-quote">
					<Stars rating={ message.rating } />
					<p>{ message.text }</p>
				</blockquote>
				{ message.replied && message.reply && (
					<div className="dev3va-vendor-reply">
						<p className="dev3va-vendor-label">{ __( 'Your reply', 'dev3-ai-vendor-assistant' ) }</p>
						<p>{ message.reply }</p>
					</div>
				) }
				<Composer key={ `${ message.source }-${ message.id }` } message={ message } onSent={ onSent } />
			</div>
		</Card>
	);
}

export default function Messages() {
	const [ filter, setFilter ] = useState( 'unanswered' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ active, setActive ] = useState( null );
	const [ manual, setManual ] = useState( { text: '', buyer: '' } );
	const [ manualOpen, setManualOpen ] = useState( false );
	const detail = useRef( null );

	const load = () => {
		setError( null );
		request( `vendor/messages?filter=${ filter }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ filter ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const open = ( m ) => {
		setManualOpen( false );
		setActive( m );
		window.setTimeout( () => {
			if ( detail.current && window.innerWidth < 960 ) {
				detail.current.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}, 50 );
	};

	const sent = ( updated ) => {
		setActive( updated );
		load();
	};

	const items = data ? data.items : [];

	return (
		<div className="dev3va-vendor-stack">
			<AiStateNotice feature="reply" />
			<div className="dev3va-vendor-inbox">
				<Card
					className="dev3va-vendor-inbox__list"
					title={ __( 'Inbox', 'dev3-ai-vendor-assistant' ) }
					actions={
						<Button size="sm" icon="edit" onClick={ () => {
							setActive( null );
							setManualOpen( true );
						} }>
							{ __( 'Paste a message', 'dev3-ai-vendor-assistant' ) }
						</Button>
					}
					flush
				>
					<div className="dev3va-vendor-inbox__filter">
						<Tabs
							variant="pills"
							label={ __( 'Filter messages', 'dev3-ai-vendor-assistant' ) }
							value={ filter }
							onChange={ setFilter }
							tabs={ [
								{ id: 'unanswered', label: __( 'Needs reply', 'dev3-ai-vendor-assistant' ), badge: data ? data.counts.unanswered : undefined },
								{ id: 'all', label: __( 'All', 'dev3-ai-vendor-assistant' ) },
							] }
						/>
					</div>
					{ error && <ErrorNotice error={ error } onRetry={ load } /> }
					{ ! data && ! error && <SkeletonCard lines={ 4 } /> }
					{ data && ! items.length && <EmptyState compact icon="inbox" title={ filter === 'unanswered' ? __( 'All caught up', 'dev3-ai-vendor-assistant' ) : __( 'No buyer messages yet', 'dev3-ai-vendor-assistant' ) } description={ __( 'Reviews, order notes and questions about your products show up here.', 'dev3-ai-vendor-assistant' ) } /> }
					{ items.length > 0 && (
						<ul className="dev3va-vendor-msgs">
							{ items.map( ( m ) => {
								const isActive = active && active.source === m.source && active.id === m.id;
								return (
									<li key={ `${ m.source }-${ m.id }` }>
										<button type="button" className={ `dev3va-vendor-msg${ isActive ? ' is-active' : '' }` } onClick={ () => open( m ) } aria-current={ isActive || undefined }>
											<span className="dev3va-vendor-msg__top">
												<span className="dev3va-vendor-msg__who">{ m.buyer || __( 'Buyer', 'dev3-ai-vendor-assistant' ) }</span>
												<span className="dev3va-vendor-muted">{ fromNow( m.date ) }</span>
											</span>
											<span className="dev3va-vendor-msg__subject">
												<Badge tone="neutral">{ m.source_label }</Badge> { m.subject }
											</span>
											<span className="dev3va-vendor-msg__text">{ m.text }</span>
											{ ! m.replied && <span className="dev3va-vendor-msg__dot" aria-label={ __( 'Needs reply', 'dev3-ai-vendor-assistant' ) } /> }
										</button>
									</li>
								);
							} ) }
						</ul>
					) }
				</Card>
				<div ref={ detail } className="dev3va-vendor-inbox__detail">
					{ manualOpen && (
						<Card title={ __( 'Reply to a pasted message', 'dev3-ai-vendor-assistant' ) } description={ __( 'For messages from email or chat. The draft is copied, not sent.', 'dev3-ai-vendor-assistant' ) }>
							<div className="dev3va-vendor-stack">
								<TextField label={ __( 'Buyer first name (optional)', 'dev3-ai-vendor-assistant' ) } value={ manual.buyer } onChange={ ( v ) => setManual( { ...manual, buyer: v } ) } maxLength={ 40 } />
								<Textarea label={ __( 'Buyer message', 'dev3-ai-vendor-assistant' ) } value={ manual.text } onChange={ ( v ) => setManual( { ...manual, text: v } ) } rows={ 5 } maxLength={ 3000 } />
								{ manual.text.trim() && <Composer key="manual" message={ { source: 'manual', id: 0, text: manual.text, buyer: manual.buyer } } onSent={ () => {} } /> }
							</div>
						</Card>
					) }
					{ ! manualOpen && active && <Detail message={ active } onSent={ sent } /> }
					{ ! manualOpen && ! active && (
						<Card>
							<EmptyState icon="inbox" title={ __( 'Pick a message', 'dev3-ai-vendor-assistant' ) } description={ __( 'Choose a message on the left to see it and draft a reply.', 'dev3-ai-vendor-assistant' ) } />
						</Card>
					) }
					{ data && data.sources.length === 0 && <Notice tone="info">{ __( 'No message sources are available on this marketplace yet.', 'dev3-ai-vendor-assistant' ) }</Notice> }
				</div>
			</div>
		</div>
	);
}
