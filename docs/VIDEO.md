# Video hosting and lesson progression

## Provider decision: Cloudflare Stream (initial)
Checked against the providers' public pages on 2026-10-15:

| | Cloudflare Stream | Bunny Stream |
|---|---|---|
| Storage | $5/month per 1,000 minutes (prepaid) | from $0.01/GB |
| Delivery | $1 per 1,000 minutes delivered | from $0.005/GB; Middle East & Africa standard-network CDN is $0.06/GB (6x Europe/N. America) |
| Private playback | `requireSignedURLs`; RS256 tokens signed locally (no API call per viewer), up to 24 h, optional IP/country rules | SHA256 token + expiry URLs |
| Upload | direct creator upload, resumable TUS | similar |

Learners are mostly in Nigeria, so Bunny's per-GB Africa rate is the deciding factor; Cloudflare bills per minute delivered regardless of region. These are list prices, not a forecast: model real watch-hours before committing. The code sits behind `App\Services\Video\VideoProvider`, so Bunny can be added as a second adapter without touching progress logic.

Required configuration: `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_STREAM_API_TOKEN`, `CLOUDFLARE_STREAM_CUSTOMER_CODE`, signing key (`POST /stream/keys` -> `CLOUDFLARE_STREAM_KEY_ID`, `CLOUDFLARE_STREAM_KEY_PEM_B64`), and webhook secret (`PUT /stream/webhook` -> `CLOUDFLARE_STREAM_WEBHOOK_SECRET`, URL `/api/v1/webhooks/video/cloudflare`).

Status: token signing, webhook verification and upload authorisation are tested against fakes (RS256 signature verified with the public key). The TUS creation request format and webhook payload follow Cloudflare's documentation but have **not yet been exercised against a real account**.

## How completion is decided (server-authoritative)
The browser reports positions; the server decides. For each report between two points the server credits playback only if:
1. the claimed advance is at most (client-reported elapsed time x max rate 2.0) + 2 s tolerance, and
2. the session's total credit never exceeds server wall-clock since the session began x max rate.

Skipped (seeked-over) regions earn nothing; replays never double-count (covered ranges are merged). A lesson completes when the covered share of the media reaches the threshold (lesson override, else course setting, default 90%). Events are idempotent per (session, seq). Sessions expire after 15 idle minutes; learners resume from the last verified position. Completion is stored once (`UNIQUE(user, lesson)`) and the next lesson unlocks from stored completions.

## Limits (honest)
- **YouTube**: the official IFrame player is controlled by YouTube. We validate URLs, embed the official player and apply the same plausibility rules, but a determined user can script a client that reports realistic-looking playback. The academy cannot block that for externally hosted video. Use uploaded video for lessons where completion must be strongly enforced.
- **No technology prevents screen recording** of any browser-played video. Signed short-lived URLs only restrict who can fetch the stream.
- Hidden-tab playback at normal speed is indistinguishable from watching.
