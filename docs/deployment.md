# Putting the system online

Written for whoever does the upload. Follow it in order; each step assumes the one before it.

Total time is about half an hour, most of it waiting for the host to create things.

---

## Before you start

You need the finished project folder and nothing else. There is no build step, no Composer, no
npm. What is on your machine is what goes on the server.

One decision first, because it changes step 3:

| | InfinityFree | Alwaysdata |
|---|---|---|
| Upload by | FTP | FTP or SSH |
| Can point the address at a subfolder | no | **yes** |
| Scripted deploy | awkward | **yes, over SSH** |

If the host lets you choose which folder the address points at, aim it at `customer-site/` and
the layout is cleaner. If it does not, the `.htaccess` at the project root handles it.

---

## 1. Create the hosting account and a database

Sign up, create a site, then find the database section and create one. Write down all of this,
because you will need it twice:

```
Database host      e.g. sql123.example-host.com
Database name      e.g. if0_12345678_ourcoffee
Database user      e.g. if0_12345678
Database password  the one you set
```

And the FTP details, usually on the same page:

```
FTP host / server
FTP username
FTP password
```

**The database host is almost never `localhost` on shared hosting.** It is a separate server,
and using `localhost` is the single most common reason a fresh deployment shows
"The system is temporarily unavailable."

## 2. Load the database

Open **phpMyAdmin** from the host's control panel and select the database you just made.

Go to **Import**, choose `database/install.sql`, and run it. That one file does everything:
tables, the menu, the settings and the owner account.

When it finishes you should see 17 tables. Spot-check by opening `products`, which should hold
20 rows.

## 3. Upload the files

Upload the whole project **except** the things listed below, keeping the folder structure
exactly as it is. The code finds its own files by relative path, so moving folders around will
break it.

Do not upload:

```
.git/                 version control, large and private
.playwright-mcp/      screenshots from testing
storage/logs/*        local logs
node_modules/         not used, if present
*.mp4                 the meeting recording
```

Do upload `.htaccess` files, including the hidden ones inside `shared/`, `database/` and
`storage/`. **Most FTP clients hide dotfiles by default.** Turn on "show hidden files" before
you start, or the private folders arrive unprotected.

### Where things land

If the address points at the project root (InfinityFree):

```
htdocs/
├── .htaccess          serves the customer site from the root
├── customer-site/
├── admin-site/
├── assets/
├── shared/            protected by its own .htaccess
├── database/          protected
└── storage/           protected
```

If you can point the address at a folder (Alwaysdata), aim the main site at `customer-site/`
and add a second site or subdomain pointing at `admin-site/`.

## 4. Configure

Copy `.env.example` to `.env` on the server and fill it in:

```
APP_ENV=production
APP_DEBUG=false

CUSTOMER_URL=https://yourdomain.example
ADMIN_URL=https://yourdomain.example/admin-site

DB_HOST=sql123.example-host.com
DB_NAME=if0_12345678_ourcoffee
DB_USER=if0_12345678
DB_PASS=your-database-password

SMS_DRY_RUN=true
```

**`APP_DEBUG=false` matters.** With it on, a PHP error prints the file path and often a
fragment of the query to whoever triggered it. Off, errors go to the log and the visitor sees a
plain message.

The two URLs are used for links between the sites and for the address shown in the SMS gateway
panel. Get them wrong and links point at localhost.

## 5. Check it

In this order, because each rules out a different failure:

1. Open your address. The menu should load with pictures.
2. Open `/admin-site/login.php`. The sign-in page should appear.
3. Sign in as `owner` with the seeded password. It will force a new one. Set it now.
4. Place a test order on the customer site.
5. Open it in the admin queue and move it through Preparing and Ready.
6. Track it on the customer site with the reference and the mobile number.

Then confirm the private folders really are private. Open each of these; **all three must fail**:

```
https://yourdomain.example/shared/config.php
https://yourdomain.example/.env
https://yourdomain.example/database/install.sql
```

If any of them shows you text, the `.htaccess` files did not upload. Fix that before going any
further: the first one contains your database password.

## 6. After it works

- **Delete `database/install.sql` from the server.** It is not needed again and it holds the
  seeded owner password hash.
- Clear the demo orders if you would rather present with your own. The SQL is in the README.
- Set the shop's real contact number, GCash name and number under **Settings**.
- Upload the GCash QR image as `assets/img/gcash-qr.png`.

If you want real texts, follow `docs/sms-handset-setup.md` **now rather than earlier**: the
phone should point at the live address, so you set it up once and never again.

---

## When something is wrong

**"The system is temporarily unavailable."**
The database details are wrong. Nine times out of ten `DB_HOST` is still `localhost`. Check it
against the host's control panel.

**The pages load but have no styling.**
The `assets` folder did not upload, or only partly. Confirm `assets/css/tokens.css` is there.

**Sign-in says the session expired, every time.**
The host cannot write session files. Most shared hosts are fine; if yours is not, check that
`storage/sessions/` uploaded and is writable.

**Pictures upload but stay huge.**
The host's PHP has no GD extension, so resizing silently falls back to storing the original.
Uploads still work. Most hosts have GD; if yours does not, resize photos before uploading them.

**A page is blank with no error at all.**
`APP_DEBUG=false` is hiding a fatal error, which is what it is for. Look in the host's error
log, or set `APP_DEBUG=true` briefly, find the problem, and set it straight back.

**Everything worked, then stopped after a few weeks.**
Free hosting accounts are often suspended for inactivity. Log into the host's control panel
occasionally, particularly in the run-up to a defence.
