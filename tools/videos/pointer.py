"""A visible pointer for the recordings: moves to an element, then a ring pulses as it is clicked."""

JS = r"""
(function () {
  if (document.getElementById('vidPointer')) { return; }
  var p = document.createElement('div');
  p.id = 'vidPointer';
  p.setAttribute('aria-hidden', 'true');
  p.style.cssText = 'position:fixed;left:-60px;top:-60px;width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50%;' +
    'background:rgba(255,255,255,.92);box-shadow:0 0 0 3px rgba(20,17,28,.85),0 2px 10px rgba(0,0,0,.5);z-index:2147483647;pointer-events:none;' +
    'transition:left .7s cubic-bezier(.3,.7,.3,1),top .7s cubic-bezier(.3,.7,.3,1),transform .15s;';
  document.documentElement.appendChild(p);
  window.vidPoint = function (x, y) { p.style.left = x + 'px'; p.style.top = y + 'px'; };
  window.vidPulse = function () {
    p.style.transform = 'scale(.7)';
    var r = document.createElement('div');
    r.style.cssText = 'position:fixed;left:' + p.style.left + ';top:' + p.style.top + ';width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50%;' +
      'border:3px solid #fff;z-index:2147483646;pointer-events:none;transition:transform .5s ease-out,opacity .5s ease-out;';
    document.documentElement.appendChild(r);
    requestAnimationFrame(function () { r.style.transform = 'scale(2.6)'; r.style.opacity = '0'; });
    setTimeout(function () { p.style.transform = ''; r.remove(); }, 520);
  };
  window.vidHide = function () { p.style.left = '-60px'; p.style.top = '-60px'; };
})();
"""


def ensure(page):
	page.evaluate(JS)


def move(page, sel, nth=0):
	ensure(page)
	loc = page.locator(sel).nth(nth)
	loc.scroll_into_view_if_needed()
	bb = loc.bounding_box()
	x, y = bb['x'] + bb['width'] / 2, bb['y'] + bb['height'] / 2
	page.evaluate('a => vidPoint(a[0], a[1])', [x, y])
	page.wait_for_timeout(850)
	return loc


def click(page, sel, nth=0, after=400):
	loc = move(page, sel, nth)
	page.evaluate('vidPulse()')
	page.wait_for_timeout(150)
	loc.click()
	page.wait_for_timeout(after)


def park(page, x=1250, y=600):
	ensure(page)
	page.evaluate('a => vidPoint(a[0], a[1])', [x, y])
