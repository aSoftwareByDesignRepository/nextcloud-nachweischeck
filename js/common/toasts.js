(function () {
	'use strict';

	var DURATIONS = { success: 4000, warn: 5000, error: 7000 };

	function live(id, text) {
		var el = document.getElementById(id);
		if (el) {
			el.textContent = text;
		}
	}

	function ensureContainer() {
		var c = document.getElementById('nw-toasts');
		if (!c) {
			c = document.createElement('div');
			c.id = 'nw-toasts';
			c.className = 'nw-toasts';
			document.body.appendChild(c);
		}
		return c;
	}

	/**
	 * Dedup on kind+text with timer reset: an identical toast already showing
	 * gets its dismiss timer reset instead of stacking a duplicate (rapid
	 * retries, repeated API failures).
	 */
	function show(kind, regionId, message) {
		live(regionId, message);
		var container = ensureContainer();
		var dedupKey = kind + '|' + String(message);
		var existing = typeof container.querySelectorAll === 'function'
			? container.querySelectorAll('.nw-toast[data-nw-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (var i = 0; i < existing.length; i++) {
			var prev = existing[i];
			var prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-nw-toast-key')
				: prev._nwToastKey;
			if (prevKey === dedupKey || prev._nwToastKey === dedupKey) {
				if (prev._nwDismissTimer) {
					window.clearTimeout(prev._nwDismissTimer);
				}
				prev._nwDismissTimer = window.setTimeout(function () {
					if (prev.parentNode) {
						prev.parentNode.removeChild(prev);
					}
				}, DURATIONS[kind]);
				return;
			}
		}
		var toast = document.createElement('div');
		toast.className = 'nw-toast nw-toast--' + kind;
		toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		toast.setAttribute('data-nw-toast-key', dedupKey);
		toast._nwToastKey = dedupKey;
		var text = document.createElement('span');
		text.className = 'nw-toast__text';
		text.textContent = String(message);
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'nw-toast__close';
		close.setAttribute('aria-label', t('nachweischeck', 'Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', function () {
			if (toast._nwDismissTimer) {
				window.clearTimeout(toast._nwDismissTimer);
			}
			toast.remove();
		});
		toast.appendChild(text);
		if (kind === 'error') {
			// Family contract (_shared/app-feedback): error toasts offer a
			// "Report this problem" mailto next to the message.
			try {
				var feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					var link = document.createElement('a');
					link.className = 'nw-toast__feedback';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('nachweischeck', 'Report this problem');
					toast.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		toast.appendChild(close);
		container.appendChild(toast);
		toast._nwDismissTimer = window.setTimeout(function () {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}, DURATIONS[kind]);
	}

	window.NWToasts = {
		success: function (message) {
			show('success', 'nw-live-region', message);
		},
		warn: function (message) {
			show('warn', 'nw-live-region', message);
		},
		error: function (message) {
			show('error', 'nw-alert-region', message);
		},
		// Back-compat aliases (contractcheck-era call sites).
		showSuccess: function (message) {
			show('success', 'nw-live-region', message);
		},
		showError: function (message) {
			show('error', 'nw-alert-region', message);
		}
	};
})();
