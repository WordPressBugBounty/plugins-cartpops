=== CartPops – WooCommerce Side Cart, Cart Drawer & Cart Popup ===
Contributors: cartpops, freemius
Tags: side cart, cart drawer, floating cart, woocommerce side cart, add to cart popup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A fast WooCommerce side cart and cart drawer with coupons and upsells. Pro adds a free shipping bar, rewards, bundles and analytics.

Source code: Authored JavaScript, JSX and SCSS are bundled edition-by-edition in this plugin package.
Build instructions: See BUILDING.md in the plugin root for the exact locked build commands.

== Description ==

CartPops adds a slide-out side cart to your WooCommerce store, so shoppers no longer have to visit the cart page. Shoppers add a product, see their cart slide in, change quantities, apply a coupon and head straight to checkout, all without a page reload.

The drawer is light: no jQuery, and its styles and scripts only load where they are needed. It works with block themes, classic themes and the popular page builders.

= What you get for free =

* **Slide-out side cart.** The cart drawer opens as an add to cart popup when a product is added, including from the product page, or only when the shopper clicks the cart. Quantities, removals, totals and coupons update in place.
* **Floating cart launcher.** A floating cart icon with a live item count, like a mini cart that is always in reach. Place it anywhere with the Cart Launcher block or the `[cartpops_launcher]` shortcode, or let it appear automatically.
* **Upsells and cross-sells.** Suggest products inside the drawer from your cross-sells, upsells or related products, with a random fallback so the section is never empty.
* **Coupon form.** Shoppers apply and remove coupons right in the drawer. On small phone screens it folds into a "Have a coupon?" link.
* **Design that matches your store.** Start from a preset, then fine-tune 24+ colors, separate dark mode colors, button text and layout with a live preview. Add your own CSS when you need it.
* **Blocks and classic themes.** Cart Drawer and Cart Launcher blocks for the Site Editor, and automatic rendering on classic themes.
* **Settings import and export.** Copy your setup from a staging site to your live store in a few clicks.

= What Pro adds =

[CartPops Pro](https://cartpops.com/pricing) turns the drawer into a place where orders grow.

* **Free shipping bar and rewards.** A progress bar shows how close the shopper is to free shipping, read straight from your WooCommerce shipping zones. Add reward tiers that unlock a discount or a free gift as the cart grows.
* **Shipping calculator.** Shoppers can estimate shipping inside the drawer.
* **Bundle builder.** Offer "frequently bought together" products with an automatic bundle discount.
* **Smart add-ons.** Offer extras such as shipping protection, gift wrapping or priority handling, added to the order as a fee.
* **Smart Bar.** Show messages, a cart expiry timer or a product spotlight at the top of the drawer, based on cart total, products or categories.
* **Hand-picked recommendations.** Choose exactly which products to recommend, in your own order.
* **Conversion analytics.** See what the drawer, bundles, add-ons and Smart Bar add to your revenue.
* **Second drawer button.** Add a "Continue shopping", "View cart" or custom link next to Checkout.
* **Elementor cart widget.** Place the launcher with Elementor.

Every Pro plan includes every Pro feature. Plans differ only in the number of sites.

= Works with your setup =

CartPops is tested with the WooCommerce Cart and Checkout blocks and supports High-Performance Order Storage (HPOS). It works alongside themes and builders such as Storefront, Astra, Kadence, Blocksy, GeneratePress, Flatsome, Divi, Elementor and Bricks, and translation plugins such as WPML, Polylang and TranslatePress.

= For developers =

CartPops has PHP hooks and filters for its drawer markup, a `cartpops:open` JavaScript event, and a REST API for its settings. See the [developer docs](https://docs.cartpops.com) for the full list.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. In WordPress, go to Plugins → Add New, search for "CartPops", then install and activate it.
3. Go to WooCommerce → CartPops to set up the drawer, launcher and recommendations.
4. On a classic theme, the drawer and launcher appear automatically. On a block theme, add the Cart Drawer and Cart Launcher blocks in the Site Editor.

To upgrade to Pro, install the CartPops Pro plugin from your purchase email and activate your license.

== Frequently Asked Questions ==

= Does CartPops require WooCommerce? =

Yes. CartPops needs WooCommerce 9.0 or newer, WordPress 6.5 or newer and PHP 8.1 or newer.

= Does it work with my theme? =

CartPops works with block themes and classic themes. On classic themes the drawer and launcher appear automatically; on block themes you add them with blocks. If something looks off, you can adjust it with custom CSS or contact support.

= Does it work with the WooCommerce Cart and Checkout blocks? =

Yes. The drawer stays in sync with block-based carts and checkout.

= Will CartPops slow down my store? =

The drawer does not need jQuery, and its files load only on pages where the drawer can appear. Cart changes happen in place, without reloading the page.

= Can shoppers still use the regular cart page? =

Yes. The cart page keeps working. You can choose whether adding a product opens the drawer, and Pro lets you add a "View cart" button to the drawer.

= Is CartPops compatible with High-Performance Order Storage (HPOS)? =

Yes.

= What is the difference between Free and Pro? =

Free gives you the full cart drawer, launcher, coupons, design options and recommendations from your cross-sells and upsells. Pro adds the shipping and rewards meter, shipping calculator, bundles, add-ons, Smart Bar, hand-picked recommendations and analytics. See [cartpops.com/pricing](https://cartpops.com/pricing).

= What happens to my settings if I deactivate or delete CartPops? =

They are kept. Deactivating or deleting the plugin keeps your settings, analytics and license, so reinstalling picks up where you left off. To remove all CartPops data when you delete the plugin, first add `define( 'CARTPOPS_ALLOW_PERMANENT_DATA_PURGE', true );` to your `wp-config.php` file.

= I'm upgrading from CartPops 1.x. What changes? =

Your settings, styling and license carry over. Version 2 is built around the cart drawer: the old popup and top bar cart layouts are gone, and the Smart Bar now lives inside the drawer (Pro). Version 1 automation rules and custom JavaScript no longer run. Back up your site and try the update on a staging copy first.

== Screenshots ==

1. The slide-out cart drawer, launcher and added-to-cart notice.
2. Design presets with live preview, plus product recommendations in the drawer.
3. Pro cart rewards for free shipping, free gifts and discounts, with bundles.
4. Pro Smart Bar rules and Smart Add-ons such as shipping protection and gift wrapping.

== Changelog ==

= 2.0.1 =
* Fix: Cart buttons and theme code built for CartPops 1.x open the cart drawer again, including links with the `cpops-toggle-drawer` class. This older method still works but is deprecated; your browser console shows a notice with the replacement.
* Fix: Some stores updating from CartPops 1.x stayed paused after the update, with no CartPops menu. CartPops now finishes the update by itself and keeps your original settings; Pro stores need an active license.
* Improved: the WordPress.org plugin page now describes everything CartPops 2.0 does, and the settings location is corrected to WooCommerce → CartPops.

= 2.0.0 =
* Important: CartPops 2.0 needs PHP 8.1 or newer, WordPress 6.5 or newer and WooCommerce 9.0 or newer. Back up your site before updating from version 1.
* Important: updating keeps your settings, styling and license. Settings that version 2 can no longer use are kept in your database, so nothing is lost.
* Important: custom JavaScript added in version 1 no longer runs. If your site has some, CartPops pauses and asks a site administrator to review it in the WordPress dashboard before it continues.
* New: a rebuilt slide-out cart drawer, plus Cart Drawer and Cart Launcher blocks for the Site Editor.
* New: adding to cart on a product page opens the cart drawer without reloading the page. You can turn this off in CartPops → Advanced.
* New: product recommendations inside the drawer, based on your cross-sells, upsells or related products.
* New in Pro: Free Shipping Meter block and reward tiers that add free gifts or discounts as the cart grows.
* New in Pro: bundle builder with automatic bundle discounts, and smart add-ons.
* New in Pro: Smart Bar in the drawer for notices, product spotlights and discounts.
* New in Pro: conversion analytics dashboard.
* Improved: choosing products and categories, setting up rewards, keyboard navigation and applying coupons.
* Improved: applying a design preset can be undone in one step, and resetting settings updates the editor straight away.
* Improved: works with WooCommerce High-Performance Order Storage and the Cart and Checkout blocks.
* Removed: the beta popup and top bar cart layouts, and the separate notification pop-up and top bar.
* Removed: automation rules from version 1 no longer run. They stay in your database but are not converted.
* Changed: in Pro, the free shipping meter is a horizontal bar; the circular style is gone.
* Changed: deactivating or deleting CartPops keeps your settings and data.

= 1.5.45 =
* Earlier releases: see https://docs.cartpops.com/changelog

== Upgrade Notice ==

= 2.0.0 =
CartPops 2.0 needs PHP 8.1+, WordPress 6.5+ and WooCommerce 9.0+. Back up first. Settings and license carry over; version 1 popup and top bar layouts, automation rules and custom JavaScript no longer run.
