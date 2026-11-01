import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { ErrorNotice, Icon, SkeletonCard, Tabs, ToastProvider, useHashRoute } from '../core3/ui';
import { VendorContext } from './shared';
import Home from './Home';
import Listing from './Listing';
import Pricing from './Pricing';
import Messages from './Messages';
import Profile from './Profile';
import Insights from './Insights';
import History from './History';

const SCREENS = [
	{ id: 'home', label: __( 'Home', 'dev3-ai-vendor-assistant' ), feature: '' },
	{ id: 'listing', label: __( 'Listing writer', 'dev3-ai-vendor-assistant' ), feature: 'listing' },
	{ id: 'pricing', label: __( 'Pricing', 'dev3-ai-vendor-assistant' ), feature: 'price' },
	{ id: 'messages', label: __( 'Buyer messages', 'dev3-ai-vendor-assistant' ), feature: 'reply' },
	{ id: 'profile', label: __( 'Store profile', 'dev3-ai-vendor-assistant' ), feature: 'profile' },
	{ id: 'insights', label: __( 'Performance', 'dev3-ai-vendor-assistant' ), feature: 'insights' },
	{ id: 'history', label: __( 'History', 'dev3-ai-vendor-assistant' ), feature: '' },
];

const COMPONENTS = { home: Home, listing: Listing, pricing: Pricing, messages: Messages, profile: Profile, insights: Insights, history: History };

function CreditsPill( { quota } ) {
	if ( ! quota ) {
		return null;
	}
	if ( quota.unlimited ) {
		return (
			<span className="dev3va-vendor-credits">
				<Icon name="bolt" size={ 14 } />
				{ __( 'Unlimited AI credits', 'dev3-ai-vendor-assistant' ) }
			</span>
		);
	}
	const low = quota.limit && quota.remaining / quota.limit < 0.15;
	return (
		<span className={ `dev3va-vendor-credits${ low ? ' is-low' : '' }` } title={ __( 'AI credits left this month', 'dev3-ai-vendor-assistant' ) }>
			<Icon name="bolt" size={ 14 } />
			{ sprintf(
				/* translators: 1: credits left, 2: monthly credits */
				__( '%1$d of %2$d credits left', 'dev3-ai-vendor-assistant' ),
				quota.remaining,
				quota.limit
			) }
			<span className="dev3va-vendor-credits__bar" aria-hidden="true">
				<span style={ { width: `${ 100 - quota.percent }%` } } />
			</span>
		</span>
	);
}

export default function App( { boot = {} } ) {
	const [ me, setMe ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = useCallback( () => {
		setError( null );
		return request( 'vendor/me' ).then( setMe ).catch( setError );
	}, [] );
	useEffect( () => {
		load();
	}, [ load ] );

	const refresh = useCallback( () => {
		request( 'vendor/me' )
			.then( setMe )
			.catch( () => {} );
	}, [] );

	const screens = useMemo( () => SCREENS.filter( ( s ) => ! s.feature || ( me && me.features[ s.feature ] ) ), [ me ] );
	// All ids are routable from the start (deep links like #/listing/12 arrive before `me`); a
	// screen whose feature is off for this vendor falls back to Home.
	const [ route, navigate ] = useHashRoute(
		SCREENS.map( ( s ) => s.id ),
		'home'
	);
	const ctx = useMemo( () => ( { me, refresh, navigate } ), [ me, refresh, navigate ] );
	const allowed = screens.some( ( s ) => s.id === route.id );
	const activeId = allowed ? route.id : 'home';
	const Screen = COMPONENTS[ activeId ] || Home;

	return (
		<ToastProvider>
			<VendorContext.Provider value={ ctx }>
				<div className="dev3va-vendor-app">
					<header className="dev3va-vendor-head">
						<div className="dev3va-vendor-head__title">
							<span className="dev3va-vendor-head__mark" aria-hidden="true">
								<Icon name="sparkles" size={ 18 } />
							</span>
							<div>
								<h2>{ boot.menuLabel || __( 'AI Assistant', 'dev3-ai-vendor-assistant' ) }</h2>
								{ me && <p>{ me.store_name }</p> }
							</div>
						</div>
						{ me && <CreditsPill quota={ me.quota } /> }
					</header>
					{ error && <ErrorNotice error={ error } onRetry={ load } /> }
					{ ! me && ! error && <SkeletonCard lines={ 5 } /> }
					{ me && (
						<>
							<div className="dev3va-vendor-tabs">
								<Tabs tabs={ screens.map( ( s ) => ( { id: s.id, label: s.label } ) ) } value={ activeId } onChange={ ( id ) => navigate( id ) } label={ __( 'Assistant sections', 'dev3-ai-vendor-assistant' ) } />
							</div>
							<main className="dev3va-vendor-main">
								<Screen params={ allowed ? route.params : [] } key={ activeId } />
							</main>
						</>
					) }
				</div>
			</VendorContext.Provider>
		</ToastProvider>
	);
}
