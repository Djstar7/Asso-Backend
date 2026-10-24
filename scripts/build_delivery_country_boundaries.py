"""Build the runtime map from Natural Earth's public-domain 1:50m map units.

Usage: python scripts/build_delivery_country_boundaries.py /path/to/ne_50m_admin_0_map_units.geojson
Source: https://github.com/nvkelso/natural-earth-vector/blob/master/geojson/ne_50m_admin_0_map_units.geojson
No third-party Python dependency. Coordinates are simplified for map display.
"""
import hashlib
import json
import math
import pathlib
import sys


def simplify(points, epsilon=0.025):
    if len(points) <= 2:
        return points
    ax, ay = points[0]
    bx, by = points[-1]
    dx, dy = bx - ax, by - ay
    length = dx * dx + dy * dy
    def distance(p):
        t = max(0, min(1, ((p[0] - ax) * dx + (p[1] - ay) * dy) / length)) if length else 0
        return math.hypot(p[0] - ax - t * dx, p[1] - ay - t * dy)
    index = max(range(1, len(points) - 1), key=lambda i: distance(points[i]))
    if distance(points[index]) <= epsilon:
        return [points[0], points[-1]]
    return simplify(points[:index + 1], epsilon)[:-1] + simplify(points[index:], epsilon)


def ring(source):
    points = [[round(p[0], 4), round(p[1], 4)] for p in source]
    reduced = simplify(points)
    if len(reduced) < 4:
        reduced = points
    return [[lat, lng] for lng, lat in reduced]


source = pathlib.Path(sys.argv[1])
data = json.loads(source.read_text())
countries = {}
# Map units identify dependencies separately even when the admin country ISO is shared.
units = {'GUF': 'GF', 'GLP': 'GP', 'MTQ': 'MQ', 'REU': 'RE', 'MYT': 'YT',
         'SJM': 'SJ', 'CCK': 'CC', 'CXR': 'CX', 'BES': 'BQ', 'BVT': 'BV',
         'TKL': 'TK', 'UMI': 'UM', 'GIB': 'GI'}
for feature in data['features']:
    props = feature['properties']
    code = units.get(props.get('GU_A3'), props.get('ISO_A2_EH', props.get('ISO_A2')))
    if not code or len(code) != 2 or code == '-99':
        continue
    geometry = feature['geometry']
    raw = [geometry['coordinates']] if geometry['type'] == 'Polygon' else geometry['coordinates']
    polygons = [[ring(r) for r in polygon] for polygon in raw]
    entry = countries.setdefault(code, {'center': [props.get('LABEL_Y'), props.get('LABEL_X')], 'polygons': []})
    entry['polygons'].extend(polygons)
for code, entry in countries.items():
    entry['bounds'] = [[min(p[0] for p in poly[0]), min(p[1] for p in poly[0]),
                        max(p[0] for p in poly[0]), max(p[1] for p in poly[0])] for poly in entry['polygons']]
    if None in entry['center']:
        bounds = entry['bounds'][0]
        entry['center'] = [(bounds[0] + bounds[2]) / 2, (bounds[1] + bounds[3]) / 2]
# Tiny destinations without a 1:50m outline: navigation points only, never circles of coverage.
for code, center in {'BV': [-54.42, 3.35], 'GI': [36.14, -5.35], 'UM': [16.75, -169.52]}.items():
    countries.setdefault(code, {'center': center, 'polygons': [], 'bounds': []})
output = pathlib.Path(__file__).resolve().parents[1] / 'database/data/delivery-country-boundaries.json'
output.write_text(json.dumps({'source': 'Natural Earth 1:50m admin-0 map units',
    'license': 'Public domain', 'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
    'countries': countries}, ensure_ascii=False, separators=(',', ':')))
print(f'{len(countries)} countries/territories, {output.stat().st_size} bytes')
