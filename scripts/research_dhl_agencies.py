import requests,re,json,html,concurrent.futures
from urllib.parse import urljoin
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'database/data/international-carrier-agencies.json'
data=json.load(open(p))
roots=['https://locations.us.express.dhl.com/ny/new-york','https://locations.us.express.dhl.com/fl/miami','https://locations.us.express.dhl.com/ca/los-angeles','https://locations.ca.express.dhl.com/on/toronto','https://locations.ca.express.dhl.com/qc/montreal']
urls=set()
for root in roots:
 s=requests.get(root,timeout=30).text
 for href in re.findall(r'href="([^"]+)"',s):
  u=urljoin(root,html.unescape(href))
  if u.startswith(root+'/') and '?' not in u and '#' not in u: urls.add(u)
def fetch(u):
 try:
  s=requests.get(u,timeout=30).text
  def meta(k):
   m=re.search(r'<meta[^>]*itemprop="'+k+r'"[^>]*content="([^"]+)"',s)
   return html.unescape(m[1]) if m else None
  lat,lng,addr=meta('latitude'),meta('longitude'),meta('streetAddress')
  if not lat or not lng or not addr:return
  city=re.search(r'class="Address-field Address-city">([^<]+)',s)
  city=html.unescape(city[1]) if city else u.split('/')[-2].replace('-',' ').title()
  return dict(carrier='DHL Express',country='CA' if '.ca.' in u else 'US',name='DHL Express Service Point '+city,city=city,address=addr,latitude=float(lat),longitude=float(lng),source_url=u,verified_on='2026-10-24',coordinate_precision='published',provisional=False)
 except Exception:return
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
 results=[v for v in pool.map(fetch,sorted(urls)) if v]
data['locations']=[v for v in data['locations'] if v['carrier']!='DHL Express']+results
data['sources']=list(dict.fromkeys(data['sources']+roots))
open(p,'w').write(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
print('DHL locations',len(results))
