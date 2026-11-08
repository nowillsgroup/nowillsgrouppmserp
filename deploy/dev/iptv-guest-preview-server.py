#!/usr/bin/env python3
"""Serve the real guest TV client with isolated sample data; no hotel API or TV required."""
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
import json
import os
import re

ROOT = Path(__file__).resolve().parents[2]
lang = (ROOT / 'app/Support/TvLang.php').read_text()
en = lang[lang.index("'en' => ["):lang.index("'fr' => [")]
translations = dict(re.findall(r"'([a-z_]+)' => '([^']*)'", en))
boot = {
    'mode': 'room', 'lang': 'en', 'langs': {'en': 'English'}, 't': translations,
    'hotel': {'name': 'Groove Esipong Beach Hotel', 'city': 'Essipong'},
    'guest': {'room': '204', 'name': 'Ama Mensah', 'departure': '12 Nov 2026'},
    'wifi': {'name': 'Guest Wi-Fi', 'password': 'Sample password'},
    'features': {k: True for k in ['tv', 'movies', 'dining', 'services', 'messages', 'info', 'explore']},
    'welcome': {'show': True, 'theme': 'night', 'headline': 'Welcome to your stay',
                'body': 'We are delighted to have you with us.\nRelax, explore and make yourself at home.'},
    'unread': 0, 'desk_messages': 0, 'desk_ext': '0', 'heartbeat': 600,
}
icons = (ROOT / 'resources/views/tv/_icons.blade.php').read_text()
mock = r'''<script>(function(){var boot=JSON.parse(document.getElementById('tvBoot').textContent);
var responses={'/state':boot,'/welcome':{},'/event':{},'/data/channels':{channels:[{no:1,name:'Sample channel',cat_label:'Preview only',url:''},{no:2,name:'Sample news',cat_label:'Preview only',url:''}]},'/data/movies':{movies:[{id:1,title:'Ocean Dreams',genre:'Adventure',rating:'PG',mins:105,hue:196,price:'GH₵ 35.00',rented:false}]},'/data/dining':{outlet:'Garden Restaurant',cats:[{name:'Local dishes',items:[{id:11,name:'Banku & Okro Stew',price:'GH₵ 58.00',raw:58,veg:false,image:null,modifiers:[],sides:[{id:501,name:'Garden salad',rate:0},{id:502,name:'French fries',rate:25}],side_required:true},{id:12,name:'Jollof rice',price:'GH₵ 65.00',raw:65,veg:false,modifiers:[],sides:[]}]}]},'/data/services':{cats:[],wakeups:[],open:[]},'/data/messages':{desk:false,messages:[]},'/data/pages':{pages:[{title:'Hotel information',category:'Guest information',body:'For assistance, please contact the front desk.'}]},'/data/explore':{places:[]},'/order':{number:'DEMO-001'}};
window.XMLHttpRequest=function(){this.readyState=0;this.status=0};XMLHttpRequest.prototype.open=function(method,url){this.url=url};XMLHttpRequest.prototype.setRequestHeader=function(){};XMLHttpRequest.prototype.send=function(){var self=this,path=self.url.replace(/^\/demo-tv/,'').split('?')[0];setTimeout(function(){self.readyState=4;self.status=Object.prototype.hasOwnProperty.call(responses,path)?200:404;self.responseText=JSON.stringify(responses[path]||{error:'Unavailable in this sample preview.'});if(self.onreadystatechange)self.onreadystatechange()},0)};})();</script>'''
html = ('''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IPTV guest preview · sample data</title><link rel="stylesheet" href="/css/tv.css"><style>.demo-label{position:fixed;top:3px;right:6px;z-index:99999;background:#fff;color:#222;border-radius:4px;font:600 11px system-ui;padding:4px 7px;opacity:.9}</style></head><body class="tv"><div class="demo-label">PREVIEW · SAMPLE DATA · NO LIVE CHANNELS</div>''' + icons + '''<div id="tvApp" data-base="/demo-tv" data-welcome-image="/images/iptv/groove-esipong-beach-hotel.jpg" data-hotel-logo="/images/iptv/groove-esipong-beach-resort-logo.png"></div><script id="tvBoot" type="application/json">''' + json.dumps(boot).replace('</', '<\\/') + '''</script>''' + mock + '''<script src="/js/tv.js"></script></body></html>''').encode()
assets = {
    '/css/tv.css': (ROOT / 'public/css/tv.css', 'text/css'),
    '/js/tv.js': (ROOT / 'public/js/tv.js', 'text/javascript'),
    '/images/iptv/groove-esipong-beach-hotel.jpg': (ROOT / 'public/images/iptv/groove-esipong-beach-hotel.jpg', 'image/jpeg'),
    '/images/iptv/groove-esipong-beach-resort-logo.png': (ROOT / 'public/images/iptv/groove-esipong-beach-resort-logo.png', 'image/png'),
}

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = self.path.split('?')[0]
        if path == '/':
            data, mime = html, 'text/html; charset=utf-8'
        elif path in assets:
            file, mime = assets[path]
            data = file.read_bytes()
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(data)

if __name__ == '__main__':
    port = int(os.environ.get('PORT', '4185'))
    print(f'IPTV sample preview listening on 0.0.0.0:{port}', flush=True)
    HTTPServer(('0.0.0.0', port), Handler).serve_forever()
