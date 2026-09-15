#!/usr/bin/env python3
"""Trace src/icon.jpg into the SVGs that ship as the plugin's mark.

There is no potrace, ImageMagick or Inkscape on the Mac this was built on, so the
whole pipeline is here: de-vignette and blur the photo's luminance, threshold it,
follow the crack boundary of the resulting bitmap, nudge each vertex onto the true
iso-level, simplify (Ramer-Douglas-Peucker), then fit cubic Beziers (Schneider).

Every stroke in the drawing is a closed outline, so the result is one even-odd path
whose counters -- eyes, nostrils, the gaps between the fur strokes -- are holes in
that same path rather than shapes painted back over the top.

    python3 -m venv .venv && .venv/bin/pip install pillow numpy
    .venv/bin/python tools/trace-icon.py --write

`--write` regenerates src/icon.svg and promos/assets/{icon,watermark}.svg. It does
NOT touch src/icon-mask.svg: that file is drawn by hand to this trace's proportions,
because at the 18px the control panel nav renders a mask icon at, ~60 strokes of fur
weld into a grey lozenge. See CLAUDE.md, "The icon".

Defaults are the settings the shipped files were traced with. --eps and --tol trade
fidelity for file size; at the defaults the path is ~23KB and indistinguishable from
a 78KB trace at any size the icon is actually used.
"""

import argparse
import math
import os
import re
import sys

import numpy as np
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'src', 'icon.jpg')
X0, X1, Y0, Y1 = 48, 606, 24, 583          # tile bbox measured from the photo


def gaussian(a, sigma):
    r = max(1, int(sigma * 3))
    k = np.exp(-0.5 * (np.arange(-r, r + 1) / sigma) ** 2)
    k /= k.sum()
    pad = np.pad(a, r, mode='edge')
    tmp = np.zeros((a.shape[0], pad.shape[1]))
    for i, kv in enumerate(k):
        tmp += kv * pad[i:i + a.shape[0], :]
    out = np.zeros_like(a, dtype=float)
    for i, kv in enumerate(k):
        out += kv * tmp[:, i:i + a.shape[1]]
    return out


def load_field(scale=3.0, sigma=1.2):
    im = Image.open(SRC).convert('RGB').crop((X0, Y0, X1 + 1, Y1 + 1))
    w, h = im.size
    im = im.resize((int(w * scale), int(h * scale)), Image.LANCZOS)
    a = np.asarray(im).astype(float)
    lum = 0.299 * a[:, :, 0] + 0.587 * a[:, :, 1] + 0.114 * a[:, :, 2]
    bg = gaussian(lum, 60 * scale)          # the photo's vignette only
    lum = gaussian(lum - bg + float(bg.mean()), sigma)
    # Everything outside the rounded tile is the photograph's peach backdrop, which
    # is brighter than the line art and would otherwise trace as four corner wedges.
    h2, w2 = lum.shape
    inset = 8.0 * scale
    r = 0.2244 * min(w2, h2)
    yy, xx = np.mgrid[0:h2, 0:w2].astype(float)
    dx = np.maximum(np.maximum(inset + r - xx, xx - (w2 - 1 - inset - r)), 0.0)
    dy = np.maximum(np.maximum(inset + r - yy, yy - (h2 - 1 - inset - r)), 0.0)
    lum[np.hypot(dx, dy) > r] = 0.0
    return lum, im.size


# ------------------------------------------------------- crack boundary following
# Directed unit edges with the filled pixel on the left (x right, y down).
_SIDES = (
    (0, -1, (1, 0), (0, 0)),   # neighbour above empty  -> travel right-to-left
    (-1, 0, (0, 0), (0, 1)),   # neighbour left empty   -> travel down
    (0, 1, (0, 1), (1, 1)),    # neighbour below empty  -> travel left-to-right
    (1, 0, (1, 1), (1, 0)),    # neighbour right empty  -> travel up
)


def boundaries(mask):
    """Every closed crack boundary of the binary mask, as integer point lists."""
    h, w = mask.shape
    m = np.zeros((h + 2, w + 2), dtype=bool)
    m[1:-1, 1:-1] = mask

    edges = {}
    ys, xs = np.nonzero(m)
    for y, x in zip(ys.tolist(), xs.tolist()):
        for dx, dy, a, b in _SIDES:
            if not m[y + dy, x + dx]:
                edges.setdefault((x + a[0], y + a[1]), []).append((x + b[0], y + b[1]))

    loops = []
    for start in list(edges):
        while edges.get(start):
            loop = [start]
            cur = start
            prev = None
            while True:
                outs = edges.get(cur)
                if not outs:
                    break
                if len(outs) == 1 or prev is None:
                    nxt = outs.pop()
                else:
                    # Diagonal pinch: keep going straight, else turn left, so the
                    # foreground stays 8-connected and loops never cross.
                    d = (cur[0] - prev[0], cur[1] - prev[1])
                    straight = (cur[0] + d[0], cur[1] + d[1])
                    left = (cur[0] + d[1], cur[1] - d[0])
                    nxt = None
                    for cand in (straight, left):
                        if cand in outs:
                            outs.remove(cand)
                            nxt = cand
                            break
                    if nxt is None:
                        nxt = outs.pop()
                if not edges[cur]:
                    del edges[cur]
                prev, cur = cur, nxt
                if cur == loop[0]:
                    break
                loop.append(cur)
            if len(loop) >= 8:
                loops.append(loop)
    return loops


def refine(loop, f, level, amount=1.0):
    """Nudge each crack vertex onto the true iso-level along the field gradient."""
    h, w = f.shape
    out = []
    for x, y in loop:
        xi = min(max(x - 1, 1), w - 2)
        yi = min(max(y - 1, 1), h - 2)
        gx = (f[yi, xi + 1] - f[yi, xi - 1]) * 0.5
        gy = (f[yi + 1, xi] - f[yi - 1, xi]) * 0.5
        g2 = gx * gx + gy * gy
        if g2 < 1e-6:
            out.append((float(x), float(y)))
            continue
        d = (f[yi, xi] - level) / g2
        d = max(-1.5, min(1.5, d)) * amount
        out.append((x + gx * d, y + gy * d))
    return out


# ------------------------------------------------------------------------ geometry
def area(pts):
    s = 0.0
    n = len(pts)
    for i in range(n):
        x1, y1 = pts[i]
        x2, y2 = pts[(i + 1) % n]
        s += x1 * y2 - x2 * y1
    return s / 2.0


def smooth_closed(pts, passes=2):
    for _ in range(passes):
        n = len(pts)
        pts = [((pts[i - 1][0] + 2 * pts[i][0] + pts[(i + 1) % n][0]) / 4.0,
                (pts[i - 1][1] + 2 * pts[i][1] + pts[(i + 1) % n][1]) / 4.0)
               for i in range(n)]
    return pts


def rdp(pts, eps):
    if len(pts) < 3:
        return pts
    keep = [False] * len(pts)
    keep[0] = keep[-1] = True
    stack = [(0, len(pts) - 1)]
    while stack:
        a, b = stack.pop()
        if b <= a + 1:
            continue
        ax, ay = pts[a]
        bx, by = pts[b]
        dx, dy = bx - ax, by - ay
        n = math.hypot(dx, dy)
        best, bi = -1.0, -1
        for i in range(a + 1, b):
            px, py = pts[i]
            d = (abs(dy * px - dx * py + bx * ay - by * ax) / n) if n else math.hypot(px - ax, py - ay)
            if d > best:
                best, bi = d, i
        if best > eps:
            keep[bi] = True
            stack.append((a, bi))
            stack.append((bi, b))
    return [p for p, k in zip(pts, keep) if k]


# ------------------------------------------------- Schneider cubic Bezier fitting
def _q(c, t):
    mt = 1 - t
    return (mt**3 * c[0][0] + 3 * mt * mt * t * c[1][0] + 3 * mt * t * t * c[2][0] + t**3 * c[3][0],
            mt**3 * c[0][1] + 3 * mt * mt * t * c[1][1] + 3 * mt * t * t * c[2][1] + t**3 * c[3][1])


def _norm(v):
    n = math.hypot(*v)
    return (v[0] / n, v[1] / n) if n else (0.0, 0.0)


def _params(pts):
    u = [0.0]
    for i in range(1, len(pts)):
        u.append(u[-1] + math.dist(pts[i], pts[i - 1]))
    t = u[-1] or 1.0
    return [x / t for x in u]


def _gen(pts, u, t1, t2):
    p0, p3 = pts[0], pts[-1]
    c00 = c01 = c11 = x0 = x1 = 0.0
    for i in range(len(pts)):
        ui = u[i]
        mt = 1 - ui
        b1, b2 = 3 * mt * mt * ui, 3 * mt * ui * ui
        a0 = (t1[0] * b1, t1[1] * b1)
        a1 = (t2[0] * b2, t2[1] * b2)
        c00 += a0[0] * a0[0] + a0[1] * a0[1]
        c01 += a0[0] * a1[0] + a0[1] * a1[1]
        c11 += a1[0] * a1[0] + a1[1] * a1[1]
        bx = p0[0] * (mt**3 + b1) + p3[0] * (b2 + ui**3)
        by = p0[1] * (mt**3 + b1) + p3[1] * (b2 + ui**3)
        tx, ty = pts[i][0] - bx, pts[i][1] - by
        x0 += a0[0] * tx + a0[1] * ty
        x1 += a1[0] * tx + a1[1] * ty
    det = c00 * c11 - c01 * c01
    seg = math.dist(p0, p3)
    if abs(det) < 1e-12:
        a = b = seg / 3.0
    else:
        a = (x0 * c11 - x1 * c01) / det
        b = (c00 * x1 - c01 * x0) / det
        if a < 1e-6 or b < 1e-6:
            a = b = seg / 3.0
    # Schneider's least squares happily returns handles many times the chord
    # length, which shows up as hairline rays shooting off the artwork.
    lo, hi = seg * 0.02, seg * 1.2
    a = min(max(a, lo), hi)
    b = min(max(b, lo), hi)
    return [p0, (p0[0] + t1[0] * a, p0[1] + t1[1] * a),
            (p3[0] + t2[0] * b, p3[1] + t2[1] * b), p3]


def _err(pts, c, u):
    worst, idx = 0.0, len(pts) // 2
    for i in range(1, len(pts) - 1):
        d = math.dist(_q(c, u[i]), pts[i])
        if d > worst:
            worst, idx = d, i
    return worst, idx


def fit_cubic(pts, t1, t2, tol, depth=0):
    if len(pts) == 2:
        d = math.dist(pts[0], pts[1]) / 3.0
        return [[pts[0], (pts[0][0] + t1[0] * d, pts[0][1] + t1[1] * d),
                 (pts[1][0] + t2[0] * d, pts[1][1] + t2[1] * d), pts[1]]]
    u = _params(pts)
    c = _gen(pts, u, t1, t2)
    e, split = _err(pts, c, u)
    if e < tol or depth > 14 or split <= 0 or split >= len(pts) - 1:
        return [c]
    t = _norm((pts[split - 1][0] - pts[split + 1][0], pts[split - 1][1] - pts[split + 1][1]))
    return (fit_cubic(pts[:split + 1], t1, t, tol, depth + 1)
            + fit_cubic(pts[split:], (-t[0], -t[1]), t2, tol, depth + 1))


def fit_loop(pts, tol):
    pts = [p for i, p in enumerate(pts) if i == 0 or math.dist(p, pts[i - 1]) > 1e-9]
    if len(pts) < 4:
        return []
    t1 = _norm((pts[1][0] - pts[-1][0], pts[1][1] - pts[-1][1]))
    t2 = _norm((pts[-2][0] - pts[0][0], pts[-2][1] - pts[0][1]))
    return fit_cubic(pts + [pts[0]], t1, t2, tol)


# -------------------------------------------------------------------------- CLI

# Traced at 3x with a 1.2px blur: the source is a 656px JPEG, and at 1x the encoder's
# ringing around the strokes shows up as scalloping along every edge.
DEFAULTS = dict(level=150.0, scale=3.0, sigma=1.2, smooth=2, eps=1.5, tol=2.5,
                minarea=0.06)

TILE = '#33608A'        # the tile, sampled from the photo
LINE = '#EAF6FF'        # the line art, sampled from the photo


def fmt(v, prec=2):
    s = f'{v:.{prec}f}'.rstrip('0').rstrip('.')
    return s if s not in ('', '-0') else '0'


def trace(level, scale, sigma, smooth, eps, tol, minarea, vb=100.0, prec=2):
    """The drawing as one even-odd path's `d`, scaled into a `vb`-unit square."""
    f, (w, h) = load_field(scale, sigma)
    S = vb / max(w, h)

    items = []
    for lp in boundaries(f > level):
        pts = smooth_closed(refine(lp, f, level), smooth)
        if abs(area(pts)) * S * S >= minarea:
            items.append(pts)

    out = []
    for pts in items:
        simp = rdp(pts, eps)
        if len(simp) < 4:
            continue
        curves = fit_loop(simp, tol)
        if not curves:
            continue
        p0 = curves[0][0]
        d = [f'M{fmt(p0[0] * S, prec)} {fmt(p0[1] * S, prec)}']
        for c in curves:
            d.append('C' + ' '.join(f'{fmt(px * S, prec)} {fmt(py * S, prec)}'
                                    for px, py in c[1:]))
        out.append(''.join(d) + 'Z')
    return ''.join(out), len(items)


ICON = """<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
  <!-- Rounded tile: 22.44% radius, the family's app-icon shape.
       Self-coloured on purpose — this file is the Plugin Store icon and the marketing-site
       logo, where there is no `currentColor` to inherit. The control panel nav uses
       icon-mask.svg instead, which is the monochrome silhouette. -->
  <rect width="100" height="100" rx="22.44" fill="{tile}"/>
  <!-- The alien, traced from src/icon.jpg by tools/trace-icon.py. One path, even-odd: every
       stroke in the drawing is a closed outline, and the counters — eyes, nostrils, the gaps
       between the fur strokes - are holes in that same path rather than shapes painted back
       over the top. -->
  <path fill="{line}" fill-rule="evenodd" d="{d}"/>
</svg>
"""

WATERMARK = """<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}">
  <!-- Watermark: the traced alien with the tile stripped.
       The app icon's tile is a solid rounded square, and at watermark scale it reads as a grey
       box across the slide rather than as a mark. The viewBox is cropped to the drawing's own
       bounds so the line art fills the watermark box instead of floating in the tile's padding,
       and because it is open line work there is no straight edge closing it into a rectangle.
       Flat and self-coloured: it is loaded through `background: url()`, where there is no
       `currentColor` to inherit, and the slide sets the opacity. -->
  <path fill="{line}" fill-rule="evenodd" d="{d}"/>
</svg>
"""


def bbox(d, pad=1.5):
    """A cubic is bounded by its control hull, so the raw numbers are bound enough."""
    n = [float(v) for v in re.findall(r'-?\d+(?:\.\d+)?', d)]
    xs, ys = n[0::2], n[1::2]
    x0, x1, y0, y1 = min(xs), max(xs), min(ys), max(ys)
    return ' '.join(fmt(v) for v in
                    (x0 - pad, y0 - pad, x1 - x0 + 2 * pad, y1 - y0 + 2 * pad))


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    for k, v in DEFAULTS.items():
        ap.add_argument('--' + k, type=type(v), default=v)
    ap.add_argument('--write', action='store_true',
                    help='overwrite the shipped SVGs instead of printing the path data')
    args = ap.parse_args()

    d, loops = trace(**{k: getattr(args, k) for k in DEFAULTS})
    print(f'{loops} loops, {d.count("C")} curves, {len(d)} bytes of path data',
          file=sys.stderr)

    if not args.write:
        print(d)
        return 0

    icon = ICON.format(tile=TILE, line=LINE, d=d)
    wm = WATERMARK.format(vb=bbox(d), line=LINE, d=d)
    for path, body in (
        (os.path.join(ROOT, 'src', 'icon.svg'), icon),
        (os.path.join(ROOT, 'promos', 'assets', 'icon.svg'), icon),
        (os.path.join(ROOT, 'promos', 'assets', 'watermark.svg'), wm),
    ):
        open(path, 'w').write(body)
        print(f'  wrote {os.path.relpath(path, ROOT)} ({len(body)} bytes)', file=sys.stderr)
    print('  src/icon-mask.svg is hand-drawn and was not touched', file=sys.stderr)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
