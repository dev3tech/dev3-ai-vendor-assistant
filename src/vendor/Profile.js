import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, Card, ErrorNotice, SkeletonCard, Textarea, useToast } from '../core3/ui';
import { AiStateNotice, FallbackNotice, GenerateActions, ToneSelect, draftTitle, htmlToText, textToHtml, useCall, useVendor } from './shared';

const FIELDS = [
	{ key: 'bio', label: __( 'Store bio', 'dev3-ai-vendor-assistant' ), multiline: true, maxLength: 3000 },
	{ key: 'tagline', label: __( 'Tagline', 'dev3-ai-vendor-assistant' ), maxLength: 90 },
	{ key: 'seo_title', label: __( 'SEO title', 'dev3-ai-vendor-assistant' ), maxLength: 70 },
	{ key: 'seo_description', label: __( 'SEO description', 'dev3-ai-vendor-assistant' ), multiline: true, maxLength: 170 },
	{ key: 'seo_keywords', label: __( 'Keywords', 'dev3-ai-vendor-assistant' ), maxLength: 300 },
];

const strip = htmlToText;

export default function Profile() {
	const { me } = useVendor();
	const toast = useToast();
	const { busy, error, run, setError } = useCall();
	const [ data, setData ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ notes, setNotes ] = useState( '' );
	const [ tone, setTone ] = useState( me.tone || 'friendly' );
	const [ result, setResult ] = useState( null );
	const [ policies, setPolicies ] = useState( '' );
	const [ lastMode, setLastMode ] = useState( 'ai' );

	const load = () =>
		request( 'vendor/profile' )
			.then( ( res ) => {
				setData( res );
				setPolicies( res.policies || '' );
			} )
			.catch( setLoadError );
	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const generate = ( mode ) => {
		setLastMode( mode );
		run( mode, 'vendor/profile/draft', { notes, tone, mode } )
			.then( ( res ) => setResult( { ...res, draft: { ...res.draft, bio: strip( res.draft.bio ) }, original: { ...res.original, bio: strip( res.original.bio ) } } ) )
			.catch( () => {} );
	};

	const accept = ( values ) => {
		const bio = textToHtml( values.bio );
		run( 'accept', 'vendor/profile/apply', { fields: { ...values, bio }, draft_id: result.draft_id } )
			.then( ( res ) => {
				setData( res );
				setResult( null );
				toast.success( __( 'Store profile saved.', 'dev3-ai-vendor-assistant' ) );
			} )
			.catch( () => {} );
	};

	const savePolicies = () => {
		run( 'policies', 'vendor/profile/apply', { fields: { policies } } )
			.then( () => toast.success( __( 'Reply policies saved.', 'dev3-ai-vendor-assistant' ) ) )
			.catch( () => {} );
	};

	if ( loadError ) {
		return <ErrorNotice error={ loadError } onRetry={ load } />;
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 5 } />;
	}
	const p = data.profile;

	return (
		<div className="dev3va-vendor-stack">
			<AiStateNotice feature="profile" />
			<div className="dev3va-vendor-split">
				<Card
					title={ __( 'Your store today', 'dev3-ai-vendor-assistant' ) }
					actions={
						data.store_url ? (
							<Button size="sm" variant="link" href={ data.store_url } iconRight="external">
								{ __( 'View store', 'dev3-ai-vendor-assistant' ) }
							</Button>
						) : null
					}
				>
					<dl className="dev3va-vendor-dl">
						<dt>{ __( 'Tagline', 'dev3-ai-vendor-assistant' ) }</dt>
						<dd>{ p.tagline || <span className="dev3va-vendor-muted">{ __( 'Not set', 'dev3-ai-vendor-assistant' ) }</span> }</dd>
						<dt>{ __( 'Bio', 'dev3-ai-vendor-assistant' ) }</dt>
						<dd>{ strip( p.bio ) || <span className="dev3va-vendor-muted">{ __( 'Not set', 'dev3-ai-vendor-assistant' ) }</span> }</dd>
						<dt>{ __( 'SEO title', 'dev3-ai-vendor-assistant' ) }</dt>
						<dd>{ p.seo_title || <span className="dev3va-vendor-muted">{ __( 'Not set', 'dev3-ai-vendor-assistant' ) }</span> }</dd>
						<dt>{ __( 'SEO description', 'dev3-ai-vendor-assistant' ) }</dt>
						<dd>{ p.seo_description || <span className="dev3va-vendor-muted">{ __( 'Not set', 'dev3-ai-vendor-assistant' ) }</span> }</dd>
					</dl>
					{ data.facts.categories.length > 0 && (
						<div className="dev3va-vendor-chips">
							{ data.facts.categories.map( ( c ) => (
								<Badge key={ c } tone="neutral">
									{ c }
								</Badge>
							) ) }
						</div>
					) }
				</Card>
				<Card title={ __( 'Write a new profile', 'dev3-ai-vendor-assistant' ) } description={ __( 'Uses your store name, categories and best products. Add anything that makes you different.', 'dev3-ai-vendor-assistant' ) }>
					<div className="dev3va-vendor-form">
						<Textarea label={ __( 'About your store (optional)', 'dev3-ai-vendor-assistant' ) } value={ notes } onChange={ setNotes } rows={ 4 } maxLength={ 1500 } placeholder={ __( 'Family workshop in Porto since 2019, small batches, plastic-free packaging…', 'dev3-ai-vendor-assistant' ) } />
						<ToneSelect value={ tone } onChange={ setTone } />
					</div>
					<GenerateActions feature="profile" busy={ busy === 'ai' || busy === 'basic' ? busy : '' } onAi={ () => generate( 'ai' ) } onBasic={ () => generate( 'basic' ) } />
				</Card>
			</div>
			{ error && <ErrorNotice error={ error } onRetry={ () => generate( lastMode ) } onDismiss={ () => setError( null ) } /> }
			{ result && (
				<>
					<FallbackNotice fallback={ result.fallback } />
					<AiDraftReview
						title={ draftTitle( me, result.ai ) }
						fields={ FIELDS }
						draft={ result.draft }
						original={ result.original }
						meta={ result.meta }
						onAccept={ accept }
						onReject={ () => {
							request( `vendor/drafts/${ result.draft_id }/reject`, { method: 'POST' } ).catch( () => {} );
							setResult( null );
						} }
						onRegenerate={ () => generate( lastMode ) }
						regenerating={ busy === 'ai' || busy === 'basic' }
						accepting={ busy === 'accept' }
						acceptLabel={ __( 'Save to my store', 'dev3-ai-vendor-assistant' ) }
					/>
				</>
			) }
			{ me.features.reply && (
				<Card
					title={ __( 'Reply policies', 'dev3-ai-vendor-assistant' ) }
					description={ __( 'Shipping times, returns and anything buyers often ask. Reply drafts follow these rules.', 'dev3-ai-vendor-assistant' ) }
					footer={
						<Button variant="primary" loading={ busy === 'policies' } onClick={ savePolicies }>
							{ __( 'Save policies', 'dev3-ai-vendor-assistant' ) }
						</Button>
					}
				>
					<Textarea label={ __( 'Your policies', 'dev3-ai-vendor-assistant' ) } hideLabel value={ policies } onChange={ setPolicies } rows={ 4 } maxLength={ 2000 } placeholder={ __( 'We ship within 2 working days. Returns accepted within 30 days, unused, buyer pays return postage.', 'dev3-ai-vendor-assistant' ) } />
				</Card>
			) }
		</div>
	);
}
