import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Vendors from './Vendors';
import Moderation from './Moderation';
import Activity from './Activity';
import SettingsTab from './SettingsTab';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-vendor-assistant' ), icon: 'dashboard' },
	{ id: 'vendors', label: __( 'Vendors & quotas', 'dev3-ai-vendor-assistant' ), icon: 'users' },
	{ id: 'moderation', label: __( 'Moderation', 'dev3-ai-vendor-assistant' ), icon: 'check' },
	{ id: 'activity', label: __( 'Activity', 'dev3-ai-vendor-assistant' ), icon: 'list' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-vendor-assistant' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-vendor-assistant' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-vendor-assistant' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute(
		NAV.map( ( n ) => n.id ),
		'dashboard'
	);
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ pending, setPending ] = useState( null );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const nav = NAV.map( ( n ) => ( n.id === 'moderation' && pending ? { ...n, badge: pending } : n ) );

	const screens = {
		dashboard: () => <Dashboard settings={ settings } navigate={ navigate } onCounts={ setPending } />,
		vendors: () => <Vendors settings={ settings } />,
		moderation: () => <Moderation onCounts={ setPending } onUsage={ () => setMeter( ( n ) => n + 1 ) } />,
		activity: () => <Activity />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-vendor-assistant' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget for the whole marketplace.', 'dev3-ai-vendor-assistant' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-vendor-assistant' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI request (vendors and admins).', 'dev3-ai-vendor-assistant' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Vendor Assistant for Dokan & WCFM' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id }
			onNavigate={ ( id ) => navigate( id ) }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-vendor-assistant' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
