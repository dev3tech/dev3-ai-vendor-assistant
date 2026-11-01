import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, FormGrid, NumberField, PageHeader, Select, Stack, TextField, Textarea, Toggle, useToast } from '../core3/ui';

const FEATURES = [
	[ 'listing', __( 'Listing writer (photos + notes)', 'dev3-ai-vendor-assistant' ) ],
	[ 'price', __( 'Price suggestions', 'dev3-ai-vendor-assistant' ) ],
	[ 'reply', __( 'Buyer reply drafts', 'dev3-ai-vendor-assistant' ) ],
	[ 'profile', __( 'Store bio & SEO', 'dev3-ai-vendor-assistant' ) ],
	[ 'insights', __( 'Performance tips', 'dev3-ai-vendor-assistant' ) ],
];

const KEYS = [ 'marketplace', 'features', 'default_credits', 'credit_weights', 'moderation_mode', 'blocked_terms', 'blocked_action', 'moderation_policy', 'menu_label', 'menu_position', 'vendor_branding', 'tone', 'language', 'comparables', 'low_stock', 'reply_policies', 'store_seo_output', 'notify_vendor', 'max_photos', 'apply_alt_text' ];

export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const setIn = ( group, key ) => ( value ) => setForm( { ...form, [ group ]: { ...form[ group ], [ key ]: value } } );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => {
			patch[ k ] = form[ k ] === '' && typeof settings[ k ] === 'number' ? settings[ k ] : form[ k ];
		} );
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-vendor-assistant' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const saveBar = (
		<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
			{ __( 'Save settings', 'dev3-ai-vendor-assistant' ) }
		</Button>
	);

	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-vendor-assistant' ) } description={ __( 'What vendors get, how many credits they have, and how AI-written listings are moderated.', 'dev3-ai-vendor-assistant' ) } actions={ saveBar } />
			<Stack gap={ 16 }>
				<Card title={ __( 'Marketplace & vendor menu', 'dev3-ai-vendor-assistant' ) }>
					<FormGrid>
						<Select
							label={ __( 'Marketplace plugin', 'dev3-ai-vendor-assistant' ) }
							value={ form.marketplace }
							onChange={ set( 'marketplace' ) }
							options={ [
								{ value: 'auto', label: __( 'Detect automatically', 'dev3-ai-vendor-assistant' ) },
								{ value: 'dokan', label: 'Dokan' },
								{ value: 'wcfm', label: 'WCFM Marketplace' },
							] }
						/>
						<TextField label={ __( 'Menu label for vendors', 'dev3-ai-vendor-assistant' ) } value={ form.menu_label } onChange={ set( 'menu_label' ) } placeholder={ __( 'AI Assistant', 'dev3-ai-vendor-assistant' ) } maxLength={ 40 } />
						<NumberField label={ __( 'Menu position', 'dev3-ai-vendor-assistant' ) } value={ form.menu_position } min={ 1 } max={ 999 } onChange={ set( 'menu_position' ) } help={ __( 'Dokan: 30 = after Products, 50 = after Orders.', 'dev3-ai-vendor-assistant' ) } />
						<Toggle label={ __( 'Show the DEV3 AI name to vendors', 'dev3-ai-vendor-assistant' ) } checked={ form.vendor_branding } onChange={ set( 'vendor_branding' ) } help={ __( 'Off: buttons say "Generate with AI".', 'dev3-ai-vendor-assistant' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Features for vendors', 'dev3-ai-vendor-assistant' ) } description={ __( 'Marketplace-wide defaults. You can override them per vendor on the Vendors screen.', 'dev3-ai-vendor-assistant' ) }>
					<FormGrid>
						{ FEATURES.map( ( [ key, label ] ) => (
							<Toggle key={ key } label={ label } checked={ !! form.features[ key ] } onChange={ setIn( 'features', key ) } />
						) ) }
					</FormGrid>
				</Card>
				<Card title={ __( 'Monthly AI credits', 'dev3-ai-vendor-assistant' ) } description={ __( 'Each AI request costs credits. Cached answers and basic (non-AI) drafts are free. The API Config monthly budget still applies to the whole site.', 'dev3-ai-vendor-assistant' ) }>
					<FormGrid>
						<NumberField label={ __( 'Credits per vendor per month', 'dev3-ai-vendor-assistant' ) } value={ form.default_credits } min={ 0 } onChange={ set( 'default_credits' ) } help={ __( '0 = unlimited.', 'dev3-ai-vendor-assistant' ) } />
						{ FEATURES.map( ( [ key, label ] ) => (
							<NumberField key={ key } label={ `${ label }: ${ __( 'credits per request', 'dev3-ai-vendor-assistant' ) }` } value={ form.credit_weights[ key ] } min={ 0 } max={ 20 } onChange={ setIn( 'credit_weights', key ) } />
						) ) }
					</FormGrid>
				</Card>
				<Card title={ __( 'Moderation', 'dev3-ai-vendor-assistant' ) }>
					<FormGrid>
						<Select
							label={ __( 'AI-written listings', 'dev3-ai-vendor-assistant' ) }
							value={ form.moderation_mode }
							onChange={ set( 'moderation_mode' ) }
							options={ [
								{ value: 'review', label: __( 'Hold for review before publishing', 'dev3-ai-vendor-assistant' ) },
								{ value: 'flag', label: __( 'Publish, but list them for review', 'dev3-ai-vendor-assistant' ) },
								{ value: 'off', label: __( 'No extra moderation', 'dev3-ai-vendor-assistant' ) },
							] }
						/>
						<Select
							label={ __( 'When a blocked word is found', 'dev3-ai-vendor-assistant' ) }
							value={ form.blocked_action }
							onChange={ set( 'blocked_action' ) }
							options={ [
								{ value: 'flag', label: __( 'Flag the listing for review', 'dev3-ai-vendor-assistant' ) },
								{ value: 'block', label: __( 'Do not let the vendor save it', 'dev3-ai-vendor-assistant' ) },
							] }
						/>
						<Textarea label={ __( 'Blocked words', 'dev3-ai-vendor-assistant' ) } value={ form.blocked_terms } onChange={ set( 'blocked_terms' ) } rows={ 4 } help={ __( 'One per line or comma separated, e.g. replica, cure, whatsapp.', 'dev3-ai-vendor-assistant' ) } />
						<Textarea label={ __( 'Marketplace listing policy (for the AI check)', 'dev3-ai-vendor-assistant' ) } value={ form.moderation_policy } onChange={ set( 'moderation_policy' ) } rows={ 4 } maxLength={ 3000 } />
						<Toggle label={ __( 'Email vendors when a listing is approved or rejected', 'dev3-ai-vendor-assistant' ) } checked={ form.notify_vendor } onChange={ set( 'notify_vendor' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Writing & replies', 'dev3-ai-vendor-assistant' ) }>
					<FormGrid>
						<Select
							label={ __( 'Default tone', 'dev3-ai-vendor-assistant' ) }
							value={ form.tone }
							onChange={ set( 'tone' ) }
							options={ [
								{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-vendor-assistant' ) },
								{ value: 'professional', label: __( 'Professional', 'dev3-ai-vendor-assistant' ) },
								{ value: 'enthusiastic', label: __( 'Enthusiastic', 'dev3-ai-vendor-assistant' ) },
								{ value: 'concise', label: __( 'Short & clear', 'dev3-ai-vendor-assistant' ) },
								{ value: 'luxury', label: __( 'Premium', 'dev3-ai-vendor-assistant' ) },
							] }
						/>
						<TextField label={ __( 'Output language', 'dev3-ai-vendor-assistant' ) } value={ form.language } onChange={ set( 'language' ) } placeholder={ __( 'Site language', 'dev3-ai-vendor-assistant' ) } />
						<NumberField label={ __( 'Photos per listing', 'dev3-ai-vendor-assistant' ) } value={ form.max_photos } min={ 1 } max={ 10 } onChange={ set( 'max_photos' ) } />
						<Toggle label={ __( 'Write image alt text from the AI draft', 'dev3-ai-vendor-assistant' ) } checked={ form.apply_alt_text } onChange={ set( 'apply_alt_text' ) } />
						<NumberField label={ __( 'Comparable listings for pricing', 'dev3-ai-vendor-assistant' ) } value={ form.comparables } min={ 3 } max={ 40 } onChange={ set( 'comparables' ) } />
						<NumberField label={ __( 'Low-stock threshold (tips)', 'dev3-ai-vendor-assistant' ) } value={ form.low_stock } min={ 0 } onChange={ set( 'low_stock' ) } />
						<Textarea label={ __( 'Marketplace policies for buyer replies', 'dev3-ai-vendor-assistant' ) } value={ form.reply_policies } onChange={ set( 'reply_policies' ) } rows={ 4 } maxLength={ 3000 } help={ __( 'Rules every reply draft follows, e.g. refunds go through the marketplace. Vendors can add their own.', 'dev3-ai-vendor-assistant' ) } />
						<Toggle label={ __( 'Print store SEO tags on Dokan Lite store pages', 'dev3-ai-vendor-assistant' ) } checked={ form.store_seo_output } onChange={ set( 'store_seo_output' ) } help={ __( 'Dokan Pro and WCFM print their own.', 'dev3-ai-vendor-assistant' ) } />
					</FormGrid>
				</Card>
				<div className="dev3va-savebar">{ saveBar }</div>
			</Stack>
		</form>
	);
}
