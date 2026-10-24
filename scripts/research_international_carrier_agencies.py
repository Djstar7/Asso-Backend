"""Research a starter catalogue from official public carrier directories.

Run manually; seeding never calls external services. Entries retain source URLs and
collection dates. A listed agency is a map reference, not proof of nationwide service.
"""
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlparse
import json
import re
import threading
import requests

BASE = 'https://local.fedex.com/'
local = threading.local()

class Page(HTMLParser):
    def __init__(self, html):
        super().__init__(); self.links=[]; self.meta={}; self.stack=[]; self.name=''; self.h1=''
        self.feed(html)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='a': self.links.append({'href':attrs.get('href',''), 'class':attrs.get('class','')})
        if tag=='meta' and attrs.get('itemprop'):
            self.meta.setdefault(attrs['itemprop'],attrs.get('content',''))
        self.stack.append((tag,attrs)) if tag not in ('meta','link','img','input','br','hr','source','wbr') else None
    def handle_endtag(self,tag):
        for i in range(len(self.stack)-1,-1,-1):
            if self.stack[i][0]==tag: self.stack=self.stack[:i]; break
    def handle_data(self,data):
        if any(a.get('id')=='location-name' for _,a in self.stack): self.name+=data
        if any(t=='h1' for t,_ in self.stack): self.h1+=data

def fetch(url):
    if not hasattr(local,'session'):
        local.session=requests.Session(); local.session.headers['User-Agent']='ASSO-Delivery-Reference-Research/1.0'
    response=local.session.get(url,timeout=(5,15));response.raise_for_status()
    return Page(response.text)

def canonical(href):
    clean=re.sub(r'^(?:\.\./|\./)+','',href)
    return urljoin(BASE,clean)

def physical(page,url,country,carrier='FedEx'):
    try:
        lat=float(page.meta['latitude']);lng=float(page.meta['longitude'])
    except (KeyError,ValueError): return None
    street=page.meta.get('streetAddress','').strip();name=' '.join(page.name.split()) or page.h1.strip()
    if not street or not name or any(x in name.lower() for x in ['not in use','non accepté','not accepted']):return None
    return {'carrier':carrier,'country':country,'name':name,'city':page.meta.get('name','') or urlparse(url).path.split('/')[-2].replace('-',' ').title(),
        'address':street,'latitude':lat,'longitude':lng,'source_url':url,'verified_on':datetime.now(timezone.utc).date().isoformat(),
        'coordinate_precision':'published','provisional':False}

def country_locations(country,url):
    queue=[url];visited=set();records=[]
    try:
        while queue and len(visited)<6 and len(records)<2:
            current=queue.pop(0)
            if current in visited:continue
            visited.add(current)
            page=fetch(current)
            entry=physical(page,current,country)
            if entry:records.append(entry);continue
            links=[]
            for link in page.links:
                target=canonical(link['href'])
                if not target.startswith(url.rstrip('/')+'/') or target in visited or target in links:continue
                if any(word in link['class'] for word in ['Directory-listLink','Teaser-titleLink']):links.append(target)
            preferred=['paris','roissy','berlin','london','new-york','toronto','ottawa','dubai','abu-dhabi','sydney','singapore','tokyo','dakar','cotonou','abidjan','brussels','amsterdam','madrid','rome','accra','nairobi','lagos','johannesburg','casablanca','buenos-aires','sao-paulo','montreal','beijing','hong-kong']
            links.sort(key=lambda u: next((i for i,p in enumerate(preferred) if '/'+p in u),999))
            queue=links[:2]+queue
    except Exception as error:
        return country,records,str(error)[:100]
    return country,records,None

if __name__=='__main__':
    root=fetch(BASE+'en')
    targets={}
    for link in root.links:
        url=canonical(link['href']);match=re.fullmatch(r'https://local\.fedex\.com/en-([a-z]{2})/?',url)
        if match and match[1]!='cm':targets[match[1].upper()]=url
    records=[];errors=[]
    print(f'Researching {len(targets)} official FedEx country directories',flush=True)
    with ThreadPoolExecutor(max_workers=6) as pool:
        futures=[pool.submit(country_locations,c,u) for c,u in targets.items()]
        for i,future in enumerate(as_completed(futures),1):
            country,found,error=future.result();records.extend(found)
            if error:errors.append({'country':country,'error':error})
            if i%20==0:print(f'{i}/{len(targets)} countries examined, {len(records)} sourced locations',flush=True)
            Path('/tmp/asso-carrier-agencies-research.partial.json').write_text(json.dumps({'locations':records,'errors':errors},ensure_ascii=False,indent=2))
    records.sort(key=lambda r:(r['country'],r['carrier'],r['source_url']))
    output=Path(__file__).resolve().parents[1]/'database/data/international-carrier-agencies.json'
    output.write_text(json.dumps({'collected_on':datetime.now(timezone.utc).date().isoformat(),'sources':['https://local.fedex.com/en'],
        'locations':records},ensure_ascii=False,indent=2)+'\n')
    print(f'Saved {len(records)} locations across {len(set(r["country"] for r in records))} countries to {output}',flush=True)
