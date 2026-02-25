=== WooPayouts to Xero (Free) ===
Contributors: hashy-au
Donate link: https://hashy.com.au/
Tags: woocommerce, woopayments, xero, accounting, payouts
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooPayments payouts to Xero as a draft invoice (single-line payout total)

== Description ==

WooPayouts to Xero (Free) creates a **draft** Xero invoice for each WooPayments payout you choose to send.

**Free features**
* Connect your own Xero app (OAuth2)
* View recent WooPayments payouts
* Send a payout to Xero as a **single-line** draft invoice (payout total)

**Go Premium**
Upgrade to unlock:
* Itemised product line exports
* Refund handling (payouts & debits)
* Category-to-account-code mapping
* Fees/shipping controls
* “Awaiting payment” invoice status
* Automatic updates while support is active

Premium page: https://hashy.com.au/shop/woopayouts-to-xero-plugin/

== Installation ==

1. Install WooPayouts to Xero
2. Navigate to Settings
3. Copy your "Redirect URI" at the top of the settings page
4. Navigate to Xero Developer Platform: https://developer.xero.com/app/manage
6. Create a Xero App
  - App Name: Anything
  - Integration Type: Web app
  - Company or Application URL: Your Website
  - OAuth 2.0 redirect URI: Link copied from the plugin settings page
7. Once created, go to Configuration
  - Copy your Client ID & enter into your plugin settings
  - Create a Client Secret & enter into your plugin settings
8. Click "Save & Connect to Xero
9. Accept the App Connection Request

You're now connected! Your WooPayouts will be sent to Xero as Gross Totals.

== Frequently Asked Questions ==

= Does this require my own Xero app? =
Yes. Each site creates its own Xero app to keep access private. You are in complete control of your Xero connection

= Why does the free version send only one line? =
The free version sends a single-line invoice with the payout total in draft mode, allowing you to make changes before reconciling

== Changelog ==

= 1.0.0 =
* Initial free release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
