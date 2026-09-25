# Our Coffee Shop

A web-based ordering system with SMS order status notification, built for
"Our Coffee" Shop in Mandaluyong City.

Two separate websites over one shared database:

- **Customer site** &ndash; browse the menu, customise a drink, check out for pickup or
  delivery, and follow the order without signing in.
- **Admin site** &ndash; the order queue, menu and inventory management, sales analytics,
  an audit trail, and shop settings.

They are kept separate on purpose, which is what was agreed in the discovery meeting:
a problem on the public site cannot reach the admin screens.

---

## What you need

| | |
|---|---|
| XAMPP | PHP 8.1 or newer, Apache, MySQL/MariaDB |
| Browser | Any current Chrome, Edge, Firefox or Safari |
| Semaphore account | Only for real SMS. The system runs fine without one. |

There is nothing to compile and nothing to install with Composer or npm. Copy the
folder, import the database, and it runs.

---

## Setting it up

### 1. Start XAMPP

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

### 2. Create the database

Open <http://localhost/phpmyadmin>, go to **Import**, and run these two files in order:

1. `database/schema.sql` &ndash; creates the `our_coffee_shop` database and its tables
2. `database/seed.sql` &ndash; adds the menu, inventory, settings and the owner account

Or from a terminal:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p our_coffee_shop < database/seed.sql
```

### 3. Point Apache at the project

Copy `docs/apache-ourcoffee.conf` to `C:/xampp/apache/conf/extra/httpd-ourcoffee.conf`,
then add this line to the end of `C:/xampp/apache/conf/httpd.conf`:

```apache
Include conf/extra/httpd-ourcoffee.conf
```

Edit the `OURCOFFEE_ROOT` line at the top of the copied file if the project does not
live at `C:/CLIENT PROJECTS/CoffeeShop`. Restart Apache.

This exposes only `customer-site`, `admin-site` and `assets`. The database credentials,
the shared code and the logs stay outside the web root where a browser cannot reach them.

### 4. Configure

```bash
cp .env.example .env
```

The defaults match a stock XAMPP install, so you usually do not need to change anything
to get started. `.env` is never committed.

### 5. Open it

| | |
|---|---|
| Customer site | <http://localhost/ourcoffee> |
| Admin site | <http://localhost/ourcoffee-admin> |

Sign in to the admin site with **`owner`** / **`OurCoffee2026!`**. It will make you set a
new password immediately. Do that before showing the system to anyone.

---

## SMS notifications

The system starts in **dry-run mode**. Messages are composed, written to
`storage/logs/sms.log`, and recorded in the `sms_log` table, but nothing is sent and no
credit is spent. Everything in the flow is testable this way, including the admin's SMS
history view.

To send real messages:

1. Create an account at [semaphore.co](https://semaphore.co) and top up.
2. Put your API key in `.env` as `SEMAPHORE_API_KEY`.
3. Set `SMS_DRY_RUN=false`.
4. In the admin site, open **Settings** and set your registered sender name.

### Keeping the cost down

SMS credits are the one running cost in this system, so a few things are built in:

- Each status has its own on/off switch under **Settings**. "Completed" is off by default.
- Every message is forced into the GSM-7 character set and kept under 160 characters, so
  it costs exactly one credit. Using the peso sign would push a message into UCS-2, where
  one credit covers only 70 characters, quietly doubling the bill.
- Every send is logged, so the actual spend is visible rather than a surprise.
- If credits run out, orders still go through. Customers can follow their order on the
  **Track Order** page using their reference and mobile number.

---

## How an order moves

```
pending  ->  preparing  ->  ready  ->  completed          (pickup)
pending  ->  preparing  ->  ready  ->  out_for_delivery  ->  completed   (delivery)
```

Any open order can be cancelled. The system refuses to skip steps or move backwards, so a
double-clicked button or a replayed form cannot corrupt the queue.

Stock is deducted when an order moves to **preparing**, and put back if it is cancelled
afterwards.

## What delivery does and does not do

Delivery is a simplified fulfilment option, as agreed with the team. The system records
the delivery request and shows the order status. The shop owner arranges the actual
delivery, either personally or by booking a courier.

Deliberately not included: GPS or live tracking, automatic rider assignment, in-app
navigation, delivery mapping, live rider status, third-party delivery platform
integration, and automatic distance-based fees. The delivery fee is a flat amount the
owner sets under Settings, with one free city.

## Payments

- **Cash** &ndash; paid at the counter on pickup, or on delivery.
- **GCash** &ndash; the customer scans the shop's QR code and sends the payment. The admin
  confirms it manually from the order screen. There is no merchant API and no automatic
  reconciliation, which is what a single-owner shop can realistically get approved.

Upload your QR image to `assets/img/gcash-qr.png` and set the account name and number
under Settings.

---

## Managing the menu

**Pictures.** Edit an item and upload a JPG, PNG, GIF or WebP, up to 2 MB. Square pictures look
best. You see a preview before it is sent, and there is a tick to remove the current one.

SVG uploads are refused on purpose. An SVG is XML that can contain script, and it would be
served from the shop's own address. The drink illustrations that ship with the system are SVG
because they were written as part of it; anything uploaded is treated as untrusted. Uploaded
pictures live in `assets/img/products/uploads/` and are not committed to the repository.

**Adding a lot at once.** The bottom of the Menu page takes a whole category in one paste:

```
Iced Americano | 85 | Double shot over ice
Cold Brew | 110 | Steeped for sixteen hours
Hot Chocolate | 75
```

The description is optional. Blank lines are ignored. A line with no valid price, or a name
already in that category, is skipped and reported back to you with its line number rather than
being guessed at. Items arrive on sale, unfeatured and without a picture.

**Removing a lot at once.** Tick the items on the list and use Delete selected. Past orders keep
their own copy of every name and price, so removing something from the menu never changes an
old receipt.

---

## Admin accounts

The shop starts with one owner account. If a helper works the counter, give them their own
sign-in under **Accounts** rather than sharing the owner password, because the audit trail
records whoever is signed in.

| Role | Can reach |
|---|---|
| Owner | Everything, including Settings and Accounts |
| Staff | Everything except Settings and Accounts |

A few things are enforced rather than left to care:

- You cannot deactivate the account you are currently signed in with.
- The last active owner cannot be demoted or deactivated, so the shop can never lock itself out.
- Resetting someone's password always forces them to set a new one at their next sign-in.
- Accounts are deactivated, never deleted, so past audit entries keep naming their author.

## Customising drinks

**Customisation** manages the choices a customer makes before adding a drink to the cart:
size, sugar level, add-ons, and anything else you want to offer.

- A group is either **pick one** (radio buttons, exactly one default) or **pick any** (checkboxes).
- Each choice can carry an extra charge, which is added to the drink's price.
- The bottom of the page controls which drinks offer that group.

Changing a price or a name here only affects future orders. Every past order keeps its own
copy of what was chosen and what it cost, so an old receipt never changes underneath anyone.

---

## Security

- Passwords hashed with bcrypt, rehashed automatically when PHP's default cost moves.
- Sign-in throttled per username and per IP, five attempts then a fifteen-minute lockout.
- Session cookies are `HttpOnly` and `SameSite=Lax`, with the id regenerated on sign-in.
  Sessions expire after 30 minutes idle and 12 hours absolute.
- Every state-changing request carries a CSRF token.
- Every query uses a real prepared statement. No user input is ever concatenated into SQL.
- A strict Content Security Policy with no inline scripts or styles and no third-party
  origins. Chart.js is vendored locally, so the system also works with no internet.
- Every admin action is written to an append-only audit trail with the user, the IP, and
  what changed.
- Order lookup needs the reference **and** the matching mobile number, so a guessed
  reference reveals nothing.

---

## Project layout

```
customer-site/   public ordering website
admin-site/      admin website
shared/          config, database, auth, CSRF, audit, SMS, order engine
database/        schema.sql and seed.sql
assets/          styles, scripts, brand assets, drink illustrations
storage/         logs and sessions (not web-reachable)
docs/            Apache config and developer notes
```

`shared/`, `database/` and `storage/` sit outside the web root on purpose.

---

## Demo data

Three sample orders ship with the working copy so the dashboard and the analytics charts have
something to render while you are setting up. They are obvious placeholders: Andrea Santos,
Miguel Reyes and Joy Dela Cruz.

Clear them before the shop goes live, or before a defence if you would rather present with
your own test orders:

```sql
DELETE FROM orders    WHERE order_ref LIKE 'ORD-%-90__';
DELETE FROM customers WHERE phone IN ('639171234567', '639189876543', '639225554433');
```

Audit rows are deliberately left alone. The audit trail is append-only by design, and deleting
from it would defeat the point of having one.

---

## Troubleshooting

**"The system is temporarily unavailable."**
MySQL is not running, or `.env` has the wrong credentials.

**The admin site says the page cannot be found.**
Apache has not picked up the config. Check the `Include` line in `httpd.conf`, confirm
`OURCOFFEE_ROOT` points at the right folder, and restart Apache.

**Styles are missing.**
The `assets` alias is not loading. Confirm both `Alias` lines in the config file come
*before* the `/ourcoffee` alias, since Apache matches them in order.

**No SMS arrives.**
Expected while `SMS_DRY_RUN=true`. Check `storage/logs/sms.log` and the SMS history on
the order screen to confirm the message was composed correctly.

---

Built for the BSIS capstone project at Makati Science Technological Institute of the
Philippines.
