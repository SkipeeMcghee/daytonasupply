# Daytona Supply Weekly Email System

Use `weekly-template.html` as the master. Duplicate it as `weekly-YYYY-MM-DD.html`; never overwrite the master.

## The 15-minute setup

1. Pick one timely angle: seasonal need, operational problem, category education, new product, or local-service reminder.
2. Pick one palette below and replace every matching `[[COLOR_TOKEN]]`.
3. Choose a hero and content mode from the rotation table.
4. Replace every remaining `[[PLACEHOLDER]]`; search for `[[` before sending.
5. Verify facts, links, image filenames, alt text, mobile layout, `%%unsubscribe_link%%`, and `%%web_view%%`.

## Palette presets

Use a full preset rather than choosing colors one at a time. Card 2 and Card 3 may use contrasting soft colors so the email does not become one-note.

| Token | Coastal | Citrus | Workshop | Fresh Start |
|---|---|---|---|---|
| `PAGE_BG` | `#edf6f3` | `#fff3e6` | `#f1eee9` | `#edf4fa` |
| `SURFACE` | `#fffefb` | `#fffdf9` | `#fffdfa` | `#ffffff` |
| `PRIMARY` | `#2f6f67` | `#b94f24` | `#48554a` | `#245b78` |
| `ACCENT` | `#d97b2f` | `#d59b22` | `#b86a32` | `#d06b3c` |
| `ACCENT_TEXT` | `#ffffff` | `#2f2414` | `#ffffff` | `#ffffff` |
| `HEADING` | `#234d4a` | `#713216` | `#303c33` | `#183f59` |
| `BODY_TEXT` | `#1f3940` | `#4e2d1e` | `#303832` | `#233d4f` |
| `MUTED_TEXT` | `#42615c` | `#78503a` | `#5d665f` | `#506b7d` |
| `HEADER_MUTED` | `#d9eee8` | `#ffe4d2` | `#e3e9e4` | `#d8eaf4` |
| `HERO_BG` | `#dff1ec` | `#ffe2c7` | `#e5eadf` | `#dcecf5` |
| `ART_BG` | `#fffaf0` | `#fff8ed` | `#f7f4ec` | `#f8fcff` |
| `BORDER` | `#cfe2dc` | `#edc9aa` | `#d5ddd2` | `#cadde8` |
| `SHADOW` | `rgba(41,77,70,0.12)` | `rgba(113,50,22,0.12)` | `rgba(48,60,51,0.12)` | `rgba(24,63,89,0.12)` |
| `FOOTER_BG` | `#285f58` | `#8e3c1e` | `#374238` | `#1e4d67` |

Choose the matching module pack below. Each cell lists `background / border / text / accent`. For Quote and CTA groups, which have no accent token, use the first three values.

| Tokens | Coastal | Citrus | Workshop | Fresh Start |
|---|---|---|---|---|
| `CARD_1_BG` / `CARD_1_BORDER` / `CARD_1_TEXT` / `CARD_1_ACCENT` | `#eef8f6` / `#d4e8e2` / `#244742` / `#2f6f67` | `#fff4e9` / `#efd5bc` / `#5c351f` / `#b94f24` | `#eef2ec` / `#d4ddd1` / `#354039` / `#48554a` | `#edf6fb` / `#cfe1eb` / `#23465b` / `#245b78` |
| `CARD_2_BG` / `CARD_2_BORDER` / `CARD_2_TEXT` / `CARD_2_ACCENT` | `#fff7ef` / `#eed9c7` / `#5d4027` / `#b86226` | `#fff9e8` / `#eadcaf` / `#594b22` / `#9b7415` | `#fff5ec` / `#ead4c2` / `#5a3e2c` / `#9b572b` | `#fff4ed` / `#ecd5c7` / `#5b3c2d` / `#a9522d` |
| `CARD_3_BG` / `CARD_3_BORDER` / `CARD_3_TEXT` / `CARD_3_ACCENT` | `#f3f7fb` / `#d8e1ee` / `#29415a` / `#58769b` | `#eef7f1` / `#d0e2d5` / `#2e4b38` / `#4f765a` | `#eef3f7` / `#d2dde5` / `#304553` / `#536f80` | `#f1f7ed` / `#d6e4ce` / `#354b2f` / `#5f7d52` |
| `QUOTE_BG` / `QUOTE_BORDER` / `QUOTE_TEXT` | `#f7f3e7` / `#e5dbc1` / `#514934` | `#f8f1e6` / `#e7d5be` / `#574431` | `#f5f1e8` / `#ded5c3` / `#4e493f` | `#f7f3e8` / `#e4dbc5` / `#504a3a` |
| `CTA_BG` / `CTA_BORDER` / `CTA_TEXT` | `#e8f3f0` / `#cbe0da` / `#244742` | `#fff0e3` / `#edccb2` / `#5c351f` | `#e9eee8` / `#ced8cc` / `#354039` | `#e7f2f8` / `#c8dce7` / `#23465b` |

Maintain strong contrast. Use dark text on pale backgrounds and white text only on dark buttons or bars.

## Four-week rotation

| Week | Hero | Content | Best use |
|---|---|---|---|
| 1 | Split copy + character | Lead spotlight + two quick picks | Normal weekly restock |
| 2 | Centered copy, no hero art | Three equal product cards | Product/category education |
| 3 | Full-width real product or seasonal image | Checklist or three numbered tips | Holiday or operational guide |
| 4 | Split hero with image first | One problem/solution story + two supporting products | Strong editorial angle |

To make a centered hero, remove the hero-art `<td>`, change hero-copy to `width="100%"`, increase its horizontal padding, and add `text-align:center`. For a full-width image hero, place a responsive 640px image in its own row between the header and copy. Keep the footer and mobile classes unchanged.

## Content recipe

Every issue should contain:

- One clear reason the email matters this week.
- One genuinely useful fact, tip, comparison, or checklist.
- Two or three relevant product categories, each with a direct catalogue link.
- One personality beat: character caption, short original joke, or friendly aside.
- One local-service reminder covering pickup, delivery, quantities, or substitutions.

Avoid repeating the same opening, category trio, character, palette, hero mode, or CTA wording in consecutive weeks. Keep factual claims specific enough to verify and avoid unsupported statistics.

## Subject and copy guardrails

- Subject/title: 35-55 characters when practical.
- Preheader: 80-120 characters and not a repeat of the subject.
- Hero headline: 3-7 words, ideally two lines or fewer.
- Hero copy: 35-55 words.
- Lead story: 60-100 words total.
- Quick picks: 25-40 words each.
- CTA labels: specific and short, such as “Shop gloves” or “Build a refill list.”

## Send checklist

- No `[[` placeholders remain.
- All URLs use `https://www.daytona-supply.com/` and valid `.php` routes.
- Product and character images load and have accurate alt text.
- Links match the featured category or SKU.
- Facts, dates, punctuation, and product availability are checked.
- Desktop and mobile previews have no overflow or cramped buttons.
- `%%unsubscribe_link%%` and `%%web_view%%` remain exactly intact.