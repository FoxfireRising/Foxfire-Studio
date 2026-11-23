"""
Makes a test translation of Foxfire Studio for the made-up locale "eo"
(Esperanto's code, so WordPress accepts it): every string is wrapped in
⟦ ⟧ and otherwise unchanged, placeholders included. Writes the .mo and the
script .json files into the test site's wp-content/languages/plugins/.

Usage: python3 tests/wp/pseudo.py <wordpress dir>\nThen set the option foxfire_test_locale to 1 to switch the test site to it.
"""
import os, re, subprocess, sys

wp_dir = sys.argv[1]
repo = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
pot = os.path.join(repo, 'plugin', 'foxfire-studio', 'languages', 'foxfire-studio.pot')
out_dir = os.path.join(wp_dir, 'wp-content', 'languages', 'plugins')
os.makedirs(out_dir, exist_ok=True)
po_path = os.path.join(out_dir, 'foxfire-studio-eo.po')

text = open(pot, encoding='utf-8').read()
text = text.replace('"Content-Type: text/plain; charset=UTF-8\\n"', '"Content-Type: text/plain; charset=UTF-8\\n"\n"Language: eo\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"', 1)

def wrap(s):
    return '⟦' + s + '⟧' if s else s

blocks = text.split('\n\n')
out = []
for b in blocks:
    lines = b.split('\n')
    def joined(key):
        vals, on = [], False
        for l in lines:
            if l.startswith(key + ' '):
                on = True
                vals.append(l[len(key) + 1:])
            elif on and l.startswith('"'):
                vals.append(l)
            elif on:
                on = False
        return ''.join(v[1:-1] for v in vals) if vals else None
    mid = joined('msgid')
    if mid is None or mid == '':
        out.append(b)
        continue
    plural = joined('msgid_plural')
    keep = [l for l in lines if not l.startswith('msgstr') and not (l.startswith('"') and any(x.startswith('msgstr') for x in lines[:lines.index(l)]))]
    keep = [l for l in keep if not l.startswith('msgstr')]
    if plural is None:
        keep.append('msgstr "' + wrap(mid) + '"')
    else:
        keep.append('msgstr[0] "' + wrap(mid) + '"')
        keep.append('msgstr[1] "' + wrap(plural) + '"')
    out.append('\n'.join(keep))
open(po_path, 'w', encoding='utf-8').write('\n\n'.join(out))

wp = ['php', os.path.join(os.path.dirname(wp_dir.rstrip('/')), 'wp-cli.phar'), '--allow-root', '--path=' + wp_dir]
subprocess.run(wp + ['i18n', 'make-mo', po_path, out_dir], check=True, stdout=subprocess.DEVNULL)
subprocess.run(wp + ['i18n', 'make-json', po_path, out_dir, '--no-purge'], check=True, stdout=subprocess.DEVNULL)
mu = os.path.join(wp_dir, 'wp-content', 'mu-plugins')
os.makedirs(mu, exist_ok=True)
open(os.path.join(mu, 'foxfire-test-locale.php'), 'w').write("""<?php
/* Test site only: switches the site to the made-up locale "eo" while the option foxfire_test_locale is set. */
add_filter( 'locale', function ( $l ) { return get_option( 'foxfire_test_locale' ) ? 'eo' : $l; } );
""")
print('Test translation "eo" written to ' + out_dir)
