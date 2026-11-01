import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, PageHeader, ResponsiveTable, Select, formatMoney, formatNumber, useToast } from '../core3/ui';

const FEATURES = [
	{ value: '', label: __( 'All features', 'dev3-ai-vendor-assistant' ) },
	{ value: 'listing', label: __( 'Listing writer', 'dev3-ai-vendor-assistant' ) },
	{ value: 'price', label: __( 'Price suggestions', 'dev3-ai-vendor-assistant' ) },
	{ value: 'reply', label: __( 'Buyer replies', 'dev3-ai-vendor-assistant' ) },
	{ value: 'profile', label: __( 'Store bio & SEO', 'dev3-ai-vendor-assistant' ) },
	{ value: 'insights', label: __( 'Performance tips', 'dev3-ai-vendor-assistant' ) },
];

const months = () => {
	const out = [ { value: '', label: __( 'All months', 'dev3-ai-vendor-assistant' ) } ];
	const d = new Date();
	for ( let i = 0; i < 12; i++ ) {
		const m = new Date( Date.UTC( d.getUTCFullYear(), d.getUTCMonth() - i, 1 ) );
		const key = `${ m.getUTCFullYear() }-${ String( m.getUTCMonth() + 1 ).padStart( 2, '0' ) }`;
		out.push( { value: key, label: m.toLocaleDateString( undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' } ) } );
	}
	return out;
};

export default function Activity() {
	const toast = useToast();
	const [ feature, setFeature ] = useState( '' );
	const [ month, setMonth ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const query = `feature=${ feature }&month=${ month }`;

	const load = () => {
		setError( null );
		setData( ( d ) => ( d ? { ...d, loading: true } : null ) );
		request( `admin/ledger?page=${ page }&${ query }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ page, feature, month ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const exportCsv = () => {
		request( `admin/ledger?format=csv&${ query }` )
			.then( ( res ) => {
				const blob = new window.Blob( [ res.csv ], { type: 'text/csv;charset=utf-8' } );
				const a = document.createElement( 'a' );
				a.href = window.URL.createObjectURL( blob );
				a.download = `dev3-vendor-ai-ledger${ month ? '-' + month : '' }.csv`;
				document.body.appendChild( a );
				a.click();
				a.remove();
			} )
			.catch( ( e ) => toast.error( e ) );
	};

	const columns = [
		{ key: 'created_at', label: __( 'When', 'dev3-ai-vendor-assistant' ), nowrap: true, render: ( r ) => new Date( r.created_at ).toLocaleString() },
		{ key: 'store', label: __( 'Store', 'dev3-ai-vendor-assistant' ), primary: true, render: ( r ) => r.store || `#${ r.vendor_id }` },
		{ key: 'user', label: __( 'User', 'dev3-ai-vendor-assistant' ), hideOnMobile: true },
		{ key: 'feature', label: __( 'Feature', 'dev3-ai-vendor-assistant' ), render: ( r ) => ( FEATURES.find( ( f ) => f.value === r.feature ) || {} ).label || r.feature },
		{ key: 'credits', label: __( 'Credits', 'dev3-ai-vendor-assistant' ), align: 'end' },
		{ key: 'tokens', label: __( 'Tokens', 'dev3-ai-vendor-assistant' ), align: 'end', hideOnMobile: true, render: ( r ) => formatNumber( r.tokens ) },
		{ key: 'cost', label: __( 'Est. cost', 'dev3-ai-vendor-assistant' ), align: 'end', render: ( r ) => formatMoney( r.cost ) },
		{
			key: 'status',
			label: __( 'Result', 'dev3-ai-vendor-assistant' ),
			render: ( r ) => {
				if ( r.status !== 'ok' ) {
					return <Badge tone="danger">{ r.error_code || r.status }</Badge>;
				}
				return r.cached ? <Badge tone="info">{ __( 'Cached', 'dev3-ai-vendor-assistant' ) }</Badge> : <Badge tone="success">{ __( 'OK', 'dev3-ai-vendor-assistant' ) }</Badge>;
			},
		},
	];

	return (
		<>
			<PageHeader
				title={ __( 'Activity', 'dev3-ai-vendor-assistant' ) }
				description={ __( 'Every AI request a vendor made: credits charged, tokens and estimated cost. Cached answers are free.', 'dev3-ai-vendor-assistant' ) }
				actions={
					<Button icon="file" onClick={ exportCsv }>
						{ __( 'Export CSV', 'dev3-ai-vendor-assistant' ) }
					</Button>
				}
			/>
			<Card
				flush
				title={ __( 'Vendor AI ledger', 'dev3-ai-vendor-assistant' ) }
				actions={
					<div className="dev3va-filters">
						<Select
							label={ __( 'Feature', 'dev3-ai-vendor-assistant' ) }
							hideLabel
							value={ feature }
							onChange={ ( v ) => {
								setPage( 1 );
								setFeature( v );
							} }
							options={ FEATURES }
						/>
						<Select
							label={ __( 'Month', 'dev3-ai-vendor-assistant' ) }
							hideLabel
							value={ month }
							onChange={ ( v ) => {
								setPage( 1 );
								setMonth( v );
							} }
							options={ months() }
						/>
					</div>
				}
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.rows : [] }
					loading={ ! data || data.loading }
					pagination={ data ? { page, perPage: 25, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'list', title: __( 'No AI requests yet', 'dev3-ai-vendor-assistant' ), description: __( 'Requests appear here as soon as vendors use the assistant.', 'dev3-ai-vendor-assistant' ) } }
				/>
			</Card>
		</>
	);
}
