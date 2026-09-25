# Sending texts from the shop phone

The system can send order updates through the shop's own handset instead of a paid SMS API.
The texts come out of your normal call and text plan, so there is no per-message cost.

## How it works

The phone **asks the server for work**. The server never contacts the phone.

```
Customer orders
      |
      v
Server writes the message to a queue          (nothing is sent yet)
      |
      |   every 20-30 seconds
      v
Phone asks: "anything for me?"   ------------>  Server hands over a small batch
      |
      v
Phone sends each one over the mobile network
      |
      v
Phone reports back: sent, or failed and why  ->  Server records it
```

This shape is deliberate. A web host cannot open a connection to a phone sitting behind a home
router or on mobile data, and free hosting usually blocks outbound calls to third parties
anyway. Because the phone reaches out, none of that matters, and the same setup works on
InfinityFree, on any other free host, and on a paid one later.

## What you need

- An Android phone with the shop SIM and an unlimited text plan
- It stays powered, charged and online. Leave it plugged in at the counter
- An app that can call a web address on a timer and send an SMS

For the app, anything that can do "HTTP request, then send SMS, then HTTP request" works.
**Automate** by LlamaLab is free and well suited. MacroDroid and Tasker also work. If the team
would rather write a small Android app, the API below is all it needs.

---

## Step 1: Get the connection details

Sign in to the admin site, open **Settings**, and scroll to **Shop handset**.

1. Press **Generate token**. Copy it somewhere safe.
2. Copy the **address the phone calls**. On a hosted site this is your real domain, for example
   `https://ourcoffee.rf.gd/admin/api/sms-gateway.php`, not `localhost`.

The token is a password. Anyone holding it can read the queue, which contains customer mobile
numbers. If a phone is lost, press **Generate a new token** and the old one stops working
immediately.

Also set **Send texts through** to *The shop handset* in the same Settings page, and make sure
`SMS_DRY_RUN=false` in `.env`. While dry run is on, nothing reaches the queue at all.

## Step 2: Set up the phone

The flow the app needs to repeat, every 20 to 30 seconds:

1. **HTTP GET** to
   `<your address>?action=pull&device=shop-phone`
   with the header `Authorization: Bearer <your token>`

2. Read the JSON that comes back. For each entry in `messages`, **send an SMS** to `to` with the
   text `message`, and remember its `id`.

3. **HTTP POST** back to
   `<your address>?action=report&device=shop-phone`
   with the same `Authorization` header, `Content-Type: application/json`, and a body listing
   what happened.

Grant the app permission to send SMS when Android asks, and exclude it from battery
optimisation, or Android will quietly stop it after a while. That single setting is the most
common reason a gateway looks like it has "randomly stopped working".

### If the app cannot set headers

Some simpler apps cannot send an `Authorization` header. The token can go in the address
instead:

```
<your address>?action=pull&device=shop-phone&token=<your token>
```

This works, but it is weaker: the token ends up in server logs and browser history. Prefer the
header where the app supports it.

---

## The API

### Collect messages

```
GET <address>?action=pull&device=shop-phone
Authorization: Bearer <token>
```

```json
{
  "ok": true,
  "device": "shop-phone",
  "count": 2,
  "messages": [
    { "id": 17, "to": "639171234567", "message": "Our Coffee Shop: Hi Ana, we got your order..." },
    { "id": 18, "to": "639189876543", "message": "Our Coffee Shop: Your order ORD-... is ready..." }
  ]
}
```

Numbers come back in `639XXXXXXXXX` form, ready to dial. Messages are plain GSM-7 text under
160 characters, so each is a single SMS.

Pulling **claims** those messages. A second pull will not return them again, so two phones, or
one phone retrying, cannot send the same text twice. If a claim is never confirmed it returns
to the queue after a couple of minutes, so a phone that dies mid-send does not lose the message.

### Report results

```
POST <address>?action=report&device=shop-phone
Authorization: Bearer <token>
Content-Type: application/json

{
  "results": [
    { "id": 17, "ok": true },
    { "id": 18, "ok": false, "error": "No signal" }
  ]
}
```

```json
{ "ok": true, "device": "shop-phone", "sent": 1, "failed": 1, "ignored": 0 }
```

For apps that can only post flat fields, a single result works too:
`id=17&ok=true`.

Reporting on a message that is already settled is ignored rather than applied, so a retried
report cannot rewrite history.

### Check the connection

```
GET <address>?action=status&token=<token>
```

Safe to open in a browser to confirm the address and token are right.

```json
{ "ok": true, "provider": "phone", "queued": 0, "sent_today": 12, "failed_today": 0 }
```

---

## Settings you can tune

All under **Settings**, in the *Handset queue* section. The defaults suit a small shop.

| Setting | Default | What it does |
|---|---|---|
| Messages per collection | 5 | How many the phone takes at once |
| Give up on a message after | 45 min | A status text has a short shelf life. Telling someone their coffee is being prepared an hour later is worse than saying nothing |
| Hand a message over at most | 3 times | Stops one bad message being retried forever |
| Return an uncollected message after | 120 sec | How long before an unconfirmed claim goes back on the queue |

## When the phone is off

Orders still go through. The messages sit in the queue, and **Settings** shows how many are
waiting along with a warning if the phone has not checked in. Anything older than the expiry is
abandoned rather than sent late.

Customers are not stranded either: the **Track Order** page shows the live status from the
reference and mobile number, with no text needed. That is the fallback the documentation review
recommended, and it is why a quiet phone is an inconvenience rather than a failure.

## Things worth knowing

**Unlimited plans are consumer plans.** Carrier fair-use rules treat automated business
messaging as bulk, and a SIM doing a lot of it can be throttled or blocked. For a capstone demo
and a small shop this is realistically fine, but it is not what the plan is sold for. If volume
ever grows, Semaphore is one setting away.

**There are no delivery receipts.** You know the handset sent it, not that it arrived. An API
gives you a proper receipt; this does not.

**The phone is now infrastructure.** If it is off, flat, or out of signal, texts stop. Leave it
charging, and glance at the Connected badge in Settings now and then.

## If something is not working

**Settings says "Not connected".** The phone has not called in for ten minutes. Check it is
awake, online, and that the app is still running. Battery optimisation is the usual culprit.

**Everything returns "Not authorised".** The token does not match. Copy it again from Settings,
watching for a missing character. If the app cannot send headers, use the `&token=` form.

**Messages queue but never send.** Open the status address in a browser. If that works, the
server is fine and the problem is on the phone: check the SMS permission.

**Messages come back "Expired before the handset collected it".** The phone was offline longer
than the expiry. Either bring it back sooner or raise the expiry in Settings.
