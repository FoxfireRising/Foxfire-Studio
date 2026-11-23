/*
 * Foxfire Studio: the teleprompter, started. The studio's parts load first, in order:
 * studio-core.js, record.js, cues.js, voice.js, master.js, review.js and
 * editor.js, sharing window.RRTStudio (docs/ARCHITECTURE.md maps them). This
 * file measures the script, paints it, starts the animation loop and offers
 * a sent or unfinished take.
 */
(function () {
	var studio = window.RRTStudio;
	/* From the studio's other files. */
	var track = studio.track;
	var offerRecovery = studio.offerRecovery;
	var offerSent = studio.offerSent;
	var W = studio.W;
	var measure = studio.measure;
	var target = studio.target;
	var markAll = studio.markAll;
	var status = studio.status;
	var frame = studio.frame;
	var setPlayLabel = studio.setPlayLabel;
	var openSent = studio.openSent;
	var recoverTake = studio.recoverTake;
	if (!track) { return; }

	/* Start */
	function init() {
		if (!W.length) { return; }
		measure();
		markAll();
		studio.pos = target();
		status();
		setPlayLabel();
		window.requestAnimationFrame(frame);
		/* Phone take mode (1.12.0) first sends the rest of a take that was cut off, and may offer Carry on. */
		if (studio.liveStartup) { studio.liveStartup(function () { if (!offerSent(openSent)) { offerRecovery(recoverTake); } }); return; }
		if (!offerSent(openSent)) { offerRecovery(recoverTake); }
	}
	window.addEventListener('resize', measure);
	if (document.fonts) {
		if (document.fonts.ready) { document.fonts.ready.then(measure); }
	}
	init();
})();
