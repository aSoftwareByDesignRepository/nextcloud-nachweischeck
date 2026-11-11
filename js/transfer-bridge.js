/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.NachweisCheckApi / NachweisCheckMessaging contract the
 * synced js/transfer.js expects, in NachweisCheck's own API envelope
 * ({success:true,data} / {success:false,error,message,fields,context}).
 * App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && j.success === false)) {
			throw {
				code: (j && j.error) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.NachweisCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/nachweischeck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	window.NachweisCheckMessaging = {
		alert(text) {
			if (window.NWToasts && NWToasts.error) {
				NWToasts.error(text);
			} else if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(text);
			}
			const el = document.getElementById('nw-transfer-result-live');
			if (el) el.textContent = text;
		},
		polite(text) {
			if (window.NWToasts && NWToasts.success) {
				NWToasts.success(text);
			} else {
				this.alert(text);
				return;
			}
			const el = document.getElementById('nw-transfer-result-live');
			if (el) el.textContent = text;
		},
	};
})();
