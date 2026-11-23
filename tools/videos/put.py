"""Puts the made-up take on the post (Use this recording), for the player and clip videos."""
from playwright.sync_api import sync_playwright
from common import *
from review_common import open_take
with sync_playwright() as p:
	b, ctx, page, box = launch(p)
	page.goto(TP, wait_until='load'); page.wait_for_timeout(1500)
	wav = open_take(page, box); page.set_input_files('#tpFile', wav)
	page.wait_for_selector('#tpRvScript [data-ti]', timeout=120000); page.wait_for_timeout(1500)
	page.locator('button:has-text("Use this recording"):visible').first.click()
	page.wait_for_timeout(8000)
	print(page.evaluate("document.getElementById('tpEdStatus') ? '' : ''"))
	link = page.evaluate("fetch('/?rest_route=/wp/v2/posts/%d').then(r=>r.json()).then(j=>j.link)" % POST)
	page.goto(link, wait_until='load'); page.wait_for_timeout(2500)
	page.screenshot(path='/tmp/post.png', full_page=False)
	print(link, page.evaluate("!!document.querySelector('.rrt-player')"))
	b.close()
