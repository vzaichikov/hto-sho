# Silpo MCP Tool Inventory

## Snapshot

This inventory was retrieved read-only through authenticated MCP `tools/list` on 2026-08-22.

- Endpoint: `https://mcp.silpo.ua/mcp`
- Transport: streamable HTTP
- Server: `silpo-mcp-service` `1.108.0`
- Protocol version: `2025-11-25`
- Advertised capability: tools with `listChanged: true`
- Discovered tools: 39

Because the server advertises that its tool list can change, always perform live discovery before implementing or executing a flow. Treat the names and schemas below as a useful baseline, not a permanent API lockfile. Never put OAuth tokens or returned account data into documentation.

## Annotation Legend

- `R`: read-only, non-destructive, idempotent.
- `W`: account/cart mutation that the server does not mark destructive.
- `D`: destructive cart mutation.
- `NI`: the server marks the mutation non-idempotent; do not retry without reading current state.

## Cart and Fulfilment

| Tool | Mode | Purpose | Required input |
| --- | --- | --- | --- |
| `silpo_get_my_shopping_cart` | R | Get the authenticated user's current cart ID; the server says to start here. | none |
| `silpo_get_shopping_cart_by_id` | R | Read products, delivery settings, totals, validations, loyalty, and checkout links. | `shoppingCartId` |
| `silpo_add_or_update_cart_products` | W, NI | Add products or change their quantities. | `shoppingCartId`, `products` |
| `silpo_remove_cart_products` | D | Remove identified product lines. | `shoppingCartId`, `products` |
| `silpo_clear_shopping_cart` | D | Remove every product from the current cart. | `shoppingCartId` |
| `silpo_update_shopping_cart` | W | Change delivery/timeslot/address/shipment settings and optional cart preferences. | `shoppingCartId`, `deliveryType`, `timeslot`, `address`, `shipments` |
| `silpo_get_time_slots` | R | Get available UTC delivery slots for a branch. | `branchId` |
| `silpo_find_address` | R | Resolve an address to coordinates. | `address` |
| `silpo_get_available_delivery_types` | R | Get delivery types and branch information for coordinates. | `latitude`, `longitude` |
| `silpo_list_branches` | R | List branches, including pickup and Nova Poshta-capable branches. | none |
| `silpo_find_nova_poshta_settlements` | R | Find Nova Poshta delivery settlements. | `title` |
| `silpo_find_nova_poshta_offices` | R | Find Nova Poshta offices or parcel lockers. | `settlementId` |

## Catalog and Product Matching

| Tool | Mode | Purpose | Required input |
| --- | --- | --- | --- |
| `silpo_find_products_batch` | R | Search up to 30 product names or exact numeric article codes. | `branchId`, `deliveryType`, `timeslotStart`, `timeslotEnd`, `products` |
| `silpo_get_products` | R | Browse products by category, promotion, set, stock, price, and sort filters. | `branchId`, `deliveryType`, `timeslotStart`, `timeslotEnd` plus at least one browse filter |
| `silpo_get_product_details` | R | Read product details, attributes, nutrition, images, package ratio, and current branch data. | `branchId`, discovered `slug`, `deliveryType`, `timeslotStart`, `timeslotEnd` |
| `silpo_get_similar_products` | R | Find similar candidates for a known product. | `branchId`, discovered `slug` |
| `silpo_get_replacements` | R | Find replacements for unavailable product IDs. | `branchId`, `companyId`, `productIds`, `deliveryType` |
| `silpo_get_promotions` | R | List active branch promotions and codes. | `branchId`, `deliveryType`, `timeslotStart`, `timeslotEnd` |
| `silpo_get_popular_categories` | R | List popular categories for a branch. | `branchId`, `deliveryType` |
| `silpo_get_category` | R | Read one category and its children. | `branchId`, `deliveryType`, `categorySlug` |
| `silpo_get_categories` | R | List or page branch categories. | `branchId` |
| `silpo_get_categories_tree` | R | Read the full category hierarchy for a branch and slot. | `branchId`, `deliveryType`, `timeslotStart`, `timeslotEnd` |
| `silpo_get_product_sets` | R | List curated collections whose slug can filter `silpo_get_products`. | `branchId` |

## Account Context and Purchase History

| Tool | Mode | Purpose | Required input |
| --- | --- | --- | --- |
| `silpo_get_my_profile` | R | Get the authenticated user's name, phone, email, and birthday. | none |
| `silpo_get_my_food_restrictions` | R | Get the authenticated user's saved food restrictions. | none |
| `silpo_get_my_family` | R | Get saved household members, children, and pets. | none |
| `silpo_get_my_delivery_addresses` | R | Get saved delivery addresses. | none |
| `silpo_get_my_online_orders` | R | Read online order history and product identifiers. | none |
| `silpo_get_my_offline_orders` | R | Read loyalty-linked store receipts and rematch products to the current catalog. | `branchId`, `deliveryType`, `timeslotStart`, `timeslotEnd` |
| `silpo_get_my_favorites` | R | Read favorite products for the current branch/slot. | `branchId`, `deliveryType`, `timeslotStart` |
| `silpo_add_or_update_favorite_products` | W, NI | Add or remove up to five favorite products. | `actions` |
| `silpo_get_loyalty_info` | R | Read loyalty-card information and balance. | none |
| `silpo_get_my_coupons` | R | List available coupons. | none |
| `silpo_get_coupon_details` | R | Read one coupon from the coupon list. | `businessCouponId` |
| `silpo_get_my_promos` | R | List personal offers available for selection. | none |
| `silpo_get_promo_codes` | R | List the authenticated user's promo codes. | none |
| `silpo_get_my_certificates` | R | List gift certificates, including sensitive redemption fields. | none |
| `silpo_add_or_update_certificates` | W, NI | Add or remove certificates on the cart. | `shoppingCartId` |
| `silpo_get_my_premium_subscription` | R | Read Плюхс status, benefits, balances, and links. | none |

## Core Хто Шо? Flow

1. Before any cart read, show the reset confirmation. After consent, call `silpo_get_my_shopping_cart`, then `silpo_get_shopping_cart_by_id`, and persist the complete response encrypted for the event.
2. Call `silpo_clear_shopping_cart`, then immediately call `silpo_get_shopping_cart_by_id`; continue only when every `cart.shipments[].products` list is empty.
3. Require a fresh place, store, and time selection. Call `silpo_update_shopping_cart` once for the selected route, then verify exact route and empty-product readback.
4. Search the generic event needs with `silpo_find_products_batch`. Use an exact `externalProductId` when already known; otherwise use names. One call accepts at most 30 search terms.
5. Use `displayRatio` for package contents and `step` for allowed quantity increments. Respect `stock`; never add more than is available.
6. Use `silpo_get_product_details` when attributes or nutrition can validate a choice. A title alone is not evidence that an item is safe for an allergy or dietary restriction.
7. Show the proposed SKU mapping, quantities, substitutions, warnings, and estimated total before cart mutation.
8. Re-read the current cart immediately before mutation. Reject any foreign line, then use an absolute quantity update (`addQuantity: false`) for the approved staged set.
9. Call `silpo_add_or_update_cart_products`, then immediately call `silpo_get_shopping_cart_by_id`. Do not mark synchronization successful until required lines, quantities, validations, and `calculation.totalAfterDiscounts` are verified.

The snapshot exposes the authenticated user's current cart but no separate create-new-cart tool. It also exposes checkout links in cart details but no checkout, order-placement, or payment tool.

## Core Input Shapes

These are condensed from the live JSON Schemas. Fetch the live schemas before constructing calls.

### Product search

`silpo_find_products_batch` requires:

```json
{
  "branchId": "uuid",
  "deliveryType": "DeliveryHome",
  "timeslotStart": "ISO timestamp",
  "timeslotEnd": "ISO timestamp",
  "products": ["вода", "хліб", "795319"],
  "limit": 30
}
```

`deliveryType` is an enum in the live schema. Copy the value and time slot from the current cart rather than inventing them.

### Add or set quantities

`silpo_add_or_update_cart_products` requires a cart ID and one or more product objects:

```json
{
  "shoppingCartId": "uuid",
  "products": [
    {
      "productId": "uuid from product search",
      "companyId": "uuid from product search",
      "branchId": "uuid from product search",
      "quantity": 2,
      "addQuantity": false,
      "comment": "optional special instruction"
    }
  ]
}
```

`productId`, `companyId`, `branchId`, and a positive `quantity` are required. `addQuantity: true` increments existing quantity; `false` replaces it. The server marks the tool non-idempotent even though absolute replacement can make an individual line converge, so always verify and re-read before retrying.

### Remove identified lines

`silpo_remove_cart_products` requires:

```json
{
  "shoppingCartId": "uuid",
  "products": [
    {"productId": "uuid from the current cart"}
  ]
}
```

`silpo_clear_shopping_cart` is mandatory only at the explicit reset gate that starts a new run. Save the encrypted backup before calling it and require the immediate empty readback. Do not call it from product commit or from a replayed token after non-empty contents have changed.

### Change delivery settings

`silpo_update_shopping_cart` requires `shoppingCartId`, `deliveryType`, `timeslot`, `address`, and `shipments`. The live tool requires copying the existing address and shipment objects from `silpo_get_shopping_cart_by_id` for ordinary delivery changes. Optional fields can change branch, product-change/contact preferences, adult confirmation, promo code, or requested Балабонуси.

Default event-cart synchronization must not change delivery settings, promo codes, certificates, bonuses, favorites, or account preferences. Those are separate user-authorized actions.

## Хто Шо?-specific Interpretations

- `silpo_get_my_food_restrictions` describes only the authenticated account owner. It does not replace restrictions extracted for other event participants.
- `silpo_get_my_family` describes a saved household, not the event guest list.
- Favorites and order history may improve matching, but they are preferences or precedent, not proof that a product is safe for the whole group.
- The live batch-search description says to spend as close to a stated budget as possible. For Хто Шо?, sensible event quantities and safety come first: treat budget as a ceiling unless the user explicitly asks to maximize spend.
- Ignore plastic shopping bags during event-product synchronization, matching the live cart-tool instructions.
- A certificate, promo code, or Балабонуси changes account/cart value and requires separate user intent; do not apply it merely because it is available.
