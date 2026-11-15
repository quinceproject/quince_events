# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.4] - 2026-11-15

### Security

- **The signature covers the time of sending.** Each request carries
  `X-Quince-Timestamp`, and `X-Quince-Signature` is now the HMAC of
  `timestamp + "." + raw body`, the same scheme as the Quince CMP sync. The receiver can
  reject a request older than a few minutes, so a captured request cannot be played
  back later. **Receivers that check the signature need to be updated**: see "What the
  receiver gets" in the README.

## [0.1.3] - 2026-11-15

### Security

- **The receiver address must be `https://`.** Other addresses are refused when you
  save. Before sending, the module checks that the address does not lead to the
  server itself or to an internal network, and connects only to the address it
  checked. Before, the field accepted any protocol (`file://`, `gopher://`) and could
  be used to probe the shop's server.
- **The event log has a retention period**: sent events are deleted after 30 days,
  failed and never-sent ones after 90. Events carry personal data from forms.

## [0.1.2] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions on the
  **Events** tab (`AdminQuinceEvents`). Before, any logged-in employee, whatever their
  profile, could change the address events are sent to, and with it where customers'
  personal data from Quince forms goes. Grant the tab to a profile under
  **Advanced Parameters → Team → Permissions**; SuperAdmin needs nothing.

## [0.1.1] - 2026-11-10

### Added

- Polish translation of every text the module shows, in the back office and in the shop.

### Changed

- Texts no longer use long dashes.

## [0.1.0] - 2026-11-10

First public release.

### Added

- Event log: every event a Quince module reports is stored in the shop, whether or
  not delivery to an outside system is switched on.
- Delivery to one webhook address as JSON, signed with HMAC SHA-256 in the
  `X-Quince-Signature` header, with a 2 second limit so the customer never waits for
  the receiving side.
- Automatic retries of failed deliveries with growing intervals, up to six attempts,
  run alongside the next delivery instead of a scheduled task.
- Back office screens: delivery settings with a secret generator and a test event,
  the event list with filters by type and status, and the event details with the
  payload, the last error and a "Retry now" button.
- Declared dependency on Quince Engine.
