# Certificate template guide

## For the academy (no code needed)
1. Sign in as an administrator (two-step verification required) → **Certificates → Template & layout**.
2. Upload your artwork: **PNG or JPG, A4 landscape**, ideally **3508 × 2480 px** (300 dpi); minimum width 1400 px, maximum 10 MB. A ready-made example is in `docs/samples/certificate-template-sample.png`.
3. Leave blank the places where details go. Do not type any student's name into the artwork.
4. Drag each item (name, course, date, signatory, number, verification link, QR code) to where it belongs, or select it and use the arrow keys / fields. Choose size, colour and font, tick or untick optional items (final grade, course duration), then **Save layout**.
5. Use **Preview as PDF** to see a certificate with sample details.

## What happens automatically
When a student meets **every** course requirement (all required lessons, assignments and examinations including the final exam at or above its pass mark, and attendance where required), the server issues the certificate and prints: the student's registered name, the course title, the issue date (Africa/Lagos), the certificate number, the signatory (from Settings → academy), optionally the final grade and course duration, a verification link and a QR code that opens the public verification page. Students download it from **Certificates**; anyone can verify it at `/verify`.

## Rules worth knowing
* A template change affects **new** certificates only. Each certificate stores the artwork file and layout it was issued with, so re-downloading an old certificate never changes it. Old artwork files are kept on private storage for this reason.
* Removing the template falls back to the built-in design for new certificates.
* Long names shrink to fit the item's maximum width instead of running off the page.
* The upload is decoded and re-encoded server-side, so only a genuine PNG/JPG is stored; the artwork is served only to staff with the `certificates.manage` permission; every upload, removal and layout change is written to the audit log.
* PDF templates are not supported (use an image). Fonts are limited to three built-in families (serif, sans-serif, Times); custom fonts would need font files added to the server.
