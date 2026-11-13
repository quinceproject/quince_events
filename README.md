# Quince Events

**Shop event log and outgoing delivery to external systems for PrestaShop 9.2.**

Quince Events is where Quince modules report what happens in the shop: a contact
enquiry, a quote request, a settled withdrawal. The module keeps every event in a log
in the back office and, once an address is set, delivers each one to an outside
system as a signed JSON message. On the other side there can be a flow in n8n,
Windmill or anything else that accepts a webhook.

> Quince Events is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **One log for every event.** Every event a Quince module reports is stored in the
  shop first, whether or not delivery is switched on. Nothing a customer sent gets
  lost because the receiving side was down.
- **Signed delivery.** Each event goes to one webhook address as JSON. The body is
  signed with HMAC SHA-256 using a shared secret, so the receiver can check that the
  message came from this shop and was not changed on the way. The secret never
  travels in the address.
- **The customer does not wait.** The shop gives the receiving side 2 seconds. A slow
  or unavailable receiver does not hold up the page the customer is on.
- **Retries without a scheduled task.** A failed delivery is tried again with growing
  intervals (1 minute, 5 minutes, 30 minutes, 2 hours, 6 hours), up to six attempts.
  Retries run alongside the next delivery, so there is no cron job to set up.
- **Event list and details.** Filter events by type and status (waiting, delivered,
  failed), open one to see its payload, the number of attempts, the next attempt and
  the last error, and send it again with **Retry now**.
- **Connection check.** A test event from the settings screen shows straight away
  whether the address, the secret and the receiver work together.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |
| PHP cURL extension | required |

Quince Engine hosts the module's settings screens and stores its settings. The
module declares it as a dependency, so PrestaShop refuses to install Quince Events
without it.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_events.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_events/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_events` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Events and click **Install**.

**From Quince Engine**

With Quince Engine installed, **Quince project → Quince modules** installs and updates
this module from the Quince release list, without downloading anything by hand.

**With Composer**

A PrestaShop shop ships without a `composer.json` of its own, so Quince packages are
installed from a separate folder next to the shop. The one-time setup of that folder is
described in the [Quince Engine README](https://github.com/quinceproject/quince_engine#installation).
Then, from that folder:

```sh
composer require "quinceproject/events:*"
php ../bin/console prestashop:module install quince_events
```

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_events/releases).

Uninstalling the module removes the event log and its settings.

## Configuration

Open **Quince project → Events** in the back office menu, or click **Configure** next
to the module in the Module Manager.

1. Paste the webhook address given to you by n8n, Windmill or whatever waits on the
   other side.
2. Click **Generate a new secret** and enter the same secret on the receiving side.
3. Switch on **Send events** and save.
4. Click **Send a test event** to check the connection.

## What the receiver gets

Each event is a `POST` request with a JSON body:

```json
{
  "id": "5f0c1e…",
  "type": "quince.test",
  "occurred_at": "2026-11-10T09:30:00+00:00",
  "source": "quince_events",
  "shop": { "id": 1, "name": "…", "url": "https://…/", "language": "en" },
  "context": {},
  "data": { "message": "Test event from Quince Events." }
}
```

and three headers:

| Header | Content |
|--------|---------|
| `X-Quince-Signature` | `sha256=` followed by the HMAC SHA-256 of the raw body, keyed with the shared secret |
| `X-Quince-Event` | the event type, for routing without reading the body |
| `X-Quince-Event-Id` | the event id, the same on every retry, for deduplication |

Any `2xx` answer counts as delivered. Anything else, or no answer within 2 seconds,
counts as a failed attempt.

## For module developers

Another module reports an event with:

```php
use Quince\Events\Event\EventDispatcher;
use Quince\Events\Event\EventOutbox;
use Quince\Events\Event\ShopEvent;

(new EventDispatcher(new EventOutbox()))->dispatch(new ShopEvent(
    type: 'contact.enquiry',
    data: ['email' => $email],
    source: 'my_module'
));
```

If your module should also work without Quince Events, check first that
`Module::getInstanceByName('quince_events')` returns the module and that
`class_exists(EventDispatcher::class)` is true, as the Quince modules do.

## Architecture

- **`EventOutbox`** keeps events in the `quince_event` table with their status,
  attempts and next attempt time.
- **`EventDispatcher`** stores an event, then sends it when delivery is switched on,
  and retries what is due.
- **Symfony controllers and Twig** for the back office screens, inside the Quince
  Engine shell.
- **Settings** are stored in PrestaShop configuration under the `QUINCE_EVENTS_`
  prefix.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_events.git
cd quince_events
composer install
```

The folder must be called `quince_events`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_events/` folder **with its `vendor/`
directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
