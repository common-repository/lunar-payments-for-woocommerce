=== Lunar Online Payments for WooCommerce ===
Contributors: lunarlbt
Tags: credit card, gateway, lunar, woocommerce, multisite
Requires at least: 4.4
Tested up to: 6.4.3
Stable tag: 4.2.1
WC requires at least: 3.0
WC tested up to: 8.6.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept payments with Visa and MasterCard instantly. Lunar Payments is the modern full-stack payment platform combining banking and payments into one.

== Description ==

Lunar is one of the leading Banks in the Nordics, providing new and innovative solutions within Banking, Payments, Lending, and Investing for over 600,000 consumers and 15,000 businesses across the Nordics.

Lunar gives you everything you need to accept online payments today. Low rates, local payment methods, local support, and so much more - all while reducing admin work by gathering gateway and acquiring in one place. Whether you're an ambitious solopreneur or a big-league business, Lunar payments is for you. Together with the Lunar Payments plugin for WooCommerce you will have a strong and lean setup to take your business to the next level.

### How do I get a Lunar Online Payments account?

To use Lunar Online Payments, you must first create an account in four simple steps.

1.  Get Lunar in App Store or Google Play.
2.  Create your account with Lunar.
3.  Log in to your new Lunar web portal and connect to your WooCommerce webshop.
4.  All set! Now you are ready to accept payments in your webshop.

Click [HERE](https://www.lunar.app/en/business/online-payments) to read more, or contact the sales team at sales@lunar.app.

**Want to get the most out of your Lunar experience? Get Online Payments together with Lunar Business! Sign up as a Business customer, then add Online Payments in the Web Portal.**

### Account and Pricing

Getting paid shouldn't be expensive, and that's why we offer some of the best rates out there. That means a low and transparent price that makes sense for you and your business.

Your account and pricing depends on whether you are a Business customer or if you use Lunar Online Payments as a standalone solution. Click [HERE](https://www.lunar.app/en/business/online-payments) to read more.

### Features & Benefits

-   Gateway & Acquirer in one
-   Local Payment Methods to boost conversion
-   Low and transparent pricing
-   Local support team from 0800-2300
-   Fast Digital Signup
-   Possibility to combine payment solution and Lunar business account
-   World Class Stability (no down-time in 6 years)
-   Instant and delayed capture
-   .csv export for easy accounting
-   Full and partial refunds
-   Void transactions
-   Free payouts
-   Free refunds

Lunar is continuously extending and modernising our service offerings!

### Questions?

We're always here if you have any questions or need help getting started.

Contact our support team: 
-   onlinepayments@lunar.app
-   +45 70 60 54 54 (Weekdays: 9-23｜Weekends and bank holidays: 10-17)

Contact our sales team
-   sales@lunar.app

== Installation ==

Once you have installed WooCommerce on your Wordpress setup, follow these simple steps:

1. Create a live account
1. Create an app key for your WooCommerce website under Webshop
1. Upload the plugin files to the `/wp-content/plugins/lunar-payments-for-woocommerce` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress.
1. Insert the app key and your public key in the Checkout settings for the Lunar payment plugin


== Frequently Asked Questions ==

= Does the plugin support subscriptions? =

Yes, the plugin supports the subscriptions plugin.

= Can the plugin be used in a multisite environment? =

Yes, the plugin works normally under multisite, the automated tests we run get ran on standard installations as well as on testing multisite installs.

= How do I capture a payment if I have set the option to not capture the money on checkout? =

In order to capture a payment you can do so by moving the order into the on hold status, and after that move to either processing or complete. This will automatically trigger the capturing process.


== Screenshots ==

1. The settings panel for the Lunar gateway
2. Checkout screen
3. The settings panel for the Lunar MobilePay gateway
4. Checkout screen for MobilePay

== Changelog ==

= 4.2.1 =
* Remove spaces for string setting fields before save

= 4.1.1 =
* Fixed stable version

= 4.1.0 =
* Fixed check when mobilepay not configured

= 4.0.9 =
* On payment method change, now preferred payment is updated

= 4.0.8 =
* Update return url to account for existing 'wc-api' query parameter
* Send currency to the test object

= 4.0.7 =
* Fix exception in polling for non authorized order
* Mitigate issue on existing payment intent with the same payment id

= 4.0.6 =
* Remove deprecated messages for validation

= 4.0.5 =
* Update woocommerce support

= 4.0.4 =
* Remove beta in names
* Update validation

= 4.0.3 =
* Do not attempt to capture orders that are already captured to avoid polling by status getting stuck

= 4.0.2 =
* Fix tagging

= 4.0.1 =
* Always create payment intents

= 4.0.0 =
* Add HPOS support

= 3.0.0 =
* AUTO MIGRATE LEGACY PAYMENT METHODS TO HOSTED CHECKOUT
* Cart only gets cleared on succesful payment

= 2.0.1 =
* Updated settings defaults

= 2.0.0 =
* Hosted checkout

= 1.5.4 =
* Fix typo

= 1.5.3 =
* Updated settings link

= 1.5.2 =
* Updated readme

= 1.5.0 =
* Add logo validation
* Add support for WooCommerce 7.1.0

= 1.4.1 =
* Udate screenshots and updated up to

= 1.4.0 =
* Udate interval check for not before

= 1.3.9 =
* Fix not before interval not working when receiving miliseconds back

= 1.3.8 =
* Minor text changes

= 1.3.7 =
* Minor text changes

= 1.3.6 =
* Minor text changes
* Updated compatiblity

= 1.3.5 =
* Fix template redirect bug

= 1.3.4 =
* Add loading indicator to pay after button, automatically start the payment

= 1.3.3 =
* Removed logging by default

= 1.3.2 =
* Minor text changes

= 1.3.1 =
* Add server side redirect for mobilepay redirect challenge when available

= 1.3.0 =
* Add Mobile Pay support

= 1.2.1 =
* Update compatibility

= 1.2.0 =
* Capture always on order complete
* Removed forced unplanned, now amount is being sent when one subscription is in the cart

= 1.1.1 =
* Update plugin translations to be in sync with the newest updates

= 1.1.0 =
* Remove compatibility mode
* Auto capture in delayed mode for virtual orders

= 1.0.4 =
* Update checkout mode order

= 1.0.3 =
* Minor fixes

= 1.0.2 =
* Fix test mode bug

= 1.0.1 =
* Fix double escaping

= 1.0.0 =
* Initial release
