=== ProteCOD ===
Contributors: ottoradics
Tags: cash on delivery,check,filter,utánvét,ellenőr,büntetés,protecod,szűrés
Tested up to: 7.0.2
Requires PHP: 8.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Connect your WooCommerce site to our SaaS available at https://utanvet-ellenor.hu
[Register](https://utanvet-ellenor.hu/register) to obtain API keys.

== Description ==

### Installation
[Register](https://utanvet-ellenor.hu/register) to obtain API keys, then set them in the plugin's settings. The reputation threshold parameter is there to let you customize how strict should the filtering be. This is a number between -1 and +1.

### What is ProteCOD?

It is a SaaS provided by Utánvét Ellenőr Kft. from Hungary: a service which will let shop owners filter orders with Cash on Delivery coming from known fraudulent e-mail addresses.

ProteCOD is the international brand; in Hungarian, the service is called Utánvét Ellenőr.

### How does it work?

The idea behind the service is the following:
* Someone orders with Cash on Delivery payment method, but later refuses to accept the package from the courier.
* The shop owner flags this order with the custom order status `Rendelést nem vette át`.
* The plugin listens for orders entering this status.
* Once an order ends up in this status, the plugin sends the order's customer and delivery data to our service, accompanied by a `-1`.
* If the courier could hand over the package successfully, the shop owner flags the order with the stock order status `completed`. In this case the plugin sends the order's customer and delivery data to our service, accompanied by a `+1`.
* When someone with the same e-mail address would like to order (from the same or from another shop), this plugin can disable Cash on Delivery from available payment methods:
  * The user enters his e-mail address and leaves the `billing_email` input field.
  * The plugin sends the entered customer and delivery data to our service for verification.
  * The service returns a JSON response and if the customer reputation provided in this payload does not meet the minimum value set by the shop owner in the plugin settings (`Reputation threshold`), the plugin will disable the Cash on Delivery payment method.

### Checkout results and payment retries

Successful checks are cached for 30 minutes. Reusing a result does not extend its expiry, and placing an order does not delete it. Another order with the same customer data and settings in the same session can reuse the result during that period.

Before an order is created, server-side checkout validation reuses a matching result or performs the check if none exists. The order stores the full API response, the payment restriction decision, and the check time in `_uvb_connector_woocommerce_check_result`, with `schema_version` set to `1`. The existing `_uvb_connector_woocommerce_flag` field still stores the reason for compatibility with older integrations.

Draft orders use the current checkout result from the 30-minute cache or a new check. Their preliminary decision and reason are replaced when checkout saves the result; an unavailable check clears obsolete draft annotations.

After an order is placed, payment retries use its saved decision even after the checkout cache expires. These orders retain their original reason. If an older order has no saved decision, a payment retry checks it and saves the result for subsequent attempts. Thank-you pages and empty carts do not start frontend checks.

The saved result includes fingerprints of both the checked customer data and the order data at the time of saving. This lets an unchanged order reuse its decision when WooCommerce has replaced a virtual cart's shipping address with billing data. Checkout submissions still match against the checked customer data; later order changes do not update either fingerprint without a matching check.

### Customer notification

When enabled, the plugin sends a WooCommerce email notification to the customer's billing email address when an order enters the `Rendelést nem vette át` status. The notification is disabled by default and can be enabled and customized under **WooCommerce > Settings > Emails > Át nem vett csomag**.

### Privacy implications

To perform customer reputation checks and record delivery outcomes, the plugin sends customer and delivery data to the ProteCOD service over HTTPS. Depending on the data available during checkout or on the order, this may include:
* E-mail address
* Phone number
* Country code and postal code
* Delivery address
* Order ID and delivery outcome when reporting a completed or rejected delivery

These data are processed in accordance with the applicable ProteCOD privacy and GDPR documentation. Store owners are responsible for informing their customers about this data processing and ensuring that they have an appropriate legal basis for using the service.
* In order to use our services, you MUST notify your users that "Automated individual decision-making" might be applied during checkout. For more information, please see GDPR Art. 22.

> Note: this is not a legal advice. Consult your attourney before using this service in production.
