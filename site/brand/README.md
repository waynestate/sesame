# SESAME brand files

**The mark is a door opening: two flat leaves part around a gold wedge of light**, because SESAME is the way in. The
light is a sliver at the top and wider at the floor, the way light falls through a door that has just started to
open. There is no frame or lintel: the door is the whole idea.

## Colours
| Role | Colour |
|---|---|
| Leaves, tile | Wayne State green `#0C5449` |
| The light (the only gold) | Wayne State gold `#FFCC33` |
| Leaves on the tile or on dark backgrounds | White `#FFFFFF` |
| Site accent only, never in the mark | Slate ink `#314842` (WayneUI `ink.700`) |

Gold never sits behind white text.

## Files
| File | Use |
|---|---|
| `sesame-mark.svg` | The mark on light backgrounds: green leaves, gold light |
| `sesame-mark-white.svg` | The mark on dark or green backgrounds: white leaves, gold light |
| `sesame-mark-mono.svg` | One colour (`currentColor`), for print and single-ink use |
| `sesame-tile.svg` | The app icon: a green rounded tile with the white door |
| `sesame-favicon.svg` | The tile simplified for 32 and 64 px: a bigger door, wider gaps, a light that is never a hairline |
| `sesame-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel. The light is a straight 2 px slot here, because a stepped wedge reads as a bottle at this size |
| `sesame-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `sesame-mark-1024.png`, `sesame-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced.
- **The door:** 304 units wide and 252 tall on a 512 grid, centred on the tile. Outer edges are vertical.
- **The light:** a trapezoid on the centre line, 6 units wide at the top and 50 at the floor.
- **The gaps:** each leaf's inner edge runs parallel to the side of the light, 10 units away measured square to the
  edge, so the green gap either side has one constant width.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape sesame-tile.svg --export-type=png --export-filename=sesame-avatar-512.png -w 512 -h 512`. The `.ico` was
built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
