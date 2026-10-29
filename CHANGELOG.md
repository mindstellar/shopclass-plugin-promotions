# Changelog

## 0.1.1

### Changed
- Cart and order hooks use core's typed events; a refused checkout uses `CheckoutStarting::refuse()`.

## 0.1.0

### New
- Coupon codes: a percentage, a fixed amount or free delivery, with an optional cap.
- Automatic discounts that apply without a code and add up with one code.
- A first-order offer for signed-in shoppers.
- Minimum order, start and end dates, uses in all and uses per shopper.
- Clear refusals under the code field at checkout; a refused code blocks the order.
- Uses counted at placement without races, given back when an order is cancelled, fails or is refunded.
- Offers and coupons screen under Billing, with usage counts and Reset uses.
- First-order banner text for themes through `promotions_banner()`.
