# SESAME README art kit

Cuts the README images under `docs/readme/` from this repo's own front page
(`site/`), brand files and source. Nothing is drawn freehand: the hero and the
gallery are screenshots of the real page, the cast is the marks resized, the
badges read their facts from `composer.json` and `src/DriverFactory.php`, and
the key duplicator is the site's own cas key, cut along its own bitting.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL, aborts any
request that tries to leave the machine, and is deterministic: same inputs,
same outputs.

## What it makes

- `docs/readme/hero.jpg`: the hero and its service counter, with the shop
  header hidden.
- `docs/readme/duplicator.svg`: the key duplicator. The tracer rides the cas
  key from the site's `#key-cas` symbol and cuts its five notches and tip; each
  cut is labeled with one sign-in step. The routes are read from
  `src/Laravel/SesameServiceProvider.php`, `src/Drivers/CasDriver.php` and
  `src/UserResolver.php`; each caption is a phrase quoted from the source, the
  README or the page, and the build fails if a phrase is no longer there.
  Under `prefers-reduced-motion` it is the finished key, standing still.
- `site/screenshots/`: the page's reference photos, re-taken on every run: one 390-wide phone crop per section
  in `sections/` (the finished shop, as reduced motion paints it; a section taller than 1600 px carries on in
  `-2`, `-3` and further files), and three full-page shots: desktop 1440 and phone 390 with the motion played through, and the
  reduced-motion desktop. Plus `frontpage-motion.gif`, one visit filmed at 1280 x 800 and written at 640 x 400:
  the counter, the cas key opening the door, a press on the saml peg (it opens the same door), and the duplicator
  cut from start to finish. The PNGs come out the same on every run; the GIF is filmed live, so its frames can
  shift a little between runs.
- `docs/readme/gallery.jpg`: three of those phone crops (hero, pegboard, duplicator), taken fresh on the same run,
  so the pegboard in the README always tags each driver the way the page does.
- `docs/readme/board.jpg`: the brand board, re-encoded.
- `docs/readme/cast/*.png`: one 96×96 mark per stack part, WayneUI on its
  green tile first.
- `docs/readme/badges/*.svg`: `php`, `license` and `drivers`, made locally by
  `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may name an
internal host; the script prints each size and exits 1 if either rule breaks.

If the duplicator ever cuts the wrong key, change the lock (the source), not
the machine. No key under the mat: we checked this folder too.
