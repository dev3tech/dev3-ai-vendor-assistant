import { createContext, useCallback, useContext, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Notice, Select } from '../core3/ui';

export const VendorContext = createContext( { me: null, refresh: () => {}, navigate: () => {} } );

export const useVendor = () => useContext( VendorContext );

/**
 * Vendor-facing AI label (the marketplace can hide the DEV3 name from vendors).
 *
 * @param {Object} me Vendor boot data.
 * @return {string} Label.
 */
export const aiLabel = ( me ) => ( me && me.branding ? __( 'Generate with DEV3 AI', 'dev3-ai-vendor-assistant' ) : __( 'Generate with AI', 'dev3-ai-vendor-assistant' ) );

export const draftTitle = ( me, ai ) => {
	if ( ! ai ) {
		return __( 'Draft (basic helper)', 'dev3-ai-vendor-assistant' );
	}
	return me && me.branding ? __( 'DEV3 AI draft', 'dev3-ai-vendor-assistant' ) : __( 'AI draft', 'dev3-ai-vendor-assistant' );
};

export const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-vendor-assistant' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-vendor-assistant' ) },
	{ value: 'enthusiastic', label: __( 'Enthusiastic', 'dev3-ai-vendor-assistant' ) },
	{ value: 'concise', label: __( 'Short & clear', 'dev3-ai-vendor-assistant' ) },
	{ value: 'luxury', label: __( 'Premium', 'dev3-ai-vendor-assistant' ) },
];

export function ToneSelect( { value, onChange } ) {
	return <Select label={ __( 'Tone', 'dev3-ai-vendor-assistant' ) } value={ value } onChange={ onChange } options={ TONES } />;
}

/**
 * Runs a vendor request with loading/error state and refreshes the credits meter after AI calls.
 *
 * @return {Object} { busy, error, run, setError }.
 */
export function useCall() {
	const { refresh } = useVendor();
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const run = useCallback(
		( key, path, data, method = 'POST' ) => {
			setBusy( key );
			setError( null );
			return request( path, method === 'GET' ? {} : { method, data } )
				.then( ( res ) => {
					if ( refresh ) {
						refresh();
					}
					return res;
				} )
				.catch( ( e ) => {
					setError( e );
					throw e;
				} )
				.finally( () => setBusy( '' ) );
		},
		[ refresh ]
	);
	return { busy, error, run, setError };
}

/**
 * "Generate" + "basic draft" action pair. The AI button is hidden when AI is unavailable.
 *
 * @param {Object}   props           Props.
 * @param {Function} props.onAi      AI request.
 * @param {Function} props.onBasic   Basic request.
 * @param {string}   props.busy      Busy key.
 * @param {string}   props.feature   Feature id (for the credit cost hint).
 * @param {boolean}  props.disabled  Disabled.
 * @return {Element} Actions.
 */
export function GenerateActions( { onAi, onBasic, busy, feature, disabled = false } ) {
	const { me } = useVendor();
	const aiOk = me && me.ai && me.ai.available && ! quotaEmpty( me, feature );
	const cost = me && me.weights ? me.weights[ feature ] : 1;
	return (
		<div className="dev3va-vendor-actions">
			{ aiOk && (
				<AiButton loading={ busy === 'ai' } disabled={ disabled || !! busy } onClick={ onAi }>
					{ aiLabel( me ) }
				</AiButton>
			) }
			{ onBasic && (
				<Button variant={ aiOk ? 'secondary' : 'primary' } icon="file" loading={ busy === 'basic' } disabled={ disabled || !! busy } onClick={ onBasic }>
					{ aiOk ? __( 'Basic draft (no AI)', 'dev3-ai-vendor-assistant' ) : __( 'Create a draft', 'dev3-ai-vendor-assistant' ) }
				</Button>
			) }
			{ aiOk && cost > 0 && (
				<span className="dev3va-vendor-cost">
					{ cost === 1 ? __( 'Uses 1 credit', 'dev3-ai-vendor-assistant' ) : `${ cost } ${ __( 'credits', 'dev3-ai-vendor-assistant' ) }` }
				</span>
			) }
		</div>
	);
}

export function quotaEmpty( me, feature ) {
	if ( ! me || ! me.quota || me.quota.unlimited ) {
		return false;
	}
	const cost = me.weights ? me.weights[ feature ] || 0 : 1;
	return me.quota.remaining !== null && me.quota.remaining < cost;
}

/**
 * Notice explaining that the result came from the basic helper because AI was unavailable.
 *
 * @param {Object} props          Props.
 * @param {Object} props.fallback { code, message }.
 * @return {Element|null} Notice.
 */
export function FallbackNotice( { fallback } ) {
	if ( ! fallback ) {
		return null;
	}
	return (
		<Notice tone="warning" title={ fallback.message }>
			{ __( 'We used the basic helper instead, so nothing was charged. Review and edit the draft below.', 'dev3-ai-vendor-assistant' ) }
		</Notice>
	);
}

/**
 * Notice when AI is off for the whole marketplace or the credits are used up.
 *
 * @param {Object} props         Props.
 * @param {string} props.feature Feature.
 * @return {Element|null} Notice.
 */
export function AiStateNotice( { feature } ) {
	const { me } = useVendor();
	if ( ! me ) {
		return null;
	}
	if ( ! me.ai.available ) {
		return <Notice tone="info">{ __( 'AI writing is not available right now. The basic helper still creates a draft you can edit.', 'dev3-ai-vendor-assistant' ) }</Notice>;
	}
	if ( quotaEmpty( me, feature ) ) {
		return <Notice tone="warning" title={ __( 'Your monthly AI credits are used up', 'dev3-ai-vendor-assistant' ) }>{ __( 'The basic helper still works. Credits reset on the 1st of the month.', 'dev3-ai-vendor-assistant' ) }</Notice>;
	}
	return null;
}

const MOD_TONES = { pending: 'warning', flagged: 'info', approved: 'success', rejected: 'danger' };
const MOD_LABELS = {
	pending: __( 'Awaiting review', 'dev3-ai-vendor-assistant' ),
	flagged: __( 'Flagged for review', 'dev3-ai-vendor-assistant' ),
	approved: __( 'Approved', 'dev3-ai-vendor-assistant' ),
	rejected: __( 'Needs changes', 'dev3-ai-vendor-assistant' ),
};

export function ModerationBadge( { state } ) {
	if ( ! state || ! MOD_LABELS[ state ] ) {
		return null;
	}
	return (
		<Badge tone={ MOD_TONES[ state ] } dot>
			{ MOD_LABELS[ state ] }
		</Badge>
	);
}

export const money = ( value, currency = '' ) => {
	const n = Number( value || 0 );
	return `${ currency }${ n.toLocaleString( undefined, { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 } ) }`;
};

export const fromNow = ( iso ) => {
	if ( ! iso ) {
		return '';
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	const diff = ( Date.now() - date.getTime() ) / 1000;
	if ( diff < 60 ) {
		return __( 'just now', 'dev3-ai-vendor-assistant' );
	}
	if ( diff < 3600 ) {
		return `${ Math.round( diff / 60 ) } ${ __( 'min ago', 'dev3-ai-vendor-assistant' ) }`;
	}
	if ( diff < 86400 ) {
		return `${ Math.round( diff / 3600 ) } ${ __( 'h ago', 'dev3-ai-vendor-assistant' ) }`;
	}
	return date.toLocaleDateString();
};

const decode = ( s ) => String( s ).replace( /&nbsp;/g, ' ' ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ).replace( /&amp;/g, '&' );
const encode = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

/**
 * Simple listing HTML (p, ul/li, strong, em) to editable plain text: blank lines between
 * paragraphs and "- " bullets.
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function htmlToText( html ) {
	return decode(
		String( html || '' )
			.replace( /\r/g, '' )
			.replace( /<li[^>]*>/gi, '\n- ' )
			.replace( /<\/li>/gi, '' )
			.replace( /<\/?(ul|ol)[^>]*>/gi, '\n\n' )
			.replace( /<br\s*\/?>/gi, '\n' )
			.replace( /<\/p>/gi, '\n\n' )
			.replace( /<[^>]+>/g, '' )
	)
		.replace( /[ \t]+\n/g, '\n' )
		.replace( /\n{3,}/g, '\n\n' )
		.trim();
}

/**
 * Inverse of htmlToText.
 *
 * @param {string} text Text.
 * @return {string} HTML.
 */
export function textToHtml( text ) {
	return String( text || '' )
		.split( /\n{2,}/ )
		.map( ( block ) => block.trim() )
		.filter( Boolean )
		.map( ( block ) => {
			const lines = block.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
			if ( lines.every( ( l ) => /^[-*•]\s+/.test( l ) ) ) {
				return '<ul>' + lines.map( ( l ) => `<li>${ encode( l.replace( /^[-*•]\s+/, '' ) ) }</li>` ).join( '' ) + '</ul>';
			}
			return `<p>${ lines.map( encode ).join( '<br>' ) }</p>`;
		} )
		.join( '' );
}
