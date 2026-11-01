import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, EmptyState, ErrorNotice, Notice, NumberField, ResponsiveTable, Select, Stat, StatGrid, TextField, Textarea, useToast } from '../core3/ui';
import { AiStateNotice, FallbackNotice, GenerateActions, money, useCall, useVendor } from './shared';

const GOALS = [
	{ value: 'competitive', label: __( 'Competitive (around the middle)', 'dev3-ai-vendor-assistant' ) },
	{ value: 'premium', label: __( 'Premium (upper range)', 'dev3-ai-vendor-assistant' ) },
	{ value: 'quick_sale', label: __( 'Quick sale (lower range)', 'dev3-ai-vendor-assistant' ) },
];

const CONFIDENCE = { low: 'warning', medium: 'info', high: 'success' };

export default function Pricing( { params = [] } ) {
	const { me } = useVendor();
	const toast = useToast();
	const { busy, error, run, setError } = useCall();
	const [ products, setProducts ] = useState( [] );
	const [ productId, setProductId ] = useState( params[ 0 ] ? String( params[ 0 ] ) : '' );
	const [ title, setTitle ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ goal, setGoal ] = useState( 'competitive' );
	const [ notes, setNotes ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ price, setPrice ] = useState( '' );
	const [ lastMode, setLastMode ] = useState( 'ai' );

	useEffect( () => {
		request( 'vendor/products?per_page=50' )
			.then( ( res ) => setProducts( res.rows.filter( ( p ) => p.type !== 'variable' ) ) )
			.catch( () => {} );
	}, [] );

	const suggest = ( mode ) => {
		setLastMode( mode );
		run( mode, 'vendor/price', { product_id: Number( productId ) || 0, title, category: Number( category ) || 0, goal, notes, mode } )
			.then( ( res ) => {
				setResult( res );
				setPrice( res.suggestion ? res.suggestion.price : '' );
			} )
			.catch( () => {} );
	};

	const apply = () => {
		run( 'apply', 'vendor/price/apply', { product_id: result.product.id, price, draft_id: result.draft_id } )
			.then( ( res ) => {
				toast.success( sprintf( /* translators: %s: price */ __( 'Price updated to %s.', 'dev3-ai-vendor-assistant' ), money( res.price, me.currency ) ) );
				setResult( { ...result, product: { ...result.product, price: res.price } } );
			} )
			.catch( () => {} );
	};

	const columns = [
		{ key: 'title', label: __( 'Listing', 'dev3-ai-vendor-assistant' ), primary: true, render: ( r ) => ( <a href={ r.url } target="_blank" rel="noreferrer">{ r.title }</a> ) },
		{ key: 'store', label: __( 'Store', 'dev3-ai-vendor-assistant' ), render: ( r ) => ( r.own ? <Badge tone="brand">{ __( 'Your store', 'dev3-ai-vendor-assistant' ) }</Badge> : r.store || '—' ) },
		{ key: 'rating', label: __( 'Rating', 'dev3-ai-vendor-assistant' ), sortable: true, render: ( r ) => ( r.reviews ? `${ r.rating } ★ (${ r.reviews })` : '—' ) },
		{ key: 'price', label: __( 'Price', 'dev3-ai-vendor-assistant' ), sortable: true, align: 'end', render: ( r ) => money( r.price, result.currency ) },
	];

	const s = result && result.suggestion;
	const options = [ { value: '', label: __( 'Not listed yet (type a title)', 'dev3-ai-vendor-assistant' ) }, ...products.map( ( p ) => ( { value: String( p.id ), label: p.title } ) ) ];
	const catOptions = [ { value: '', label: __( 'Any category', 'dev3-ai-vendor-assistant' ) }, ...me.categories.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ) ];

	return (
		<div className="dev3va-vendor-stack">
			<AiStateNotice feature="price" />
			<Card title={ __( 'What are you pricing?', 'dev3-ai-vendor-assistant' ) } description={ __( 'We compare it with similar published listings on the marketplace. Only public prices and ratings are used.', 'dev3-ai-vendor-assistant' ) }>
				<div className="dev3va-vendor-form">
					<Select label={ __( 'Your product', 'dev3-ai-vendor-assistant' ) } value={ productId } onChange={ setProductId } options={ options } />
					{ ! productId && (
						<div className="dev3va-vendor-row">
							<TextField label={ __( 'Product title', 'dev3-ai-vendor-assistant' ) } value={ title } onChange={ setTitle } maxLength={ 120 } />
							<Select label={ __( 'Category', 'dev3-ai-vendor-assistant' ) } value={ category } onChange={ setCategory } options={ catOptions } />
						</div>
					) }
					<div className="dev3va-vendor-row">
						<Select label={ __( 'Goal', 'dev3-ai-vendor-assistant' ) } value={ goal } onChange={ setGoal } options={ GOALS } />
						<Textarea label={ __( 'Anything special? (optional)', 'dev3-ai-vendor-assistant' ) } value={ notes } onChange={ setNotes } rows={ 2 } maxLength={ 600 } placeholder={ __( 'Handmade, limited edition, includes gift box…', 'dev3-ai-vendor-assistant' ) } />
					</div>
				</div>
				<GenerateActions feature="price" busy={ busy === 'ai' || busy === 'basic' ? busy : '' } onAi={ () => suggest( 'ai' ) } onBasic={ () => suggest( 'basic' ) } disabled={ ! productId && ! title.trim() } />
			</Card>
			{ error && <ErrorNotice error={ error } onRetry={ () => suggest( lastMode ) } onDismiss={ () => setError( null ) } /> }
			{ result && (
				<>
					<FallbackNotice fallback={ result.fallback } />
					{ ! s && (
						<Card>
							<EmptyState icon="search" title={ __( 'No similar listings found', 'dev3-ai-vendor-assistant' ) } description={ __( 'Try a shorter title or another category.', 'dev3-ai-vendor-assistant' ) } />
						</Card>
					) }
					{ s && (
						<Card tone="ai" title={ result.ai ? __( 'Suggested price', 'dev3-ai-vendor-assistant' ) : __( 'Suggested price (from the numbers)', 'dev3-ai-vendor-assistant' ) }>
							<div className="dev3va-vendor-price">
								<div className="dev3va-vendor-price__main">
									<span className="dev3va-vendor-price__value">{ money( s.price, result.currency ) }</span>
									<span className="dev3va-vendor-muted">
										{ sprintf( /* translators: 1: low price, 2: high price */ __( 'Range %1$s – %2$s', 'dev3-ai-vendor-assistant' ), money( s.low, result.currency ), money( s.high, result.currency ) ) }
									</span>
									<Badge tone={ CONFIDENCE[ s.confidence ] || 'info' }>
										{ sprintf( /* translators: %s: confidence */ __( 'Confidence: %s', 'dev3-ai-vendor-assistant' ), s.confidence ) }
									</Badge>
								</div>
								<p className="dev3va-vendor-price__why">{ s.rationale }</p>
							</div>
							{ result.product.id > 0 ? (
								<div className="dev3va-vendor-apply">
									<NumberField label={ sprintf( /* translators: %s: currency */ __( 'New price (%s)', 'dev3-ai-vendor-assistant' ), result.currency ) } value={ price } min={ 0 } step={ 0.01 } onChange={ setPrice } help={ result.product.price ? sprintf( /* translators: %s: price */ __( 'Current price: %s', 'dev3-ai-vendor-assistant' ), money( result.product.price, result.currency ) ) : __( 'No price yet.', 'dev3-ai-vendor-assistant' ) } />
									<Button variant="primary" icon="check" loading={ busy === 'apply' } disabled={ ! price || !! busy } onClick={ apply }>
										{ __( 'Apply price', 'dev3-ai-vendor-assistant' ) }
									</Button>
								</div>
							) : (
								<Notice tone="info">{ __( 'Use this price when you create the listing.', 'dev3-ai-vendor-assistant' ) }</Notice>
							) }
						</Card>
					) }
					{ result.stats.count > 0 && (
						<StatGrid min={ 140 }>
							<Stat label={ __( 'Similar listings', 'dev3-ai-vendor-assistant' ) } value={ result.stats.count } />
							<Stat label={ __( 'Lowest', 'dev3-ai-vendor-assistant' ) } value={ money( result.stats.min, result.currency ) } />
							<Stat label={ __( 'Median', 'dev3-ai-vendor-assistant' ) } value={ money( result.stats.median, result.currency ) } tone="brand" />
							<Stat label={ __( 'Highest', 'dev3-ai-vendor-assistant' ) } value={ money( result.stats.max, result.currency ) } />
						</StatGrid>
					) }
					{ result.comparables.length > 0 && (
						<Card title={ __( 'Comparable listings', 'dev3-ai-vendor-assistant' ) } flush>
							<ResponsiveTable columns={ columns } rows={ result.comparables } pageSize={ 8 } defaultSort={ { key: 'price', dir: 'asc' } } />
						</Card>
					) }
				</>
			) }
		</div>
	);
}
