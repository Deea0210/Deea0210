# Marketing kit: business card, ads & flyer

Everything uses the same brand as the website (colors, fonts, "deea." wordmark), and every QR code
points to your booking page.

| File in `export/` | What it is | Use it for |
| --- | --- | --- |
| `business-card-eu.pdf` | 85 × 55 mm card, front + back, 3 mm bleed | Printing in Europe (most online printers) |
| `business-card-us.pdf` | 3.5 × 2 in card, front + back, 1/8 in bleed | Printing in the US / Canada |
| `business-card-front.png`, `-back.png` | High-res previews | Sharing a preview, email signature, portfolio |
| `flyer-a5.pdf` / `.png` | A5 flyer with 3 mm bleed + QR code | Local shops, cafés, co-working spaces, events |
| `ad-square-services.png` | 1080 × 1080 | Instagram / Facebook feed ad: all services |
| `ad-square-offer.png` | 1080 × 1080 | Instagram / Facebook feed ad: Small Business Launch Pack |
| `ad-story.png` | 1080 × 1920 | Instagram / Facebook / TikTok stories |
| `ad-landscape.png` | 1200 × 628 | Facebook / LinkedIn link ads and posts |
| `og-image.png` | 1200 × 630 | Preview image when your website is shared (copied into the booking app) |

Captions, Google Ads headlines and descriptions, and hashtags are in **[ad-copy.md](ad-copy.md)**.

## Put your details in

1. Edit **`brand.js`**: phone, email, website, booking link (for the QR codes), Instagram handle and the offer price.
2. Re-export everything:

   ```bash
   cd marketing
   npm install playwright
   npx playwright install chromium
   node render.mjs
   ```

You can also preview any design by opening the `.html` files in a browser
(`business-card/business-card.html`, `business-card/business-card.html?size=us`, `ads/ads.html`, `ads/flyer.html`).

## Printing tips

- Upload the PDF as-is: it already includes bleed. Choose **"PDF has bleed"** / **"trim to 85 × 55 mm"** at the printer.
- Suggested stock: 350–400 gsm matte or soft-touch, which suits the dark front. Spot UV on the "deea." wordmark is a nice upgrade.
- Scan the QR code with your phone before ordering to make sure it opens your live booking page.
