# Menu Pop-up

A complete restaurant toolkit for WordPress: an interactive menu with video pop-ups, online ordering (delivery, pickup and dine-in by QR code), Egyptian payment methods, a live order dashboard, and a table reservation system with a live floor plan.

**Version:** 7.5.1 · **License:** GPL v2 or later · **Author:** Ahmed Ali

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Shortcodes](#shortcodes)
  - [`[menu-popup]`: the menu](#menu-popup--the-menu)
  - [`[menu-popup-featured]`: featured items slider](#menu-popup-featured--featured-items-slider)
  - [`[menu_popup_reservation]`: table booking form](#menu_popup_reservation--table-booking-form)
- [URL parameters](#url-parameters)
- [Ordering](#ordering)
- [Order from the table (QR)](#order-from-the-table-qr)
- [Payments](#payments)
- [Delivery](#delivery)
- [Reservations](#reservations)
- [Admin screens](#admin-screens)
- [Settings reference](#settings-reference)
- [POS integration](#pos-integration)
- [Languages](#languages)
- [Security notes](#security-notes)
- [Developer hooks](#developer-hooks)
- [Uninstall](#uninstall)
- [Changelog](#changelog)

---

## Features

**Menu**
- Menu items with image, gallery, video, description and price
- Sizes / variations, each with its own price
- Arabic and English content for every item (name, description, video, button labels)
- Categories and multiple menus (for example: Food, Drinks, Desserts)
- Menus can be linked to specific branches
- Video pop-up per item, with optional autoplay
- Featured items slider
- Optional search box, "Show more" button and category filter bar
- Voice search and spoken item names (browser speech or Google Cloud TTS / Speech-to-Text)

**Ordering**
- Online ordering with a cart
- Order types: delivery, pickup, and dine-in (from a table QR code)
- WhatsApp ordering as an extra channel
- Prices and totals are always calculated on the server, never trusted from the browser
- Order status page for the customer with a private link
- Live order dashboard for staff (New → Confirmed → Preparing → Ready → Completed), with sound alerts

**Payments**
- Cash on delivery / at pickup
- InstaPay, Vodafone Cash, Orange Cash, e& Cash, WE Pay and bank transfer, each with a reference number and a receipt screenshot that staff confirm
- Fawry (online gateway, see [Payments](#payments) for its current status)

**Branches and delivery**
- Multiple branches, grouped by city or area
- Optional forced branch selection before the menu shows
- Delivery zones: drawn on a map (Google Maps) or entered manually, with a fee per zone

**Dine-in by QR code**
- One signed QR code per table, ready to print
- Customers order from their phone; the waiter confirms before it reaches the kitchen
- "Call waiter" and "Ask for the bill" buttons
- The bill can be settled inside the plugin or on your own POS

**Reservations**
- Four-step booking form: branch and party size → date and time → table → contact details
- Tables with capacity, shape, area (indoor, terrace, VIP …) and branch
- Opening-hour rules and blocked dates per branch
- Double-booking protection, including two customers booking the same table at the same moment
- Live floor plan for staff with quick actions (confirm, seat, complete, no-show, cancel)

**Business tools**
- Business day open / close
- Accounting and reports with export
- Import tool for menu items
- Optional POS forwarding (GloriaFood or a custom API)

---

## Requirements

| | |
|---|---|
| WordPress | A recent version (6.x recommended) |
| PHP | 7.4 or newer (8.x recommended) |
| MySQL / MariaDB | Any version supported by your WordPress |
| Optional | Google Maps API key (map-based delivery zones) |
| Optional | Google Cloud API keys (external text-to-speech and speech-to-text) |
| Optional | Fawry merchant account |

---

## Installation

1. Download or clone this repository.
2. Copy the plugin folder into `wp-content/plugins/` and name it **`menu-popup`**. Keep this exact folder name, because updates and saved settings depend on it.
3. In WordPress go to **Plugins** and activate **Menu Pop-up**.
4. A new **Menu Pop-up** item appears in the admin sidebar.

```bash
cd wp-content/plugins
git clone https://github.com/Ahmed123sa/Menu-PopUp-Plugin.git menu-popup
```

---

## Quick start

1. **Menu Pop-up → Settings → General**: set the currency, tax rate and WhatsApp number.
2. **Menu Pop-up → Categories**: add your categories (Starters, Mains, Drinks …).
3. **Menu Pop-up → Menus** (optional): group categories into menus and link them to branches.
4. **Menu Pop-up → Add New Item**: add items with price, image and video.
5. Create a page and add:

   ```
   [menu-popup]
   ```

6. To take orders, open **Settings → Ordering** and turn on online ordering.
7. To take reservations, add tables under **Reservations** and put this shortcode on a page:

   ```
   [menu_popup_reservation]
   ```

---

## Shortcodes

The plugin has three shortcodes.

| Shortcode | What it shows |
|---|---|
| `[menu-popup]` | The full interactive menu with cart and ordering |
| `[menu-popup-featured]` | A slider of items marked as featured |
| `[menu_popup_reservation]` | The table reservation form |

> **Note:** the first two use a **hyphen** (`menu-popup`), the reservation shortcode uses **underscores** (`menu_popup_reservation`).

---

### `[menu-popup]`: the menu

Shows menu items with the category bar, video pop-ups, cart and checkout.

#### Attributes

| Attribute | Default | Values | Description |
|---|---|---|---|
| `menu` | `all` | menu slug | Show only one menu. Copy the slug from **Menu Pop-up → Menus**. |
| `category` | `all` | category slug | Show only one category. Copy the slug from **Menu Pop-up → Categories**. When used with `menu`, the category must belong to that menu. |
| `limit` | `-1` | number | Maximum number of items. `-1` means no limit. |
| `initial_limit` | `-1` | number | How many items to show before the "Show more" button. Only works with `show_more="true"`. |
| `show_more` | `false` | `true` / `false` | Show a "Show more" button. Needs `initial_limit` greater than 0. |
| `show_search` | `false` | `true` / `false` | Show a search box above the menu. |
| `all_filter` | `true` | `true` / `false` | Show the "All" button in the category bar. With `false`, the first category is selected when the page opens. |

#### Examples

Full menu, all categories:

```
[menu-popup]
```

One menu only:

```
[menu-popup menu="food-menu"]
```

One category inside a menu:

```
[menu-popup menu="food-menu" category="starters"]
```

One category from all menus:

```
[menu-popup category="drinks"]
```

Menu with a search box:

```
[menu-popup show_search="true"]
```

Show 8 items first, then a "Show more" button that reveals the rest:

```
[menu-popup show_more="true" initial_limit="8"]
```

Show 8 items first, and at most 20 in total after "Show more":

```
[menu-popup show_more="true" initial_limit="8" limit="20"]
```

Only the first 6 items, with no button:

```
[menu-popup limit="6"]
```

Category bar without the "All" button:

```
[menu-popup all_filter="false"]
```

Everything together:

```
[menu-popup menu="food-menu" show_search="true" show_more="true" initial_limit="12" all_filter="false"]
```

#### Behaviour notes

- If **Force Branch Selection** is on (**Settings → General**), visitors first pick a city/area and branch. The menu then opens with `?branch=<id>` in the address bar.
- If a menu is linked to certain branches and the visitor picks a branch that is not one of them, a "No menu for this branch" message is shown.
- A wrong `menu` slug shows a "Menu not found" message with a link to the Menus screen (useful while building the page).
- The shortcode renders nothing during AJAX, REST or POST requests. This is on purpose, so it never breaks JSON responses.
- The page that holds `[menu-popup]` is also the page that table QR codes open (see [Order from the table](#order-from-the-table-qr)).

---

### `[menu-popup-featured]`: featured items slider

Shows a slider of items that have **Featured** ticked on their edit screen. Clicking an item opens its video pop-up.

#### Attributes

| Attribute | Default | Values | Description |
|---|---|---|---|
| `limit` | `6` | number | Maximum number of featured items in the slider. |
| `autoplay` | `true` | `true` / `false` | Slide automatically. |
| `show_dots` | `true` | `true` / `false` | Show the dot navigation under the slider. |
| `show_arrows` | `true` | `true` / `false` | Show the previous / next arrows. |

#### Examples

Default slider (6 items, autoplay, dots and arrows):

```
[menu-popup-featured]
```

Ten items, no autoplay:

```
[menu-popup-featured limit="10" autoplay="false"]
```

Clean slider without navigation, for a hero area:

```
[menu-popup-featured show_dots="false" show_arrows="false"]
```

#### Behaviour notes

- Featured items come from all menus and categories. This shortcode has no `menu` or `category` attribute.
- If no item is marked as featured, a short message is shown instead of an empty slider.
- You can use it on the same page as `[menu-popup]`, for example the slider at the top and the full menu below it.

---

### `[menu_popup_reservation]`: table booking form

Shows the reservation form. It has **no attributes**; everything comes from your branches, tables and rules.

```
[menu_popup_reservation]
```

#### The four steps

1. **Start your reservation**: branch and party size.
2. **Choose date and time**: only time slots inside the branch opening hours are offered.
3. **Choose your table**: only free tables with enough seats are shown.
4. **Your details**: name, phone and special requests.

#### Behaviour notes

- Before saving, the server checks everything again: the time is not in the past, the table belongs to the branch and has enough seats, the slot is inside opening hours, and the date is not blocked.
- If someone else takes the same table first, the customer sees a clear message and the form goes back to the table or time step with fresh availability.
- New reservations start as **Pending** until staff confirm them.
- Submitting the same booking twice (same phone, table, date and time) does not create a duplicate.

---

## URL parameters

| Parameter | Used on | Effect |
|---|---|---|
| `?branch=<branch ID>` | Menu page | Opens the menu for that branch and skips branch selection. |
| `?lang=ar` / `?lang=en` | Menu page | Forces the display language. The choice is remembered in a cookie. |
| `?mp_table=<ID>&s=<signature>` | Any page | Link inside a table QR code. Starts a dine-in session and redirects to the menu page. Generated by the plugin; do not build it by hand. |
| `?mp_order=<ID>&k=<key>` | Any page | Customer order status page. The private link is given to the customer after ordering. |

---

## Ordering

Turn ordering on under **Settings → Ordering**.

- **Channels**: online ordering, WhatsApp ordering, or both.
- **Order types**: delivery (when delivery is on), pickup, and dine-in (only from a table QR code).
- **Customer fields**: choose which of name, phone, email, address and special instructions are required.
- **Server-side pricing**: the browser only sends item IDs, sizes and quantities. The server looks up prices, adds tax and the delivery fee, and saves the order. A changed price in the browser has no effect.
- **Order status page**: after ordering, the customer gets a private link to follow the order. Nobody without the link can open it.
- **Order dashboard** (**Menu Pop-up → Order Dashboard**):
  - **Live orders**: columns New, Confirmed, Preparing, Ready, with branch and order type filters and search
  - **Payments**: transfers waiting for a receipt check
  - **History**: completed, cancelled and expired orders
  - Sound alert for new orders and auto-refresh (interval in settings)

---

## Order from the table (QR)

Turn it on under **Settings → Ordering → Order from the Table (QR)**. A new **Tables & QR** screen then appears.

1. Add tables under **Reservations**. Tables are shared between reservations and QR ordering.
2. Open **Tables & QR** and print the QR cards (logo and table name, A4 sheet). You can also download each code as PNG.
3. The customer scans the code, the menu opens for that table's branch, and they order from their phone.
4. The order appears on the dashboard as **Dine-in · Table X**. The waiter presses **Confirm** before it goes to the kitchen.
5. The customer can press **Call waiter** or **Ask for the bill**. The table lights up on the Tables & QR screen and the live floor plan.

**Who handles the bill** (setting):

| Mode | Customer choices | Settlement |
|---|---|---|
| **Inside the plugin** (default) | Cash or card at the table, plus enabled transfers | Staff mark orders as paid (cash / card) per order or for the whole table, then close the table |
| **Outside (own POS)** | "Pay at the table", plus enabled transfers | Payment is taken on your POS; the plugin only closes the table |

- Each QR code is signed. Changing the table number in the link does not work.
- **Regenerate** on a table makes its old printed code stop working (for example if a card was taken).
- **Menu page for QR codes**: the page that holds `[menu-popup]`. If left empty, the plugin finds it automatically.

---

## Payments

Configure under **Settings → Payment**.

| Method | How it works |
|---|---|
| **Cash** | Cash on delivery or at pickup. |
| **InstaPay** | The customer sends the money, then enters the reference number and uploads a screenshot. The order waits until staff confirm on the **Payments** tab. An unpaid order expires after the deadline you set. |
| **Vodafone Cash, Orange Cash, e& Cash, WE Pay** | Same flow as InstaPay, each with its own wallet number. |
| **Bank transfer** | Same flow, with your bank details shown to the customer. |
| **Fawry** | Online gateway. **The full integration is not finished yet.** Without merchant credentials it refuses payments on a live site (a test response is only returned when `WP_DEBUG` is on). Keep it switched off until the integration is completed with your Fawry sandbox account. |

---

## Delivery

Configure under **Settings → Delivery**.

- **Delivery off**: customers can only choose pickup.
- **Manual zones mode**: you add zones (name and fee) per branch, and the customer picks a zone.
- **Map mode**: you draw zones on a Google Map and the customer drops a pin; the plugin finds the zone, branch and fee. This mode needs a **Google Maps API key** with the Maps JavaScript and Geocoding APIs enabled and billing active on the Google Cloud project.

---

## Reservations

**Menu Pop-up → Reservations**

- **Tables**: name, branch, minimum and maximum guests, shape and area.
- **Rules**: opening hours and blocked dates or areas per branch.
- **Professional Cards**: list of reservations with status filters, status changes and delete.
- **Live Floor Plan**: every table as a card, refreshed every 20 seconds.

**Reservation statuses**

`Pending` → `Confirmed` → `Seated` → `Completed`, or `Cancelled` / `No-show`.

**Live floor plan states**

| State | Meaning |
|---|---|
| Available | No guest now and no booking in the next 2 hours |
| Reserved | A booking starts within 2 hours (or the guest is late) |
| Occupied | Guests are seated (reservation marked **Seated**) or the table has open QR orders |

Alerts on a table card: guest late by 15 minutes or more, booking still not confirmed within an hour of its time, seated guests over their booked time, waiter called, bill requested.

Click a card to see the day's bookings for that table with phone numbers and notes, and to confirm, seat, complete, mark as no-show or cancel in one click.

A booking blocks its table for **90 minutes** by default (see [Developer hooks](#developer-hooks)).

---

## Admin screens

| Screen | Purpose |
|---|---|
| Dashboard | Overview and quick actions |
| All Items / Add New Item | Menu items |
| Categories | Categories and their slugs |
| Menus | Menus, their categories and branches, with ready shortcodes to copy |
| Branches | Branch details |
| Cities/Areas | Groups branches for the branch picker |
| Order Dashboard | Live orders, payments to check, history |
| Tables & QR | Open tables, bills, waiter calls, QR printing (when QR ordering is on) |
| Reservations | Booking list and live floor plan |
| Accounting | Business day, daily summary, last 7 days, export, reset (password protected) |
| Import Items | Import menu items |
| Settings | All plugin settings, in tabs |

---

## Settings reference

| Tab | Settings |
|---|---|
| **General** | WhatsApp number, currency symbol, tax rate, minimum order amount, city/area selection, force branch selection |
| **Display** | Video pop-ups and autoplay, animation speed, prices on cards and in the video pop-up, Order / WhatsApp buttons in the video pop-up, layout |
| **Ordering** | Ordering channels, order from the table (QR), customer fields, order dashboard auto-refresh |
| **Delivery** | Delivery on/off, zones mode (manual or map), Google Maps API key, default map center |
| **Voice** | Voice search, pronunciation on hover (desktop and mobile), confirmation dialogs, external TTS, Google Cloud TTS and Speech-to-Text keys, audio caching, Arabic and English voices |
| **Appearance** | Main colors, hero section (title, subtitle, colors, sizes), cart colors and icon sizes, order confirmation pop-up colors |
| **Payment** | Cash, transfers (InstaPay and wallets), bank transfer, Fawry, payment deadline |

---

## POS integration

When enabled, every new order is also sent to an external system.

- **GloriaFood**
- **Custom API**: your own endpoint receives the order as JSON.

Failed sends are logged, and staff can resend an order from the admin. The integration does not change how orders are created or priced in the plugin.

---

## Languages

- Every menu item can have Arabic and English content.
- The display language is chosen in this order: `?lang=` in the URL, the visitor's saved choice, then WPML or Polylang if installed.
- Arabic switches the layout to right-to-left.
- Admin text uses the `menu-popup` text domain and can be translated with a tool such as Loco Translate (files go in `/languages`).

---

## Security notes

- All admin actions check a nonce **and** the `manage_options` capability.
- Order prices are recalculated on the server.
- QR table links are signed (HMAC) and can be regenerated per table.
- Order status links contain a private key.
- Reservations use a database lock to stop double booking at the same moment.
- Payment gateway callbacks are rejected unless they carry a valid signature.
- Debug logs (reservations) are written only when `WP_DEBUG` is on and can only be read by administrators. Turn `WP_DEBUG` off on a live site.

---

## Developer hooks

| Hook | Type | Default | Description |
|---|---|---|---|
| `menu_popup_reservation_duration` | filter | `90` | How many minutes one booking blocks its table (30–480). |
| `menu_popup_live_reserved_window` | filter | `120` | Minutes before a booking at which the live floor plan shows the table as Reserved. |
| `menu_popup_send_to_pos` | action | – | Fired with the order data when an order should go to the POS. |

Example: make every booking block the table for two hours.

```php
add_filter( 'menu_popup_reservation_duration', function () {
    return 120;
} );
```

---

## Uninstall

Deleting the plugin **keeps all data**: menu items, orders, reservations and settings stay in the database, so reinstalling restores everything.

---

## Changelog

**7.5.1**
- New live floor plan: table cards, side panel with quick actions, accurate statuses, alerts

**7.5.0**
- Security hardening: payment callback signatures, admin capability checks, removal of an old public order endpoint, no fake payment responses on live sites

**7.4.2**
- Reservations: double-booking protection with a final locked check, server-side validation, safe restore of cancelled bookings

**7.4.1**
- Order from the table by QR code: signed codes, waiter confirmation, call waiter / ask for the bill, settle inside the plugin or on your own POS

**7.4.0**
- InstaPay and mobile wallet payments with receipt checking
- Customer order status page
- New order dashboard
- Settings split into tabs

**7.3.0**
- Delivery by map with branch and fee resolution, plus manual zones mode

**7.2.0**
- Server-side order pricing and creation

**7.1.0**
- Initial public version

---

## License

GPL v2 or later. See <https://www.gnu.org/licenses/gpl-2.0.html>.
