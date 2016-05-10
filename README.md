# Jigoshop Followup Emails
A Jigoshop extension for sending automated followup emails after orders

Tested with Wordpress version **4.5.2** and Jigoshop version **1.17.15** (most likely won't be compatible with the upcoming 2.0 Jigoshop version).

### Setup
After [installing and activating the plugin](https://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation)
go to "Jigoshop >> Settings >> General" tab and set the following options:

![admin-screenshot](https://cloud.githubusercontent.com/assets/4682432/15158403/aa5c1c92-16e7-11e6-8800-dc546bb63a94.png)

### How it works
When an order made a `Eztettem_Followup_Email::create_followup_attribute` attaches some extra attribute to the order: an emailing state and optionally a coupon code.

The plugin might send out different emails to the customer, and the emailing state attribute will control which one. For the possible values of this attribute and their description see the comment section of the former method.

If the net value of the order (e.g. without taxes) is over the *Cart value threshold* admin option and the coupon creation is not turned off by setting the *Coupon validity interval* to 0, a new coupon is automatically generated. Its code is also attached to the order for later use.

The coupon itself is of a "Cart Discount" type with a single use limit valid for "*Coupon validity interval*" months from the moment of the order completion and with the value of the given *Cart value percentage*.

For these orders having these attributes a cron process is executed once in a day and by following to the logic in `Eztettem_Followup_Email::send_emails` method it sends emails to the former buyers.

For a given order it first sends a followup email in a few days after the order (specified by *Email after order* option) to keep the awareness of the webshop and optionally to remind the customer of the unused coupon. This email is not sent if the coupon is already used, because in this case a new order was already made, so there's no need for any reminder.

For the same order a second email is sent if there was a coupon created and it will soon expire. "Soon" is specified by the *Email before expiry* option.

The plugin itself doesn't ship with any predefined email templates but provides three extra email variables:

* `followup_coupon`: Prints the coupon code if there is any coupon attached to the order. Has an empty value otherwise, so it can be used for conditional blocks as well.
* `followup_value`: The value of the coupon as a number (in the currency of the shop, but the currency sign has to be separately written to the email template).
* `followup_expiry`: The coupon expiry date formatted with the general WordPress date format set for the website.

The necessary email templates can be created in "Jigoshop >> Emails". Even the *Completed order customer notification* email template can be modified to include the information of the coupon.

Example template text:
```
[followup_coupon]
  <p>Also we created a discount coupon for you with value €[followup_value]
  that you can use with your next purchase up until [followup_expiry].
  The coupon code is: <strong>[value]</strong>.</p>
[/followup_coupon]
```
