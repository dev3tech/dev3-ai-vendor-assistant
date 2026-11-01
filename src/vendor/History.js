import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, ResponsiveTable, Select } from '../core3/ui';
import { ModerationBadge, fromNow, useVendor } from './shared';

const STATUS = { pending: 'warning', applied: 'success', rejected: 'neutral' };
const STATUS_LABEL = {
	pending: __( 'Draft', 'dev3-ai-vendor-assistant' ),
	applied: __( 'Used', 'dev3-ai-vendor-assistant' ),
	rejected: __( 'Discarded', 'dev3-ai-vendor-assistant' ),
};

export default function History() {
	const { me } = useVendor();
	const [ feature, setFeature ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		setData( ( d ) => ( d ? { ...d, loading: true } : null ) );
		request( `vendor/history?page=${ page }&feature=${ feature }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ page, feature ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const columns = [
		{
			key: 'title',
			label: __( 'Draft', 'dev3-ai-vendor-assistant' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3va-vendor-cell">
					<span>{ r.title || '—' }</span>
					{ r.note && r.moderation === 'rejected' && <span className="dev3va-vendor-note">{ r.note }</span> }
				</span>
			),
		},
		{ key: 'feature', label: __( 'Feature', 'dev3-ai-vendor-assistant' ), render: ( r ) => me.labels[ r.feature ] || r.feature },
		{
			key: 'status',
			label: __( 'Status', 'dev3-ai-vendor-assistant' ),
			render: ( r ) => (
				<span className="dev3va-vendor-chips">
					<Badge tone={ STATUS[ r.status ] || 'neutral' }>{ STATUS_LABEL[ r.status ] || r.status }</Badge>
					<ModerationBadge state={ r.moderation } />
				</span>
			),
		},
		{ key: 'ai', label: __( 'Made with', 'dev3-ai-vendor-assistant' ), hideOnMobile: true, render: ( r ) => ( r.ai ? __( 'AI', 'dev3-ai-vendor-assistant' ) : __( 'Basic helper', 'dev3-ai-vendor-assistant' ) ) },
		{ key: 'created_at', label: __( 'When', 'dev3-ai-vendor-assistant' ), nowrap: true, render: ( r ) => fromNow( r.created_at ) },
		{
			key: 'link',
			label: __( 'Open', 'dev3-ai-vendor-assistant' ),
			align: 'end',
			render: ( r ) =>
				r.edit_url ? (
					<Button size="sm" variant="link" href={ r.edit_url }>
						{ __( 'Edit product', 'dev3-ai-vendor-assistant' ) }
					</Button>
				) : null,
		},
	];

	const options = [ { value: '', label: __( 'All features', 'dev3-ai-vendor-assistant' ) }, ...Object.keys( me.labels ).filter( ( k ) => me.features[ k ] ).map( ( k ) => ( { value: k, label: me.labels[ k ] } ) ) ];

	return (
		<Card
			title={ __( 'Your drafts', 'dev3-ai-vendor-assistant' ) }
			description={ __( 'Everything the assistant wrote for your store. Only you and your staff can see this.', 'dev3-ai-vendor-assistant' ) }
			actions={
				<div className="dev3va-vendor-filter">
					<Select
						label={ __( 'Feature', 'dev3-ai-vendor-assistant' ) }
						hideLabel
						value={ feature }
						onChange={ ( v ) => {
							setPage( 1 );
							setFeature( v );
						} }
						options={ options }
					/>
				</div>
			}
			flush
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<ResponsiveTable
				columns={ columns }
				rows={ data ? data.rows : [] }
				loading={ ! data || data.loading }
				pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
				empty={ { icon: 'list', title: __( 'No drafts yet', 'dev3-ai-vendor-assistant' ), description: __( 'Drafts you create appear here.', 'dev3-ai-vendor-assistant' ) } }
			/>
		</Card>
	);
}
