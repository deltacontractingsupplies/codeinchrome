#!/usr/bin/env python3
"""A stand-in for `gh api`, for .github/cla/test.sh: GitHub's answers from, and
its writes to, JSON files in $FAKE_GH (one state per test). Only the calls
cla.sh makes."""
import json, os, re, sys

state_dir = os.environ['FAKE_GH']
def load(name, default):
    p = os.path.join(state_dir, name)
    return json.load(open(p)) if os.path.exists(p) else default
def save(name, data):
    json.dump(data, open(os.path.join(state_dir, name), 'w'))

args = sys.argv[1:]
assert args[0] == 'api', args
args = args[1:]
method, fields, path = 'GET', {}, None
i = 0
while i < len(args):
    a = args[i]
    if a == '-X': method = args[i + 1]; i += 2; continue
    if a == '-f': k, v = args[i + 1].split('=', 1); fields[k] = v; i += 2; continue
    if a == '--paginate': i += 1; continue
    path = a; i += 1

log = load('calls.json', []); log.append({'method': method, 'path': path, 'fields': fields}); save('calls.json', log)

m = re.fullmatch(r'repos/[^/]+/[^/]+/(.*)', path)
rest = m.group(1)
if method == 'GET' and re.fullmatch(r'pulls/\d+', rest):
    print(json.dumps(load('pr.json', None)))
elif method == 'GET' and re.fullmatch(r'pulls/\d+/commits', rest):
    print(json.dumps(load('commits.json', [])))
elif method == 'GET' and rest.startswith('contents/'):
    f = load('file.json', None)
    if f is None:
        print('{"message":"Not Found"}'); sys.exit(1)
    print(json.dumps(f))
elif method == 'PUT' and rest.startswith('contents/'):
    f = load('file.json', None)
    if (f and fields.get('sha') != f['sha']) or (not f and 'sha' in fields):
        print('{"message":"conflict"}'); sys.exit(1)
    n = load('file_version.json', 0) + 1; save('file_version.json', n)
    save('file.json', {'sha': f'sha{n}', 'content': fields['content']})
    print('{}')
elif method == 'POST' and rest.startswith('statuses/'):
    save('status.json', dict(fields, sha=rest.split('/')[1])); print('{}')
elif method == 'GET' and re.fullmatch(r'issues/\d+/comments', rest):
    print(json.dumps(load('comments.json', [])))
elif method == 'POST' and re.fullmatch(r'issues/\d+/comments', rest):
    c = load('comments.json', []); c.append({'id': len(c) + 100, 'user': {'login': 'github-actions[bot]'}, 'body': fields['body']})
    save('comments.json', c); print('{}')
elif method == 'PATCH' and rest.startswith('issues/comments/'):
    cid = int(rest.rsplit('/', 1)[1]); c = load('comments.json', [])
    for x in c:
        if x['id'] == cid: x['body'] = fields['body']
    save('comments.json', c); print('{}')
else:
    sys.exit(f'fake gh: unhandled {method} {path}')
