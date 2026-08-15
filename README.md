# Woo Checkout Donation + Fee

Adds a donation selector and a voluntary "cover the payment processing fee"
checkbox to the WooCommerce checkout.

Built and maintained by [Biscuit Studios](https://biscuitstudios.com/) for our
own client sites. Published because it may be useful to others, not because it
is a supported product. See [Support](#support).

## What it does

Two things, in one checkout block:

1. **A donation selector,** so a customer can add a gift to their order.
2. **A processing-fee checkbox,** so they can cover the card processing cost.

The fee is **grossed up** across the whole basket including any donation, not
simply added. If the processor takes 2.9% plus 30 cents, the amount charged is
calculated so that what lands in the account afterwards is the full intended
total, donation included, rather than that total minus fees.

Both are off by default. Nothing changes until the customer chooses.

## Requirements

- WordPress 6.3 or later
- WooCommerce
- PHP 8.2 or later
- **The classic shortcode checkout.** See below.

## Classic checkout only

This plugin hooks the classic `[woocommerce_checkout]` shortcode checkout. It
does **not** support the newer checkout block.

That is a deliberate scope decision rather than an oversight. The block checkout
needs a different extension mechanism, and the sites this was written for all
run the classic checkout.

## Installation

Download the zip from
[Releases](https://github.com/biscuitstudios/woo-donation-cover-processing-fee/releases),
then **Plugins → Add New → Upload Plugin**.

## Do not run this alongside its sibling

There is a companion plugin, Woo Cover Processing Fee, which is the same fee
logic without the donation selector.

**Activating both on one site is a fatal error.** They share the
`WOO_COVER_FEE_*` constant prefix and the `WOO_Cover_Fee_Admin` /
`WOO_Cover_Fee_Settings` class names, and the class declarations are unguarded,
so the second one to load raises "Cannot declare class". This is by design: they
are two variants of the same plugin and were never meant to run together. Pick
one per site.

## Support

None, in the usual sense. This is published as-is, and we make changes when our
own client work calls for them.

You are welcome to fork it. If you find a genuine security problem, please
report it privately using the **Security** tab on this repository rather than
opening it in public.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
