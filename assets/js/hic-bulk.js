/**
 * HEIC to JPG Converter – converts HEIC images already in the Media Library.
 * Each file is converted on the server (when enabled and supported) or downloaded,
 * converted in the browser and sent back. The attachment ID stays the same.
 */
(function (window, document) {
	'use strict';

	var cfg = window.hicBulk;
	var HIC = window.HIC;
	if (!cfg || !HIC || !cfg.items || !cfg.items.length) {
		return;
	}

	var i18n = cfg.i18n || {};
	var workers = Math.max(1, parseInt((window.hicSettings || {}).concurrency, 10) || 2);
	var running = false;

	function fmt(str, a, b) {
		return String(str || '').replace('%1$d', a).replace('%2$d', b);
	}

	function setStatus(id, text, kind) {
		var el = document.querySelector('[data-hic-status="' + id + '"]');
		if (el) {
			el.textContent = text;
			el.className = 'hic-pill' + (kind ? ' is-' + kind : '');
			el.removeAttribute('title');
		}
	}

	function setThumb(id, url) {
		var el = document.querySelector('[data-hic-thumb="' + id + '"]');
		if (el && url) {
			var img = document.createElement('img');
			img.src = url;
			img.alt = '';
			img.loading = 'lazy';
			el.textContent = '';
			el.appendChild(img);
		}
	}

	/** Adds delta to a stat card on the Tools page (no-op on other screens). */
	function bump(key, delta) {
		var card = document.querySelector('[data-hic-stat="' + key + '"]');
		if (!card) {
			return;
		}
		var value = Math.max(0, (parseInt(card.getAttribute('data-value'), 10) || 0) + delta);
		card.setAttribute('data-value', value);
		card.querySelector('strong').textContent = value.toLocaleString();
		if (key === 'waiting') {
			card.classList.toggle('is-warn', value > 0);
		} else if (key === 'failed') {
			card.classList.toggle('is-error', value > 0);
		}
	}

	function setProgress(text, processed, total) {
		var el = document.getElementById('hic-progress');
		if (el) {
			el.textContent = text;
		}
		var bar = document.getElementById('hic-bar');
		if (bar) {
			bar.setAttribute('aria-valuenow', processed);
			bar.setAttribute('aria-valuemax', total);
			bar.firstElementChild.style.width = (total ? Math.round(processed / total * 100) : 0) + '%';
		}
	}

	/** Download → convert in this browser → upload. */
	function convertInBrowser(item) {
		setStatus(item.id, i18n.downloading, 'busy');

		return window.fetch(item.url, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (res) {
				if (!res.ok) {
					throw new Error('Download failed (HTTP ' + res.status + ')');
				}
				return res.blob();
			})
			.then(function (blob) {
				setStatus(item.id, i18n.converting, 'busy');
				return HIC.convert(blob, item.name);
			})
			.then(function (file) {
				setStatus(item.id, i18n.uploading, 'busy');
				var fd = new window.FormData();
				fd.append('file', file, file.name);
				fd.append('ms', file.hicMs || 0);
				fd.append('decoder', file.hicDecoder || '');
				return HIC.post('convert/' + item.id, fd);
			});
	}

	/** Server conversion, falling back to the browser if the server cannot do this file. */
	function convertOnServer(item) {
		setStatus(item.id, i18n.serverConverting, 'busy');
		var fd = new window.FormData();
		fd.append('server', '1');
		return HIC.post('convert/' + item.id, fd).catch(function (err) {
			if (err && err.status === 409) {
				throw err;
			}
			if (window.console) {
				window.console.warn('Server conversion failed for ' + item.name + ', trying the browser.', err);
			}
			return convertInBrowser(item);
		});
	}

	function convertOne(item) {
		return (cfg.server ? convertOnServer(item) : convertInBrowser(item))
			.then(function (result) {
				setStatus(item.id, i18n.done, 'done');
				setThumb(item.id, result && (result.thumb || result.url));
				bump('waiting', -1);
				if (!(result && result.skipped)) {
					bump('library', 1); // Skipped = another tab converted it and counted it.
				}
				if (item.failed) {
					bump('failed', -1);
				}
				item.failed = false;
				return true;
			})
			.catch(function (err) {
				// Another tab is converting this image right now: not a failure.
				if (err && err.status === 409) {
					setStatus(item.id, i18n.elsewhere, 'busy');
					bump('waiting', -1);
					return true;
				}
				if (!item.failed) {
					bump('failed', 1);
				}
				var msg = HIC.errorText(err);
				if (window.console) {
					window.console.error('HEIC conversion failed for ' + item.name, err, err && err.details);
				}
				setStatus(item.id, i18n.failed + ': ' + msg, 'error');
				item.failed = true;
				var fd = new window.FormData();
				fd.append('message', msg + (err && err.details ? ' [' + err.details + ']' : ''));
				HIC.post('fail/' + item.id, fd).catch(function () {});
				return false;
			});
	}

	function onBeforeUnload(e) {
		if (running && cfg.mode === 'page') {
			e.preventDefault();
			e.returnValue = i18n.leaveWarn;
			return i18n.leaveWarn;
		}
	}

	function setButtons(disabled) {
		['hic-start', 'hic-retry'].forEach(function (id) {
			var button = document.getElementById(id);
			if (button) {
				button.disabled = disabled;
			}
		});
	}

	/** Converts the given items, a few at a time. */
	function run(list) {
		var items = (Array.isArray(list) ? list : cfg.items).slice();
		if (running || !items.length) {
			return;
		}
		running = true;
		setButtons(true);
		var panel = document.getElementById('hic-run');
		if (panel) {
			panel.hidden = false;
		}

		var total = items.length;
		var done = 0;
		var failed = 0;
		var busy = 0;

		setProgress(fmt(i18n.progress, 0, total), 0, total);

		function next() {
			if (!items.length) {
				if (!busy) {
					running = false;
					setButtons(false);
					setProgress(fmt(i18n.finished, done, failed), total, total);
				}
				return;
			}
			busy++;
			convertOne(items.shift()).then(function (ok) {
				busy--;
				if (ok) {
					done++;
				} else {
					failed++;
				}
				setProgress(fmt(i18n.progress, done + failed, total), done + failed, total);
				next();
			});
		}

		for (var w = 0; w < workers; w++) {
			next();
		}
	}

	window.addEventListener('beforeunload', onBeforeUnload);

	var startButton = document.getElementById('hic-start');
	if (startButton) {
		startButton.addEventListener('click', function () {
			run();
		});
	}

	var retryButton = document.getElementById('hic-retry');
	if (retryButton) {
		retryButton.addEventListener('click', function () {
			run(cfg.items.filter(function (item) {
				return item.failed;
			}));
		});
	}

	if (cfg.autoStart) {
		// Failed images are retried only from the "Retry failed" button, not on every visit.
		var autoItems = cfg.items.filter(function (item) {
			return !item.failed;
		});
		// Let the page finish loading first.
		if (document.readyState === 'complete') {
			run(autoItems);
		} else {
			window.addEventListener('load', function () {
				run(autoItems);
			});
		}
	}
})(window, document);
