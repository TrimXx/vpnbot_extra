#!/usr/bin/env python3
import json
import sys

path = sys.argv[1] if len(sys.argv) > 1 else '/config/xray.json'
with open(path, 'r', encoding='utf-8') as fh:
    config = json.load(fh)

config['stats'] = {}
levels = config.get('policy', {}).get('levels')
if isinstance(levels, list) and levels:
    config.setdefault('policy', {})['levels'] = {'0': levels[0]}

with open(path, 'w', encoding='utf-8') as fh:
    json.dump(config, fh, indent=4, ensure_ascii=False)
    fh.write('\n')

print('fixed', path)
