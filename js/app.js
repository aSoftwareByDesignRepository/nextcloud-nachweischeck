(function () {
	'use strict';

	/**
	 * NachweisCheck front-end kernel — shared Check-family layer.
	 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
	 *
	 * - NWApi: fetch wrapper around the {success,data|error,fields} envelope
	 * - pickers: accessible combobox with abortable search + removable chips —
	 *   never raw uid input
	 * - dialogs: modal <dialog> with focus trap/restore + explicit confirm flow
	 * - filter panel: canonical §3.6b Apply/Clear wiring, deep-linkable via
	 *   location.search, active-filter chips + clear-all
	 * - helpers: formatting, debounce, element creation, page actions
	 */

	var boot = {};
	try {
		boot = (window.OCP && OCP.InitialState && OCP.InitialState.loadState)
			? OCP.InitialState.loadState('nachweischeck', 'boot') : {};
	} catch (e) {
		boot = {};
	}
	var role = (boot && boot.role) || {};

	function t(text, vars) {
		var out = (window.OC && OC.L10N && OC.L10N.translate)
			? OC.L10N.translate('nachweischeck', text) : text;
		if (Array.isArray(vars)) {
			// sprintf-style: each array item replaces the next %s in order.
			var i = 0;
			out = out.replace(/%s/g, function () { return String(vars[i++]); });
		} else if (vars) {
			Object.keys(vars).forEach(function (k) {
				out = out.split('{' + k + '}').join(String(vars[k]));
			});
		}
		return out;
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = (s === null || s === undefined) ? '' : String(s);
		return d.innerHTML;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'class') node.className = attrs[k];
				else if (k === 'text') node.textContent = attrs[k];
				else if (k === 'html') node.innerHTML = attrs[k];
				else node.setAttribute(k, attrs[k]);
			});
		}
		(children || []).forEach(function (c) {
			if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	/* ---------- API ---------- */

	function ApiError(payload, status) {
		this.error = (payload && payload.error) || 'SERVER_ERROR';
		this.message = (payload && payload.message) || t('Unexpected error');
		this.fields = (payload && payload.fields) || {};
		this.status = status || 0;
	}

	function requestToken() {
		if (window.OC && OC.requestToken) return OC.requestToken;
		var meta = document.querySelector('meta[name="requesttoken"]');
		var input = document.getElementById('requesttoken');
		var holder = document.querySelector('[data-requesttoken]');
		return (meta && meta.getAttribute('content')) || (input && input.value)
			|| (holder && holder.getAttribute('data-requesttoken')) || '';
	}

	function appBase() {
		// OC.generateUrl prefixes /index.php when pretty URLs are off
		// (modRewriteWorking=false — typical on shared hosting).
		if (window.OC && typeof OC.generateUrl === 'function') {
			return OC.generateUrl('/apps/nachweischeck');
		}
		// Last resort: index.php form routes on every install; webroot
		// still covers subdirectory deployments.
		var webroot = (window.OC && OC.getRootPath) ? OC.getRootPath() : '';
		return webroot + '/index.php/apps/nachweischeck';
	}

	function api(path, opts) {
		opts = opts || {};
		var headers = {
			'OCS-APIREQUEST': 'true',
			'Accept': 'application/json',
			'requesttoken': requestToken()
		};
		var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
		if (opts.json !== undefined) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.json);
		} else if (opts.formData) {
			init.body = opts.formData;
		}
		if (opts.signal) init.signal = opts.signal;

		// Apps expose their JSON API under appBase + apiBase (boot-provided;
		// '/api' matches contractcheck, '/api/v1' privacycheck).
		return fetch(appBase() + (boot.apiBase || '/api') + path, init).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (body) {
				if (!res.ok || body.success === false) {
					throw new ApiError(body, res.status);
				}
				return body.data;
			});
		});
	}

	/* ---------- formatting ---------- */

	function fmtDate(iso) {
		if (!iso) return '—';
		try {
			var d = String(iso).length === 10 ? iso + 'T00:00:00' : iso;
			return new Date(d).toLocaleDateString(undefined, {
				year: 'numeric', month: 'short', day: 'numeric'
			});
		} catch (e) { return iso; }
	}

	function fmtDateTime(iso) {
		if (!iso) return '—';
		try {
			return new Date(iso).toLocaleString(undefined, {
				year: 'numeric', month: 'short', day: 'numeric',
				hour: '2-digit', minute: '2-digit'
			});
		} catch (e) { return iso; }
	}

	// Local calendar day as YYYY-MM-DD for <input type="date"> defaults.
	// (toISOString().slice(0,10) is UTC — off by one for negative offsets.)
	function todayYmd() {
		var d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
			+ '-' + String(d.getDate()).padStart(2, '0');
	}

	/* ---------- badges ---------- */

	var VARIANTS = ['neutral', 'success', 'warn', 'danger', 'info'];

	function badge(variant, text) {
		if (VARIANTS.indexOf(variant) === -1) variant = 'neutral';
		return el('span', { class: 'nw-badge nw-badge--' + variant, text: text });
	}

	/* ---------- misc helpers ---------- */

	function debounce(fn, ms) {
		var h = null;
		return function () {
			var args = arguments, self = this;
			window.clearTimeout(h);
			h = window.setTimeout(function () { fn.apply(self, args); }, ms || 250);
		};
	}

	function toast(kind, msg) {
		if (!window.NWToasts) return;
		if (kind === 'error') window.NWToasts.error(msg);
		else if (kind === 'warn') window.NWToasts.warn(msg);
		else window.NWToasts.success(msg);
	}

	function handleError(e) {
		// FieldErrors scopes to the open dialog itself; no form arg needed.
		if (e && e.fields && Object.keys(e.fields).length && window.CheckFieldErrors) {
			CheckFieldErrors.markValidationFields(e.fields);
		}
		toast('error', (e && e.message) ? e.message : t('Something went wrong.'));
	}

	/**
	 * Accessible searchable picker: input combobox → async result listbox →
	 * removable chips. cfg = { input, results, chips, search(term, signal),
	 * onChange(ids), max?, renderItem? }.
	 */
	function initPicker(cfg) {
		var state = { items: [], aborter: null, selected: cfg.selected || [] };
		var input = cfg.input, results = cfg.results;

		function renderChips() {
			if (!cfg.chips) return;
			cfg.chips.innerHTML = '';
			state.selected.forEach(function (item) {
				var chip = el('span', { class: 'nw-chip', role: 'listitem' }, [
					el('span', { class: 'nw-chip__label', text: item.label })
				]);
				var b = el('button', {
					type: 'button', class: 'nw-chip__remove',
					'aria-label': t('Remove') + ' ' + item.label
				});
				b.textContent = '×';
				b.addEventListener('click', function () {
					state.selected = state.selected.filter(function (x) { return x.id !== item.id; });
					renderChips(); emit();
				});
				chip.appendChild(b);
				cfg.chips.appendChild(chip);
			});
		}

		function emit() {
			if (cfg.onChange) cfg.onChange(state.selected.map(function (x) { return x.id; }));
		}

		function hideResults() {
			results.innerHTML = '';
			results.hidden = true;
			input.setAttribute('aria-expanded', 'false');
		}

		function choose(item) {
			if (!state.selected.some(function (x) { return x.id === item.id; })) {
				state.selected.push(item);
				renderChips(); emit();
			}
			input.value = '';
			hideResults();
			input.focus();
		}

		var doSearch = debounce(function () {
			var term = input.value.trim();
			if (term.length < 2) { hideResults(); return; }
			if (state.aborter) state.aborter.abort();
			state.aborter = new AbortController();
			cfg.search(term, state.aborter.signal).then(function (items) {
				state.items = items;
				results.innerHTML = '';
				items.slice(0, 8).forEach(function (item, i) {
					var li = el('li', {
						class: 'nw-picker__result', role: 'option',
						id: input.id + '-opt-' + i,
						'aria-selected': 'false', tabindex: '-1',
						'data-id': String(item.id), text: item.label
					});
					li.addEventListener('mousedown', function (ev) {
						ev.preventDefault();
						choose(item);
					});
					results.appendChild(li);
				});
				results.hidden = !items.length;
				input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
			}).catch(function (e) {
				if (e && e.name === 'AbortError') return;
				hideResults();
			});
		}, 250);

		input.addEventListener('input', doSearch);
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'ArrowDown' && results.children.length) {
				ev.preventDefault();
				results.children[0].focus();
			} else if (ev.key === 'Escape') {
				hideResults();
			} else if (ev.key === 'Backspace' && input.value === '' && state.selected.length) {
				state.selected.pop();
				renderChips(); emit();
			}
		});
		results.addEventListener('keydown', function (ev) {
			var cur = document.activeElement;
			var list = Array.prototype.slice.call(results.children);
			var idx = list.indexOf(cur);
			if (ev.key === 'ArrowDown' && idx < list.length - 1) { ev.preventDefault(); list[idx + 1].focus(); }
			else if (ev.key === 'ArrowUp') { ev.preventDefault(); (idx > 0 ? list[idx - 1] : input).focus(); }
			else if (ev.key === 'Enter' && idx >= 0) {
				ev.preventDefault();
				var id = cur.getAttribute('data-id');
				var hit = state.items.filter(function (x) { return String(x.id) === id; })[0];
				if (hit) choose(hit);
			} else if (ev.key === 'Escape') { hideResults(); input.focus(); }
		});
		document.addEventListener('click', function (ev) {
			if (ev.target !== input && !results.contains(ev.target)) hideResults();
		});
		renderChips();
		return {
			getSelected: function () { return state.selected.slice(); },
			setSelected: function (items) { state.selected = items || []; renderChips(); emit(); },
			clear: function () { state.selected = []; renderChips(); emit(); }
		};
	}

	function busyGuard(btn) {
		var inFlight = false;
		return {
			tryStart: function () {
				if (inFlight) return false;
				inFlight = true;
				btn.disabled = true;
				btn.setAttribute('aria-busy', 'true');
				return true;
			},
			end: function () {
				inFlight = false;
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
			}
		};
	}

	/* ---------- field errors ---------- */

	/**
	 * Mark a control with aria-invalid + an inline .nw-field-error — same
	 * DOM contract as CheckFieldErrors so its input-clear wiring removes it.
	 */
	function markFieldError(input, msg) {
		if (!input) return;
		input.setAttribute('aria-invalid', 'true');
		input.setAttribute('data-nw-invalid', '1');
		var errId = 'nw-field-error-' + (input.name || input.id);
		var err = document.getElementById(errId);
		if (!err) {
			err = el('p', { class: 'nw-field-error', id: errId, 'data-nw-field-error': '1' });
			input.insertAdjacentElement('afterend', err);
		}
		err.textContent = msg;
		if (input.getAttribute('data-nw-prev-describedby') === null) {
			input.setAttribute('data-nw-prev-describedby',
				input.getAttribute('aria-describedby') || '');
		}
		var prev = input.getAttribute('data-nw-prev-describedby') || '';
		input.setAttribute('aria-describedby', (prev ? prev + ' ' : '') + errId);
	}

	function clearFieldError(input) {
		if (!input) return;
		input.removeAttribute('aria-invalid');
		input.removeAttribute('data-nw-invalid');
		var prev = input.getAttribute('data-nw-prev-describedby');
		input.removeAttribute('data-nw-prev-describedby');
		if (prev === null || prev === '') input.removeAttribute('aria-describedby');
		else input.setAttribute('aria-describedby', prev);
		var err = input.parentNode && input.parentNode.querySelector
			? input.parentNode.querySelector('.nw-field-error[data-nw-field-error]')
			: null;
		if (err) err.remove();
	}

	/* ---------- dialogs ---------- */

	var openDialogs = [];
	var dlgSeq = 0;

	function closeDialog(dlg, returnValue) {
		var idx = openDialogs.indexOf(dlg);
		if (idx >= 0) openDialogs.splice(idx, 1);
		if (dlg.open) dlg.close(returnValue);
		dlg.remove();
		// WCAG 2.4.3: return focus to the invoking control after teardown.
		var back = dlg._restoreFocus;
		if (back && document.contains(back) && typeof back.focus === 'function') {
			back.focus();
		}
	}

	/**
	 * openDialog({ title, body, submitLabel, cancelLabel, danger, wide, onSubmit })
	 * body: Node | HTML string. onSubmit(formData, close) may return a Promise —
	 * rejection keeps the dialog open and reports via handleError.
	 * Returns { dialog, close }.
	 */
	function openDialog(cfg) {
		cfg = cfg || {};
		var dlg = el('dialog', { class: 'nw-dialog' + (cfg.wide ? ' nw-dialog--wide' : '') });
		var form = el('form', { method: 'dialog', class: 'nw-dialog__form', novalidate: 'novalidate' });
		var head = el('div', { class: 'nw-dialog__head' });
		// WCAG 4.1.2: the dialog needs an accessible name — aria-labelledby the title.
		var dlgTitleId = 'nw-dlg-title-' + (++dlgSeq);
		var h = el('h2', { class: 'nw-dialog__title', id: dlgTitleId, text: cfg.title || '' });
		dlg.setAttribute('aria-labelledby', dlgTitleId);
		var closeBtn = el('button', {
			type: 'button', class: 'nw-dialog__close', 'aria-label': t('Close')
		});
		closeBtn.textContent = '×';
		head.appendChild(h); head.appendChild(closeBtn);
		var body = el('div', { class: 'nw-dialog__body' });
		if (typeof cfg.body === 'string') body.innerHTML = cfg.body;
		else if (cfg.body) body.appendChild(cfg.body);
		var foot = el('div', { class: 'nw-dialog__actions' });
		var cancel = el('button', { type: 'button', class: 'nw-btn nw-btn--secondary', text: cfg.cancelLabel || t('Cancel') });
		var submit = el('button', {
			type: 'submit',
			class: 'nw-btn ' + (cfg.danger ? 'nw-btn--danger' : 'nw-btn--primary'),
			text: cfg.submitLabel || t('Save')
		});
		foot.appendChild(cancel); foot.appendChild(submit);
		form.appendChild(head); form.appendChild(body); form.appendChild(foot);
		dlg.appendChild(form);
		document.body.appendChild(dlg);

		var api = { dialog: dlg, body: body, close: function (v) { closeDialog(dlg, v); } };
		var guard = busyGuard(submit);
		var submitLabel = submit.textContent;

		// Dirty-close guard: Escape / cancel with unsaved edits asks first.
		var dirty = false;
		form.addEventListener('input', function () { dirty = true; });
		function requestClose(ev) {
			if (ev && ev.preventDefault) ev.preventDefault();
			if (dirty) {
				// Native window.confirm is banned — stack a real nw-dialog
				// on top; only an explicit Discard closes the dirty form.
				confirmDialog(
					t('Discard changes?'),
					t('You have unsaved changes in this dialog.'),
					{ confirmLabel: t('Discard') }
				).then(function (ok) {
					if (ok) { dirty = false; closeDialog(dlg); }
				});
				return;
			}
			dirty = false;
			closeDialog(dlg);
		}
		closeBtn.addEventListener('click', requestClose);
		cancel.addEventListener('click', requestClose);
		dlg.addEventListener('cancel', requestClose); // Escape intercepted
		// NC's notifications app preventDefault()s the global Escape keydown,
		// which suppresses the native `cancel` event — handle Escape manually.
		dlg.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); requestClose(ev); }
		});

		// Forgiving inline validation: a control is only flagged after the
		// user has touched it — untouched forms stay quiet until submit.
		form.addEventListener('focusout', function (ev) {
			var c = ev.target;
			if (!c || !c.willValidate || c.disabled || !c.validity) return;
			if (c.validity.valid) { clearFieldError(c); return; }
			markFieldError(c, c.validationMessage || t('Invalid value.'));
		});
		form.addEventListener('change', function (ev) {
			var c = ev.target;
			if (c && c.validity && c.validity.valid) clearFieldError(c);
		});
		dlg.addEventListener('close', function () { closeDialog(dlg); });
		dlg._restoreFocus = document.activeElement;

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			// novalidate + method="dialog" suppress native checks — run them
			// manually and reveal invalid controls hidden in closed disclosures.
			var invalid = form.querySelector(':invalid');
			if (invalid) {
				var det = invalid.closest('details.nw-details');
				if (det) det.open = true;
				form.reportValidity();
				return;
			}
			if (cfg.onSubmit) {
				if (!guard.tryStart()) return;
				submit.textContent = t('Saving…');
				Promise.resolve(cfg.onSubmit(new FormData(form), api, form))
					.then(function (keep) {
						submit.textContent = submitLabel;
						guard.end();
						if (keep !== false) { dirty = false; closeDialog(dlg); }
					})
					.catch(function (e) {
						submit.textContent = submitLabel;
						guard.end();
						handleError(e && e.payload ? Object.assign(e, e.payload.fields ? { fields: e.payload.fields } : {}) : e);
					});
			} else {
				dirty = false;
				closeDialog(dlg);
			}
		});

		openDialogs.push(dlg);
		dlg.showModal();
		var first = body.querySelector('input,select,textarea,button') || submit;
		first.focus();
		if (cfg.onOpen) cfg.onOpen(api);
		return api;
	}

	/**
	 * confirmDialog(title, message, { confirmLabel, danger }) → Promise<bool>
	 */
	function confirmDialog(title, message, opts) {
		opts = opts || {};
		return new Promise(function (resolve) {
			var resolved = false;
			openDialog({
				title: title,
				body: el('p', { class: 'nw-dialog__text', text: message }),
				submitLabel: opts.confirmLabel || t('Confirm'),
				cancelLabel: t('Cancel'),
				danger: opts.danger !== false,
				onSubmit: function () { resolved = true; resolve(true); }
			}).dialog.addEventListener('close', function () {
				if (!resolved) resolve(false);
			});
		});
	}

	/* ---------- page actions ---------- */

	/**
	 * Mount action buttons into the shared page-header actions slot.
	 * defs: [{ id, label, icon?, primary?, danger?, href? (renders <a>), onClick }]
	 */
	function pageActions(defs) {
		var host = document.getElementById('nw-page-actions');
		if (!host) return;
		host.innerHTML = '';
		(defs || []).forEach(function (d) {
			var cls = 'nw-btn ' + (d.danger ? 'nw-btn--danger' : (d.primary ? 'nw-btn--primary' : 'nw-btn--secondary'));
			var node = d.href
				? el('a', { class: cls, href: d.href })
				: el('button', { type: 'button', class: cls, id: d.id || '' });
			if (d.iconHtml) node.innerHTML = d.iconHtml;
			node.appendChild(document.createTextNode(d.label));
			if (!d.href && d.onClick) node.addEventListener('click', d.onClick);
			host.appendChild(node);
		});
	}

	/* ---------- filter panel (design-system §3.6b recipe A) ---------- */

	/**
	 * Wire a canonical filter panel:
	 *   initFilterPanel({
	 *     form,            // the nw-filter-panel__form
	 *     summary,         // optional region for active-filter chips
	 *     onApply(params), // URLSearchParams → reload list
	 *     deepLink: true   // mirror state into location.search (default true)
	 *   })
	 * Read initial values from location.search so filter links deep-link.
	 */
	function initFilterPanel(cfg) {
		var form = cfg.form;
		// The summary region conventionally lives next to the form inside the
		// filter panel — look there too, not only inside the form itself.
		var summary = cfg.summary || form.querySelector('[data-filter-summary]')
			|| (form.closest('.' + 'nw' + '-filter-panel')
				&& form.closest('.' + 'nw' + '-filter-panel').querySelector('[data-filter-summary]'));
		var deepLink = cfg.deepLink !== false;

		function readParams() {
			var params = new URLSearchParams();
			new FormData(form).forEach(function (v, k) {
				var s = String(v).trim();
				if (s !== '') params.set(k, s);
			});
			return params;
		}

		function applyToControls(params) {
			params.forEach(function (v, k) {
				var c = form.elements.namedItem(k);
				if (c && 'value' in c) c.value = v;
			});
		}

		function labelFor(name, value) {
			var c = form.elements.namedItem(name);
			var label = name;
			var group = c && c.closest('.' + 'nw' + '-filter-field');
			var lbl = group && group.querySelector('.' + 'nw' + '-filter-field__label, label');
			if (lbl) label = lbl.textContent.trim();
			if (c && c.tagName === 'SELECT') {
				var opt = c.querySelector('option[value="' + CSS.escape(value) + '"]');
				if (opt) value = opt.textContent.trim();
			}
			return label + ': ' + value;
		}

		function renderSummary(params) {
			if (!summary) return;
			summary.innerHTML = '';
			var active = [];
			params.forEach(function (v, k) { active.push([k, v]); });
			summary.hidden = active.length === 0;
			if (!active.length) return;
			summary.appendChild(el('span', {
				class: 'nw-filter-summary__label',
				text: t('Active filters') + ':'
			}));
			active.forEach(function (kv) {
				var chip = el('button', {
					type: 'button', class: 'nw-chip nw-chip--filter',
					'aria-label': t('Remove filter') + ' ' + labelFor(kv[0], kv[1]),
					text: labelFor(kv[0], kv[1]) + ' ×'
				});
				chip.addEventListener('click', function () {
					var c = form.elements.namedItem(kv[0]);
					if (c && 'value' in c) c.value = '';
					apply();
				});
				summary.appendChild(chip);
			});
			var clearAll = el('button', {
				type: 'button', class: 'nw-btn nw-btn--ghost nw-btn--small',
				text: t('Clear all')
			});
			clearAll.addEventListener('click', reset);
			summary.appendChild(clearAll);
		}

		function syncUrl(params) {
			if (!deepLink) return;
			var qs = params.toString();
			var url = location.pathname + (qs ? '?' + qs : '');
			window.history.replaceState(null, '', url);
		}

		function apply() {
			var params = readParams();
			renderSummary(params);
			syncUrl(params);
			cfg.onApply(params);
		}

		function reset() {
			form.reset();
			apply();
		}


		form.addEventListener('submit', function (ev) { ev.preventDefault(); apply(); });
		var clearBtn = form.querySelector('[data-filter-clear]');
		if (clearBtn) clearBtn.addEventListener('click', reset);

		// Live filtering (§3.6b progressive enhancement): selects/checkboxes
		// apply immediately, text inputs on a short debounce — Apply stays as
		// the explicit commit for keyboard/AT users and no-JS semantics.
		var liveApply = debounce(apply, 350);
		form.addEventListener('input', function (ev) {
			var tg = ev.target;
			if (!tg || !tg.tagName) return;
			if (tg.tagName === 'SELECT' || tg.type === 'checkbox' || tg.type === 'radio') { apply(); return; }
			liveApply();
		});
		form.addEventListener('change', function (ev) {
			var tg = ev.target;
			if (tg && (tg.tagName === 'SELECT' || tg.type === 'checkbox' || tg.type === 'radio')) return;
			apply();
		});

		// Deep-link: seed controls from ?k=v before first load.
		applyToControls(new URLSearchParams(location.search));
		apply();
		return { apply: apply, reset: reset, params: readParams };
	}

	/* ---------- field errors ---------- */
	if (window.CheckFieldErrors) {
		CheckFieldErrors.install({ prefix: 'nw' });
	}
	if (window.CheckFocusPreservation) {
		CheckFocusPreservation.install({ prefix: 'nw' });
	}

	window.NW = {
		boot: boot,
		role: role,
		t: t,
		// tf: sprintf-substituting translate (farm gate convention — keeps
		// t('%s…', [vars]) call sites distinct from raw OC.L10N.translate).
		tf: t,
		esc: esc,
		el: el,
		api: api,
		appBase: appBase,
		fmtDate: fmtDate,
		fmtDateTime: fmtDateTime,
		todayYmd: todayYmd,
		badge: badge,
		debounce: debounce,
		toast: toast,
		handleError: handleError,
		initPicker: initPicker,
		busyGuard: busyGuard,
		openDialog: openDialog,
		confirmDialog: confirmDialog,
		pageActions: pageActions,
		initFilterPanel: initFilterPanel,
		markFieldError: markFieldError,
		clearFieldError: clearFieldError
	};
})();
