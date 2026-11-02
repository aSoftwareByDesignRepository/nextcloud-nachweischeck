/* SPDX-License-Identifier: AGPL-3.0-or-later */
/*
 * NachweisCheck workspace — boot-driven client UI for every project view.
 * Renders into #nw-main; navigation itself is server-rendered (Chrome.php),
 * so a view switch is a full page load keyed off NW.boot.view.
 *
 * Role awareness: every write action checks boot.project.caps
 * (can_book / can_finance / can_admin / can_see_rates) before it renders —
 * the server still enforces, the UI simply never offers dead doors.
 */
(function () {
	'use strict';
	if (!window.NW) { return; }

	var t = NW.t, el = NW.el, esc = NW.esc;
	var boot = NW.boot || {};
	var role = NW.role || {};
	var project = boot.project || null;
	var caps = (project && project.caps) || {};
	var pid = project ? project.id : 0;
	var cache = {};

	/* ---------- formatting ---------- */

	function fmtMoney(minor) {
		if (minor === null || minor === undefined || minor === '') { return '—'; }
		return (minor / 100).toLocaleString('de-DE', {
			minimumFractionDigits: 2, maximumFractionDigits: 2
		}) + ' €';
	}

	/* Strict de-DE input: 1234 | 1.234 | 1.234,56 | 0,5 — never float math
	   on the raw string, never silent truncation. */
	function parseMoney(str) {
		var s = String(str === null || str === undefined ? '' : str).trim();
		if (!/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+(,\d{1,2})?$/.test(s)) { return null; }
		s = s.replace(/\./g, '').replace(',', '.');
		var n = Math.round(parseFloat(s) * 100);
		return isFinite(n) ? n : null;
	}

	function parseFx(str) {
		var s = String(str === null || str === undefined ? '' : str).trim();
		if (s === '') { return null; }
		if (!/^\d+([.,]\d{1,6})?$/.test(s)) { return undefined; }
		return Math.round(parseFloat(s.replace(',', '.')) * 1000);
	}

	function fmtHours(minutes) {
		minutes = minutes || 0;
		return (minutes / 60).toLocaleString('de-DE', {
			minimumFractionDigits: 0, maximumFractionDigits: 1
		}) + ' h';
	}

	function fmtMonth(ym) { return ym || '—'; }

	function hashShort(h) { return h ? String(h).slice(0, 16) + '…' : '—'; }

	/* ---------- state + kind labels (badge text, never colour alone) ---------- */

	var STATE_BADGE = {
		draft: 'neutral', active: 'success', closing: 'warn', closed: 'neutral',
		open: 'info', filed: 'neutral', submitted: 'info', accepted: 'success', signed: 'success',
		rejected: 'danger', final: 'success', superseded: 'warn',
		requested: 'warn', received: 'success', settled: 'success',
		pending: 'warn', applied: 'success', builtin: 'neutral', pack: 'info',
		installed: 'info', receipt: 'neutral', salary: 'info',
		member: 'neutral', finance: 'info', admin: 'warn',
		zuwendung: 'info', mitteluebertrag: 'neutral'
	};

	function stateBadge(state, label) {
		return NW.badge(STATE_BADGE[state] || 'neutral', label || state || '—');
	}

	var THRESHOLDS = {
		ok: ['success', 'Within plan'],
		within_20: ['warn', 'Over plan — inside the 20 % rule'],
		blocked_over: ['danger', 'Over 120 % — funder approval required'],
		not_applicable: ['info', 'Flat rate — threshold not applicable']
	};

	function thresholdBadge(code) {
		var m = THRESHOLDS[code] || ['neutral', code || '—'];
		return NW.badge(m[0], t(m[1]));
	}

	var POSITION_KINDS = ['personal', 'sach', 'reise', 'extern', 'eigen', 'fremd'];

	var POSITION_KIND_LABELS = {
		personal: 'Personnel', sach: 'Materials', reise: 'Travel',
		extern: 'External services', eigen: 'Own funds', fremd: 'Third-party funds'
	};

	function posKindLabel(k) { return t(POSITION_KIND_LABELS[k] || k || '—'); }

	var DEADLINE_KINDS = {
		nachweis_frist: 'Final report deadline',
		bew_end_warning: 'Funding period end warning',
		zwischennachweis: 'Interim report',
		ap_end: 'Work package end'
	};

	function deadlineLabel(kind) { return t(DEADLINE_KINDS[kind] || kind || '—'); }

	var DEVIATION_KINDS = { time: 'Time', cost: 'Cost', schedule: 'Schedule' };
	var NACHWEIS_KINDS = { final: 'Final report', zwischen: 'Interim report' };
	var FINANCING_MODES = { ausgaben: 'Expenditure-based funding', festbetrag: 'Flat-rate funding' };
	var AP_STATES = { active: 'active', closed: 'closed' };

	/* Audit-readiness check ids (ReadinessService) → user-facing labels.
	   Raw snake_case enum ids must never reach the page — the contract
	   test pins this map against the PHP enum so a new check id cannot
	   ship unmapped. Unknown ids degrade to a humanized fallback. */
	var READINESS_CHECK_LABELS = {
		no_active_plan: 'No active financing plan',
		unmapped_aps: 'Work packages without mapped positions',
		missing_data: 'Missing receipt or time data',
		duplicate_receipts: 'Unacknowledged duplicate receipts',
		unsigned_timesheets: 'Unsigned timesheets',
		pending_drawdowns: 'Drawdowns still open',
		open_claims: 'Open claims'
	};

	function readinessCheckLabel(id) {
		return t(READINESS_CHECK_LABELS[id] || String(id || '—').replace(/_/g, ' '));
	}

	/* ---------- small DOM builders ---------- */

	function loading() {
		return el('div', { class: 'nw-loading', role: 'status', text: t('Loading…') });
	}

	function emptyState(title, text, action) {
		var box = el('div', { class: 'nw-empty-state nw-card' }, [
			el('p', { class: 'nw-empty-state__title', text: title }),
			el('p', { class: 'nw-empty-state__text', text: text })
		]);
		if (action) { box.appendChild(action); }
		return box;
	}

	function card(titleText, opts) {
		opts = opts || {};
		var c = el('section', { class: 'nw-card' });
		var head = el('div', { class: 'nw-card__header' });
		head.appendChild(el('div', { class: 'nw-card__header-text' }, [
			el('h2', { class: 'nw-card__title', text: titleText })
		]));
		if (opts.actions && opts.actions.length) {
			var act = el('div', { class: 'nw-card__header-actions' });
			opts.actions.forEach(function (a) { act.appendChild(a); });
			head.appendChild(act);
		}
		c.appendChild(head);
		var body = el('div', { class: 'nw-card__body' + (opts.table ? ' nw-card__body--table' : '') });
		(opts.children || []).forEach(function (n) { if (n) { body.appendChild(n); } });
		c.appendChild(body);
		return c;
	}

	function smallBtn(label, onClick, cls) {
		var b = el('button', {
			type: 'button',
			class: 'nw-btn nw-btn--small ' + (cls || 'nw-btn--secondary'),
			text: label
		});
		b.addEventListener('click', onClick);
		return b;
	}

	function btn(label, onClick, cls) {
		var b = el('button', {
			type: 'button',
			class: 'nw-btn ' + (cls || 'nw-btn--secondary'),
			text: label
		});
		b.addEventListener('click', onClick);
		return b;
	}

	/* Table with mobile card rows — every cell under a non-empty header
	   carries its label via data-cell (§3.7); '' headers mark the actions
	   column: th gets nw-table-actions-col, td gets actions-cell so the
	   pack's hover/focus reveal + inline-end pin applies. */
	function tableEl(headers, rows, opts) {
		opts = opts || {};
		var wrap = el('div', { class: 'nw-table-wrap' });
		var tbl = el('table', { class: 'nw-table nw-table--cards' });
		var thead = el('thead');
		var hr = el('tr');
		headers.forEach(function (h) {
			var th = el('th', { scope: 'col', text: h });
			if (h === '') { th.className = 'nw-table-actions-col'; }
			hr.appendChild(th);
		});
		thead.appendChild(hr);
		tbl.appendChild(thead);
		var tbody = el('tbody');
		if (!rows.length) {
			var tr = el('tr');
			tr.appendChild(el('td', {
				colspan: String(headers.length),
				class: 'nw-table-empty',
				text: opts.empty || t('Nothing here yet.')
			}));
			tbody.appendChild(tr);
		}
		rows.forEach(function (row) {
			var tr = el('tr');
			row.forEach(function (cellData, i) {
				var td = el('td');
				if (headers[i] !== '') {
					td.setAttribute('data-cell', headers[i]);
				} else {
					td.className = 'actions-cell';
				}
				if (typeof cellData === 'string' || typeof cellData === 'number') {
					td.textContent = String(cellData);
				} else if (cellData) {
					td.appendChild(cellData);
				}
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		});
		tbl.appendChild(tbody);
		wrap.appendChild(tbl);
		return wrap;
	}

	function dlEl(pairs) {
		var dl = el('dl', { class: 'nw-dl' });
		pairs.forEach(function (p) {
			var row = el('div', { class: 'nw-dl__row' }, [
				el('dt', { text: p[0] }),
				el('dd', { text: p[1] })
			]);
			dl.appendChild(row);
		});
		return dl;
	}

	/* ---------- form builders (dialog bodies) ---------- */

	var fieldSeq = 0;

	function fieldEl(labelText, control, opts) {
		opts = opts || {};
		var f = el('div', { class: 'nw-field' });
		// Composite controls (directory picker, file picker row) hand back
		// {node, focusEl}; the label must point at the real focusable control,
		// never at a wrapper div (WCAG 1.3.1 + click-to-focus).
		var node = control && control.node ? control.node : control;
		var focus = control && control.focusEl ? control.focusEl : node;
		var id = (focus && focus.id) || ('nw-f-' + (++fieldSeq));
		if (focus && !focus.id) { focus.id = id; }
		var lbl = el('label', { 'for': id, text: labelText });
		f.appendChild(lbl);
		f.appendChild(node);
		if (opts.hint) {
			f.appendChild(el('p', { class: 'nw-field__hint', text: opts.hint }));
		}
		return f;
	}

	/**
	 * Progressive disclosure — DESIGN-SYSTEM §12.5: create dialogs keep
	 * ≤2 primary fields; everything else lives under one calm-band
	 * "More options". The kernel auto-opens this details when an invalid
	 * control hides inside, so validation never strands.
	 */
	function moreDetails(labelText, children) {
		return el('details', { class: 'nw-details' }, [
			el('summary', { class: 'nw-details__summary', text: labelText || t('More options') }),
			el('div', { class: 'nw-details__body' }, children)
		]);
	}

	/**
	 * Forgiving duration input → minutes. Accepted:
	 *   "1:30" (h:mm) · "1,5"/"1.5" (decimal hours) · "2h"/"2 h" ·
	 *   "90 min"/"90min" · bare "90" (minutes, back-compat).
	 * Returns minutes > 0 or null. Never floats: integer math only.
	 */
	function parseDuration(str) {
		var s = String(str === null || str === undefined ? '' : str).trim().toLowerCase();
		if (s === '') { return null; }
		var m;
		if ((m = s.match(/^(\d{1,4})\s*:\s*(\d{1,2})$/))) {
			var mm = parseInt(m[2], 10);
			if (mm > 59) { return null; }
			return parseInt(m[1], 10) * 60 + mm;
		}
		if ((m = s.match(/^(\d{1,4})\s*h(?:\s*(\d{1,2})\s*(?:min|m)?)?$/))) {
			var hm = m[2] ? parseInt(m[2], 10) : 0;
			if (hm > 59) { return null; }
			return parseInt(m[1], 10) * 60 + hm;
		}
		if ((m = s.match(/^(\d{1,5})\s*(?:min|m)$/))) {
			return parseInt(m[1], 10);
		}
		if ((m = s.match(/^(\d{1,4})[.,](\d{1,2})\s*h?$/))) {
			// decimal hours — quarter precision rounding
			var cents = Math.round((parseInt(m[1], 10) * 100 + parseInt(m[2].length === 1 ? m[2] + '0' : m[2], 10)) * 60 / 100);
			return cents > 0 ? cents : null;
		}
		if ((m = s.match(/^(\d{1,5})$/))) {
			return parseInt(m[1], 10); // bare = minutes (previous behaviour)
		}
		return null;
	}

	function fmtDurationHuman(minutes) {
		var h = Math.floor(minutes / 60), m = minutes % 60;
		if (h === 0) { return m + ' min'; }
		if (m === 0) { return h + ' h'; }
		return h + ':' + String(m).padStart(2, '0') + ' h';
	}

	function inputEl(name, opts) {
		opts = opts || {};
		// nw-input is text-field chrome (display:block; width:100%) — it must
		// never land on checkbox/radio, whose 1.25rem sizing loses the
		// specificity tie and the control renders stretched.
		var textish = opts.type !== 'checkbox' && opts.type !== 'radio';
		var i = el('input', {
			type: opts.type || 'text',
			name: name,
			class: textish ? 'nw-input' : '',
			autocomplete: 'off'
		});
		if (opts.value !== undefined && opts.value !== null) { i.value = opts.value; }
		if (opts.required) { i.required = true; }
		if (opts.placeholder) { i.setAttribute('placeholder', opts.placeholder); }
		if (opts.min !== undefined) { i.setAttribute('min', String(opts.min)); }
		if (opts.max !== undefined) { i.setAttribute('max', String(opts.max)); }
		if (opts.step !== undefined) { i.setAttribute('step', String(opts.step)); }
		if (opts.inputmode) { i.setAttribute('inputmode', opts.inputmode); }
		if (opts.pattern) { i.setAttribute('pattern', opts.pattern); }
		return i;
	}

	function moneyEl(name, valueMinor) {
		var i = inputEl(name, { inputmode: 'decimal', placeholder: '0,00', required: true });
		if (valueMinor !== undefined && valueMinor !== null) {
			i.value = (valueMinor / 100).toLocaleString('de-DE', {
				minimumFractionDigits: 2, maximumFractionDigits: 2
			});
		}
		return i;
	}

	function selectEl(name, options, value, opts) {
		opts = opts || {};
		var s = el('select', { name: name, class: 'nw-select' });
		if (opts.blank) { s.appendChild(el('option', { value: '', text: opts.blank })); }
		options.forEach(function (o) {
			var op = el('option', { value: String(o[0]), text: o[1] });
			if (value !== undefined && String(o[0]) === String(value)) { op.selected = true; }
			s.appendChild(op);
		});
		if (opts.required) { s.required = true; }
		return s;
	}

	function textareaEl(name, opts) {
		opts = opts || {};
		var ta = el('textarea', { name: name, class: 'nw-textarea', rows: opts.rows || '3' });
		if (opts.required) { ta.required = true; }
		if (opts.value) { ta.value = opts.value; }
		if (opts.placeholder) { ta.setAttribute('placeholder', opts.placeholder); }
		return ta;
	}

	/* Directory picker (app-admin only endpoint) or plain uid input —
	   project admins who are not app admins get the honest fallback. */
	function uidControl(name, opts) {
		opts = opts || {};
		var wrap = el('div', { class: 'nw-picker' });
		var hidden = el('input', { type: 'hidden', name: name });
		if (role.isAppAdmin && !opts.plain) {
			var input = inputEl(name + '_search', { type: 'search' });
			input.id = 'nw-' + name + '-' + (++fieldSeq);
			input.setAttribute('role', 'combobox');
			input.setAttribute('aria-expanded', 'false');
			var results = el('ul', { class: 'nw-picker__results', role: 'listbox', hidden: 'hidden' });
			results.id = input.id + '-results';
			input.setAttribute('aria-controls', results.id);
			var chips = el('div', { class: 'nw-picker__chips', role: 'list' });
			wrap.appendChild(input); wrap.appendChild(results); wrap.appendChild(chips); wrap.appendChild(hidden);
			var picker = NW.initPicker({
				input: input, results: results, chips: chips,
				search: function (term, signal) {
					return NW.api('/directory/users?q=' + encodeURIComponent(term), { signal: signal })
						.catch(function () { return []; });
				},
				onChange: function (ids) { hidden.value = ids.join(','); }
			});
			if (opts.selected) { picker.setSelected(opts.selected); }
			return { node: wrap, focusEl: input, value: function () { return hidden.value.split(',').filter(Boolean); } };
		}
		var plain = inputEl(name, { value: opts.value || '', required: !!opts.required });
		wrap.appendChild(plain); wrap.appendChild(hidden);
		hidden.disabled = true;
		return { node: wrap, focusEl: plain, value: function () { return plain.value.trim() ? [plain.value.trim()] : []; } };
	}

	function memberSelect(name, members, value, opts) {
		opts = opts || {};
		var options = (members || []).map(function (m) {
			return [m.uid, (m.display_name && m.display_name !== m.uid) ? m.display_name + ' (' + m.uid + ')' : m.uid];
		});
		return selectEl(name, options, value, { blank: opts.blank === false ? undefined : t('— choose —'), required: opts.required });
	}

	/* ---------- data ---------- */

	function reload() {
		cache = {};
		render();
	}

	function loadProject() {
		if (!cache.project) { cache.project = NW.api('/projects/' + pid); }
		return cache.project;
	}

	function loadAps() {
		if (!cache.aps) { cache.aps = NW.api('/projects/' + pid + '/workpackages'); }
		return cache.aps;
	}

	function loadMembers() {
		if (!cache.members) { cache.members = NW.api('/projects/' + pid + '/members').catch(function () { return []; }); }
		return cache.members;
	}

	function loadTemplates() {
		if (!cache.templates) { cache.templates = NW.api('/templates').catch(function () { return []; }); }
		return cache.templates;
	}

	/* Active finplan version positions — receipts/splits/deviations need them. */
	function loadActivePositions() {
		if (!cache.positions) {
			cache.positions = NW.api('/projects/' + pid + '/finplan/versions').then(function (vers) {
				var active = null;
				(vers || []).forEach(function (v) { if (v.state === 'active') { active = v; } });
				if (!active) { return []; }
				return NW.api('/projects/' + pid + '/finplan/versions/' + active.id + '/positions');
			});
		}
		return cache.positions;
	}

	function positionOptions(positions, blank) {
		var opts = (positions || []).map(function (p) { return [p.id, p.code + ' — ' + p.title]; });
		if (blank !== undefined) { opts.unshift(['', blank]); }
		return opts;
	}

	function apOptions(aps, blank) {
		var opts = (aps || []).map(function (a) { return [a.id, a.code + ' — ' + a.title]; });
		if (blank !== undefined) { opts.unshift(['', blank]); }
		return opts;
	}

	function indexById(list) {
		var m = {};
		(list || []).forEach(function (x) { m[x.id] = x; });
		return m;
	}

	/* ---------- shared: split editor (receipt create / replace splits) ---------- */

	function splitEditor(positions, aps, initial, totalInput) {
		var grid = el('div', { class: 'nw-edit-grid nw-edit-grid--splits' });
		['Position', 'Work package', 'Amount (EUR)', 'Note', ''].forEach(function (h, i) {
			var cell = el('span', { class: 'nw-edit-grid__head', text: i === 4 ? '' : t(h) });
			cell.setAttribute('aria-hidden', i === 4 ? 'true' : 'false');
			grid.appendChild(cell);
		});
		var sum = el('div', { class: 'nw-split-sum', 'aria-live': 'polite' });
		var rows = [];

		function refresh() {
			var sumMinor = 0;
			rows.forEach(function (r) {
				var v = parseMoney(r.amount.value);
				if (v !== null) { sumMinor += v; }
			});
			var total = parseMoney(totalInput ? totalInput.value : '');
			sum.innerHTML = '';
			sum.appendChild(el('span', { text: t('Allocated') + ': ' + fmtMoney(sumMinor) }));
			sum.appendChild(el('span', { text: t('Receipt total') + ': ' + (total === null ? '—' : fmtMoney(total)) }));
		}

		function addRow(data) {
			data = data || {};
			var row = {
				pos: selectEl('split_position', positionOptions(positions, t('— choose —')), data.position_id, { required: true }),
				ap: selectEl('split_ap', apOptions(aps, t('—')), data.ap_id),
				amount: inputEl('split_amount', { inputmode: 'decimal', placeholder: '0,00', required: true }),
				note: inputEl('split_note', { value: data.note || '' })
			};
			if (data.amount_minor !== undefined && data.amount_minor !== null) {
				row.amount.value = (data.amount_minor / 100).toLocaleString('de-DE', {
					minimumFractionDigits: 2, maximumFractionDigits: 2
				});
			}
			row.amount.setAttribute('aria-label', t('Split amount (EUR)'));
			row.pos.setAttribute('aria-label', t('Position'));
			row.ap.setAttribute('aria-label', t('Work package'));
			row.note.setAttribute('aria-label', t('Note'));
			var rm = el('button', {
				type: 'button', class: 'nw-btn nw-btn--small nw-btn--ghost',
				'aria-label': t('Remove split row'), text: '×'
			});
			var r = el('div', { class: 'nw-edit-grid__row' }, [row.pos, row.ap, row.amount, row.note, rm]);
			rm.addEventListener('click', function () {
				rows.splice(rows.indexOf(row), 1);
				r.remove(); refresh();
			});
			row.amount.addEventListener('input', refresh);
			if (totalInput) { totalInput.addEventListener('input', refresh); }
			grid.appendChild(r);
			rows.push(row);
			return row;
		}

		(initial || [{}]).forEach(addRow);
		var addBtn = smallBtn(t('Add split'), function () { addRow(); });
		var wrap = el('div', {}, [grid, addBtn, sum]);
		refresh();
		return {
			node: wrap,
			collect: function () {
				var splits = [], ok = true;
				rows.forEach(function (r) {
					var amount = parseMoney(r.amount.value);
					var posId = parseInt(r.pos.value, 10);
					if (amount === null || !posId) { ok = false; return; }
					var s = { position_id: posId, amount_minor: amount, note: r.note.value };
					if (r.ap.value !== '') { s.ap_id = parseInt(r.ap.value, 10); }
					splits.push(s);
				});
				return ok && splits.length ? splits : null;
			},
			total: function () {
				var s = 0;
				rows.forEach(function (r) {
					var v = parseMoney(r.amount.value);
					if (v !== null) { s += v; }
				});
				return s;
			}
		};
	}

	/* ---------- shared dialogs ---------- */

	function openProjectDialog() {
		loadTemplates().then(function (templates) {
			var tplOpts = (templates || []).map(function (x) { return [x.key, x.name]; });
			if (!tplOpts.length) { tplOpts = [['anbest-p', 'ANBest-P']]; }
			var body = el('div', {}, [
				fieldEl(t('Project name'), inputEl('name', { required: true })),
				fieldEl(t('Funder'), inputEl('funder_name', { required: true })),
				moreDetails(t('More options'), [
					fieldEl(t('Reference number'), inputEl('funder_ref')),
					fieldEl(t('Funding period start'), inputEl('bew_start', { type: 'date' })),
					fieldEl(t('Funding period end'), inputEl('bew_end', { type: 'date' })),
					fieldEl(t('Nebenbestimmungen template'), selectEl('template_key', tplOpts, 'anbest-p')),
					fieldEl(t('Financing mode'), selectEl('financing_mode', [
						['ausgaben', t('Expenditure-based funding')],
						['festbetrag', t('Flat-rate funding')]
					], 'ausgaben'))
				])
			]);
			NW.openDialog({
				title: t('New funded project'),
				body: body,
				submitLabel: t('Create project'),
				onSubmit: function (fd) {
					return NW.api('/projects', { method: 'POST', json: {
						name: fd.get('name'),
						funder_name: fd.get('funder_name'),
						funder_ref: fd.get('funder_ref') || '',
						bew_start: fd.get('bew_start') || '',
						bew_end: fd.get('bew_end') || '',
						template_key: fd.get('template_key'),
						financing_mode: fd.get('financing_mode')
					}}).then(function (p) {
						NW.toast('success', t('Project created.'));
						location.href = location.pathname + '?project=' + p.id + '&view=dashboard';
					});
				}
			});
		});
	}

	/* Booking < 60 s: date today, AP preselected from last booking. */
	function openBookDialog(after) {
		Promise.all([loadAps(), loadMembers()]).then(function (res) {
			var aps = (res[0] || []).filter(function (a) { return a.state !== 'closed'; });
			var members = res[1] || [];
			if (!aps.length) {
				NW.toast('warn', t('Create a work package first — time needs an AP to book against.'));
				return;
			}
			var lastAp = null;
			try { lastAp = window.localStorage.getItem('nw-last-ap-' + pid); } catch (e) {}
			// Forgiving duration: "1:30", "1,5", "2h" or "90" — live echo so
			// the user always sees the parsed result before saving.
			var durEcho = el('p', { class: 'nw-field__hint', 'aria-live': 'polite' });
			var durIn = inputEl('minutes', { inputmode: 'decimal', placeholder: '1:30', required: true });
			durIn.addEventListener('input', function () {
				var v = parseDuration(durIn.value);
				durEcho.textContent = durIn.value.trim() === '' ? t('e.g. 1:30, 1,5 h or 90')
					: (v === null ? t('Not a duration — try 1:30 or 1,5 h or 90') : '→ ' + fmtDurationHuman(v));
				if (v !== null) { NW.clearFieldError(durIn); }
			});
			var durField = fieldEl(t('Duration'), durIn, { hint: t('e.g. 1:30, 1,5 h or 90') });
			durField.appendChild(durEcho);
			var fields = [
				fieldEl(t('Work package'), selectEl('ap_id', apOptions(aps), lastAp, { required: true })),
				fieldEl(t('Date'), inputEl('entry_date', { type: 'date', value: NW.todayYmd(), required: true })),
				durField,
				fieldEl(t('Short note'), inputEl('note'))
			];
			if (caps.can_finance && members.length) {
				fields.splice(1, 0, fieldEl(t('Book for'), memberSelect('uid', members, boot.uid, { blank: false })));
			}
			NW.openDialog({
				title: t('Book time'),
				body: el('div', {}, fields),
				submitLabel: t('Book'),
				onSubmit: function (fd) {
					var minutes = parseDuration(fd.get('minutes'));
					if (minutes === null) {
						NW.markFieldError(durIn, t('Not a duration — try 1:30 or 1,5 h or 90'));
						return Promise.reject({ fields: { minutes: t('Invalid duration.') }, message: t('Invalid duration.') });
					}
					var body = {
						ap_id: parseInt(fd.get('ap_id'), 10),
						entry_date: fd.get('entry_date'),
						minutes: minutes,
						note: fd.get('note') || ''
					};
					var uid = fd.get('uid');
					if (uid && uid !== boot.uid) { body.uid = uid; }
					return NW.api('/projects/' + pid + '/time-entries', { method: 'POST', json: body })
						.then(function () {
							try { window.localStorage.setItem('nw-last-ap-' + pid, String(body.ap_id)); } catch (e) {}
							NW.toast('success', t('Time booked.'));
							(after || reload)();
						});
				}
			});
		}).catch(NW.handleError);
	}

	function openReceiptDialog(existing, after) {
		Promise.all([loadActivePositions(), loadAps()]).then(function (res) {
			var positions = res[0] || [], aps = res[1] || [];
			if (!positions.length && !existing) {
				NW.toast('warn', t('No active financing plan — activate one before filing receipts.'));
				return;
			}
			var isEdit = !!existing;
			var kindSel = selectEl('kind', [['receipt', t('Receipt')]], (existing && existing.kind) || 'receipt');
			if (caps.can_finance && !isEdit) {
				kindSel.appendChild(el('option', { value: 'salary', text: t('Salary receipt') }));
			}
			var salaryField = fieldEl(t('Salary period (YYYY-MM)'), inputEl('salary_period', {
				type: 'month', value: (existing && existing.salary_period) || ''
			}), { hint: t('Required for salary receipts — the rate period this proves.') });
			salaryField.hidden = ((existing && existing.kind) || 'receipt') !== 'salary';
			kindSel.addEventListener('change', function () {
				salaryField.hidden = kindSel.value !== 'salary';
				salaryField.querySelector('input').required = kindSel.value === 'salary';
			});
			var currencyIn = inputEl('currency', { value: (existing && existing.currency) || 'EUR' });
			var fxField = fieldEl(t('Exchange rate (per 1 unit, in EUR)'), inputEl('fx_rate', {
				inputmode: 'decimal', placeholder: '1,000000',
				value: existing && existing.fx_rate_milli ? String(existing.fx_rate_milli / 1000).replace('.', ',') : ''
			}), { hint: t('Only for foreign currency receipts.') });
			fxField.hidden = (currencyIn.value || 'EUR') === 'EUR';
			currencyIn.addEventListener('input', function () {
				fxField.hidden = (currencyIn.value || 'EUR').toUpperCase() === 'EUR';
			});
			var totalIn = moneyEl('total_minor', existing ? existing.total_minor : null);
			var editor = splitEditor(positions, aps, existing ? existing.splits : null, totalIn);

			// Attachment: pick from Files (no raw-id typing) or type the id
			// under More options. The picker resolves the path server-side.
			var fileIdIn = inputEl('file_id', {
				inputmode: 'numeric', value: existing && existing.file_id ? existing.file_id : ''
			});
			var filePickBtn = null;
			if (window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
				filePickBtn = el('button', { type: 'button', class: 'nw-btn nw-btn--secondary', text: t('Choose from Files…') });
				filePickBtn.addEventListener('click', function () {
					OC.dialogs.filepicker(t('Pick the receipt file'), function (path) {
						if (!path) { return; }
						NW.api('/projects/' + pid + '/files/resolve?path=' + encodeURIComponent(path))
							.then(function (res) {
								fileIdIn.value = String(res.file_id);
								NW.toast('success', t('Attached %s.', [res.name || path]));
							})
							.catch(function () { NW.toast('warn', t('Could not resolve that file — enter the file id manually.')); });
					}, false, null, true);
				});
			}
			var fileRow = el('div', { class: 'nw-file-row' },
				[fileIdIn, filePickBtn].filter(Boolean));
			var fileCtl = { node: fileRow, focusEl: fileIdIn };

			// §12.5: 3 primary fields (Vendor / Invoice date / Total) + splits.
			// Everything else folds into one More-options band.
			var children = [
				isEdit ? null : fieldEl(t('Kind'), kindSel),
				fieldEl(t('Vendor'), inputEl('vendor', { required: true, value: existing ? existing.vendor : '' })),
				fieldEl(t('Invoice date'), inputEl('invoice_date', { type: 'date', required: true, value: existing ? existing.invoice_date : '' })),
				fieldEl(t('Total (EUR)'), totalIn),
				moreDetails(t('More options'), [
					salaryField,
					fieldEl(t('Invoice number'), inputEl('invoice_no', { value: existing ? existing.invoice_no : '' })),
					fieldEl(t('Payment date'), inputEl('payment_date', { type: 'date', value: existing ? existing.payment_date : '' })),
					fieldEl(t('Currency'), currencyIn),
					fxField,
					fieldEl(t('Tax (EUR)'), moneyEl('tax_minor', existing ? existing.tax_minor : 0)),
					fieldEl(t('Purpose'), textareaEl('purpose', { value: existing ? existing.purpose : '' })),
					fieldEl(t('Attachment'), fileCtl, { hint: t('Pick the scanned receipt from Files — or paste a file id. Empty flags a missing attachment.') }),
					fieldEl(t('External reference'), inputEl('external_ref'), { hint: t('Import dedupe key — optional.') })
				]),
				el('fieldset', { class: 'nw-fieldset' }, [
					el('legend', { class: 'nw-fieldset__legend', text: t('Splits by position') }),
					editor.node
				])
			];
			if (isEdit) {
				// splits have their own validated endpoint — edit fields only here
				children.pop();
			}
			NW.openDialog({
				title: isEdit ? t('Edit receipt') : t('File receipt'),
				body: el('div', {}, children),
				submitLabel: isEdit ? t('Save') : t('File'),
				wide: true,
				onSubmit: function (fd, api2, form) {
					var total = parseMoney(fd.get('total_minor'));
					var tax = parseMoney(fd.get('tax_minor'));
					if (total === null || tax === null) {
						NW.markFieldError(total === null ? totalIn : form.querySelector('[name="tax_minor"]'),
							t('Invalid amount — use a value like 1.234,56.'));
						return Promise.reject({ message: t('Invalid amount.') });
					}
					var fx = parseFx(fd.get('fx_rate'));
					if (fx === undefined) {
						NW.markFieldError(form.querySelector('[name="fx_rate"]'), t('Invalid exchange rate.'));
						return Promise.reject({ message: t('Invalid exchange rate.') });
					}
					var body = {
						vendor: fd.get('vendor'),
						invoice_no: fd.get('invoice_no') || '',
						invoice_date: fd.get('invoice_date'),
						payment_date: fd.get('payment_date') || '',
						currency: (fd.get('currency') || 'EUR').toUpperCase(),
						fx_rate_milli: fx,
						total_minor: total,
						tax_minor: tax,
						purpose: fd.get('purpose') || '',
						file_id: fd.get('file_id') || '',
						salary_period: fd.get('salary_period') || '',
						external_ref: fd.get('external_ref') || ''
					};
					if (isEdit) {
						return NW.api('/projects/' + pid + '/receipts/' + existing.id, { method: 'PUT', json: body })
							.then(function () {
								NW.toast('success', t('Receipt saved.'));
								(after || reload)();
							});
					}
					body.kind = fd.get('kind') || 'receipt';
					var splits = editor.collect();
					if (!splits) {
						return Promise.reject({ message: t('Every split needs a position and a valid amount.') });
					}
					if (editor.total() !== total) {
						return Promise.reject({ message: t('Splits must add up to the receipt total.') });
					}
					body.splits = splits;
					return NW.api('/projects/' + pid + '/receipts', { method: 'POST', json: body })
						.then(function (r) {
							if (r && r.dup_flag) {
								NW.toast('warn', t('Possible duplicate — flagged for review (vendor + date + amount match an existing receipt).'));
							} else {
								NW.toast('success', t('Receipt filed.'));
							}
							(after || reload)();
						});
				}
			});
		}).catch(NW.handleError);
	}

	function openSplitsDialog(receipt, after) {
		Promise.all([loadActivePositions(), loadAps()]).then(function (res) {
			var editor = splitEditor(res[0] || [], res[1] || [], receipt.splits, null);
			NW.openDialog({
				title: t('Edit splits'),
				body: el('div', {}, [
					el('p', { class: 'nw-dialog__text', text: t('Splits must add up to %s.', [fmtMoney(receipt.total_minor)]) }),
					editor.node
				]),
				submitLabel: t('Save'),
				wide: true,
				onSubmit: function () {
					var splits = editor.collect();
					if (!splits) {
						return Promise.reject({ message: t('Every split needs a position and a valid amount.') });
					}
					if (editor.total() !== receipt.total_minor) {
						return Promise.reject({ message: t('Splits must add up to the receipt total.') });
					}
					return NW.api('/projects/' + pid + '/receipts/' + receipt.id + '/splits', {
						method: 'PUT', json: { splits: splits }
					}).then(function () {
						NW.toast('success', t('Splits saved.'));
						(after || reload)();
					});
				}
			});
		}).catch(NW.handleError);
	}

	function openDeviationDialog(after) {
		Promise.all([loadActivePositions(), loadAps()]).then(function (res) {
			var positions = res[0] || [], aps = res[1] || [];
			var scopeSel = selectEl('scope', [
				['position', t('Budget position')],
				['ap', t('Work package')]
			], 'position');
			var refSel = selectEl('ref_id', positionOptions(positions), null, { required: true });
			var refField = fieldEl(t('Reference'), refSel);
			scopeSel.addEventListener('change', function () {
				var opts = scopeSel.value === 'ap' ? apOptions(aps) : positionOptions(positions);
				refSel.innerHTML = '';
				opts.forEach(function (o) {
					refSel.appendChild(el('option', { value: String(o[0]), text: o[1] }));
				});
			});
			NW.openDialog({
				title: t('Record deviation'),
				body: el('div', {}, [
					fieldEl(t('Scope'), scopeSel),
					refField,
					fieldEl(t('Kind'), selectEl('kind', [
						['time', t('Time')],
						['cost', t('Cost')],
						['schedule', t('Schedule')]
					], 'cost')),
					fieldEl(t('Justification (required)'), textareaEl('reason_text', { required: true }), {
						hint: t('The funder sees this verbatim — explain what deviates and why.')
					})
				]),
				submitLabel: t('Record'),
				onSubmit: function (fd) {
					var reason = String(fd.get('reason_text') || '').trim();
					if (reason === '') {
						return Promise.reject({ fields: { reason_text: t('A justification is required.') }, message: t('A justification is required.') });
					}
					return NW.api('/projects/' + pid + '/deviations', { method: 'POST', json: {
						scope: fd.get('scope'),
						ref_id: parseInt(fd.get('ref_id'), 10),
						kind: fd.get('kind'),
						reason_text: reason
					}}).then(function () {
						NW.toast('success', t('Deviation recorded.'));
						(after || reload)();
					});
				}
			});
		}).catch(NW.handleError);
	}

	/* ================= views ================= */

	var host = null;

	function renderPositionList(positions) {
		var box = el('div', { class: 'nw-position-list' });
		(positions || []).forEach(function (pos) {
			var pct = pos.budget_minor > 0
				? Math.min(100, Math.round(pos.used_minor / pos.budget_minor * 100))
				: (pos.used_minor > 0 ? 100 : 0);
			var cls = pos.threshold === 'blocked_over' ? ' nw-position--blocked'
				: (pos.threshold === 'within_20' ? ' nw-position--over' : '');
			box.appendChild(el('div', { class: 'nw-position' + cls }, [
				el('div', { class: 'nw-position__head' }, [
					el('span', { class: 'nw-position__title', text: pos.code + ' — ' + pos.title }),
					el('span', { class: 'nw-position__meta' }, [
						el('span', { text: fmtMoney(pos.used_minor) + ' / ' + fmtMoney(pos.budget_minor) }),
						thresholdBadge(pos.threshold)
					])
				]),
				el('div', {
					class: 'nw-position__bar', role: 'progressbar',
					'aria-valuenow': String(pct), 'aria-valuemin': '0', 'aria-valuemax': '100',
					'aria-label': pos.title
				}, [el('div', { class: 'nw-position__fill', style: 'width:' + pct + '%' })])
			]));
		});
		return box;
	}

	/* ---------- dashboard ---------- */

	function renderDashboard() {
		NW.pageActions([]);
		host.innerHTML = '';
		host.appendChild(loading());
		loadProject().then(function (p) {
			var pcaps = p.capabilities || caps;
			var actions = [];
			if (pcaps.can_book && p.state === 'active') {
				actions.push({ label: t('Book time'), primary: true, onClick: function () { openBookDialog(); } });
				actions.push({ label: t('File receipt'), onClick: function () { openReceiptDialog(); } });
			}
			if (pcaps.can_admin && p.state === 'draft') {
				actions.push({ label: t('Activate project'), primary: true, onClick: function () {
					NW.api('/projects/' + pid + '/activate', { method: 'POST', json: {} })
						.then(reload).catch(NW.handleError);
				}});
				actions.push({ label: t('Delete draft'), danger: true, onClick: function () {
					NW.confirmDialog(t('Delete project'), t('Delete this draft project? This cannot be undone.'))
						.then(function (ok) {
							if (!ok) { return; }
							NW.api('/projects/' + pid, { method: 'DELETE' }).then(function () {
								location.href = location.pathname;
							}).catch(NW.handleError);
						});
				}});
			}
			if (pcaps.can_admin && p.state === 'active') {
				actions.push({ label: t('Start closing'), onClick: function () {
					NW.confirmDialog(t('Close project'), t('Move the project into closing? Booking stays possible until it is fully closed.'))
						.then(function (ok) {
							if (!ok) { return; }
							NW.api('/projects/' + pid + '/state/closing', { method: 'POST', json: {} })
								.then(reload).catch(NW.handleError);
						});
				}});
			}
			if (pcaps.can_admin && p.state === 'closing') {
				actions.push({ label: t('Mark closed'), onClick: function () {
					NW.confirmDialog(t('Close project'), t('Mark the project as fully closed? The fiscal record is then immutable.'))
						.then(function (ok) {
							if (!ok) { return; }
							NW.api('/projects/' + pid + '/state/closed', { method: 'POST', json: {} })
								.then(reload).catch(NW.handleError);
						});
				}});
			}
			NW.pageActions(actions);
			host.innerHTML = '';

			var si = p.soll_ist || { positions: [], totals: { budget: 0, used: 0 } };
			var totals = si.totals || { budget: 0, used: 0 };
			var pct = totals.budget > 0 ? Math.round(totals.used / totals.budget * 100) : 0;

			host.appendChild(el('div', { class: 'nw-stat-strip' }, [
				el('div', { class: 'nw-stat-tile nw-stat-tile--primary' }, [
					el('span', { class: 'nw-stat-tile__label', text: t('Budget') }),
					el('span', { class: 'nw-stat-tile__value', text: fmtMoney(totals.budget) })
				]),
				el('div', { class: 'nw-stat-tile' }, [
					el('span', { class: 'nw-stat-tile__label', text: t('Spent') }),
					el('span', { class: 'nw-stat-tile__value', text: fmtMoney(totals.used) })
				]),
				el('div', { class: 'nw-stat-tile' }, [
					el('span', { class: 'nw-stat-tile__label', text: t('Utilisation') }),
					el('span', { class: 'nw-stat-tile__value', text: pct + ' %' }),
					el('span', { class: 'nw-stat-tile__meta', text: t('of approved budget') })
				]),
				el('div', { class: 'nw-stat-tile' }, [
					el('span', { class: 'nw-stat-tile__label', text: t('State') }),
					el('span', { class: 'nw-stat-tile__value' }, [stateBadge(p.state, t(p.state))])
				])
			]));

			if (p.state === 'draft') {
				host.appendChild(el('div', { class: 'nw-callout nw-callout--info', role: 'status' }, [
					el('p', { class: 'nw-callout__title', text: t('Draft project') }),
					el('p', { class: 'nw-callout__text', text: t('Set up the financing plan first, then activate the project — after that, times and receipts can be booked.') }),
					el('p', {}, [el('a', {
						class: 'nw-btn nw-btn--secondary nw-btn--small',
						href: location.pathname + '?project=' + pid + '&view=finplan',
						text: t('Open the financing plan')
					})])
				]));
			}

			host.appendChild(card(t('Project'), { children: [
				dlEl([
					[t('Funder'), p.funder_name || '—'],
					[t('Reference number'), p.funder_ref || '—'],
					[t('Funding period'), (p.bew_start || '—') + ' – ' + (p.bew_end || '—')],
					[t('Template'), p.template_key || '—'],
					[t('Financing mode'), t(FINANCING_MODES[p.financing_mode] || p.financing_mode || '—')]
				])
			]}));

			host.appendChild(card(t('Budget vs. actuals by position'), { children: [
				(si.positions || []).length
					? renderPositionList(si.positions)
					: el('p', { class: 'nw-muted', text: t('No active financing plan yet.') })
			]}));

			host.appendChild(card(t('Work packages'), { children: [
				tableEl([t('AP'), t('Hours'), t('Cost'), t('State'), t('Reports')],
					(p.work_packages || []).map(function (ap) {
						return [
							el('span', {}, [el('strong', { text: ap.code }), document.createTextNode(' ' + ap.title)]),
							fmtHours(ap.minutes),
							fmtMoney(ap.cost_minor),
							stateBadge(ap.state, t(ap.state)),
							String((ap.reports || []).length)
						];
					}), { empty: t('No work packages yet.') })
			]}));

			host.appendChild(card(t('Deadlines'), { children: [
				renderDeadlineList(p.deadlines || [])
			]}));

			// "What needs attention" — the readiness endpoint already knows
			// every audit gap; surface it here so dashboard answers that
			// question first, not only on the export page.
			var attnRegion = el('div');
			host.insertBefore(attnRegion, host.children[1] || null);
			NW.api('/projects/' + pid + '/readiness').then(function (r) {
				if (!r) { return; }
				var blockers = (r.checks || []).filter(function (c) { return c.severity === 'blocker'; });
				var warnings = (r.checks || []).filter(function (c) { return c.severity === 'warning'; });
				if (!blockers.length && !warnings.length) { return; }
				var list = el('ul', { class: 'nw-readiness-list' });
				blockers.concat(warnings).slice(0, 4).forEach(function (c) {
					list.appendChild(el('li', { class: 'nw-readiness-item' }, [
						NW.badge(c.severity === 'blocker' ? 'danger' : 'warn',
							c.severity === 'blocker' ? t('Blocker') : t('Warning')),
						el('span', { text: ' ' + readinessCheckLabel(c.id) + ' (' + c.count + ')' })
					]));
				});
				attnRegion.appendChild(el('div', {
					class: 'nw-callout ' + (blockers.length ? 'nw-callout--danger' : 'nw-callout--warning'), role: 'alert'
				}, [
					el('p', { class: 'nw-callout__title', text: blockers.length
						? t('%s things block the audit', [String(blockers.length)])
						: t('%s things need attention', [String(warnings.length)]) }),
					list,
					el('p', {}, [el('a', {
						class: 'nw-btn nw-btn--secondary nw-btn--small',
						href: location.pathname + '?project=' + pid + '&view=nachweis',
						text: t('Open the checklist')
					})])
				]));
			}).catch(function () { /* readiness is advisory — dashboard still works */ });
		}).catch(function (e) {
			host.innerHTML = '';
			host.appendChild(emptyState(t('Could not load the project'), e.message || t('Something went wrong.')));
			NW.handleError(e);
		});
	}

	function renderDeadlineList(deadlines) {
		if (!deadlines.length) {
			return el('p', { class: 'nw-muted', text: t('No deadlines calculated yet.') });
		}
		var ul = el('ul', { class: 'nw-deadline-list' });
		deadlines.forEach(function (d) {
			// __item is the flex row the CSS contract lays out — the badge
			// must be a direct child or margin-inline-start:auto cannot
			// pin it to the row's end.
			ul.appendChild(el('li', { class: 'nw-deadline-list__item' }, [
				el('span', { class: 'nw-deadline-list__title', text: deadlineLabel(d.kind) }),
				el('span', { class: 'nw-deadline-list__meta', text: NW.fmtDate(d.due_date) }),
				d.notified ? NW.badge('success', t('reminded')) : NW.badge('neutral', t('pending'))
			]));
		});
		return ul;
	}

	/* ---------- finplan ---------- */

	function renderFinplan() {
		NW.pageActions([]);
		host.innerHTML = '';
		host.appendChild(loading());
		NW.api('/projects/' + pid + '/finplan/versions').then(function (vers) {
			host.innerHTML = '';
			var actions = [];
			if (caps.can_finance) {
				actions.push({ label: t('New plan draft'), primary: true, onClick: function () {
					NW.openDialog({
						title: t('New plan draft'),
						body: fieldEl(t('Note'), inputEl('note', { placeholder: t('What changes in this version?') })),
						submitLabel: t('Create draft'),
						onSubmit: function (fd) {
							return NW.api('/projects/' + pid + '/finplan/draft', {
								method: 'POST', json: { note: fd.get('note') || '' }
							}).then(function () {
								NW.toast('success', t('Draft created.'));
								reload();
							});
						}
					});
				}});
			}
			NW.pageActions(actions);

			if (!vers.length) {
				host.appendChild(emptyState(t('No financing plan yet'),
					t('The financing plan comes first — after that, times and receipts can be assigned to positions.')));
				return;
			}
			var selected = vers.filter(function (v) { return v.state === 'draft'; })[0]
				|| vers.filter(function (v) { return v.state === 'active'; })[0] || vers[0];

			var pillBar = el('div', { class: 'nw-quick-filters' });
			pillBar.appendChild(el('span', { class: 'nw-quick-filters__label', text: t('Version') + ':' }));
			var pills = el('div', { class: 'nw-filter-bar', role: 'group', 'aria-label': t('Plan version') });
			pillBar.appendChild(pills);
			host.appendChild(pillBar);
			var detail = el('div');
			host.appendChild(detail);

			var pillSeq = 0;
			function paint() {
				pills.innerHTML = '';
				vers.forEach(function (v) {
					var b = el('button', {
						type: 'button', class: 'nw-filter',
						'aria-pressed': v.id === selected.id ? 'true' : 'false',
						text: 'v' + v.version + ' — ' + t(v.state)
					});
					b.addEventListener('click', function () { selected = v; paint(); });
					pills.appendChild(b);
				});
				// Generation guard: pills are retriggerable while a positions
				// fetch is in flight — a stale response must not render an
				// older version's rows under the newest pill's label.
				var seq = ++pillSeq;
				var wanted = selected;
				detail.innerHTML = '';
				detail.appendChild(loading());
				NW.api('/projects/' + pid + '/finplan/versions/' + wanted.id + '/positions').then(function (positions) {
					if (seq !== pillSeq) { return; }
					detail.innerHTML = '';
					detail.appendChild(renderFinplanVersion(wanted, positions));
				}).catch(function (e) {
					if (seq !== pillSeq) { return; }
					detail.innerHTML = '';
					NW.handleError(e);
				});
			}
			paint();
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function renderFinplanVersion(ver, positions) {
		var editable = ver.state === 'draft' && caps.can_finance;
		var wrap = el('div');
		var head = el('div', { class: 'nw-list-head' }, [
			el('h2', { class: 'nw-list-head__title', text: t('Version %s', [String(ver.version)]) }),
			stateBadge(ver.state, t(ver.state)),
			el('span', { class: 'nw-list-head__count', text: ver.note || '' })
		]);
		wrap.appendChild(head);

		if (!editable) {
			wrap.appendChild(card(t('Positions'), { table: true, children: [
				tableEl([t('Code'), t('Title'), t('Kind'), t('Budget')],
					(positions || []).map(function (p) {
						return [p.code, p.title, posKindLabel(p.kind), fmtMoney(p.budget_minor)];
					}), { empty: t('No positions.') })
			]}));
			if (ver.state === 'draft' && caps.can_admin) {
				wrap.appendChild(el('div', { class: 'nw-btn-row' }, [
					btn(t('Activate this version'), function () { activateFinplan(ver); }, 'nw-btn--primary')
				]));
			}
			return wrap;
		}

		// Editable draft grid
		var grid = el('div', { class: 'nw-edit-grid' });
		['Code', 'Title', 'Kind', 'Budget (EUR)', ''].forEach(function (h, i) {
			grid.appendChild(el('span', { class: 'nw-edit-grid__head', text: i === 4 ? '' : t(h) }));
		});
		var rows = [];
		function addRow(p) {
			p = p || {};
			var row = {
				code: inputEl('pos_code', { value: p.code || '', required: true }),
				title: inputEl('pos_title', { value: p.title || '', required: true }),
				kind: selectEl('pos_kind', POSITION_KINDS.map(function (k) { return [k, posKindLabel(k)]; }), p.kind || 'sach'),
				budget: inputEl('pos_budget', {
					inputmode: 'decimal', placeholder: '0,00', required: true,
					value: p.budget_minor !== undefined && p.budget_minor !== null
						? (p.budget_minor / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
						: ''
				})
			};
			row.code.setAttribute('aria-label', t('Position code'));
			row.title.setAttribute('aria-label', t('Position title'));
			row.kind.setAttribute('aria-label', t('Kind'));
			row.budget.setAttribute('aria-label', t('Budget (EUR)'));
			var rm = el('button', { type: 'button', class: 'nw-btn nw-btn--small nw-btn--ghost', 'aria-label': t('Remove position'), text: '×' });
			var r = el('div', { class: 'nw-edit-grid__row' }, [row.code, row.title, row.kind, row.budget, rm]);
			rm.addEventListener('click', function () { rows.splice(rows.indexOf(row), 1); r.remove(); });
			grid.appendChild(r);
			rows.push(row);
		}
		(positions || []).forEach(addRow);
		if (!positions || !positions.length) { addRow(); }

		wrap.appendChild(card(t('Positions'), { children: [
			grid,
			el('div', { class: 'nw-btn-row' }, [
				smallBtn(t('Add position'), function () { addRow(); }),
				btn(t('Save positions'), function () {
					var out = [], bad = false;
					rows.forEach(function (r, i) {
						var budget = parseMoney(r.budget.value);
						if (r.code.value.trim() === '' || r.title.value.trim() === '' || budget === null) {
							bad = true;
						}
						out.push({
							code: r.code.value.trim(), title: r.title.value.trim(),
							kind: r.kind.value, budget_minor: budget === null ? 0 : budget,
							sort: i
						});
					});
					if (bad) {
						NW.toast('error', t('Every position needs a code, a title and a valid budget.'));
						return;
					}
					NW.api('/projects/' + pid + '/finplan/versions/' + ver.id + '/positions', {
						method: 'PUT', json: { positions: out }
					}).then(function () {
						NW.toast('success', t('Positions saved.'));
						reload();
					}).catch(NW.handleError);
				}, 'nw-btn--primary'),
				caps.can_admin ? btn(t('Activate this version'), function () { activateFinplan(ver); }) : null
			])
		]}));
		return wrap;
	}

	function activateFinplan(ver) {
		// Fail-closed CAS: send the base this draft was cut from; a stale
		// base → 409 → reload, never a silent merge.
		NW.api('/projects/' + pid + '/finplan/versions/' + ver.id + '/activate', {
			method: 'POST', json: { base_version: ver.base_version }
		}).then(function () {
			NW.toast('success', t('Plan activated.'));
			reload();
		}).catch(function (e) {
			NW.handleError(e);
			reload();
		});
	}

	/* ---------- work packages ---------- */

	function renderWorkPackages() {
		NW.pageActions([]);
		host.innerHTML = '';
		host.appendChild(loading());
		Promise.all([loadAps(), loadActivePositions(), loadMembers()]).then(function (res) {
			var aps = res[0] || [], positions = res[1] || [], members = res[2] || [];
			host.innerHTML = '';
			var actions = [];
			if (caps.can_finance) {
				actions.push({ label: t('New work package'), primary: true, onClick: function () {
					openApDialog(members);
				}});
			}
			NW.pageActions(actions);
			var posMap = indexById(positions);
			host.appendChild(card(t('Work packages'), { children: [
				tableEl([t('Code'), t('Title'), t('Owner'), t('Period'), t('Positions'), t('State'), ''],
					aps.map(function (ap) {
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_finance && ap.state !== 'closed') {
							act.appendChild(smallBtn(t('Positions'), function () { openApPositions(ap, positions); }));
							act.appendChild(smallBtn(t('Close'), function () {
								NW.confirmDialog(t('Close work package'), t('Close %s? No more time can be booked to it.', [ap.code]))
									.then(function (ok) {
										if (!ok) { return; }
										NW.api('/projects/' + pid + '/workpackages/' + ap.id + '/close', { method: 'POST', json: {} })
											.then(reload).catch(NW.handleError);
									});
							}));
						}
						var posNames = (ap.position_ids || []).map(function (i) {
							return posMap[i] ? posMap[i].code : '#' + i;
						}).join(', ');
						return [
							el('strong', { text: ap.code }),
							ap.title,
							ap.owner_uid || '—',
							(ap.start_date || '—') + ' – ' + (ap.end_date || '—'),
							posNames || '—',
							stateBadge(ap.state, t(ap.state)),
							act
						];
					}), { empty: t('No work packages yet.') })
			]}));
			if (!positions.length) {
				host.appendChild(el('div', { class: 'nw-callout nw-callout--warning', role: 'status' }, [
					el('p', { class: 'nw-callout__title', text: t('No active financing plan') }),
					el('p', { class: 'nw-callout__text', text: t('Positions appear once a financing plan version is activated.') })
				]));
			}
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function openApDialog(members) {
		var body = el('div', {}, [
			fieldEl(t('Code'), inputEl('code', { required: true, placeholder: 'AP1' })),
			fieldEl(t('Title'), inputEl('title', { required: true })),
			moreDetails(t('More options'), [
				fieldEl(t('Owner'), memberSelect('owner_uid', members, null, { blank: t('— none —') })),
				fieldEl(t('Start'), inputEl('start_date', { type: 'date' })),
				fieldEl(t('End'), inputEl('end_date', { type: 'date' }))
			])
		]);
		NW.openDialog({
			title: t('New work package'),
			body: body,
			submitLabel: t('Create'),
			onSubmit: function (fd) {
				return NW.api('/projects/' + pid + '/workpackages', { method: 'POST', json: {
					code: fd.get('code'), title: fd.get('title'),
					owner_uid: fd.get('owner_uid') || '',
					start_date: fd.get('start_date') || '',
					end_date: fd.get('end_date') || ''
				}}).then(function () {
					NW.toast('success', t('Work package created.'));
					reload();
				});
			}
		});
	}

	function openApPositions(ap, positions) {
		var current = {};
		(ap.position_ids || []).forEach(function (i) { current[i] = true; });
		var box = el('div');
		(positions || []).forEach(function (p) {
			var id = 'nw-appos-' + p.id;
			var cb = inputEl('position_ids', { type: 'checkbox', value: String(p.id) });
			cb.id = id; cb.checked = !!current[p.id];
			box.appendChild(el('div', { class: 'nw-checkbox-row' }, [
				el('label', { 'for': id }, [cb, document.createTextNode(' ' + p.code + ' — ' + p.title)])
			]));
		});
		NW.openDialog({
			title: t('Positions for %s', [ap.code]),
			body: box,
			submitLabel: t('Save'),
			onSubmit: function (fd) {
				var ids = fd.getAll('position_ids').map(function (v) { return parseInt(v, 10); });
				return NW.api('/projects/' + pid + '/workpackages/' + ap.id + '/positions', {
					method: 'PUT', json: { position_ids: ids }
				}).then(function () {
					NW.toast('success', t('Positions saved.'));
					reload();
				});
			}
		});
	}

	/* ---------- time & timesheets ---------- */

	function renderTime() {
		NW.pageActions([]);
		host.innerHTML = '';
		var actions = [];
		if (caps.can_book) {
			actions.push({ label: t('Book time'), primary: true, onClick: function () { openBookDialog(); } });
			actions.push({ label: t('Generate timesheet'), onClick: function () { openTimesheetDialog(); } });
		}
		NW.pageActions(actions);

		var fromIn = inputEl('from', { type: 'date' });
		fromIn.id = 'nw-time-from';
		var toIn = inputEl('to', { type: 'date' });
		toIn.id = 'nw-time-to';
		var filterCard = el('section', { class: 'nw-filter-panel', 'aria-labelledby': 'nw-time-filter-title' }, [
			el('header', { class: 'nw-filter-panel__head' }, [
				el('h2', { id: 'nw-time-filter-title', text: t('Filter') }),
				el('p', { class: 'nw-filter-panel__intro', text: t('Narrow the list to a period, then click Apply.') })
			]),
			el('div', { class: 'nw-filter-panel__body' }, [
				el('form', { class: 'nw-filter-panel__form', role: 'search', 'aria-label': t('Filter time entries'), novalidate: 'novalidate' }, [
					el('div', { class: 'nw-filter-grid', role: 'group', 'aria-label': t('Filter options') }, [
						el('div', { class: 'nw-filter-field' }, [
							el('label', { class: 'nw-filter-field__label', 'for': 'nw-time-from', text: t('From') }),
							el('div', { class: 'nw-filter-field__control' }, [fromIn])
						]),
						el('div', { class: 'nw-filter-field' }, [
							el('label', { class: 'nw-filter-field__label', 'for': 'nw-time-to', text: t('To') }),
							el('div', { class: 'nw-filter-field__control' }, [toIn])
						]),
						el('div', { class: 'nw-filter-field nw-filter-field--actions' }, [
							el('button', { type: 'submit', class: 'nw-btn nw-btn--primary', text: t('Apply') }),
							el('button', { type: 'button', class: 'nw-btn nw-btn--secondary', 'data-filter-clear': '1', text: t('Clear') })
						])
					])
				])
			])
		]);
		var listRegion = el('div');
		var tsRegion = el('div');
		host.appendChild(filterCard);
		host.appendChild(listRegion);
		host.appendChild(tsRegion);

		var form = filterCard.querySelector('form');

		// Generation guard: Apply/Clear are retriggerable while a fetch is
		// still in flight — a stale response must never paint over, error-
		// render, or clear the newest request's skeleton. Declared BEFORE
		// initFilterPanel: it applies once synchronously on mount.
		var entriesSeq = 0;

		// deepLink off: the kernel mirrors every form field into
		// location.search — hidden project/view context would surface as
		// active-filter chips and could be "cleared" by a user.
		NW.initFilterPanel({
			form: form,
			onApply: function (params) { loadEntries(params.get('from'), params.get('to')); },
			deepLink: false
		});

		function loadEntries(from, to) {
			var seq = ++entriesSeq;
			listRegion.innerHTML = '';
			listRegion.appendChild(loading());
			var qs = [];
			if (from) { qs.push('from=' + encodeURIComponent(from)); }
			if (to) { qs.push('to=' + encodeURIComponent(to)); }
			Promise.all([
				NW.api('/projects/' + pid + '/time-entries' + (qs.length ? '?' + qs.join('&') : '')),
				loadAps(), loadMembers()
			]).then(function (res) {
				if (seq !== entriesSeq) { return; }
				var entries = res[0] || [], apMap = indexById(res[1]), memMap = {};
				(res[2] || []).forEach(function (m) { memMap[m.uid] = m.display_name || m.uid; });
				listRegion.innerHTML = '';
				listRegion.appendChild(card(t('Time entries'), { children: [
					tableEl(
						[t('Date'), t('User'), t('AP'), t('Hours'), t('Note')]
							.concat(caps.can_see_rates ? [t('Amount')] : []).concat(['']),
						entries.map(function (e) {
							var act = el('span', { class: 'nw-table-actions' });
							if (caps.can_finance || (caps.can_book && e.uid === boot.uid)) {
								act.appendChild(smallBtn(t('Edit'), function () { openEntryEdit(e, res[1]); }));
								act.appendChild(smallBtn(t('Delete'), function () {
									NW.confirmDialog(t('Delete time entry'), t('Delete this time entry?'))
										.then(function (ok) {
											if (!ok) { return; }
											NW.api('/projects/' + pid + '/time-entries/' + e.id, { method: 'DELETE' })
												.then(reload).catch(NW.handleError);
										});
								}));
							}
							var row = [
								NW.fmtDate(e.entry_date),
								memMap[e.uid] || e.uid,
								apMap[e.ap_id] ? apMap[e.ap_id].code : '#' + e.ap_id,
								fmtHours(e.minutes),
								e.note || '—'
							];
							if (caps.can_see_rates) {
								row.push(e.priced ? fmtMoney(e.amount_minor) : NW.badge('warn', t('unpriced')));
							}
							row.push(act);
							return row;
						}), { empty: t('No time entries in this period.') })
				]}));
			}).catch(function (e) {
				if (seq !== entriesSeq) { return; }
				listRegion.innerHTML = '';
				NW.handleError(e);
			});
		}

		// Timesheets
		tsRegion.appendChild(loading());
		Promise.all([NW.api('/projects/' + pid + '/timesheets'), loadAps(), loadMembers()])
			.then(function (res) {
				var sheets = res[0] || [], apMap = indexById(res[1]), memMap = {};
				(res[2] || []).forEach(function (m) { memMap[m.uid] = m.display_name || m.uid; });
				tsRegion.innerHTML = '';
				var children = [
					tableEl([t('Period'), t('User'), t('AP'), t('Hours'), t('State'), ''],
						sheets.map(function (ts) {
							var act = el('span', { class: 'nw-table-actions' });
							if (role.isPro && caps.can_book && (ts.state === 'draft' || ts.state === 'rejected')) {
								act.appendChild(smallBtn(t('Submit'), function () {
									tsAction(ts.id, 'submit');
								}));
							}
							if (role.isPro && caps.can_finance && ts.state === 'submitted') {
								act.appendChild(smallBtn(t('Sign'), function () { tsAction(ts.id, 'sign'); }));
								act.appendChild(smallBtn(t('Reject'), function () {
									NW.openDialog({
										title: t('Reject timesheet'),
										body: fieldEl(t('Reason'), textareaEl('reason', { required: true })),
										submitLabel: t('Reject'), danger: true,
										onSubmit: function (fd) {
											return NW.api('/projects/' + pid + '/timesheets/' + ts.id + '/reject', {
												method: 'POST', json: { reason: fd.get('reason') || '' }
											}).then(function () { NW.toast('success', t('Timesheet rejected.')); reload(); });
										}
									});
								}));
							}
							return [
								ts.period,
								memMap[ts.uid] || ts.uid,
								ts.ap_id ? (apMap[ts.ap_id] ? apMap[ts.ap_id].code : '#' + ts.ap_id) : t('all'),
								fmtHours(ts.minutes_total),
								el('span', {}, [stateBadge(ts.state, t(ts.state)),
									ts.reject_reason ? document.createTextNode(' — ' + ts.reject_reason) : null]),
								act
							];
						}), { empty: t('No timesheets yet.') })
				];
				if (!role.isPro) {
					children.unshift(el('p', { class: 'nw-muted', text: t('Timesheet sign-off is a Pro feature — generated sheets stay drafts.') }));
				}
				tsRegion.appendChild(card(t('Timesheets'), { children: children }));
			}).catch(function () {
				tsRegion.innerHTML = '';
			});
	}

	function tsAction(id, action) {
		NW.api('/projects/' + pid + '/timesheets/' + id + '/' + action, { method: 'POST', json: {} })
			.then(function () { NW.toast('success', t('Timesheet updated.')); reload(); })
			.catch(NW.handleError);
	}

	function openTimesheetDialog() {
		Promise.all([loadAps(), loadMembers()]).then(function (res) {
			var fields = [
				fieldEl(t('Period (YYYY-MM)'), inputEl('period', { type: 'month', required: true })),
				fieldEl(t('Work package'), selectEl('ap_id', apOptions(res[0], t('all')), '')),
			];
			if (caps.can_finance) {
				fields.push(fieldEl(t('For user'), memberSelect('uid', res[1], boot.uid, { blank: false })));
			}
			NW.openDialog({
				title: t('Generate timesheet'),
				body: el('div', {}, fields),
				submitLabel: t('Generate'),
				onSubmit: function (fd) {
					var body = { period: fd.get('period') };
					if (fd.get('ap_id')) { body.ap_id = parseInt(fd.get('ap_id'), 10); }
					var uid = fd.get('uid');
					if (uid) { body.uid = uid; }
					return NW.api('/projects/' + pid + '/timesheets', { method: 'POST', json: body })
						.then(function () { NW.toast('success', t('Timesheet generated.')); reload(); });
				}
			});
		}).catch(NW.handleError);
	}

	function openEntryEdit(entry, aps) {
		// The update endpoint accepts entry_date/minutes/note only —
		// rebooking to another AP means delete + re-book, so no AP field.
		NW.openDialog({
			title: t('Edit time entry'),
			body: el('div', {}, [
				fieldEl(t('Date'), inputEl('entry_date', { type: 'date', value: entry.entry_date, required: true })),
				fieldEl(t('Minutes'), inputEl('minutes', { type: 'number', min: '1', step: '1', value: entry.minutes, required: true })),
				fieldEl(t('Short note'), inputEl('note', { value: entry.note || '' }))
			]),
			submitLabel: t('Save'),
			onSubmit: function (fd) {
				return NW.api('/projects/' + pid + '/time-entries/' + entry.id, { method: 'PUT', json: {
					entry_date: fd.get('entry_date'),
					minutes: parseInt(fd.get('minutes'), 10),
					note: fd.get('note') || ''
				}}).then(function () {
					NW.toast('success', t('Entry saved.'));
					reload();
				});
			}
		});
	}

	/* ---------- receipts ---------- */

	function renderReceipts() {
		NW.pageActions([]);
		host.innerHTML = '';
		var actions = [];
		if (caps.can_book) {
			actions.push({ label: t('File receipt'), primary: true, onClick: function () { openReceiptDialog(); } });
			if (role.isPro) {
				actions.push({ label: t('Parse e-invoice'), onClick: function () { openEinvoiceDialog(); } });
			}
		}
		NW.pageActions(actions);
		host.appendChild(loading());
		NW.api('/projects/' + pid + '/receipts').then(function (receipts) {
			host.innerHTML = '';
			// Recipe B quick pills — the list gets filterable the moment it
			// can exceed a screen (vendor text + flag semantics).
			var state = { flag: 'all', q: '' };
			var pillBar = el('div', { class: 'nw-quick-filters' });
			pillBar.appendChild(el('span', { class: 'nw-quick-filters__label', text: t('Show') + ':' }));
			var pills = el('div', { class: 'nw-filter-bar', role: 'group', 'aria-label': t('Filter receipts') });
			pillBar.appendChild(pills);
			var qIn = inputEl('rq', { type: 'search', placeholder: t('Vendor or invoice no…') });
			qIn.setAttribute('aria-label', t('Filter receipts'));
			pillBar.appendChild(el('div', { class: 'nw-quick-filters__search' }, [qIn]));
			var listCard = el('div');
			var count = el('span', { class: 'nw-list-head__count' });
			host.appendChild(pillBar);
			host.appendChild(listCard);

			var FLAG_PILLS = [
				['all', t('All')], ['flagged', t('Needs attention')],
				['duplicate', t('Duplicates')], ['no_attachment', t('No attachment')], ['salary', t('Salary')]
			];

			function matches(r) {
				if (state.q !== '') {
					var hay = ((r.vendor || '') + ' ' + (r.invoice_no || '')).toLowerCase();
					if (hay.indexOf(state.q.toLowerCase()) === -1) { return false; }
				}
				switch (state.flag) {
					case 'flagged': return !!r.dup_flag || !r.file_id;
					case 'duplicate': return !!r.dup_flag;
					case 'no_attachment': return !r.file_id;
					case 'salary': return r.kind === 'salary';
					default: return true;
				}
			}

			function paint() {
				pills.innerHTML = '';
				FLAG_PILLS.forEach(function (f) {
					var b = el('button', {
						type: 'button', class: 'nw-filter',
						'aria-pressed': state.flag === f[0] ? 'true' : 'false', text: f[1]
					});
					b.addEventListener('click', function () { state.flag = f[0]; paint(); });
					pills.appendChild(b);
				});
				var shown = (receipts || []).filter(matches);
				listCard.innerHTML = '';
				listCard.appendChild(card(t('Receipts'), { children: [
					el('div', { class: 'nw-list-head' }, [count]),
					tableEl([t('Vendor'), t('Invoice no.'), t('Invoice date'), t('Payment date'), t('Total'), t('Kind'), t('Flags'), ''],
					shown.map(function (r) {
						var flags = el('span', { class: 'nw-badge-row' }, [stateBadge(r.state, t(r.state))]);
						if (r.dup_flag) { flags.appendChild(NW.badge('warn', t('duplicate'))); }
						if (!r.file_id) { flags.appendChild(NW.badge('danger', t('no attachment'))); }
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_finance) {
							act.appendChild(smallBtn(t('Edit'), function () { openReceiptDialog(r); }));
							act.appendChild(smallBtn(t('Splits'), function () { openSplitsDialog(r); }));
							if (r.dup_flag) {
								act.appendChild(smallBtn(t('Dismiss flag'), function () {
									NW.api('/projects/' + pid + '/receipts/' + r.id, {
										method: 'PUT', json: { dup_ack: '1' }
									}).then(function () {
										NW.toast('success', t('Duplicate flag dismissed.'));
										reload();
									}).catch(NW.handleError);
								}));
							}
							act.appendChild(smallBtn(t('Delete'), function () {
								NW.confirmDialog(t('Delete receipt'), t('Delete this receipt? The fiscal record keeps an audit trail.'))
									.then(function (ok) {
										if (!ok) { return; }
										NW.api('/projects/' + pid + '/receipts/' + r.id, { method: 'DELETE' })
											.then(reload).catch(NW.handleError);
									});
							}));
						}
						return [
							r.vendor || '—',
							r.invoice_no || NW.badge('warn', t('missing')),
							NW.fmtDate(r.invoice_date),
							r.payment_date ? NW.fmtDate(r.payment_date) : NW.badge('warn', t('missing')),
							fmtMoney(r.total_minor),
							stateBadge(r.kind, t(r.kind === 'salary' ? 'salary' : 'receipt')),
							flags,
							act
						];
					}), { empty: (receipts || []).length ? t('No receipts match the filter.') : t('No receipts filed yet.') })
			]}));
			count.textContent = (receipts || []).length === shown.length
				? t('%s receipts', [String(shown.length)])
				: t('%s of %s receipts', [String(shown.length), String((receipts || []).length)]);
			}
			qIn.addEventListener('input', NW.debounce(function () {
				state.q = qIn.value.trim(); paint();
			}, 200));
			paint();
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function openEinvoiceDialog() {
		var fileIn = inputEl('file_id', { inputmode: 'numeric', required: true });
		var pickBtn = null;
		if (window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
			pickBtn = el('button', { type: 'button', class: 'nw-btn nw-btn--secondary', text: t('Choose from Files…') });
			pickBtn.addEventListener('click', function () {
				OC.dialogs.filepicker(t('Pick the e-invoice'), function (path) {
					if (!path) { return; }
					NW.api('/projects/' + pid + '/files/resolve?path=' + encodeURIComponent(path))
						.then(function (res) { fileIn.value = String(res.file_id); })
						.catch(function () { NW.toast('warn', t('Could not resolve that file — enter the file id manually.')); });
				}, false, null, true);
			});
		}
		var body = el('div', {}, [
			fieldEl(t('Invoice file'), { node: el('div', { class: 'nw-file-row' },
				[fileIn, pickBtn].filter(Boolean)), focusEl: fileIn }, {
				hint: t('Pick the ZUGFeRD/XRechnung file — or paste its file id.')
			})
		]);
		NW.openDialog({
			title: t('Parse e-invoice'),
			body: body,
			submitLabel: t('Parse'),
			onSubmit: function (fd) {
				return NW.api('/projects/' + pid + '/receipts/parse-einvoice', {
					method: 'POST', json: { file_id: parseInt(fd.get('file_id'), 10) || 0 }
				}).then(function (res) {
					if (res && res.draft) {
						NW.toast('success', t('Draft parsed — review and file it.'));
						openReceiptDialog(res.draft);
						return;
					}
					NW.toast('warn', t('Nothing readable in that file — file the receipt manually.'));
					openReceiptDialog();
				});
			}
		});
	}

	/* ---------- work reports ---------- */

	function renderReports() {
		NW.pageActions([]);
		host.innerHTML = '';
		var actions = [];
		if (caps.can_book) {
			actions.push({ label: t('New work report'), primary: true, onClick: function () { openReportDialog(); } });
		}
		NW.pageActions(actions);
		host.appendChild(loading());
		loadAps().then(function (aps) {
			if (!(aps || []).length) {
				host.innerHTML = '';
				host.appendChild(emptyState(t('No work packages'),
				t('Work reports live inside work packages — create one first.'),
				el('a', {
					class: 'nw-btn nw-btn--primary',
					href: location.pathname + '?project=' + pid + '&view=workpackages',
					text: t('Open work packages')
				})));
				return;
			}
			return Promise.all(aps.map(function (ap) {
				return NW.api('/projects/' + pid + '/workpackages/' + ap.id + '/reports')
					.then(function (reports) { return { ap: ap, reports: reports || [] }; });
			}));
		}).then(function (blocks) {
			if (!blocks) { return; }
			host.innerHTML = '';
			blocks.forEach(function (b) {
				host.appendChild(card(b.ap.code + ' — ' + b.ap.title, { children: [
					tableEl([t('Period'), t('State'), t('Author'), t('Summary'), ''],
						b.reports.map(function (r) {
							var act = el('span', { class: 'nw-table-actions' });
							if (caps.can_book && r.state === 'draft') {
								act.appendChild(smallBtn(t('Submit'), function () { reportState(r, 'submitted'); }));
							}
							if (caps.can_finance && r.state === 'submitted') {
								act.appendChild(smallBtn(t('Accept'), function () { reportState(r, 'accepted'); }));
								act.appendChild(smallBtn(t('Back to draft'), function () { reportState(r, 'draft'); }));
							}
							return [
								fmtMonth(r.period), stateBadge(r.state, t(r.state)),
								r.author_uid || '—', r.summary || '—', act
							];
						}), { empty: t('No reports for this work package yet.') })
				]}));
			});
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function reportState(r, target) {
		NW.api('/projects/' + pid + '/reports/' + r.id + '/state/' + target, { method: 'POST', json: {} })
			.then(function () { NW.toast('success', t('Report updated.')); reload(); })
			.catch(NW.handleError);
	}

	function openReportDialog() {
		loadAps().then(function (aps) {
			var open = (aps || []).filter(function (a) { return a.state !== 'closed'; });
			if (!open.length) {
				NW.toast('warn', t('Create a work package first.'));
				return;
			}
			NW.openDialog({
				title: t('New work report'),
				body: el('div', {}, [
					fieldEl(t('Work package'), selectEl('ap_id', apOptions(open), null, { required: true })),
					fieldEl(t('Period (YYYY-MM)'), inputEl('period', { type: 'month', required: true })),
					fieldEl(t('Summary'), textareaEl('summary', { required: true })),
					fieldEl(t('Outcome'), textareaEl('outcome'))
				]),
				submitLabel: t('Create'),
				onSubmit: function (fd) {
					return NW.api('/projects/' + pid + '/reports', { method: 'POST', json: {
						ap_id: parseInt(fd.get('ap_id'), 10),
						period: fd.get('period'),
						summary: fd.get('summary') || '',
						outcome: fd.get('outcome') || ''
					}}).then(function () {
						NW.toast('success', t('Report created.'));
						reload();
					});
				}
			});
		}).catch(NW.handleError);
	}

	/* ---------- deviations ---------- */

	function renderDeviations() {
		NW.pageActions([]);
		host.innerHTML = '';
		if (caps.can_book) {
			NW.pageActions([{ label: t('Record deviation'), primary: true, onClick: function () { openDeviationDialog(); } }]);
		}
		host.appendChild(loading());
		Promise.all([
			NW.api('/projects/' + pid + '/deviations'),
			loadActivePositions(), loadAps()
		]).then(function (res) {
			var deviations = res[0] || [], posMap = indexById(res[1]), apMap = indexById(res[2]);
			host.innerHTML = '';
			host.appendChild(card(t('Deviations'), { children: [
				tableEl([t('Date'), t('Scope'), t('Reference'), t('Kind'), t('Justification'), t('By')],
					deviations.map(function (d) {
						var ref = d.scope === 'ap'
							? (apMap[d.ref_id] ? apMap[d.ref_id].code : '#' + d.ref_id)
							: (posMap[d.ref_id] ? posMap[d.ref_id].code : '#' + d.ref_id);
						return [
							NW.fmtDateTime(d.created_at),
							t(d.scope === 'ap' ? 'Work package' : 'Budget position'),
							ref,
							t(DEVIATION_KINDS[d.kind] || d.kind),
							d.reason_text || '—',
							d.uid || '—'
						];
					}), { empty: t('No deviations recorded.') })
			]}));
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	/* ---------- nachweis (usage reports) ---------- */

	var NACHWEIS_SECTIONS = [
		['01_ausgaben.csv', 'Expenditure (CSV)'],
		['02_einnahmen.csv', 'Income (CSV)'],
		['03_belegliste.csv', 'Receipt list (CSV)'],
		['04_fehlende_angaben.csv', 'Missing data (CSV)'],
		['05_sachbericht.txt', 'Narrative report (TXT)'],
		['10_nachweis.ods', 'Report (ODS)'],
		['11_nachweis.pdf', 'Report (PDF)'],
		['manifest.json', 'Manifest (JSON)'],
		['zip', 'Full package (ZIP)']
	];

	function renderNachweis() {
		NW.pageActions([]);
		host.innerHTML = '';
		if (caps.can_finance) {
			NW.pageActions([{ label: t('Generate usage report'), primary: true, onClick: function () { openNachweisDialog(); } }]);
		}
		host.appendChild(loading());
		Promise.all([
			NW.api('/projects/' + pid + '/nachweise'),
			NW.api('/projects/' + pid + '/readiness').catch(function () { return null; })
		]).then(function (res) {
			var list = res[0] || [], readiness = res[1];
			host.innerHTML = '';
			var detail = el('div', { id: 'nw-nachweis-detail' });

			// Prüfungsbereit checklist (NH-04): every audit gap as a visible
			// task before the export runs — never a dead-end report.
			if (readiness) {
				var sevBadge = { blocker: 'danger', warning: 'warn', info: 'neutral' };
				var sevLabel = { blocker: 'Blocker', warning: 'Warning', info: 'Info' };
				var items = [];
				(readiness.checks || []).forEach(function (c) {
					var li = el('li', { class: 'nw-readiness-item' }, [
						stateBadge(sevBadge[c.severity] || 'neutral', t(sevLabel[c.severity] || c.severity)),
						el('span', { text: ' ' + readinessCheckLabel(c.id) + ' (' + c.count + ')' })
					]);
					var sub = el('ul', { class: 'nw-readiness-sub' });
					(c.items || []).slice(0, 5).forEach(function (it) {
						sub.appendChild(el('li', { text: it }));
					});
					if ((c.items || []).length > 5) {
						sub.appendChild(el('li', { text: '… +' + (c.items.length - 5) }));
					}
					li.appendChild(sub);
					items.push(li);
				});
				var readyNote = readiness.ready
					? el('p', { class: 'nw-field__hint', text: t('No blockers — the export can run. Warnings are listed in the report.') })
					: el('p', { class: 'nw-field-error', text: t('Blockers found — resolve them before generating the report.') });
				host.appendChild(card(t('Audit readiness'), { children: [
					readyNote,
					items.length ? el('ul', { class: 'nw-readiness-list' }, items)
						: el('p', { class: 'nw-field__hint', text: t('Everything looks audit-ready.') })
				] }));
			}

			// Export row: package downloads + DATEV + Antrag (NH-02/NH-07).
			var exportRow = el('div', { class: 'nw-btn-row' });
			if (caps.can_finance) {
				exportRow.appendChild(el('a', {
					class: 'nw-btn nw-btn--secondary',
					href: NW.appBase() + (boot.apiBase || '/api/v1') + '/projects/' + pid + '/export/datev',
					text: t('DATEV export')
				}));
				exportRow.appendChild(el('a', {
					class: 'nw-btn nw-btn--secondary',
					href: NW.appBase() + (boot.apiBase || '/api/v1') + '/projects/' + pid + '/antrag?format=pdf',
					text: t('Application (PDF)')
				}));
			}
			host.appendChild(card(t('Exports'), { children: [exportRow] }));

			host.appendChild(card(t('Usage reports'), { children: [
				tableEl([t('Version'), t('Kind'), t('Period'), t('State'), t('Hash'), t('Generated'), ''],
					(list || []).map(function (n) {
						var act = el('span', { class: 'nw-table-actions' });
						act.appendChild(smallBtn(t('Open'), function () { openNachweisDetail(n.id, detail); }));
						return [
							'v' + n.version + (n.supersedes_id ? ' ↺' : ''),
							t(NACHWEIS_KINDS[n.kind] || n.kind),
							NW.fmtDate(n.period_from) + ' – ' + NW.fmtDate(n.period_to),
							stateBadge(n.state, t(n.state)),
							el('code', { class: 'nw-mono', title: n.manifest_hash || '', text: hashShort(n.manifest_hash) }),
							NW.fmtDateTime(n.generated_at),
							act
						];
					}), { empty: t('No usage reports generated yet.') })
			]}));
			host.appendChild(detail);
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function downloadUrl(nid, section) {
		return NW.appBase() + (boot.apiBase || '/api/v1')
			+ '/projects/' + pid + '/nachweise/' + nid + '/download/' + section;
	}

	function gapTypeLabel(type) {
		var map = {
			missing_attachment: 'Missing attachment',
			missing_invoice_no: 'Missing invoice number',
			missing_payment_date: 'Missing payment date',
			unpriced_time: 'Unpriced time entry'
		};
		return t(map[type] || type);
	}

	function renderNachweisPayload(payload, integrity, nid) {
		var wrap = el('div');
		var meta = (payload && payload.meta) || {};
		var gaps = (payload && payload.fehlende_angaben) || [];

		var statStrip = el('div', { class: 'nw-stat-strip' }, [
			['Receipts', (payload.belegliste || []).length],
			['Expenditure positions', (payload.ausgaben || []).length],
			['Income rows', (payload.einnahmen || []).length],
			['Narrative sections', (payload.sachbericht || []).length],
			['Missing data', gaps.length]
		].map(function (s) {
			return el('div', { class: 'nw-stat-tile' + (s[0] === 'Missing data' && s[1] > 0 ? ' nw-stat-tile--danger' : '') }, [
				el('span', { class: 'nw-stat-tile__label', text: t(s[0]) }),
				el('span', { class: 'nw-stat-tile__value', text: String(s[1]) })
			]);
		}));
		wrap.appendChild(statStrip);

		// Fehlende Angaben — always visible, never only in the CSV.
		var gapChildren;
		if (gaps.length) {
			gapChildren = [el('div', { class: 'nw-callout nw-callout--warning', role: 'alert' }, [
				el('p', { class: 'nw-callout__title', text: t('%s gaps must be closed before submission', [String(gaps.length)]) }),
				el('p', { class: 'nw-callout__text', text: t('The funder form lists every missing item — fix them in the receipts and time entries, then regenerate.') })
			]), tableEl([t('Type'), t('Record'), t('Detail')],
				gaps.map(function (g) {
					return [gapTypeLabel(g.type), g.entity + ' #' + g.entity_id, g.detail || '—'];
				}))];
		} else {
			gapChildren = [el('div', { class: 'nw-callout nw-callout--success', role: 'status' }, [
				el('p', { class: 'nw-callout__title', text: t('Complete — no missing data') }),
				el('p', { class: 'nw-callout__text', text: t('Every receipt and time entry in the period is complete.') })
			])];
		}
		wrap.appendChild(card(t('Missing data (Fehlende Angaben)'), { children: gapChildren }));

		wrap.appendChild(card(t('Expenditure by position'), { children: [
			tableEl([t('Code'), t('Title'), t('Kind'), t('Budget'), t('Used')],
				(payload.ausgaben || []).map(function (a) {
					return [a.code, a.title, posKindLabel(a.kind), fmtMoney(a.budget_minor), fmtMoney(a.used_minor)];
				}), { empty: t('No expenditure rows.') })
		]}));

		wrap.appendChild(card(t('Income'), { children: [
			tableEl([t('Date'), t('Amount'), t('Reference'), t('Kind')],
				(payload.einnahmen || []).map(function (e) {
					return [NW.fmtDate(e.received_date), fmtMoney(e.amount_minor), e.call_ref || '—',
						stateBadge(e.kind, t(e.kind === 'mitteluebertrag' ? 'Carryover' : 'Grant payment'))];
				}), { empty: t('No income rows.') })
		]}));

		wrap.appendChild(card(t('Receipt list'), { children: [
			tableEl([t('No.'), t('Vendor'), t('Invoice date'), t('Amount'), t('Attachment')],
				(payload.belegliste || []).map(function (b) {
					return [
						b.invoice_no || '—', b.vendor || '—', NW.fmtDate(b.invoice_date),
						fmtMoney(b.amount_minor),
						b.has_attachment ? NW.badge('success', t('yes')) : NW.badge('danger', t('missing'))
					];
				}), { empty: t('No receipts in this period.') })
		]}));

		wrap.appendChild(card(t('Narrative report'), { children:
			(payload.sachbericht || []).map(function (s) {
				return el('div', {}, [
					el('h3', { text: s.ap_code + ' — ' + s.ap_title }),
					(s.reports || []).length
						? el('ul', { class: 'nw-list-cards' }, s.reports.map(function (r) {
							return el('li', {}, [
								el('span', { text: r.period + ' · ' + (r.summary || '') }),
								stateBadge(r.state, t(r.state))
							]);
						}))
						: el('p', { class: 'nw-muted', text: t('No reports in this period.') })
				]);
			})
		}));

		if (nid) {
			var dl = el('ul', { class: 'nw-downloads' });
			NACHWEIS_SECTIONS.forEach(function (s) {
				dl.appendChild(el('li', {}, [
					el('a', {
						class: 'nw-btn nw-btn--secondary nw-btn--small',
						href: downloadUrl(nid, s[0]), text: t(s[1])
					})
				]));
			});
			wrap.appendChild(card(t('Downloads'), { children: [dl] }));
		}
		if (integrity === true) {
			wrap.appendChild(el('p', {}, [NW.badge('success', t('Integrity verified'))]));
		} else if (integrity === false) {
			wrap.appendChild(el('p', {}, [NW.badge('danger', t('Integrity check FAILED — do not submit'))]));
		}
		return wrap;
	}

	// Generation guard: every report row's Open button re-paints the same
	// detail region — a stale fetch must not render report A's payload
	// after the user opened report B.
	var nachweisDetailSeq = 0;
	function openNachweisDetail(nid, region) {
		var seq = ++nachweisDetailSeq;
		region.innerHTML = '';
		region.appendChild(loading());
		NW.api('/projects/' + pid + '/nachweise/' + nid).then(function (n) {
			if (seq !== nachweisDetailSeq) { return; }
			region.innerHTML = '';
			var head = el('div', { class: 'nw-list-head' }, [
				el('h2', { class: 'nw-list-head__title', text: t('Usage report v%s', [String(n.version)]) }),
				stateBadge(n.state, t(n.state))
			]);
			region.appendChild(head);
			region.appendChild(el('div', { class: 'nw-preview__meta' }, [
				el('span', { text: (n.manifest_hash || '') }),
			]));
			region.appendChild(renderNachweisPayload(n.payload || {}, n.integrity, nid));
			region.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}).catch(function (e) {
			if (seq !== nachweisDetailSeq) { return; }
			region.innerHTML = '';
			NW.handleError(e);
		});
	}

	function openNachweisDialog() {
		loadProject().then(function (p) {
			var previewRegion = el('div');
			var body = el('div', {}, [
				fieldEl(t('Kind'), selectEl('kind', [
					['final', t('Final report')],
					['zwischen', t('Interim report')]
				], 'final')),
				fieldEl(t('Period from'), inputEl('period_from', { type: 'date', required: true, value: p.bew_start || '' })),
				fieldEl(t('Period to'), inputEl('period_to', { type: 'date', required: true, value: p.bew_end || '' })),
				moreDetails(t('Correct an earlier report'), [
					fieldEl(t('Supersedes report'), inputEl('supersedes_id', { inputmode: 'numeric' }), {
						hint: t('Only for corrections — the id of the report this version replaces.')
					}),
					fieldEl(t('Reason for new version'), inputEl('reason'))
				]),
				el('div', { class: 'nw-btn-row' }, []),
				previewRegion
			]);
			NW.openDialog({
				title: t('Generate usage report'),
				body: body,
				submitLabel: t('Generate'),
				wide: true,
				onOpen: function (api2) {
					var btnRow = body.querySelector('.nw-btn-row');
					// Generation guard: re-clicking preview after editing the
					// period must not let the stale preview paint last.
					var previewSeq = 0;
					var pv = btn(t('Check preview'), function () {
						var seq = ++previewSeq;
						var form = api2.dialog.querySelector('form');
						var fd = new FormData(form);
						previewRegion.innerHTML = '';
						previewRegion.appendChild(loading());
						NW.api('/projects/' + pid + '/nachweise/preview', { method: 'POST', json: {
							kind: fd.get('kind'),
							period_from: fd.get('period_from'),
							period_to: fd.get('period_to')
						}}).then(function (payload) {
							if (seq !== previewSeq) { return; }
							previewRegion.innerHTML = '';
							previewRegion.appendChild(renderNachweisPayload(payload, null, null));
						}).catch(function (e) {
							if (seq !== previewSeq) { return; }
							previewRegion.innerHTML = '';
							NW.handleError(e);
						});
					});
					btnRow.appendChild(pv);
				},
				onSubmit: function (fd) {
					var json = {
						kind: fd.get('kind'),
						period_from: fd.get('period_from'),
						period_to: fd.get('period_to')
					};
					if (String(fd.get('supersedes_id') || '').trim() !== '') {
						json.supersedes_id = parseInt(fd.get('supersedes_id'), 10);
						json.reason = fd.get('reason') || '';
					}
					return NW.api('/projects/' + pid + '/nachweise', { method: 'POST', json: json })
						.then(function () {
							NW.toast('success', t('Usage report generated.'));
							reload();
						});
				}
			});
		}).catch(NW.handleError);
	}

	/* ---------- deadlines ---------- */

	function renderDeadlines() {
		NW.pageActions([]);
		host.innerHTML = '';
		host.appendChild(loading());
		loadProject().then(function (p) {
			host.innerHTML = '';
			host.appendChild(card(t('Deadlines'), { children: [
				renderDeadlineList(p.deadlines || [])
			]}));
			if (caps.can_admin) {
				var feedRegion = el('div', {}, [loading()]);
				host.appendChild(card(t('Calendar feed (ICS)'), { children: [feedRegion] }));
				NW.api('/projects/' + pid + '/ics-feed').then(function (feed) {
					feedRegion.innerHTML = '';
					var urlIn = inputEl('ics_url', { value: feed.url });
					urlIn.readOnly = true;
					urlIn.id = 'nw-ics-url';
					feedRegion.appendChild(fieldEl(t('Feed URL'), urlIn, {
						hint: t('Subscribe in any calendar client — deadlines only, token-bound.')
					}));
					feedRegion.appendChild(el('div', { class: 'nw-btn-row' }, [
						btn(t('Copy URL'), function () {
							urlIn.select();
							var done = function () { NW.toast('success', t('Copied.')); };
							if (navigator.clipboard && navigator.clipboard.writeText) {
								navigator.clipboard.writeText(feed.url).then(done, function () { document.execCommand('copy'); done(); });
							} else {
								document.execCommand('copy'); done();
							}
						}),
						btn(t('Rotate token'), function () {
							NW.confirmDialog(t('Rotate feed token'), t('Rotate the feed token? Every existing subscription loses access immediately.'))
								.then(function (ok) {
									if (!ok) { return; }
									NW.api('/projects/' + pid + '/ics-feed/rotate', { method: 'POST', json: {} })
										.then(function () { NW.toast('success', t('Token rotated.')); reload(); })
										.catch(NW.handleError);
								});
						}, 'nw-btn--secondary')
					]));
				}).catch(function () {
					feedRegion.innerHTML = '';
					feedRegion.appendChild(el('p', { class: 'nw-muted', text: t('Feed unavailable.') }));
				});
			}
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	/* ---------- finance ---------- */

	function renderFinance() {
		NW.pageActions([]);
		host.innerHTML = '';
		host.appendChild(loading());
		Promise.all([
			NW.api('/projects/' + pid + '/payments'),
			NW.api('/projects/' + pid + '/reallocations'),
			NW.api('/projects/' + pid + '/carryovers'),
			loadActivePositions(), loadMembers(),
			NW.api('/projects/' + pid + '/drawdowns'),
			NW.api('/projects/' + pid + '/forecast').catch(function () { return null; }),
			NW.api('/projects/' + pid + '/claims'),
			NW.api('/projects/' + pid + '/cofunders')
		]).then(function (res) {
			var payments = res[0] || [], reallocs = res[1] || [], carryovers = res[2] || [];
			var positions = res[3] || [], members = res[4] || [];
			var drawdowns = res[5] || [], forecast = res[6], claims = res[7] || [], cofunders = res[8] || [];
			var posMap = indexById(positions);
			host.innerHTML = '';

			// Payments
			var payChildren = [
				tableEl([t('Date'), t('Amount'), t('Call reference'), t('Year'), t('Note'), ''],
					payments.map(function (pay) {
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_finance) {
							act.appendChild(smallBtn(t('Delete'), function () {
								NW.confirmDialog(t('Delete payment'), t('Delete this incoming payment?'))
									.then(function (ok) {
										if (!ok) { return; }
										NW.api('/projects/' + pid + '/payments/' + pay.id, { method: 'DELETE' })
											.then(reload).catch(NW.handleError);
									});
							}));
						}
						return [NW.fmtDate(pay.received_date), fmtMoney(pay.amount_minor),
							pay.call_ref || '—', String(pay.year_received || '—'), pay.note || '—', act];
					}), { empty: t('No incoming payments recorded.') })
			];
			if (caps.can_finance) {
				payChildren.unshift(el('div', { class: 'nw-btn-row' }, [
					btn(t('Record payment'), function () { openPaymentDialog(); }, 'nw-btn--primary')
				]));
			}
			host.appendChild(card(t('Incoming payments'), { children: payChildren }));

			// Rates (can_see_rates)
			if (caps.can_see_rates) {
				var rateRegion = el('div');
				var selWrap = el('div', {}, [
					fieldEl(t('Member'), memberSelect('rate_member', members, null, { blank: t('— choose —') }))
				]);
				var rateSel = selWrap.querySelector('select');
				rateSel.classList.add('nw-select--inline');
				rateSel.addEventListener('change', function () { paintRates(rateSel.value); });
				var rateChildren = [selWrap, rateRegion];
				if (caps.can_finance) {
					rateChildren.push(el('div', { class: 'nw-btn-row' }, [
						btn(t('Add rate'), function () { openRateDialog(members); })
					]));
				}
				// Generation guard: the member select is retriggerable while
				// a rates fetch is in flight — stale rates of member A must
				// not paint under member B's selection.
				var rateSeq = 0;
				function paintRates(uid) {
					var seq = ++rateSeq;
					rateRegion.innerHTML = '';
					if (!uid) { return; }
					rateRegion.appendChild(loading());
					NW.api('/projects/' + pid + '/rates/' + encodeURIComponent(uid)).then(function (rates) {
						if (seq !== rateSeq) { return; }
						rateRegion.innerHTML = '';
						rateRegion.appendChild(tableEl([t('Role key'), t('Rate/hour'), t('Valid from')],
							(rates || []).map(function (r) {
								return [r.role_key || '—', fmtMoney(r.rate_minor), NW.fmtDate(r.valid_from)];
							}), { empty: t('No rates for this member yet.') }));
					}).catch(function () {
						if (seq !== rateSeq) { return; }
						rateRegion.innerHTML = '';
					});
				}
				host.appendChild(card(t('Hourly rates'), { children: rateChildren }));
			}

			// Reallocations
			var reaChildren = [
				tableEl([t('From'), t('To'), t('Amount'), t('Reason'), t('State'), ''],
					reallocs.map(function (r) {
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_admin && r.state === 'pending') {
							act.appendChild(smallBtn(t('Approve'), function () { reallocDecide(r.id, 'approve'); }));
							act.appendChild(smallBtn(t('Reject'), function () { reallocDecide(r.id, 'reject'); }));
						}
						return [
							posMap[r.from_position_id] ? posMap[r.from_position_id].code : '#' + r.from_position_id,
							posMap[r.to_position_id] ? posMap[r.to_position_id].code : '#' + r.to_position_id,
							fmtMoney(r.amount_minor),
							r.reason_text || '—',
							stateBadge(r.state, t(r.state)),
							act
						];
					}), { empty: t('No reallocations requested.') })
			];
			if (caps.can_finance) {
				reaChildren.unshift(el('div', { class: 'nw-btn-row' }, [
					btn(t('Request reallocation'), function () { openReallocDialog(positions); })
				]));
			}
			host.appendChild(card(t('Reallocations'), { children: reaChildren }));

			// Carryovers
			var carryChildren = [
				tableEl([t('From year'), t('To year'), t('Amount'), t('Document'), t('Decided by')],
					carryovers.map(function (c) {
						return [String(c.from_year), String(c.to_year), fmtMoney(c.amount_minor),
							c.doc_ref || '—', c.decided_by || '—'];
					}), { empty: t('No carryovers recorded.') })
			];
			if (caps.can_finance) {
				carryChildren.unshift(el('div', { class: 'nw-btn-row' }, [
					btn(t('Record carryover'), function () { openCarryoverDialog(); })
				]));
			}
			host.appendChild(card(t('Carryovers'), { children: carryChildren }));

			// Co-funders (multi-funder co-financing, BQ-03)
			var cofChildren = [
				tableEl([t('Name'), t('Reference'), t('Contact'), t('Note'), ''],
					cofunders.map(function (c) {
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_finance) {
							act.appendChild(smallBtn(t('Delete'), function () {
								NW.confirmDialog(t('Delete co-funder'), t('Delete this co-funder? Fails while plan positions still reference it.'))
									.then(function (ok) {
										if (!ok) { return; }
										NW.api('/projects/' + pid + '/cofunders/' + c.id, { method: 'DELETE' })
											.then(reload).catch(NW.handleError);
									});
							}));
						}
						return [c.name, c.ref || '—', c.contact || '—', c.note || '—', act];
					}), { empty: t('No co-funders. Positions without a co-funder belong to the lead funder.') })
			];
			if (caps.can_finance) {
				cofChildren.unshift(el('div', { class: 'nw-btn-row' }, [
					btn(t('Add co-funder'), function () { openCofunderDialog(); })
				]));
			}
			host.appendChild(card(t('Co-funders'), { children: cofChildren }));

			// Drawdowns + liquidity forecast (NH-01/NH-09)
			var ddChildren = [];
			if (forecast) {
				ddChildren.push(el('dl', { class: 'nw-dl' }, [
					dlEl([[t('Cash'), fmtMoney(forecast.cash_minor)],
						[t('Received'), fmtMoney(forecast.received_minor)],
						[t('Pending drawdowns'), fmtMoney(forecast.pending_drawdowns_minor)],
						[t('Burn rate / month'), forecast.burn_rate_minor === null ? '—' : fmtMoney(forecast.burn_rate_minor)],
						[t('Runway (months)'), forecast.runway_months === null ? '—' : String(forecast.runway_months)],
						[t('Remaining months'), String(forecast.remaining_months)],
						[t('Suggested drawdown'), forecast.suggested_drawdown_minor === null ? '—' : fmtMoney(forecast.suggested_drawdown_minor)]
					])
				]));
			}
			ddChildren.push(
				tableEl([t('Requested'), t('Amount'), t('Call ref'), t('State'), ''],
					drawdowns.map(function (d) {
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_finance && d.state === 'requested') {
							act.appendChild(smallBtn(t('Received'), function () { drawdownDecide(d.id, 'receive', payments); }));
							act.appendChild(smallBtn(t('Reject'), function () { drawdownDecide(d.id, 'reject'); }));
						}
						return [NW.fmtDate(d.requested_date), fmtMoney(d.amount_minor),
							d.call_ref || '—', stateBadge(d.state, t(d.state)), act];
					}), { empty: t('No drawdowns recorded.') })
			);
			if (caps.can_finance) {
				ddChildren.unshift(el('div', { class: 'nw-btn-row' }, [
					btn(t('Record drawdown'), function () { openDrawdownDialog(); }, 'nw-btn--primary')
				]));
			}
			host.appendChild(card(t('Drawdowns & liquidity'), { children: ddChildren }));

			// Claims — interest + recoveries (NH-06, ANBest-P Nr. 8)
			var claimChildren = [
				tableEl([t('Kind'), t('Title'), t('Date'), t('Principal'), t('Rate (bp)'), t('Amount'), t('State'), ''],
					claims.map(function (c) {
						var act = el('span', { class: 'nw-table-actions' });
						if (caps.can_finance && c.state === 'open') {
							act.appendChild(smallBtn(t('Settle'), function () {
								NW.api('/projects/' + pid + '/claims/' + c.id + '/settle', { method: 'POST', json: {} })
									.then(reload).catch(NW.handleError);
							}));
						}
						return [t(c.kind), c.title, NW.fmtDate(c.occurred_date),
							fmtMoney(c.principal_minor), String(c.rate_bp || 0),
							fmtMoney(c.amount_minor), stateBadge(c.state, t(c.state)), act];
					}), { empty: t('No claims or interest recorded.') })
			];
			if (caps.can_finance) {
				claimChildren.unshift(el('div', { class: 'nw-btn-row' }, [
					btn(t('Record claim'), function () { openClaimDialog(); })
				]));
			}
			host.appendChild(card(t('Interest & recoveries'), { children: claimChildren }));
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function drawdownDecide(id, decision, payments) {
		var payload = { decision: decision };
		if (decision === 'receive') {
			var latest = (payments || [])[0];
			NW.openDialog({
				title: t('Mark drawdown received'),
				body: el('div', {}, [
					fieldEl(t('Incoming payment'), selectEl('payment_id',
						(payments || []).map(function (p) {
							return [p.id, NW.fmtDate(p.received_date) + ' — ' + fmtMoney(p.amount_minor) + (p.call_ref ? ' · ' + p.call_ref : '')];
						}), latest ? latest.id : null, { blank: t('— choose —') }))
				]),
				submitLabel: t('Mark received'),
				onSubmit: function (fd) {
					payload.payment_id = parseInt(fd.get('payment_id'), 10) || 0;
					return NW.api('/projects/' + pid + '/drawdowns/' + id + '/decide', { method: 'POST', json: payload })
						.then(reload);
				}
			});
			return;
		}
		NW.api('/projects/' + pid + '/drawdowns/' + id + '/decide', { method: 'POST', json: payload })
			.then(reload).catch(NW.handleError);
	}

	function openDrawdownDialog() {
		NW.openDialog({
			title: t('Record drawdown'),
			body: el('div', {}, [
				fieldEl(t('Requested date'), inputEl('requested_date', { type: 'date', required: true, value: NW.todayYmd() })),
				fieldEl(t('Amount (EUR)'), moneyEl('amount_minor')),
				fieldEl(t('Call reference'), inputEl('call_ref')),
				fieldEl(t('Note'), inputEl('note')),
				fieldEl(t('External reference'), inputEl('external_ref'))
			]),
			submitLabel: t('Record'),
			onSubmit: function (fd) {
				var amount = parseMoney(fd.get('amount_minor'));
				if (amount === null) {
					return Promise.reject({ fields: { amount_minor: t('Invalid amount.') }, message: t('Invalid amount.') });
				}
				return NW.api('/projects/' + pid + '/drawdowns', { method: 'POST', json: {
					requested_date: fd.get('requested_date'), amount_minor: amount,
					call_ref: fd.get('call_ref') || '', note: fd.get('note') || '',
					external_ref: fd.get('external_ref') || ''
				}}).then(function () { NW.toast('success', t('Drawdown recorded.')); reload(); });
			}
		});
	}

	function openCofunderDialog() {
		NW.openDialog({
			title: t('Add co-funder'),
			body: el('div', {}, [
				fieldEl(t('Name'), inputEl('name', { required: true })),
				fieldEl(t('Reference'), inputEl('ref')),
				fieldEl(t('Contact'), inputEl('contact')),
				fieldEl(t('Note'), inputEl('note'))
			]),
			submitLabel: t('Add'),
			onSubmit: function (fd) {
				return NW.api('/projects/' + pid + '/cofunders', { method: 'POST', json: {
					name: fd.get('name') || '', ref: fd.get('ref') || '',
					contact: fd.get('contact') || '', note: fd.get('note') || ''
				}}).then(function () { NW.toast('success', t('Co-funder added.')); reload(); });
			}
		});
	}

	function openClaimDialog() {
		// Only the fields the chosen kind needs stay visible — no
		// "recovery only"/"interest only" hints to mentally parse.
		var kindSel = selectEl('kind', [['recovery', t('Recovery')], ['interest', t('Interest on unused funds')]], 'recovery');
		var amountF = fieldEl(t('Amount (EUR)'), moneyEl('amount_minor'));
		var principalF = fieldEl(t('Principal (EUR)'), moneyEl('principal_minor'));
		var rateF = fieldEl(t('Rate (basis points)'), inputEl('rate_bp', { type: 'number', min: '0' }));
		var periodF = el('div', { class: 'nw-field-pair' }, [
			fieldEl(t('Period from'), inputEl('period_from', { type: 'date' })),
			fieldEl(t('Period to'), inputEl('period_to', { type: 'date' }))
		]);
		function paintKind() {
			var interest = kindSel.value === 'interest';
			amountF.hidden = interest;
			principalF.hidden = !interest;
			rateF.hidden = !interest;
			periodF.hidden = !interest;
		}
		kindSel.addEventListener('change', paintKind);
		paintKind();
		var body = el('div', {}, [
			fieldEl(t('Kind'), kindSel),
			fieldEl(t('Title'), inputEl('title', { required: true })),
			fieldEl(t('Date'), inputEl('occurred_date', { type: 'date', required: true, value: NW.todayYmd() })),
			amountF, principalF, rateF, periodF,
			fieldEl(t('Note'), inputEl('note'))
		]);
		NW.openDialog({
			title: t('Record claim'), body: body, submitLabel: t('Record'),
			onSubmit: function (fd) {
				var json = {
					kind: fd.get('kind'), title: fd.get('title') || '',
					occurred_date: fd.get('occurred_date'), note: fd.get('note') || ''
				};
				if (fd.get('kind') === 'recovery') {
					json.amount_minor = parseMoney(fd.get('amount_minor')) || 0;
				} else {
					json.principal_minor = parseMoney(fd.get('principal_minor')) || 0;
					json.rate_bp = parseInt(fd.get('rate_bp'), 10) || 0;
					json.period_from = fd.get('period_from') || '';
					json.period_to = fd.get('period_to') || '';
				}
				return NW.api('/projects/' + pid + '/claims', { method: 'POST', json: json })
					.then(function () { NW.toast('success', t('Claim recorded.')); reload(); });
			}
		});
	}

	function openPaymentDialog() {
		NW.openDialog({
			title: t('Record payment'),
			body: el('div', {}, [
				fieldEl(t('Received date'), inputEl('received_date', { type: 'date', required: true, value: NW.todayYmd() })),
				fieldEl(t('Amount (EUR)'), moneyEl('amount_minor')),
				fieldEl(t('Call reference'), inputEl('call_ref')),
				fieldEl(t('Note'), inputEl('note')),
				fieldEl(t('External reference'), inputEl('external_ref'))
			]),
			submitLabel: t('Record'),
			onSubmit: function (fd) {
				var amount = parseMoney(fd.get('amount_minor'));
				if (amount === null) {
					return Promise.reject({ fields: { amount_minor: t('Invalid amount.') }, message: t('Invalid amount.') });
				}
				return NW.api('/projects/' + pid + '/payments', { method: 'POST', json: {
					received_date: fd.get('received_date'),
					amount_minor: amount,
					call_ref: fd.get('call_ref') || '',
					note: fd.get('note') || '',
					external_ref: fd.get('external_ref') || ''
				}}).then(function () {
					NW.toast('success', t('Payment recorded.'));
					reload();
				});
			}
		});
	}

	function openRateDialog(members) {
		NW.openDialog({
			title: t('Add rate'),
			body: el('div', {}, [
				fieldEl(t('Member'), memberSelect('uid', members, null, { blank: t('— choose —'), required: true })),
				fieldEl(t('Role key'), inputEl('role_key', { required: true, placeholder: 'sachbearbeiter' })),
				fieldEl(t('Rate per hour (EUR)'), moneyEl('rate_minor')),
				fieldEl(t('Valid from'), inputEl('valid_from', { type: 'date', required: true }))
			]),
			submitLabel: t('Add'),
			onSubmit: function (fd) {
				var rate = parseMoney(fd.get('rate_minor'));
				if (rate === null) {
					return Promise.reject({ fields: { rate_minor: t('Invalid amount.') }, message: t('Invalid amount.') });
				}
				return NW.api('/projects/' + pid + '/rates', { method: 'POST', json: {
					uid: fd.get('uid'), role_key: fd.get('role_key'),
					rate_minor: rate, valid_from: fd.get('valid_from')
				}}).then(function () {
					NW.toast('success', t('Rate saved.'));
					reload();
				});
			}
		});
	}

	function reallocDecide(id, decision) {
		NW.api('/projects/' + pid + '/reallocations/' + id + '/decide', {
			method: 'POST', json: { decision: decision }
		}).then(function () {
			NW.toast('success', t('Decision recorded.'));
			reload();
		}).catch(NW.handleError);
	}

	function openReallocDialog(positions) {
		if (!(positions || []).length) {
			NW.toast('warn', t('No active financing plan.'));
			return;
		}
		NW.openDialog({
			title: t('Request reallocation'),
			body: el('div', {}, [
				fieldEl(t('From position'), selectEl('from_position_id', positionOptions(positions), null, { required: true })),
				fieldEl(t('To position'), selectEl('to_position_id', positionOptions(positions), null, { required: true })),
				fieldEl(t('Amount (EUR)'), moneyEl('amount_minor')),
				fieldEl(t('Reason (required)'), textareaEl('reason_text', { required: true }))
			]),
			submitLabel: t('Request'),
			onSubmit: function (fd) {
				var amount = parseMoney(fd.get('amount_minor'));
				if (amount === null) {
					return Promise.reject({ fields: { amount_minor: t('Invalid amount.') }, message: t('Invalid amount.') });
				}
				if (fd.get('from_position_id') === fd.get('to_position_id')) {
					return Promise.reject({ message: t('Source and target must differ.') });
				}
				return NW.api('/projects/' + pid + '/reallocations', { method: 'POST', json: {
					from_position_id: parseInt(fd.get('from_position_id'), 10),
					to_position_id: parseInt(fd.get('to_position_id'), 10),
					amount_minor: amount,
					reason_text: fd.get('reason_text') || ''
				}}).then(function () {
					NW.toast('success', t('Reallocation requested.'));
					reload();
				});
			}
		});
	}

	function openCarryoverDialog() {
		var thisYear = new Date().getFullYear();
		NW.openDialog({
			title: t('Record carryover'),
			body: el('div', {}, [
				fieldEl(t('From year'), inputEl('from_year', { type: 'number', min: '2000', max: '2100', step: '1', value: thisYear - 1, required: true })),
				fieldEl(t('To year'), inputEl('to_year', { type: 'number', min: '2000', max: '2100', step: '1', value: thisYear, required: true })),
				fieldEl(t('Amount (EUR)'), moneyEl('amount_minor')),
				fieldEl(t('Document reference'), inputEl('doc_ref')),
				fieldEl(t('Decided by'), inputEl('decided_by'))
			]),
			submitLabel: t('Record'),
			onSubmit: function (fd) {
				var amount = parseMoney(fd.get('amount_minor'));
				if (amount === null) {
					return Promise.reject({ fields: { amount_minor: t('Invalid amount.') }, message: t('Invalid amount.') });
				}
				return NW.api('/projects/' + pid + '/carryovers', { method: 'POST', json: {
					from_year: parseInt(fd.get('from_year'), 10),
					to_year: parseInt(fd.get('to_year'), 10),
					amount_minor: amount,
					doc_ref: fd.get('doc_ref') || '',
					decided_by: fd.get('decided_by') || ''
				}}).then(function () {
					NW.toast('success', t('Carryover recorded.'));
					reload();
				});
			}
		});
	}

	/* ---------- team & access ---------- */

	var MEMBER_ROLES = [['member', 'Member'], ['finance', 'Finance'], ['admin', 'Admin']];
	var GRANT_SCOPES = [['soll_ist', 'Budget vs. actuals'], ['belege', 'Receipts'], ['nachweise', 'Usage reports']];

	function renderTeam() {
		NW.pageActions([]);
		host.innerHTML = '';
		var actions = [];
		actions.push({ label: t('Add member'), primary: true, onClick: function () { openMemberDialog(); } });
		if (role.isPro) {
			actions.push({ label: t('Grant auditor access'), onClick: function () { openGrantDialog(); } });
		}
		NW.pageActions(actions);
		host.appendChild(loading());
		Promise.all([
			NW.api('/projects/' + pid + '/members'),
			NW.api('/projects/' + pid + '/grants').catch(function () { return []; })
		]).then(function (res) {
			var members = res[0] || [], grants = res[1] || [];
			host.innerHTML = '';

			host.appendChild(card(t('Members'), { children: [
				tableEl([t('User'), t('Name'), t('Role'), ''],
					members.map(function (m) {
						var act = el('span', { class: 'nw-table-actions' });
						var roleSel = selectEl('role', MEMBER_ROLES.map(function (r) { return [r[0], t(r[1])]; }), m.role);
						roleSel.classList.add('nw-select--inline');
						roleSel.setAttribute('aria-label', t('Role for %s', [m.uid]));
						roleSel.addEventListener('change', function () {
							NW.api('/projects/' + pid + '/members/' + encodeURIComponent(m.uid), {
								method: 'PUT', json: { role: roleSel.value }
							}).then(function () {
								NW.toast('success', t('Role updated.'));
								reload();
							}).catch(function (e) { NW.handleError(e); reload(); });
						});
						if (!(m.uid === boot.uid && m.role === 'admin')) {
							act.appendChild(smallBtn(t('Remove'), function () {
								NW.confirmDialog(t('Remove member'), t('Remove %s from the project?', [m.uid]))
									.then(function (ok) {
										if (!ok) { return; }
										NW.api('/projects/' + pid + '/members/' + encodeURIComponent(m.uid), { method: 'DELETE' })
											.then(reload).catch(NW.handleError);
									});
							}));
						}
						return [m.uid, m.display_name || '—', roleSel, act];
					}), { empty: t('No members.') })
			]}));

			var grantChildren = [];
			if (!role.isPro) {
				grantChildren.push(el('p', { class: 'nw-muted', text: t('Auditor seats are a Pro feature.') }));
			}
			grantChildren.push(tableEl([t('Auditor'), t('Firm'), t('Account'), t('Valid'), t('Scope'), t('State'), ''],
				grants.map(function (g) {
					var stateTxt = g.revoked ? 'revoked' : (g.expired ? 'expired' : 'active');
					var act = el('span', { class: 'nw-table-actions' });
					if (!g.revoked) {
						act.appendChild(smallBtn(t('Reads'), function () { openReadsDialog(g); }));
						act.appendChild(smallBtn(t('Revoke'), function () {
							NW.confirmDialog(t('Revoke auditor access'), t('Revoke this grant? The auditor loses access immediately.'))
								.then(function (ok) {
									if (!ok) { return; }
									NW.api('/projects/' + pid + '/grants/' + g.id + '/revoke', { method: 'POST', json: {} })
										.then(reload).catch(NW.handleError);
								});
						}));
					}
					return [
						g.name || '—', g.firm || '—', g.nc_uid || t('token only'),
						NW.fmtDate(g.valid_from) + ' – ' + NW.fmtDate(g.valid_to),
						(g.scope || []).length ? g.scope.join(', ') : t('all'),
						stateBadge(stateTxt, t(stateTxt)),
						act
					];
				}), { empty: t('No auditor grants.') }));
			host.appendChild(card(t('Auditor access'), { children: grantChildren }));
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function openMemberDialog() {
		var uid = uidControl('uid', { required: true });
		NW.openDialog({
			title: t('Add member'),
			body: el('div', {}, [
				fieldEl(t('User'), uid, {
					hint: role.isAppAdmin ? t('Search by name — pick from results.') : t('Enter the exact Nextcloud user id.')
				}),
				fieldEl(t('Role'), selectEl('role', MEMBER_ROLES.map(function (r) { return [r[0], t(r[1])]; }), 'member'))
			]),
			submitLabel: t('Add'),
			onSubmit: function (fd) {
				var ids = uid.value();
				var chosen = ids.length ? ids[0] : String(fd.get('uid') || '').trim();
				if (chosen === '') {
					return Promise.reject({ fields: { uid: t('A user is required.') }, message: t('A user is required.') });
				}
				return NW.api('/projects/' + pid + '/members', { method: 'POST', json: {
					uid: chosen, role: fd.get('role')
				}}).then(function () {
					NW.toast('success', t('Member added.'));
					reload();
				});
			}
		});
	}

	function openGrantDialog() {
		var uid = uidControl('nc_uid');
		var scopeBox = el('div');
		GRANT_SCOPES.forEach(function (s) {
			var id = 'nw-scope-' + s[0] + '-' + (++fieldSeq);
			var cb = inputEl('scope', { type: 'checkbox', value: s[0] });
			cb.id = id;
			scopeBox.appendChild(el('div', { class: 'nw-checkbox-row' }, [
				el('label', { 'for': id }, [cb, document.createTextNode(' ' + t(s[1]))])
			]));
		});
		NW.openDialog({
			title: t('Grant auditor access'),
			body: el('div', {}, [
				fieldEl(t('Auditor account'), uid, {
					hint: t('Optional — an external auditor without an NC account reads via the grant token.')
				}),
				fieldEl(t('Name'), inputEl('name', { required: true })),
				fieldEl(t('Firm'), inputEl('firm')),
				fieldEl(t('Valid from'), inputEl('valid_from', { type: 'date', required: true })),
				fieldEl(t('Valid to'), inputEl('valid_to', { type: 'date', required: true })),
				el('fieldset', { class: 'nw-fieldset' }, [
					el('legend', { class: 'nw-fieldset__legend', text: t('Readable sections') }),
					scopeBox,
					el('p', { class: 'nw-field__hint', text: t('Unchecked all = everything.') })
				])
			]),
			submitLabel: t('Grant'),
			onSubmit: function (fd) {
				var ids = uid.value();
				var scope = fd.getAll('scope');
				return NW.api('/projects/' + pid + '/grants', { method: 'POST', json: {
					nc_uid: ids.length ? ids[0] : '',
					name: fd.get('name'), firm: fd.get('firm') || '',
					valid_from: fd.get('valid_from'), valid_to: fd.get('valid_to'),
					scope: scope.length ? scope : null
				}}).then(function () {
					NW.toast('success', t('Grant created.'));
					reload();
				});
			}
		});
	}

	function openReadsDialog(g) {
		NW.api('/projects/' + pid + '/grants/' + g.id + '/reads').then(function (reads) {
			NW.openDialog({
				title: t('Read log — %s', [g.name || g.nc_uid || ('#' + g.id)]),
				body: tableEl([t('Route'), t('Record'), t('Time')],
					(reads || []).map(function (r) {
						return [r.route || '—', r.entity + ' #' + r.entity_id, NW.fmtDateTime(r.ts)];
					}), { empty: t('No reads logged yet.') }),
				submitLabel: t('Close'),
				wide: true
			});
		}).catch(NW.handleError);
	}

	/* ---------- csv import ---------- */

	function renderImport() {
		NW.pageActions([]);
		host.innerHTML = '';
		var presets = [
			['generic', t('Generic CSV')],
			['arbeitszeitcheck', t('ArbeitszeitCheck export')],
			['budgetcheck', t('BudgetCheck export')]
		];
		[['times', t('Import time entries'), t('CSV columns depend on the preset — generic expects date, ap_code, minutes, note.')],
		 ['receipts', t('Import receipts'), t('Generic expects vendor, invoice_date, total, position_code per row.')]].forEach(function (blk) {
			var kind = blk[0];
			var fileIn = inputEl('csv_file', { type: 'file' });
			fileIn.setAttribute('accept', '.csv,text/csv');
			var presetSel = selectEl('preset', presets, 'generic');
			var paste = textareaEl('csv', { rows: '8', placeholder: 'date,ap_code,minutes,note\n2026-01-15,AP1,90,Setup' });
			var result = el('div', { 'aria-live': 'polite' });
			var submit = el('button', { type: 'button', class: 'nw-btn nw-btn--primary', text: t('Import') });
			var guard = NW.busyGuard(submit);
			submit.addEventListener('click', function () {
				var send = function (csvText, filename) {
					if (!guard.tryStart()) { return; }
					NW.api('/projects/' + pid + '/import/' + kind, { method: 'POST', json: {
						preset: presetSel.value, csv: csvText, filename: filename || 'inline.csv'
					}}).then(function (res) {
						result.innerHTML = '';
						var ok = res.rows_ok !== undefined ? res.rows_ok : (res.imported || 0);
						var failed = res.rows_failed !== undefined ? res.rows_failed : (res.skipped || 0);
						result.appendChild(el('div', {
							class: 'nw-callout ' + (failed > 0 ? 'nw-callout--warning' : 'nw-callout--success'),
							role: 'status'
						}, [
							el('p', { class: 'nw-callout__title', text: t('%s rows imported, %s failed', [String(ok), String(failed)]) })
						]));
						if ((res.errors || []).length) {
							result.appendChild(tableEl([t('Row'), t('Error')],
								res.errors.map(function (e) { return [String(e.row), e.error || '—']; })));
						}
					}).catch(NW.handleError)
						.finally(function () { guard.end(); });
				};
				var f = fileIn.files && fileIn.files[0];
				if (f) {
					var reader = new FileReader();
					reader.onload = function () { send(String(reader.result || ''), f.name); };
					reader.onerror = function () { NW.toast('error', t('Could not read the file.')); };
					reader.readAsText(f);
					return;
				}
				if (paste.value.trim() === '') {
					NW.toast('warn', t('Choose a CSV file or paste its contents.'));
					return;
				}
				send(paste.value, 'pasted.csv');
			});
			host.appendChild(card(blk[1], { children: [
				el('p', { class: 'nw-muted', text: blk[2] }),
				fieldEl(t('Preset'), presetSel),
				fieldEl(t('CSV file'), fileIn),
				fieldEl(t('…or paste CSV'), paste),
				el('div', { class: 'nw-btn-row' }, [submit]),
				result
			]}));
		});
	}

	/* ---------- auditor read surface ---------- */

	function renderAuditor(grants) {
		NW.pageActions([]);
		host.innerHTML = '';
		host.appendChild(el('div', { class: 'nw-callout nw-callout--info', role: 'status' }, [
			el('p', { class: 'nw-callout__title', text: t('Auditor view') }),
			el('p', { class: 'nw-callout__text', text: t('Read-only — every access is logged for the project team.') })
		]));
		var sel = selectEl('audit_project', grants.map(function (g) {
			return [g.project_id, t('Project #%s (grant until %s)', [String(g.project_id), NW.fmtDate(g.valid_to)])];
		}), grants[0].project_id);
		host.appendChild(fieldEl(t('Granted project'), sel));
		var region = el('div');
		host.appendChild(region);
		sel.addEventListener('change', function () { paintAuditor(parseInt(sel.value, 10)); });
		function paintAuditor(projectId) {
			region.innerHTML = '';
			region.appendChild(loading());
			Promise.all([
				NW.api('/auditor/projects/' + projectId).catch(function () { return null; }),
				NW.api('/auditor/projects/' + projectId + '/soll-ist').catch(function () { return null; }),
				NW.api('/auditor/projects/' + projectId + '/belege').catch(function () { return null; }),
				NW.api('/auditor/projects/' + projectId + '/nachweise').catch(function () { return null; })
			]).then(function (res) {
				region.innerHTML = '';
				var p = res[0];
				if (p) {
					region.appendChild(card(p.name || t('Project'), { children: [
						dlEl([
							[t('Funder'), p.funder_name || '—'],
							[t('Reference number'), p.funder_ref || '—'],
							[t('Funding period'), (p.bew_start || '—') + ' – ' + (p.bew_end || '—')],
							[t('State'), p.state ? t(p.state) : '—']
						])
					]}));
				}
				if (res[1]) {
					region.appendChild(card(t('Budget vs. actuals'), { children: [
						renderPositionList((res[1].positions) || [])
					]}));
				}
				if (res[2]) {
					region.appendChild(card(t('Receipts'), { children: [
						tableEl([t('Vendor'), t('Invoice date'), t('Total'), t('Kind')],
							res[2].map(function (r) {
								return [r.vendor || '—', NW.fmtDate(r.invoice_date), fmtMoney(r.total_minor), r.kind ? t(r.kind) : '—'];
							}), { empty: t('No receipts.') })
					]}));
				}
				if (res[3]) {
					region.appendChild(card(t('Usage reports'), { children: [
						tableEl([t('Version'), t('Kind'), t('State'), t('Hash'), t('Generated')],
							res[3].map(function (n) {
								return ['v' + n.version, t(NACHWEIS_KINDS[n.kind] || n.kind),
									t(n.state), el('code', { class: 'nw-mono', text: hashShort(n.manifest_hash) }),
									NW.fmtDateTime(n.generated_at)];
							}), { empty: t('None yet.') })
					]}));
				}
				if (!res[1] && !res[2] && !res[3]) {
					region.appendChild(el('p', { class: 'nw-muted', text: t('No readable sections in this grant.') }));
				}
			});
		}
		paintAuditor(grants[0].project_id);
	}

	/* ---------- no project selected ---------- */

	function renderProjectPicker() {
		NW.pageActions([]);
		host.innerHTML = '';
		var projects = boot.projects || [];
		if (!projects.length) {
			host.appendChild(emptyState(t('No projects yet'),
				t('Create your first funded project — the financing plan comes first, then times and receipts can be booked.'),
				btn(t('New project'), function () { openProjectDialog(); }, 'nw-btn--primary')));
			return;
		}
		var grid = el('div', { class: 'nw-card-grid' });
		projects.forEach(function (p) {
			var c = el('section', { class: 'nw-card' }, [
				el('div', { class: 'nw-card__header' }, [
					el('div', { class: 'nw-card__header-text' }, [
						el('h2', { class: 'nw-card__title', text: p.name })
					]),
					stateBadge(p.state, t(p.state))
				]),
				el('div', { class: 'nw-card__body' }, [
					el('a', {
						class: 'nw-btn nw-btn--primary',
						href: location.pathname + '?project=' + p.id + '&view=dashboard',
						text: t('Open')
					})
				])
			]);
			grid.appendChild(c);
		});
		host.appendChild(grid);
	}

	/* ---------- boot ---------- */

	var VIEWS = {
		dashboard: { fn: renderDashboard },
		finplan: { fn: renderFinplan },
		workpackages: { fn: renderWorkPackages },
		time: { fn: renderTime },
		receipts: { fn: renderReceipts },
		reports: { fn: renderReports },
		deviations: { fn: renderDeviations },
		nachweis: { fn: renderNachweis },
		deadlines: { fn: renderDeadlines },
		finance: { fn: renderFinance, cap: 'can_finance' },
		team: { fn: renderTeam, cap: 'can_admin' },
		import: { fn: renderImport, cap: 'can_finance' }
	};

	function render() {
		if (!project) {
			renderEntry();
			return;
		}
		var view = VIEWS[boot.view] ? boot.view : 'dashboard';
		if (VIEWS[view].cap && !caps[VIEWS[view].cap]) {
			view = 'dashboard';
		}
		VIEWS[view].fn();
	}

	function renderEntry() {
		var projects = boot.projects || [];
		if (!projects.length) {
			// No membership — maybe an auditor with grants.
			host.innerHTML = '';
			host.appendChild(loading());
			NW.api('/auditor/projects').then(function (grants) {
				if ((grants || []).length) {
					renderAuditor(grants);
					return;
				}
				renderProjectPicker();
			}).catch(function () {
				renderProjectPicker();
			});
			return;
		}
		renderProjectPicker();
	}

	/* §3.7 row actions: Escape dismisses the revealed cluster until the
	   pointer re-enters the row or focus returns to an action. */
	function installRowActionsDismiss() {
		document.addEventListener('keydown', function (ev) {
			if (ev.key !== 'Escape') { return; }
			if (document.querySelector('dialog[open]')) { return; }
			var target = ev.target;
			if (!(target instanceof Element)) { return; }
			var row = target.closest('tr');
			if (!row || row.classList.contains('nw-row-actions-dismissed')) { return; }
			if (!row.querySelector('.nw-table-actions')) { return; }
			ev.preventDefault();
			row.classList.add('nw-row-actions-dismissed');
			if (!row.hasAttribute('tabindex')) {
				row.setAttribute('tabindex', '-1');
				row.dataset.nwRowTabindexAdded = 'true';
			}
			row.focus();
		});
		document.addEventListener('pointerover', function (ev) {
			var target = ev.target;
			if (!(target instanceof Element)) { return; }
			var row = target.closest('tr.nw-row-actions-dismissed');
			if (row) { row.classList.remove('nw-row-actions-dismissed'); }
		});
		document.addEventListener('focusin', function (ev) {
			var target = ev.target;
			if (!(target instanceof Element) || !target.closest('.nw-table-actions')) { return; }
			var row = target.closest('tr.nw-row-actions-dismissed');
			if (row) { row.classList.remove('nw-row-actions-dismissed'); }
		});
	}

	function init() {
		host = document.getElementById('nw-main');
		if (!host) { return; }
		installRowActionsDismiss();
		render();
		if (boot.newProject) {
			openProjectDialog();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
