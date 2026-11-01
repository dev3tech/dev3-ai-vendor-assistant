import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Drawer, ErrorNotice, NumberField, PageHeader, ProgressBar, ResponsiveTable, Select, Stack, TextField, Toggle, formatMoney, formatNumber, useToast } from '../core3/ui';

const OVERRIDE = [
	{ value: 'inherit', label: __( 'Default', 'dev3-ai-vendor-assistant' ) },
	{ value: 'on', label: __( 'On', 'dev3-ai-vendor-assistant' ) },
	{ value: 'off', label: __( 'Off', 'dev3-ai-vendor-assistant' ) },
];

function Credits( { quota } ) {
	if ( quota.unlimited ) {
		return (
			<span className="dev3va-credits">
				{ formatNumber( quota.used ) } <Badge tone="info">{ __( 'Unlimited', 'dev3-ai-vendor-assistant' ) }</Badge>
			</span>
		);
	}
	return (
		<span className="dev3va-credits">
			<span>
				{ formatNumber( quota.used ) } / { formatNumber( quota.limit ) }
			</span>
			<ProgressBar value={ quota.percent } tone={ quota.percent >= 100 ? 'danger' : quota.percent > 80 ? 'warning' : 'brand' } label={ __( 'Credits used', 'dev3-ai-vendor-assistant' ) } />
		</span>
	);
}

function VendorDrawer( { vendor, labels, defaultCredits, onClose, onSaved } ) {
	const toast = useToast();
	const [ access, setAccess ] = useState( vendor.access );
	const [ overrides, setOverrides ] = useState( vendor.overrides );
	const [ custom, setCustom ] = useState( vendor.has_override );
	const [ quota, setQuota ] = useState( vendor.has_override ? vendor.override : defaultCredits );
	const [ busy, setBusy ] = useState( false );

	const save = () => {
		setBusy( true );
		request( `admin/vendors/${ vendor.id }`, { method: 'POST', data: { access, overrides, quota: custom ? quota : '' } } )
			.then( ( row ) => {
				toast.success( __( 'Vendor settings saved.', 'dev3-ai-vendor-assistant' ) );
				onSaved( row );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Drawer
			open
			onClose={ onClose }
			size="md"
			title={ vendor.store || vendor.name }
			description={ vendor.email }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-vendor-assistant' ) }
					</Button>
					<Button variant="primary" loading={ busy } onClick={ save }>
						{ __( 'Save', 'dev3-ai-vendor-assistant' ) }
					</Button>
				</>
			}
		>
			<Stack gap={ 24 }>
				<Toggle label={ __( 'AI assistant access', 'dev3-ai-vendor-assistant' ) } checked={ access } onChange={ setAccess } help={ __( 'Off hides the assistant from this vendor and blocks its API.', 'dev3-ai-vendor-assistant' ) } />
				<div>
					<p className="dev3va-label">{ __( 'Monthly credits', 'dev3-ai-vendor-assistant' ) }</p>
					<Toggle label={ __( 'Custom limit for this vendor', 'dev3-ai-vendor-assistant' ) } checked={ custom } onChange={ setCustom } help={ sprintf( /* translators: %d: credits */ __( 'Marketplace default: %d credits (0 = unlimited).', 'dev3-ai-vendor-assistant' ), defaultCredits ) } />
					{ custom && <NumberField label={ __( 'Credits per month', 'dev3-ai-vendor-assistant' ) } value={ quota } min={ 0 } onChange={ setQuota } help={ __( '0 = unlimited.', 'dev3-ai-vendor-assistant' ) } /> }
					<p className="dev3va-muted">
						{ sprintf( /* translators: 1: used, 2: requests, 3: cost */ __( 'Used this month: %1$s credits in %2$s requests (about %3$s).', 'dev3-ai-vendor-assistant' ), formatNumber( vendor.quota.used ), formatNumber( vendor.usage.requests ), formatMoney( vendor.usage.cost ) ) }
					</p>
				</div>
				<div>
					<p className="dev3va-label">{ __( 'Features', 'dev3-ai-vendor-assistant' ) }</p>
					<div className="dev3va-override-grid">
						{ Object.keys( labels ).map( ( f ) => (
							<Select key={ f } label={ labels[ f ] } value={ overrides[ f ] } onChange={ ( v ) => setOverrides( { ...overrides, [ f ]: v } ) } options={ OVERRIDE } help={ vendor.features[ f ] ? __( 'Currently on', 'dev3-ai-vendor-assistant' ) : __( 'Currently off', 'dev3-ai-vendor-assistant' ) } />
						) ) }
					</div>
				</div>
			</Stack>
		</Drawer>
	);
}

export default function Vendors( { settings } ) {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ labels, setLabels ] = useState( {} );

	useEffect( () => {
		request( 'admin/overview' )
			.then( ( r ) => setLabels( r.labels ) )
			.catch( () => {} );
	}, [] );

	const load = () => {
		setError( null );
		setData( ( d ) => ( d ? { ...d, loading: true } : null ) );
		request( `admin/vendors?page=${ page }&search=${ encodeURIComponent( query ) }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ page, query ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const columns = [
		{
			key: 'store',
			label: __( 'Vendor', 'dev3-ai-vendor-assistant' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3va-cell">
					<strong>{ r.store || r.name }</strong>
					<span className="dev3va-muted">{ r.email }</span>
				</span>
			),
		},
		{ key: 'access', label: __( 'Access', 'dev3-ai-vendor-assistant' ), render: ( r ) => ( r.access ? <Badge tone="success" dot>{ __( 'On', 'dev3-ai-vendor-assistant' ) }</Badge> : <Badge tone="neutral" dot>{ __( 'Off', 'dev3-ai-vendor-assistant' ) }</Badge> ) },
		{ key: 'credits', label: __( 'Credits this month', 'dev3-ai-vendor-assistant' ), render: ( r ) => <Credits quota={ r.quota } /> },
		{
			key: 'features',
			label: __( 'Features', 'dev3-ai-vendor-assistant' ),
			hideOnMobile: true,
			render: ( r ) => {
				const on = Object.values( r.features ).filter( Boolean ).length;
				return sprintf( /* translators: 1: enabled, 2: total */ __( '%1$d of %2$d', 'dev3-ai-vendor-assistant' ), on, Object.keys( r.features ).length );
			},
		},
		{ key: 'products', label: __( 'Products', 'dev3-ai-vendor-assistant' ), align: 'end', hideOnMobile: true },
		{ key: 'cost', label: __( 'Est. cost', 'dev3-ai-vendor-assistant' ), align: 'end', render: ( r ) => formatMoney( r.usage.cost ) },
	];

	return (
		<>
			<PageHeader title={ __( 'Vendors & quotas', 'dev3-ai-vendor-assistant' ) } description={ __( 'Turn the assistant on or off per vendor, set monthly credits and pick the features each store gets.', 'dev3-ai-vendor-assistant' ) } />
			<Card
				flush
				title={ __( 'Vendors', 'dev3-ai-vendor-assistant' ) }
				actions={
					<form
						className="dev3va-search"
						role="search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							setPage( 1 );
							setQuery( search );
						} }
					>
						<TextField label={ __( 'Search vendors', 'dev3-ai-vendor-assistant' ) } hideLabel value={ search } onChange={ setSearch } placeholder={ __( 'Search vendors…', 'dev3-ai-vendor-assistant' ) } />
						<Button type="submit" icon="search" label={ __( 'Search', 'dev3-ai-vendor-assistant' ) } />
					</form>
				}
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.rows : [] }
					loading={ ! data || data.loading }
					onRowClick={ ( r ) => setEditing( r ) }
					rowActions={ ( r ) => (
						<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
							<Button size="sm" icon="edit" onClick={ () => setEditing( r ) }>
								{ __( 'Edit', 'dev3-ai-vendor-assistant' ) }
							</Button>
						</span>
					) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'users', title: __( 'No vendors found', 'dev3-ai-vendor-assistant' ), description: __( 'Vendors are users with the Dokan "Vendor" or WCFM "Store Vendor" role.', 'dev3-ai-vendor-assistant' ) } }
				/>
			</Card>
			{ editing && (
				<VendorDrawer
					vendor={ editing }
					labels={ labels }
					defaultCredits={ settings.default_credits }
					onClose={ () => setEditing( null ) }
					onSaved={ ( row ) => {
						setEditing( null );
						setData( ( d ) => ( d ? { ...d, rows: d.rows.map( ( x ) => ( x.id === row.id ? row : x ) ) } : d ) );
					} }
				/>
			) }
		</>
	);
}
