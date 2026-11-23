#!/usr/bin/env python3
"""A stand-in for outside storage, for the storage offload tests (1.23.0).

One small server plays four parts:

  /s3/<bucket>/<key>     an S3-compatible API that checks every request's
                         AWS Signature Version 4 with its own code (written
                         apart from the plugin's), with PUT, HEAD, GET,
                         DELETE, multipart uploads and PutBucketCors
  /bunny/<zone>/<key>    Bunny Storage's HTTP API (AccessKey, Checksum)
  /pub/<key>             the public side: what listeners get, with byte ranges
                         and CORS headers (both stores share one set of files)
  /cdn/<path>            a pull CDN in front of the test site: copies a file
                         from the origin the first time and keeps it
  /purge?url=            bunny.net's purge API (AccessKey)
  /admin/...             state, failures and CORS on or off, for the tests

Usage: storage.py PORT ORIGIN   (ORIGIN: the test site, for the CDN part)
Keys: S3 FFTESTKEY / fftestsecret, Bunny zone ff-test / bunnypass, purge purgekey.
"""
import hashlib
import hmac
import json
import re
import sys
import threading
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

S3_KEY, S3_SECRET, BUCKET = 'FFTESTKEY', 'fftestsecret', 'readings'
ZONE, ZONE_PASS, PURGE_KEY = 'ff-test', 'bunnypass', 'purgekey'

LOCK = threading.Lock()
STATE = {'objects': {}, 'cdn': {}, 'purges': [], 'uploads': {}, 'cors': True, 'fail': False, 'bucket_cors': '', 'log': []}
ORIGIN = ''


def sign_check(h, method, raw_path, query, body):
    """Checks an AWS Signature Version 4 Authorization header; returns '' or why not."""
    auth = h.get('authorization', '')
    m = re.match(r'AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([^,]+), Signature=([0-9a-f]{64})$', auth)
    if not m:
        return 'no signature'
    key, day, region, signed, sig = m.groups()
    if key != S3_KEY:
        return 'unknown key'
    payload = h.get('x-amz-content-sha256', '')
    if payload != 'UNSIGNED-PAYLOAD' and payload != hashlib.sha256(body).hexdigest():
        return 'payload hash'
    pairs = urllib.parse.parse_qsl(query, keep_blank_values=True)
    cq = '&'.join(urllib.parse.quote(k, safe='-_.~') + '=' + urllib.parse.quote(v, safe='-_.~') for k, v in sorted(pairs))
    ch = ''.join(n + ':' + ' '.join(h.get(n, '').split()) + '\n' for n in signed.split(';'))
    canon = '\n'.join([method, raw_path, cq, ch, signed, payload])
    date = h.get('x-amz-date', '')
    sts = '\n'.join(['AWS4-HMAC-SHA256', date, day + '/' + region + '/s3/aws4_request', hashlib.sha256(canon.encode()).hexdigest()])
    k = ('AWS4' + S3_SECRET).encode()
    for part in (day, region, 's3', 'aws4_request'):
        k = hmac.new(k, part.encode(), hashlib.sha256).digest()
    want = hmac.new(k, sts.encode(), hashlib.sha256).hexdigest()
    return '' if hmac.compare_digest(want, sig) else 'signature'


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *a):
        pass

    def body(self):
        n = int(self.headers.get('Content-Length') or 0)
        return self.rfile.read(n) if n else b''

    def send(self, code, data=b'', headers=None):
        self.send_response(code)
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(data)

    def xml_err(self, code, msg):
        self.send(code, ('<?xml version="1.0"?><Error><Code>E</Code><Message>%s</Message></Error>' % msg).encode(), {'Content-Type': 'application/xml'})

    def serve(self, obj, extra=None):
        """Answers a public read of an object, with ranges and CORS."""
        data, ctype, cache = obj
        h = {'Content-Type': ctype, 'Accept-Ranges': 'bytes'}
        if cache:
            h['Cache-Control'] = cache
        if STATE['cors'] and self.headers.get('Origin'):
            h['Access-Control-Allow-Origin'] = '*'
            h['Access-Control-Expose-Headers'] = 'Content-Range, Content-Length, Accept-Ranges'
        h.update(extra or {})
        rng = self.headers.get('Range', '')
        m = re.match(r'bytes=(\d+)-(\d*)$', rng)
        if m:
            a = int(m.group(1))
            b = int(m.group(2)) if m.group(2) else len(data) - 1
            b = min(b, len(data) - 1)
            h['Content-Range'] = 'bytes %d-%d/%d' % (a, b, len(data))
            return self.send(206, data[a:b + 1], h)
        return self.send(200, data, h)

    def do_OPTIONS(self):
        h = {}
        if STATE['cors']:
            h = {'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Methods': 'GET, HEAD', 'Access-Control-Allow-Headers': 'Range'}
        self.send(204, b'', h)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        self.route(b'')

    def do_PUT(self):
        self.route(self.body())

    def do_POST(self):
        self.route(self.body())

    def do_DELETE(self):
        self.route(b'')

    def route(self, body):
        u = urllib.parse.urlsplit(self.path)
        raw, query = u.path, u.query
        path = urllib.parse.unquote(raw)
        with LOCK:
            STATE['log'].append('%s %s' % (self.command, path))
        if path.startswith('/admin/'):
            return self.admin(path, query)
        if path == '/purge':
            if self.headers.get('AccessKey') != PURGE_KEY:
                return self.send(401)
            with LOCK:
                STATE['purges'].append(dict(urllib.parse.parse_qsl(query)).get('url', ''))
                url = STATE['purges'][-1]
                STATE['cdn'].pop(urllib.parse.urlsplit(url).path, None)
            return self.send(200)
        if path.startswith('/pub/'):
            if STATE['fail']:
                return self.send(503)
            obj = STATE['objects'].get(path[5:])
            return self.serve(obj) if obj else self.send(404)
        if path.startswith('/cdn/'):
            if STATE['fail']:
                return self.send(503)
            p = path[4:]
            obj = STATE['cdn'].get(p)
            if not obj:
                try:
                    r = urllib.request.urlopen(ORIGIN + p, timeout=20)
                    obj = (r.read(), r.headers.get('Content-Type', 'application/octet-stream').split(';')[0], '')
                except Exception:
                    return self.send(404)
                with LOCK:
                    STATE['cdn'][p] = obj
            return self.serve(obj)
        h = {k.lower(): v for k, v in self.headers.items()}
        if path.startswith('/bunny/'):
            parts = path[7:].split('/', 1)
            if len(parts) < 2 or parts[0] != ZONE:
                return self.send(404, b'{"Message":"Storage zone not found"}')
            if h.get('accesskey') != ZONE_PASS:
                return self.send(401, b'{"Message":"Unauthorized"}')
            key = parts[1]
            if self.command == 'PUT':
                if 'checksum' in h and h['checksum'] != hashlib.sha256(body).hexdigest().upper():
                    return self.send(400, b'{"Message":"Checksum"}')
                # Like the real Bunny Storage: the type comes from the extension, and .vtt is not one it knows.
                ext = key.rsplit('.', 1)[-1].lower()
                ctype = {'mp3': 'audio/mpeg', 'json': 'application/json', 'mp4': 'video/mp4'}.get(ext, 'application/octet-stream')
                with LOCK:
                    STATE['objects'][key] = (body, ctype, '')
                return self.send(201, b'{"Message":"File uploaded."}')
            if self.command == 'DELETE':
                with LOCK:
                    gone = STATE['objects'].pop(key, None)
                return self.send(200 if gone else 404)
            return self.send(405)
        if path.startswith('/s3/'):
            why = sign_check(h, self.command, raw, query, body)
            if why:
                return self.xml_err(403, 'The request signature we calculated does not match (%s)' % why)
            rest = path[4:].split('/', 1)
            if rest[0] != BUCKET:
                return self.xml_err(404, 'NoSuchBucket')
            key = rest[1] if len(rest) > 1 else ''
            q = dict(urllib.parse.parse_qsl(query, keep_blank_values=True))
            if self.command == 'PUT' and 'cors' in q and not key:
                if h.get('content-type', '').startswith('application/x-www-form'):
                    return self.xml_err(400, 'form body')
                with LOCK:
                    STATE['bucket_cors'] = body.decode()
                    STATE['cors'] = True
                return self.send(200)
            if self.command == 'POST' and 'uploads' in q:
                uid = hashlib.md5((key + str(len(STATE['uploads']))).encode()).hexdigest()
                with LOCK:
                    STATE['uploads'][uid] = {'key': key, 'type': h.get('content-type', ''), 'cache': h.get('cache-control', ''), 'parts': {}}
                return self.send(200, ('<InitiateMultipartUploadResult><UploadId>%s</UploadId></InitiateMultipartUploadResult>' % uid).encode(), {'Content-Type': 'application/xml'})
            if self.command == 'PUT' and 'partNumber' in q:
                up = STATE['uploads'].get(q.get('uploadId', ''))
                if not up:
                    return self.xml_err(404, 'NoSuchUpload')
                etag = '"%s"' % hashlib.md5(body).hexdigest()
                up['parts'][int(q['partNumber'])] = (etag, body)
                return self.send(200, b'', {'ETag': etag})
            if self.command == 'POST' and 'uploadId' in q:
                up = STATE['uploads'].pop(q['uploadId'], None)
                if not up:
                    return self.xml_err(404, 'NoSuchUpload')
                listed = re.findall(r'<PartNumber>(\d+)</PartNumber><ETag>([^<]+)</ETag>', body.decode())
                data = b''
                for n, etag in listed:
                    part = up['parts'].get(int(n))
                    if not part or part[0] != etag.replace('&quot;', '"'):
                        return self.xml_err(400, 'InvalidPart')
                    data += part[1]
                with LOCK:
                    STATE['objects'][up['key']] = (data, up['type'], up['cache'])
                return self.send(200, b'<CompleteMultipartUploadResult/>', {'Content-Type': 'application/xml'})
            if self.command == 'DELETE':
                if 'uploadId' in q:
                    STATE['uploads'].pop(q['uploadId'], None)
                    return self.send(204)
                with LOCK:
                    STATE['objects'].pop(key, None)
                return self.send(204)
            if self.command == 'PUT':
                if h.get('content-type', '').startswith('application/x-www-form'):
                    return self.xml_err(400, 'form body')
                with LOCK:
                    STATE['objects'][key] = (body, h.get('content-type', 'binary/octet-stream'), h.get('cache-control', ''))
                return self.send(200, b'', {'ETag': '"%s"' % hashlib.md5(body).hexdigest()})
            if self.command in ('GET', 'HEAD'):
                obj = STATE['objects'].get(key)
                return self.serve(obj) if obj else self.xml_err(404, 'NoSuchKey')
            return self.send(405)
        return self.send(404)

    def admin(self, path, query):
        q = dict(urllib.parse.parse_qsl(query))
        with LOCK:
            if path == '/admin/state':
                out = {
                    'objects': {k: {'size': len(v[0]), 'type': v[1], 'cache': v[2], 'sha': hashlib.sha256(v[0]).hexdigest()} for k, v in STATE['objects'].items()},
                    'cdn': sorted(STATE['cdn'].keys()),
                    'purges': STATE['purges'],
                    'cors': STATE['cors'],
                    'bucket_cors': STATE['bucket_cors'],
                    'fail': STATE['fail'],
                    'log': STATE['log'][-200:],
                }
                return self.send(200, json.dumps(out).encode(), {'Content-Type': 'application/json'})
            if path == '/admin/set':
                if 'cors' in q:
                    STATE['cors'] = q['cors'] == '1'
                if 'fail' in q:
                    STATE['fail'] = q['fail'] == '1'
                return self.send(200, b'{}')
            if path == '/admin/reset':
                STATE.update({'objects': {}, 'cdn': {}, 'purges': [], 'uploads': {}, 'cors': True, 'fail': False, 'bucket_cors': '', 'log': []})
                return self.send(200, b'{}')
        return self.send(404)


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8090
    ORIGIN = sys.argv[2].rstrip('/') if len(sys.argv) > 2 else 'http://localhost:8080'
    ThreadingHTTPServer(('127.0.0.1', port), Handler).serve_forever()
