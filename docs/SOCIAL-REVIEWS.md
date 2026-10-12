# Social connectors & review feeds (v7.39)

Live delivery for the content calendar and live pulls for the reputation
inbox, on top of the v6.2 digital-marketing and review modules.

## What publishes live

| Platform | Endpoint (Graph v20.0) | Where the target lives |
|---|---|---|
| Facebook page | `POST /{page_id}/feed` `{message, link, access_token}` | `DmChannel.handle` = page id |
| WhatsApp Cloud | `POST /{phone_number_id}/messages` `text` | `DmChannel.handle` = phone-number id; recipient = setting `social_wa_to` |

Other platforms (Instagram, X, LinkedIn …) stay **recorded-only** exactly as
before — mark published with the post URL.

**Credentials** — one encrypted secret (`MessageService` secret `social`):

```json
{ "fb_token": "…", "wa_token": "…", "review_feed": "http://…/reviews-feed" }
```

Graph base URL: setting `social_graph_url` (default `https://graph.facebook.com`).
Posting happens in `DigitalMarketingService::publishPost()` when an approved
post is marked published; per-channel results land on `dm_posts.publish_log`
and the Facebook post URL is attached automatically.

## Review feeds

`SocialPublishService::pullReviews()` GETs `{review_feed}/reviews/{platform}`
for every platform in `OnlineReview::SOURCES` (booking, google, tripadvisor,
expedia, makemytrip, agoda) and feeds each row through `ReviewService::store()`
— dedupe, sentiment analysis, guest matching and service-recovery cases all
run as with CSV imports. **Reputation → Reviews → Pull from feeds** runs one
cycle and flashes the summary.

## Local simulator

```
node deploy/dev/social-sim.mjs --port=8095 --fb-token=demo --wa-token=demo
```

Configure: `MessageService::storeSecret('social', json_encode([
'fb_token' => 'demo', 'wa_token' => 'demo',
'review_feed' => 'http://127.0.0.1:8095']))`, setting
`social_graph_url = http://127.0.0.1:8095`. Seeded: one fresh review per
platform; `POST /__reviews` queues more.

Proven (2026-10-11): Graph handshake; Facebook post `fb_post_1` with the
post URL attached; WhatsApp send `wamid.…`; feed pull of 3 reviews (Booking
10-scale converted to 5, Google, TripAdvisor) into the inbox.

## Going live

Facebook: a Page access token with `pages_manage_posts` + the page id on the
channel. WhatsApp: a Cloud API token with `whatsapp_business_messaging` +
phone-number id. Review feeds: point `review_feed` at your review aggregator
export endpoint (or a nightly extractor) — the row shape is documented in
`SocialPublishService::pullReviews()`.
