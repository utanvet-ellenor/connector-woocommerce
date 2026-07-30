=== Utánvét Ellenőr ===
Contributors: ottoradics
Tags: cash on delivery,check,filter,utánvét,ellenőr,büntetés,utánvét ellenőr,szűrés
Tested up to: 7.0.2
Requires PHP: 8.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Connect your WooCommerce site to our SaaS available at https://utanvet-ellenor.hu
[Register](https://utanvet-ellenor.hu/register) to obtain API keys.

== Description ==

### Installation
[Register](https://utanvet-ellenor.hu/register) to obtain API keys, then set them in the plugin's settings. The reputation threshold parameter is there to let you customize how strict should the filtering be. This is a number between -1 and +1.

### What is Utánvét Ellenőr?

It is a SaaS provided by Utánvét Ellenőr Kft. from Hungary: a service which will let shop owners filter orders with Cash on Delivery coming from known fraudulent e-mail addresses.

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

### Customer notification

When enabled, the plugin sends a WooCommerce email notification to the customer's billing email address when an order enters the `Rendelést nem vette át` status. The notification is disabled by default and can be enabled and customized under **WooCommerce > Settings > Emails > Át nem vett csomag**.

### Privacy implications

To perform customer reputation checks and record delivery outcomes, the plugin sends customer and delivery data to the Utánvét Ellenőr service over HTTPS. Depending on the data available during checkout or on the order, this may include:
* E-mail address
* Phone number
* Country code and postal code
* Delivery address
* Order ID and delivery outcome when reporting a completed or rejected delivery

These data are processed in accordance with the applicable Utánvét Ellenőr privacy and GDPR documentation. Store owners are responsible for informing their customers about this data processing and ensuring that they have an appropriate legal basis for using the service.
* In order to use our services, you MUST notify your users that "Automated individual decision-making" might be applied during checkout. For more information, please see GDPR Art. 22.

> Note: this is not a legal advice. Consult your attourney before using this service in production.
