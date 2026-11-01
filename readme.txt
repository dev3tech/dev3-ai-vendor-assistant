=== DEV3 AI Vendor Assistant for Dokan & WCFM ===
Contributors: dev3tech
Tags: dokan, wcfm, marketplace, vendor, ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI assistant inside the Dokan and WCFM vendor dashboards: listing writer, price suggestions, buyer reply drafts, store bio/SEO and performance tips, with admin quotas and moderation.

== Description ==

Give every vendor on your marketplace an assistant inside their own dashboard, styled to match Dokan or WCFM:

* **Listing writer** – photos + a few notes become a title, descriptions, tags, attributes, category, SEO text and alt text. Creates the product for the vendor.
* **Price suggestions** – compares with similar published listings on the marketplace (public prices and ratings only) and suggests a price and range.
* **Buyer replies** – one inbox for product reviews, order notes, WCFM inquiries and Dokan Pro support topics, with reply drafts that are sent back to the right place.
* **Store bio & SEO** – bio, tagline, SEO title/description and keywords saved into the marketplace's own fields.
* **Performance tips** – sales, listing quality, stock and reviews from the vendor's own data, summed up as clear next steps.

For the marketplace owner:

* Turn features on or off for everyone or per vendor, and switch the assistant off for a vendor.
* **Monthly AI credits per vendor** with a full ledger (credits, tokens, estimated cost, CSV export).
* **Moderation** of AI-written listings: hold for review or flag, blocked words, approve/reject with a note, DEV3 AI policy check.
* Vendors only ever see their own data (strict ownership checks on every request).
* Everything works without AI too: basic helpers for every feature.

Uses Google Gemini with your own API key, a monthly budget cap, caching and PII masking. No licence key required.

== Installation ==

1. Install WooCommerce and Dokan (Lite or Pro) or WCFM Frontend Manager + WCFM Marketplace.
2. Upload and activate the plugin.
3. Open DEV3 AI Vendors → API Config and add a Google Gemini key (optional).
4. Vendors find "AI Assistant" in their dashboard menu. Full documentation: Documentation/index.html in the download.

== Frequently Asked Questions ==

= Does it work without an API key? =
Yes. Every feature has a basic (non-AI) helper.

= Can vendors see other vendors' data? =
No. Only public listing data (title, price, rating, store) is used for price comparisons.

= Does it support Dokan Pro and WCFM Ultimate? =
Yes, through their documented hooks: vendor staff, Dokan Pro biography/store SEO and store support topics.

== Privacy ==

Product notes, photos, listing text, store facts and buyer messages (first names only) are sent to Google Gemini when a vendor uses an AI feature. Nothing is sent when AI is off. The plugin adds a privacy-policy snippet and supports WordPress personal-data export and erase.

== Changelog ==

= 1.0.0 =
* Initial release.
