<?php
/**
 * Versioned prompts for every vendor feature (overridable with dev3va_prompt_<id> filters).
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt registrations.
 */
class Prompt_Library {

	/**
	 * Registers all prompts (idempotent).
	 */
	public static function register() {
		if ( Prompts::get( 'va_listing' ) ) {
			return;
		}
		$str = static function ( $max ) {
			return array(
				'type'      => 'STRING',
				'maxLength' => $max,
			);
		};

		Prompts::register(
			'va_listing',
			array(
				'version'     => '1.0.0',
				'task'        => 'vendor_listing',
				'system'      => 'You are a marketplace listing copywriter for an online store vendor. Write accurate, honest product listings from the photos and the vendor notes only. Never invent certifications, materials, sizes, brands or guarantees that are not visible or stated. Write in {{language}}. Tone: {{tone}}. The description may use only <p>, <ul>, <li>, <strong> and <em> HTML tags.',
				'template'    => "Store: {{store}}\nExisting listing (may be empty): {{existing}}\nVendor notes: {{notes}}\nCategory hint: {{category}}\nAvailable store categories: {{categories}}\nNumber of photos attached: {{photo_count}}\n\nWrite the listing. alt_texts must have one short description per attached photo, in order.",
				'temperature' => 0.4,
				'max_tokens'  => 3072,
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'title'             => $str( 120 ),
						'short_description' => $str( 400 ),
						'description'       => $str( 3000 ),
						'tags'              => array(
							'type'  => 'ARRAY',
							'items' => $str( 40 ),
						),
						'categories'        => array(
							'type'  => 'ARRAY',
							'items' => $str( 80 ),
						),
						'attributes'        => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'name'  => $str( 40 ),
									'value' => $str( 120 ),
								),
								'required'   => array( 'name', 'value' ),
							),
						),
						'seo_title'         => $str( 70 ),
						'seo_description'   => $str( 170 ),
						'alt_texts'         => array(
							'type'  => 'ARRAY',
							'items' => $str( 140 ),
						),
					),
					'required'   => array( 'title', 'short_description', 'description', 'tags' ),
				),
			)
		);

		Prompts::register(
			'va_price',
			array(
				'version'     => '1.0.0',
				'task'        => 'vendor_price',
				'system'      => 'You are a pricing analyst for a multi-vendor marketplace. Suggest a price for the vendor\'s product using the comparable listings given. Stay inside the realistic range of the comparables unless the product facts justify a premium. Explain in plain words. Currency: {{currency}}.',
				'template'    => "Product: {{product}}\nVendor goal: {{goal}}\nVendor notes: {{notes}}\nComparable marketplace listings (title, price, rating, reviews): {{comparables}}\nStatistics: {{stats}}\n\nReturn the suggested price, a low-high range, a short rationale and your confidence.",
				'temperature' => 0.2,
				'max_tokens'  => 1024,
				'cache'       => 6 * HOUR_IN_SECONDS,
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'price'      => array( 'type' => 'NUMBER' ),
						'low'        => array( 'type' => 'NUMBER' ),
						'high'       => array( 'type' => 'NUMBER' ),
						'rationale'  => $str( 600 ),
						'confidence' => array(
							'type' => 'STRING',
							'enum' => array( 'low', 'medium', 'high' ),
						),
					),
					'required'   => array( 'price', 'low', 'high', 'rationale', 'confidence' ),
				),
			)
		);

		Prompts::register(
			'va_reply',
			array(
				'version'     => '1.0.0',
				'task'        => 'vendor_reply',
				'system'      => 'You write replies from a marketplace vendor to a buyer. Be {{tone}}, clear and helpful. Use only the facts given (product, order, policies). Never promise refunds, discounts, delivery dates or anything the policies do not allow; if unsure, say the store will check and follow up. Address the buyer by first name if given. Reply in the language of the buyer message. Plain text, no markdown, no signature placeholders.',
				'template'    => "Store: {{store}}\nBuyer first name: {{buyer}}\nMessage type: {{source}}\nBuyer message: {{message}}\nProduct facts: {{product}}\nOrder facts: {{order}}\nStore policies: {{policies}}\nVendor instructions for this reply: {{instructions}}",
				'temperature' => 0.4,
				'max_tokens'  => 1024,
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'reply'     => $str( 1500 ),
						'intent'    => array(
							'type' => 'STRING',
							'enum' => array( 'shipping', 'returns', 'stock', 'sizing', 'product_question', 'complaint', 'praise', 'other' ),
						),
						'sentiment' => array(
							'type' => 'STRING',
							'enum' => array( 'positive', 'neutral', 'negative' ),
						),
						'summary'   => $str( 160 ),
					),
					'required'   => array( 'reply', 'intent', 'sentiment', 'summary' ),
				),
			)
		);

		Prompts::register(
			'va_profile',
			array(
				'version'     => '1.0.0',
				'task'        => 'vendor_profile',
				'system'      => 'You write marketplace store profiles. Write in {{language}}, tone {{tone}}. Use only the facts given; no invented awards, years in business or certifications. The bio may use <p> tags only.',
				'template'    => "Store name: {{store}}\nWhat the store sells (categories): {{categories}}\nTop products: {{products}}\nCurrent bio: {{bio}}\nVendor notes: {{notes}}\n\nWrite a store bio (2-3 short paragraphs), a tagline, an SEO title (max 60 characters), an SEO description (max 155 characters) and 5-8 keywords.",
				'temperature' => 0.5,
				'max_tokens'  => 1536,
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'bio'             => $str( 1500 ),
						'tagline'         => $str( 90 ),
						'seo_title'       => $str( 65 ),
						'seo_description' => $str( 165 ),
						'keywords'        => array(
							'type'  => 'ARRAY',
							'items' => $str( 40 ),
						),
					),
					'required'   => array( 'bio', 'tagline', 'seo_title', 'seo_description', 'keywords' ),
				),
			)
		);

		Prompts::register(
			'va_insights',
			array(
				'version'     => '1.0.0',
				'task'        => 'vendor_insights',
				'system'      => 'You are a friendly marketplace growth coach. From the store statistics, give the vendor a short headline and 3-6 concrete, prioritised tips. Refer to their products by name. No generic advice that ignores the numbers.',
				'template'    => "Store: {{store}}\nPeriod: last {{days}} days vs the {{days}} days before.\nStatistics: {{stats}}\nIssues found by rules: {{issues}}\nAction ids you may use: listing, pricing, messages, profile, none.",
				'temperature' => 0.4,
				'max_tokens'  => 1536,
				'cache'       => DAY_IN_SECONDS,
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'headline' => $str( 160 ),
						'tips'     => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'title'    => $str( 90 ),
									'detail'   => $str( 400 ),
									'priority' => array(
										'type' => 'STRING',
										'enum' => array( 'high', 'medium', 'low' ),
									),
									'action'   => array(
										'type' => 'STRING',
										'enum' => array( 'listing', 'pricing', 'messages', 'profile', 'none' ),
									),
								),
								'required'   => array( 'title', 'detail', 'priority', 'action' ),
							),
						),
					),
					'required'   => array( 'headline', 'tips' ),
				),
			)
		);

		Prompts::register(
			'va_moderation',
			array(
				'version'     => '1.0.0',
				'task'        => 'admin_listing_check',
				'system'      => 'You review vendor listings for a marketplace owner. Check the listing against the marketplace policy and general rules: no misleading claims, no prohibited items, no contact details or off-site links, no keyword stuffing, accurate title. Be specific and brief.',
				'template'    => "Marketplace policy: {{policy}}\nListing title: {{title}}\nShort description: {{short}}\nDescription: {{description}}\nTags: {{tags}}\nPrice: {{price}}",
				'temperature' => 0.1,
				'max_tokens'  => 1024,
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'verdict' => array(
							'type' => 'STRING',
							'enum' => array( 'approve', 'review', 'reject' ),
						),
						'summary' => $str( 300 ),
						'issues'  => array(
							'type'  => 'ARRAY',
							'items' => $str( 200 ),
						),
					),
					'required'   => array( 'verdict', 'summary', 'issues' ),
				),
			)
		);
	}
}
