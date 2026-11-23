#!/usr/bin/env python3
"""Builds plugin/foxfire-studio/data/agents.php (1.24.0) from two open lists:

  OPAWG user-agents-v2 (MIT): bots, apps, libraries, browsers and devices.
  OP3 (MIT): its extra list of bot user agents not yet in OPAWG's.

  python3 tools/lists/agents.py <user-agents-v2 checkout> <op3 checkout>

Both are on GitHub (opawg/user-agents-v2, skymethod/op3); a shallow clone of
each is enough. The plugin matches in OPAWG's order: bots, apps, libraries,
browsers, then devices for anything that is not a bot.
"""
import json, os, re, sys, subprocess, datetime

def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"

def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    ua, op3 = sys.argv[1], sys.argv[2]
    here = os.path.dirname(os.path.abspath(__file__))
    out_dir = os.path.join(here, '..', '..', 'plugin', 'foxfire-studio', 'data')
    os.makedirs(out_dir, exist_ok=True)
    rows = {}
    for f in ('bots', 'apps', 'libraries', 'browsers', 'devices'):
        rows[f] = json.load(open(os.path.join(ua, 'src', f + '.json')))['entries']
    src = open(os.path.join(op3, 'worker', 'backend', 'bots.ts')).read()
    m = re.search(r'export const EXTRA_BOT_USER_AGENTS = \[(.*?)\];', src, re.S)
    if not m:
        sys.exit('OP3 extra bot list not found')
    extra = re.findall(r"'((?:[^'\\]|\\.)*)'", m.group(1))
    def rev(path):
        return subprocess.run(['git', '-C', path, 'log', '-1', '--format=%h %cs'], capture_output=True, text=True).stdout.strip()
    lines = ['<?php', '/*', ' * Podcast app, bot and device names for download counting (1.24.0).',
             ' * Made by tools/lists/agents.py from OPAWG user-agents-v2 (' + rev(ua) + ') and',
             " * OP3's extra bot list (" + rev(op3) + '), both MIT licensed: see agents-LICENSE.txt.',
             ' * Do not edit by hand.', ' */', '', "if ( ! defined( 'ABSPATH' ) ) {", '\texit;', '}', '', 'return array(',
             "\t'built' => " + php(datetime.date.today().isoformat()) + ','
             "\n\t'source' => " + php('OPAWG ' + rev(ua).split(' ')[1] + ', OP3 ' + rev(op3).split(' ')[1]) + ',']
    for f in ('bots', 'apps', 'libraries', 'browsers', 'devices'):
        lines.append("\t'" + f + "' => array(")
        for e in rows[f]:
            pat = e['pattern'].replace('/', '\\/')
            third = ''
            if f == 'libraries':
                third = ', ' + php(e.get('category', ''))
            if f == 'devices':
                third = ', ' + php(e.get('category', ''))
            lines.append('\t\tarray( ' + php(e['name']) + ', ' + php('/' + pat + '/') + third + ' ),')
        lines.append('\t),')
    lines.append("\t'extra' => array(")
    for x in extra:
        lines.append('\t\t' + php(x.replace("\\'", "'")) + ' => true,')
    lines.append('\t),')
    lines.append(');')
    open(os.path.join(out_dir, 'agents.php'), 'w').write('\n'.join(lines) + '\n')
    lic = ('The lists in agents.php come from two projects, both under the MIT License.\n\n'
           '== OPAWG user-agents-v2 (https://github.com/opawg/user-agents-v2) ==\n\n' + open(os.path.join(ua, 'LICENSE')).read().strip() +
           "\n\n== OP3, The Open Podcast Prefix Project (https://github.com/skymethod/op3), its extra bot list ==\n\n" + open(os.path.join(op3, 'LICENSE')).read().strip() + '\n')
    open(os.path.join(out_dir, 'agents-LICENSE.txt'), 'w').write(lic)
    print('bots', len(rows['bots']), 'apps', len(rows['apps']), 'libraries', len(rows['libraries']),
          'browsers', len(rows['browsers']), 'devices', len(rows['devices']), 'extra bots', len(extra))

main()
