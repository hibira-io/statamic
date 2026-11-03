# Changelog

## 0.1.1

- Add `THIRD-PARTY-NOTICES` with the copyright and MIT license texts of
  pusher-js, @stablelib/base64, @stablelib/utf8 and qrcode-generator, which
  `dist/cp.js` bundles, and a short notice at the top of `dist/cp.js`.
- Events are sent after the response, so a save never waits on Hibira.
- Comment events carry only the comment's ID and pages fetch the comment,
  so long comments in any script appear live on every plan.
- The comment file is encoded before it is rewritten, and comments with
  invalid UTF-8 are refused, so a failed write can't empty it.
- The comments card shows when loading failed, with a retry button, and
  clears an old error after a later success.
- README: Statamic Pro, what passes through Hibira (hosted in Hong Kong),
  plan limits and support contact.

## 0.1.0

- Avatars of everyone who has the same entry open, and a warning when one
  of them saves it.
- Live comments on entries, stored as one JSON file per entry: add,
  resolve, reopen and delete, checked against Statamic's permissions.
- Live preview on other devices: a QR code and link of your live preview,
  which reloads on every change.
- With `BROADCAST_CONNECTION=hibira`, Statamic's own control panel Echo
  client connects to Hibira.
- Statamic 5 and 6. English, 繁體中文, 简体中文, 日本語, 한국어, ไทย and Tiếng Việt.
