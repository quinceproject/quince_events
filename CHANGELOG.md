# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
