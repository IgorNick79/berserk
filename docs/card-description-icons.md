# Card description icon inventory

Source: `tmp/cards.sql`, backup dated 2026-10-03. Upload these 18 PNG files to `www/images/icons/` (URLs `/images/icons/`). No database import or writes were performed.

| File | Occurrences in dump |
|---|---:|
| `icon-armor.png` | 4 |
| `icon-counter.png` | 73 |
| `icon-direct.png` | 12 |
| `icon-elite.png` | 6 |
| `icon-first.png` | 1 |
| `icon-health.png` | 16 |
| `icon-instant.png` | 13 |
| `icon-move.png` | 6 |
| `icon-ordinary.png` | 3 |
| `icon-ova.png` | 17 |
| `icon-ovz.png` | 5 |
| `icon-regen.png` | 2 |
| `icon-second.png` | 8 |
| `icon-strike.png` | 93 |
| `icon-tap.png` | 134 |
| `icon-uchr.png` | 15 |
| `icon-zoal.png` | 6 |
| `icon-zot.png` | 1 |

The dump may differ from the live database. Images are not bundled with it and are currently absent locally.

Implementation: load cards.descr in the existing render query; show formatted description directly below the selected card name. Preserve local PNG icons and formatting while removing executable HTML/attributes. Empty graveyard/exile details are native disabled buttons with no href; positive counts retain existing links.

Verification: `test_card_description_ui.php` passed (Kshar text/icons/line breaks, safe HTML, empty/missing descriptions, card switching, zero/positive graveyard/exile for both viewers). Existing Stage 1B, AJAX layout, card visuals, coordinates and timer snapshot/UI tests passed. Headless Edge checks passed at 1600/800/375px, including description position, fit, snapshot replacement and native disabled zone controls. Live DB/HTTP checks and actual icon appearance remain unverified because the DB was unavailable and the icon files have not been supplied. No game rules or DB contents/schema were changed.
