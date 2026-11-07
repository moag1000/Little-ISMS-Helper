#!/usr/bin/env python3
"""Build the bundled data of the location map (docs/user-guide/STANDORTKARTE.md).

Inputs (download first, see NOTICE.md for licences):
  --welt       Natural Earth outlines as {"land": [[[lon, lat], ...]], "grenzen": [...]}
               (same file as moag1000/tycoon app/assets/data/welt.json)
  --postal     directory with DE/AT/CH zipcodes.<cc>.csv from
               zauberware/postal-codes-json-xml-csv (GeoNames, CC BY 4.0)
  --cities     JSON list of {country, name, pop, lat, lon} decoded from the
               npm package all-the-cities (GeoNames cities1000, CC BY 4.0)
  --countries  countries-50m.json from the npm package world-atlas 2.0.2
               (Natural Earth, public domain) plus --numeric, a JSON map
               {"_276": "DE", ...} (symfony/intl regions meta NumericToAlpha2)

Outputs:
  assets/data/world-map.json      Equal Earth, width 1000, crop 84N..58S;
                                  "laender" = one path per ISO alpha-2 country
                                  (GDPR country zones of the map)
  fixtures/geo/postal_dach.csv.gz country, postal_code, place, lat, lng
  fixtures/geo/cities.csv.gz      country, name, lat, lng (largest place per name, DACH excluded)

The projection constants must match assets/controllers/location_map_controller.js.
"""
import argparse, csv, gzip, io, json, math
from pathlib import Path

A1, A2, A3, A4 = 1.340264, -0.081106, 0.000893, 0.003796
EM = math.sqrt(3) / 2


def raw(lon, lat):
    l = math.radians(lon); t = math.asin(EM * math.sin(math.radians(lat)))
    t2 = t * t; t6 = t2 ** 3
    return l * math.cos(t) / (EM * (A1 + 3 * A2 * t2 + t6 * (7 * A3 + 9 * A4 * t2))), t * (A1 + A2 * t2 + t6 * (A3 + A4 * t2))


XMAX = raw(180, 0)[0]; SCALE = 1000 / (2 * XMAX); TOP = raw(0, 84)[1]; BOTTOM = raw(0, -58)[1]


def project(lon, lat):
    x, y = raw(lon, lat)
    return (x + XMAX) * SCALE, (TOP - y) * SCALE


def dp(pts,eps):
    if len(pts)<3: return pts
    a,b=pts[0],pts[-1]; dmax=0; idx=0
    ax,ay=a; bx,by=b; L=math.hypot(bx-ax,by-ay) or 1e-9
    for i in range(1,len(pts)-1):
        px,py=pts[i]; dd=abs((bx-ax)*(ay-py)-(ax-px)*(by-ay))/L
        if dd>dmax: dmax=dd; idx=i
    if dmax>eps: return dp(pts[:idx+1],eps)[:-1]+dp(pts[idx:],eps)
    return [a,b]
def path(rings,eps,closed,minarea=0):
    out=[]
    for r in rings:
        allpts=[project(lo,la) for lo,la in r if la>-60]
        runs=[[]]
        for q0 in allpts:
            if runs[-1] and abs(runs[-1][-1][0]-q0[0])>300: runs.append([])
            runs[-1].append(q0)
        for pts in runs:
          if len(pts)<2: continue
          if closed:
              xs=[p[0] for p in pts]; ys=[p[1] for p in pts]
              if (max(xs)-min(xs))*(max(ys)-min(ys))<minarea: continue
          if closed and len(pts)>3:
              h=len(pts)//2; q=dp(pts[:h+1],eps)[:-1]+dp(pts[h:],eps)
          else: q=dp(pts,eps)
          if closed and len(q)<3: continue
          out.append('M'+'L'.join(f'{x:.1f} {y:.1f}' for x,y in q)+('Z' if closed else ''))
    return ''.join(out)


# Natural Earth areas without an ISO numeric code.
NO_NUMERIC = {'Kosovo': 'XK', 'Somaliland': 'SO', 'N. Cyprus': 'CY'}


def topo_rings(topo, numeric):
    """TopoJSON countries -> {alpha2: [outer rings as [[lon, lat], ...]]}."""
    sx, sy = topo['transform']['scale']; tx, ty = topo['transform']['translate']
    arcs = []
    for arc in topo['arcs']:
        x = y = 0; pts = []
        for dx, dy in arc:
            x += dx; y += dy
            pts.append((x * sx + tx, y * sy + ty))
        arcs.append(pts)
    out = {}
    for g in topo['objects']['countries']['geometries']:
        code = numeric.get('_' + str(g.get('id'))) if g.get('id') else NO_NUMERIC.get(g['properties']['name'])
        if not code or 'arcs' not in g:
            continue
        polys = g['arcs'] if g['type'] == 'MultiPolygon' else [g['arcs']]
        for poly in polys:
            ring = []
            for r in poly[0]:
                seg = arcs[r] if r >= 0 else arcs[~r][::-1]
                ring += seg if not ring else seg[1:]
            out.setdefault(code, []).append(ring)
    return out


def write_csv_gz(path, header, rows):
    buf = io.StringIO(); w = csv.writer(buf, lineterminator='\n')
    w.writerow(header); w.writerows(rows)
    Path(path).write_bytes(gzip.compress(buf.getvalue().encode(), 9))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--welt'); ap.add_argument('--postal'); ap.add_argument('--cities')
    ap.add_argument('--countries'); ap.add_argument('--numeric')
    a = ap.parse_args()
    target = Path('assets/data/world-map.json')
    if a.welt:
        d = json.load(open(a.welt))
        out = {'w': 1000, 'h': round((TOP - BOTTOM) * SCALE, 1),
               'land': path(d['land'], 0.35, True, 1.5), 'grenzen': path(d['grenzen'], 0.5, False)}
        if target.exists():
            out['laender'] = json.loads(target.read_text()).get('laender', {})
        target.write_text(json.dumps(out, separators=(',', ':')))
    if a.countries:
        out = json.loads(target.read_text())
        rings = topo_rings(json.load(open(a.countries)), json.load(open(a.numeric)))
        laender = {}
        for code in sorted(rings):
            d = path(rings[code], 0.5, True, 0.05)
            if d:
                laender[code] = d
        out['laender'] = laender
        target.write_text(json.dumps(out, separators=(',', ':')))
    if a.postal:
        rows = set()
        for cc in ('de', 'at', 'ch'):
            with open(Path(a.postal) / cc.upper() / f'zipcodes.{cc}.csv', newline='', encoding='utf-8') as f:
                for r in csv.DictReader(f):
                    try:
                        lat, lng = float(r['latitude']), float(r['longitude'])
                    except ValueError:
                        continue
                    rows.add((r['country_code'], r['zipcode'].strip(), r['place'].strip(), round(lat, 3), round(lng, 3)))
        write_csv_gz('fixtures/geo/postal_dach.csv.gz', ['country', 'postal_code', 'place', 'lat', 'lng'], sorted(rows))
    if a.cities:
        cities = [c for c in json.load(open(a.cities))
                  if c.get('country') and c['country'] not in ('DE', 'AT', 'CH') and 'lat' in c and c.get('name')]
        cities.sort(key=lambda c: (c['country'], -c.get('pop', 0)))
        seen, rows = set(), []
        for c in cities:
            key = (c['country'], c['name'].lower())
            if key not in seen:
                seen.add(key); rows.append((c['country'], c['name'], round(c['lat'], 2), round(c['lon'], 2)))
        write_csv_gz('fixtures/geo/cities.csv.gz', ['country', 'name', 'lat', 'lng'], rows)


if __name__ == '__main__':
    main()
