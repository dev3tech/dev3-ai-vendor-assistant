import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Button, Card, ErrorNotice, Icon, IconButton, Notice, NumberField, Select, Spinner, TextField, Textarea, useToast } from '../core3/ui';
import { AiStateNotice, FallbackNotice, GenerateActions, ModerationBadge, ToneSelect, draftTitle, htmlToText, textToHtml, useCall, useVendor } from './shared';

const FIELDS = [
	{ key: 'title', label: __( 'Title', 'dev3-ai-vendor-assistant' ), maxLength: 120 },
	{ key: 'short_description', label: __( 'Short description', 'dev3-ai-vendor-assistant' ), multiline: true, maxLength: 400 },
	{ key: 'description', label: __( 'Description', 'dev3-ai-vendor-assistant' ), multiline: true, maxLength: 4000 },
	{ key: 'tags', label: __( 'Tags (comma separated)', 'dev3-ai-vendor-assistant' ), maxLength: 300 },
	{ key: 'seo_title', label: __( 'SEO title', 'dev3-ai-vendor-assistant' ), maxLength: 70 },
	{ key: 'seo_description', label: __( 'SEO description', 'dev3-ai-vendor-assistant' ), multiline: true, maxLength: 170 },
];

function PhotoPicker( { photos, setPhotos, max } ) {
	const toast = useToast();
	const input = useRef( null );
	const [ uploading, setUploading ] = useState( 0 );
	const [ over, setOver ] = useState( false );

	const upload = ( files ) => {
		const list = Array.from( files || [] ).slice( 0, Math.max( 0, max - photos.length ) );
		if ( ! list.length ) {
			if ( files && files.length ) {
				toast.warning( sprintf( /* translators: %d: max photos */ __( 'You can add up to %d photos.', 'dev3-ai-vendor-assistant' ), max ) );
			}
			return;
		}
		setUploading( ( n ) => n + list.length );
		list.forEach( ( file ) => {
			const body = new window.FormData();
			body.append( 'file', file );
			request( 'vendor/photos', { method: 'POST', body } )
				.then( ( photo ) => setPhotos( ( current ) => [ ...current, photo ] ) )
				.catch( ( e ) => toast.error( e ) )
				.finally( () => setUploading( ( n ) => n - 1 ) );
		} );
	};

	return (
		<div className="dev3va-vendor-photos">
			<div
				className={ `dev3va-vendor-drop${ over ? ' is-over' : '' }` }
				onDragOver={ ( e ) => {
					e.preventDefault();
					setOver( true );
				} }
				onDragLeave={ () => setOver( false ) }
				onDrop={ ( e ) => {
					e.preventDefault();
					setOver( false );
					upload( e.dataTransfer.files );
				} }
			>
				<input
					ref={ input }
					id="dev3va-photo-input"
					className="dev3va-vendor-drop__input"
					type="file"
					accept="image/jpeg,image/png,image/webp"
					multiple
					onChange={ ( e ) => {
						upload( e.target.files );
						e.target.value = '';
					} }
				/>
				<label htmlFor="dev3va-photo-input" className="dev3va-vendor-drop__label">
					<Icon name="plus" size={ 20 } />
					<strong>{ __( 'Add product photos', 'dev3-ai-vendor-assistant' ) }</strong>
					<span>{ sprintf( /* translators: %d: max photos */ __( 'Drag & drop or browse. JPEG, PNG or WebP, up to %d photos.', 'dev3-ai-vendor-assistant' ), max ) }</span>
				</label>
			</div>
			{ ( photos.length > 0 || uploading > 0 ) && (
				<ul className="dev3va-vendor-thumbs">
					{ photos.map( ( p, i ) => (
						<li key={ p.id }>
							<img src={ p.thumb } alt={ sprintf( /* translators: %d: photo number */ __( 'Photo %d', 'dev3-ai-vendor-assistant' ), i + 1 ) } width="96" height="96" />
							{ i === 0 && <span className="dev3va-vendor-thumbs__main">{ __( 'Main', 'dev3-ai-vendor-assistant' ) }</span> }
							<IconButton icon="x" size="sm" label={ __( 'Remove photo', 'dev3-ai-vendor-assistant' ) } onClick={ () => setPhotos( photos.filter( ( x ) => x.id !== p.id ) ) } />
						</li>
					) ) }
					{ Array.from( { length: uploading } ).map( ( _, i ) => (
						<li key={ `u${ i }` } className="is-loading">
							<Spinner />
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

function Extras( { me, cats, setCats, attrs, setAttrs, price, setPrice, isNew } ) {
	const options = [ { value: '', label: __( 'Choose a category', 'dev3-ai-vendor-assistant' ) }, ...me.categories.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ];
	return (
		<Card title={ __( 'Category, attributes and price', 'dev3-ai-vendor-assistant' ) } description={ __( 'Check these before saving.', 'dev3-ai-vendor-assistant' ) }>
			<div className="dev3va-vendor-row">
				<Select label={ __( 'Category', 'dev3-ai-vendor-assistant' ) } value={ cats[ 0 ] ? String( cats[ 0 ] ) : '' } onChange={ ( v ) => setCats( v ? [ Number( v ) ] : [] ) } options={ options } />
				{ isNew && <NumberField label={ sprintf( /* translators: %s: currency */ __( 'Price (%s)', 'dev3-ai-vendor-assistant' ), me.currency ) } value={ price } min={ 0 } step={ 0.01 } onChange={ setPrice } help={ __( 'Optional. Use Pricing to compare first.', 'dev3-ai-vendor-assistant' ) } /> }
			</div>
			<fieldset className="dev3va-vendor-attrs">
				<legend>{ __( 'Attributes', 'dev3-ai-vendor-assistant' ) }</legend>
				{ attrs.length === 0 && <p className="dev3va-vendor-muted">{ __( 'No attributes yet.', 'dev3-ai-vendor-assistant' ) }</p> }
				{ attrs.map( ( a, i ) => (
					<div className="dev3va-vendor-attrs__row" key={ i }>
						<TextField label={ __( 'Name', 'dev3-ai-vendor-assistant' ) } value={ a.name } onChange={ ( v ) => setAttrs( attrs.map( ( x, j ) => ( j === i ? { ...x, name: v } : x ) ) ) } />
						<TextField label={ __( 'Value', 'dev3-ai-vendor-assistant' ) } value={ a.value } onChange={ ( v ) => setAttrs( attrs.map( ( x, j ) => ( j === i ? { ...x, value: v } : x ) ) ) } />
						<IconButton icon="trash" label={ __( 'Remove attribute', 'dev3-ai-vendor-assistant' ) } onClick={ () => setAttrs( attrs.filter( ( _, j ) => j !== i ) ) } />
					</div>
				) ) }
				<Button size="sm" icon="plus" onClick={ () => setAttrs( [ ...attrs, { name: '', value: '' } ] ) }>
					{ __( 'Add attribute', 'dev3-ai-vendor-assistant' ) }
				</Button>
			</fieldset>
		</Card>
	);
}

export default function Listing( { params = [] } ) {
	const { me } = useVendor();
	const toast = useToast();
	const { busy, error, run, setError } = useCall();
	const [ products, setProducts ] = useState( [] );
	const [ productId, setProductId ] = useState( params[ 0 ] ? String( params[ 0 ] ) : '' );
	const [ photos, setPhotos ] = useState( [] );
	const [ notes, setNotes ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ tone, setTone ] = useState( me.tone || 'friendly' );
	const [ result, setResult ] = useState( null );
	const [ cats, setCats ] = useState( [] );
	const [ attrs, setAttrs ] = useState( [] );
	const [ price, setPrice ] = useState( '' );
	const [ saved, setSaved ] = useState( null );
	const [ lastMode, setLastMode ] = useState( 'ai' );

	useEffect( () => {
		request( 'vendor/products?per_page=50' )
			.then( ( res ) => setProducts( res.rows ) )
			.catch( () => {} );
	}, [] );

	const generate = ( mode ) => {
		setSaved( null );
		setLastMode( mode );
		run( mode, 'vendor/listing/draft', { photos: photos.map( ( p ) => p.id ), notes, product_id: Number( productId ) || 0, category: Number( category ) || 0, tone, mode } )
			.then( ( res ) => {
				setResult( { ...res, draft: { ...res.draft, description: htmlToText( res.draft.description ) }, original: res.original ? { ...res.original, description: htmlToText( res.original.description ) } : res.original } );
				setCats( res.draft.category_ids || [] );
				setAttrs( res.draft.attributes || [] );
			} )
			.catch( () => {} );
	};

	const accept = ( values ) => {
		run( 'accept', 'vendor/listing/apply', {
			draft_id: result.draft_id,
			fields: { ...values, description: textToHtml( values.description ), category_ids: cats, attributes: attrs.filter( ( a ) => a.name && a.value ), price: price === '' ? undefined : price, alt_texts: result.draft.alt_texts },
		} )
			.then( ( res ) => {
				setSaved( res );
				setResult( null );
				setPhotos( [] );
				setNotes( '' );
				setPrice( '' );
				toast.success( res.created ? __( 'Listing created.', 'dev3-ai-vendor-assistant' ) : __( 'Listing updated.', 'dev3-ai-vendor-assistant' ) );
			} )
			.catch( () => {} );
	};

	const reject = () => {
		request( `vendor/drafts/${ result.draft_id }/reject`, { method: 'POST' } ).catch( () => {} );
		setResult( null );
	};

	const productOptions = [ { value: '', label: __( 'New listing', 'dev3-ai-vendor-assistant' ) }, ...products.map( ( p ) => ( { value: String( p.id ), label: p.title } ) ) ];
	const catOptions = [ { value: '', label: __( 'Let the assistant suggest', 'dev3-ai-vendor-assistant' ) }, ...me.categories.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ];

	return (
		<div className="dev3va-vendor-stack">
			<AiStateNotice feature="listing" />
			{ saved && (
				<Notice
					tone="success"
					title={ saved.created ? __( 'Your listing was created', 'dev3-ai-vendor-assistant' ) : __( 'Your listing was updated', 'dev3-ai-vendor-assistant' ) }
					actions={
						<>
							{ saved.edit_url && (
								<Button size="sm" href={ saved.edit_url }>
									{ __( 'Open in product editor', 'dev3-ai-vendor-assistant' ) }
								</Button>
							) }
							{ saved.status === 'publish' && saved.view_url && (
								<Button size="sm" variant="link" href={ saved.view_url } iconRight="external">
									{ __( 'View', 'dev3-ai-vendor-assistant' ) }
								</Button>
							) }
						</>
					}
				>
					{ saved.moderation === 'pending' && __( 'It is waiting for the marketplace to review it. You will get an email when it is approved.', 'dev3-ai-vendor-assistant' ) }
					{ saved.moderation === 'flagged' && __( 'It is live and the marketplace may review it.', 'dev3-ai-vendor-assistant' ) }
					{ saved.moderation === 'none' && ( saved.status === 'publish' ? __( 'It is live in your store.', 'dev3-ai-vendor-assistant' ) : __( 'It is saved and waiting for publication.', 'dev3-ai-vendor-assistant' ) ) }
				</Notice>
			) }
			<Card title={ __( 'Photos and notes', 'dev3-ai-vendor-assistant' ) } description={ __( 'The more you tell the assistant (material, size, condition, what is included), the better the listing.', 'dev3-ai-vendor-assistant' ) }>
				<div className="dev3va-vendor-split">
					<PhotoPicker photos={ photos } setPhotos={ setPhotos } max={ me.max_photos } />
					<div className="dev3va-vendor-form">
						<Select label={ __( 'Listing', 'dev3-ai-vendor-assistant' ) } value={ productId } onChange={ setProductId } options={ productOptions } help={ productId ? __( 'The draft improves this listing; nothing changes until you accept.', 'dev3-ai-vendor-assistant' ) : '' } />
						<Textarea
							label={ __( 'Notes', 'dev3-ai-vendor-assistant' ) }
							value={ notes }
							onChange={ setNotes }
							rows={ 6 }
							maxLength={ 3000 }
							placeholder={ __( 'Hand-thrown ceramic mug\nMaterial: stoneware\nCapacity: 350 ml\nDishwasher safe', 'dev3-ai-vendor-assistant' ) }
						/>
						<div className="dev3va-vendor-row">
							<Select label={ __( 'Category', 'dev3-ai-vendor-assistant' ) } value={ category } onChange={ setCategory } options={ catOptions } />
							<ToneSelect value={ tone } onChange={ setTone } />
						</div>
					</div>
				</div>
				<GenerateActions feature="listing" busy={ busy === 'ai' || busy === 'basic' ? busy : '' } onAi={ () => generate( 'ai' ) } onBasic={ () => generate( 'basic' ) } disabled={ ! notes.trim() && ! photos.length && ! productId } />
			</Card>
			{ error && <ErrorNotice error={ error } onRetry={ () => generate( lastMode ) } onDismiss={ () => setError( null ) } /> }
			{ result && (
				<>
					<FallbackNotice fallback={ result.fallback } />
					{ result.flags && result.flags.length > 0 && (
						<Notice tone="warning" title={ __( 'Words the marketplace does not allow', 'dev3-ai-vendor-assistant' ) }>
							{ result.flags.join( ', ' ) }
						</Notice>
					) }
					<Extras me={ me } cats={ cats } setCats={ setCats } attrs={ attrs } setAttrs={ setAttrs } price={ price } setPrice={ setPrice } isNew={ ! result.product_id } />
					<AiDraftReview
						title={ draftTitle( me, result.ai ) }
						fields={ FIELDS }
						draft={ result.draft }
						original={ result.product_id ? result.original : undefined }
						meta={ result.meta }
						onAccept={ accept }
						onReject={ reject }
						onRegenerate={ () => generate( lastMode ) }
						regenerating={ busy === 'ai' || busy === 'basic' }
						accepting={ busy === 'accept' }
						acceptLabel={ result.product_id ? __( 'Update listing', 'dev3-ai-vendor-assistant' ) : __( 'Create listing', 'dev3-ai-vendor-assistant' ) }
					/>
					{ result.moderation === 'pending' && (
						<p className="dev3va-vendor-muted">
							<ModerationBadge state="pending" /> { __( 'AI-written listings are checked by the marketplace before they go live.', 'dev3-ai-vendor-assistant' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
