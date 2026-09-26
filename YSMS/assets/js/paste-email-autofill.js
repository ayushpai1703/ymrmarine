/**
 * Paste Email Auto-Fill for the Assign Vessel form — assign_vessel.php only.
 *
 * The client/agent's booking email is pasted into a textarea and parsed
 * entirely in the browser — no external API, no per-use cost. Because this
 * is typed/pasted text (not speech), it has real punctuation and line
 * breaks, which makes it far more reliable to parse than a spoken sentence
 * ever was: real colons ("Vessel: MV Ocean Star") and real newlines let the
 * parser anchor on line starts instead of guessing at word boundaries in a
 * run-on sentence.
 *
 * Matching strategy — same reasoning as everywhere else on this page: Port,
 * Client and Survey Type are real master tables (rendered as the existing
 * .searchable-select widgets already on this page), so pasted text is
 * fuzzy-matched against the exact option list the server already put in
 * the DOM — no new AJAX endpoint, no way to select an id the server didn't
 * render. Vessel Name and Agent Name are genuine free-text columns on
 * `surveys` in this schema (no vessels/agents master table exists), so
 * they're filled as text and always shown for the user to verify before
 * Confirm, exactly like typing them by hand. There's no Quantity/Product
 * field on this form, so a recognized quantity+unit+product is appended to
 * the existing Remarks textarea instead of inventing new fields/columns.
 *
 * Applying a match clicks the real .ss-option element the manual flow
 * already uses, so existing change-event side effects (e.g. the
 * report-number preview) keep firing unmodified — zero duplicated
 * business logic. Nothing is written into the real form until the user
 * presses Confirm (or Apply, from Edit mode).
 */
(function () {
    'use strict';

    $(document).ready(function () {
        var $form = $('#assignVesselForm');
        if (!$form.length || !document.getElementById('pasteEmailModal')) return;

        var lastRawText = '';
        var lastResult = null;

        var $triggerBtn = $('#pasteEmailTriggerBtn');
        var $emailInput = $('#peEmailInput');
        var $parseBtn = $('#peParseBtn');
        var $clearBtn = $('#peClearBtn');
        var $heardText = $('#peHeardText');
        var $resultList = $('#peResultList');
        var $backBtn = $('#peBackBtn');
        var $editBtn = $('#peEditBtn');
        var $confirmBtn = $('#peConfirmBtn');
        var $errorTitle = $('#peErrorTitle');
        var $errorMsg = $('#peErrorMsg');
        var $errorRetryBtn = $('#peErrorRetryBtn');
        var modalEl = document.getElementById('pasteEmailModal');
        var bsModal = null;

        function showState(id) {
            $('.pe-state').removeClass('active');
            $('#' + id).addClass('active');
        }
        function openModal() {
            if (!bsModal) bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        }
        function closeModal() {
            if (bsModal) bsModal.hide();
        }
        function showError(title, msg) {
            $errorTitle.text(title);
            $errorMsg.text(msg);
            showState('peErrorState');
        }

        // ── Text normalisation + fuzzy matching (same proven approach used
        // for the other free-text-vs-dropdown matching on this page) ──────
        function normalize(s) {
            return String(s || '').toLowerCase().replace(/[.,;:!?"'()]/g, ' ').replace(/\s+/g, ' ').trim();
        }
        function cleanFreeText(s) {
            return String(s || '').replace(/[,;.]+$/, '').trim();
        }
        function titleCase(s) {
            return String(s || '').replace(/\w\S*/g, function (t) { return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase(); });
        }
        function levenshtein(a, b) {
            var m = a.length, n = b.length, i, j;
            var dp = [];
            for (i = 0; i <= m; i++) dp[i] = [i];
            for (j = 0; j <= n; j++) dp[0][j] = j;
            for (i = 1; i <= m; i++) {
                for (j = 1; j <= n; j++) {
                    dp[i][j] = a[i - 1] === b[j - 1] ? dp[i - 1][j - 1] : 1 + Math.min(dp[i - 1][j - 1], dp[i - 1][j], dp[i][j - 1]);
                }
            }
            return dp[m][n];
        }
        function similarity(a, b) {
            if (!a || !b) return 0;
            if (a === b) return 1;
            if (b.indexOf(a) !== -1 || a.indexOf(b) !== -1) {
                var longer = Math.max(a.length, b.length), shorter = Math.min(a.length, b.length);
                return 0.75 + 0.2 * (shorter / longer);
            }
            var at = a.split(' '), bt = b.split(' ');
            var common = at.filter(function (t) { return bt.indexOf(t) !== -1; }).length;
            var tokenScore = common / Math.max(at.length, bt.length);
            var lev = levenshtein(a, b);
            var levScore = 1 - lev / Math.max(a.length, b.length);
            return Math.max(tokenScore, levScore);
        }

        // Reads the SAME .ss-option elements the server already rendered
        // into the page (real client_id/port_id/survey_type_id values).
        function getOptionCandidates(rootKey) {
            var $root = $('.searchable-select[data-ss-root="' + rootKey + '"]');
            var list = [];
            if (!$root.length) return list;
            $root.find('.ss-options .ss-option').each(function () {
                var $o = $(this);
                if ($o.hasClass('ss-option-other') || $o.hasClass('ss-option-empty')) return;
                var value = $o.attr('data-value');
                var name = String($o.attr('data-name') || '').toLowerCase().trim();
                if (!value || !name) return;
                list.push({ value: value, name: name, label: titleCase(name) });
            });
            return list;
        }

        function matchCandidates(spokenText, candidates) {
            var query = normalize(spokenText);
            if (!query || !candidates.length) return { status: 'none', matches: [] };
            var scored = candidates
                .map(function (c) { return { value: c.value, label: c.label, name: c.name, score: similarity(query, c.name) }; })
                .filter(function (c) { return c.score >= 0.55; })
                .sort(function (a, b) { return b.score - a.score; });
            if (!scored.length) return { status: 'none', matches: [] };
            if (scored.length === 1) return { status: 'single', matches: scored };
            var top = scored[0].score, second = scored[1].score;
            if (top - second >= 0.15) return { status: 'single', matches: [scored[0]] };
            var close = scored.filter(function (c) { return top - c.score < 0.15; }).slice(0, 5);
            return { status: 'multiple', matches: close };
        }

        function bestSubstringMatch(text, candidates) {
            var lowerText = text.toLowerCase();
            var best = null;
            candidates.forEach(function (c) {
                if (c.name.length < 3) return;
                var idx = lowerText.indexOf(c.name);
                if (idx !== -1 && (!best || c.name.length > best.name.length)) {
                    best = { value: c.value, label: c.label, name: c.name, matchedText: text.substr(idx, c.name.length) };
                }
            });
            return best;
        }

        // ── Parsing ──────────────────────────────────────────────────────
        // Short, low-collision label set on purpose (lessons learned from
        // an earlier voice-input version of this feature): wide synonyms
        // like "shipping agent" or a bare "survey"/"cargo" kept
        // false-matching *inside* an adjacent field's own value — e.g. a
        // survey type literally named "Cargo Survey" made "cargo" (a
        // product word) and the bare word "survey" both look like new
        // field labels mid-value. Keeping the label list to exactly what's
        // asked for avoids that.
        var FIELD_KEYWORDS = [
            { field: 'vessel', words: ['vessel name', 'vessel', 'ship name', 'ship'] },
            { field: 'client', words: ['client name', 'client', 'principal', 'customer'] },
            { field: 'agent', words: ['agent name', 'agent'] },
            { field: 'port', words: ['port name', 'port', 'port of survey'] },
            { field: 'survey', words: ['type of survey', 'survey type'] }
        ];
        var LINE_LABEL_RE_BY_FIELD = (function () {
            var map = {};
            FIELD_KEYWORDS.forEach(function (g) {
                map[g.field] = g.words
                    .slice()
                    .sort(function (a, b) { return b.length - a.length; })
                    .map(function (w) { return new RegExp('^' + escapeRegex(w) + '\\s*[:\\-]\\s*(.+)$', 'i'); });
            });
            return map;
        })();
        var LINE_PRODUCT_RE = [/^product\s*[:\-]\s*(.+)$/i, /^grade\s*[:\-]\s*(.+)$/i];
        // "Quantity:"/"Qty:" lines are claimed here so they don't leak into
        // the Pass-2 keyword scan, but their value is deliberately NOT
        // copied into productText — extractQuantity(raw) below already
        // reads the number+unit straight out of the raw text, so copying it
        // here too would duplicate it in the Remarks line (e.g. "1500 MT
        // 1500 MT").
        var LINE_QTY_RE = [/^quantity\s*[:\-]\s*(.+)$/i, /^qty\s*[:\-]\s*(.+)$/i];

        function escapeRegex(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

        var KEYWORD_TO_FIELD = {};
        var ALL_KEYWORD_PHRASES = [];
        FIELD_KEYWORDS.forEach(function (g) {
            g.words.forEach(function (w) { KEYWORD_TO_FIELD[w] = g.field; ALL_KEYWORD_PHRASES.push(w); });
        });
        ALL_KEYWORD_PHRASES.sort(function (a, b) { return b.length - a.length; });
        var KEYWORD_SCAN_SOURCE = '\\b(' + ALL_KEYWORD_PHRASES.map(escapeRegex).join('|') + ')\\b';

        function scanKeywordPositions(text) {
            var re = new RegExp(KEYWORD_SCAN_SOURCE, 'gi');
            var matches = [];
            var m;
            while ((m = re.exec(text)) !== null) {
                matches.push({ field: KEYWORD_TO_FIELD[m[1].toLowerCase()], start: m.index, end: m.index + m[0].length });
                if (m.index === re.lastIndex) re.lastIndex++;
            }
            return matches;
        }

        function tryAssignTemplate(text) {
            var m = text.match(/assign\s+(.+?)\s+at\s+(.+?)\s+for\s+(.+?)\s+through\s+(.+?)\s*$/i);
            if (!m) return null;
            return { vessel: m[1].trim(), port: m[2].trim(), client: m[3].trim(), agent: m[4].trim() };
        }

        var QTY_RE = /(\d+(?:\.\d+)?)\s*(metric\s*tons?|mts?\b|tons?\b|kgs?\b|kilograms?\b|liters?\b|litres?\b|kls?\b)/i;
        function extractQuantity(text) {
            var m = text.match(QTY_RE);
            if (!m) return null;
            var unitRaw = m[2].toLowerCase();
            var unit = 'MT';
            if (/kg|kilogram/.test(unitRaw)) unit = 'KG';
            else if (/lit/.test(unitRaw)) unit = 'L';
            else if (/kl/.test(unitRaw)) unit = 'KL';
            return { quantity: m[1], unit: unit, matchText: m[0] };
        }
        function stripQuantityTail(value) {
            if (!value) return value;
            var m = value.match(QTY_RE);
            return m ? value.slice(0, m.index).trim() : value;
        }

        function setField(out, field, value) {
            value = cleanFreeText(value);
            if (!value) return;
            if (field === 'vessel' && !out.vessel) out.vessel = value;
            else if (field === 'client' && !out.client) out.client = value;
            else if (field === 'agent' && !out.agent) out.agent = value;
            else if (field === 'port' && !out.port) out.port = value;
            else if (field === 'survey' && !out.survey) out.survey = value;
        }

        function parseEmailText(raw) {
            var out = { vessel: '', client: '', agent: '', port: '', survey: '', quantity: '', unit: '', productText: '' };
            var lines = raw.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
            var unclaimedLines = [];

            // Pass 1 — "Label: Value" / "Label - Value" per line. Very
            // reliable since real punctuation is present (this is typed
            // text, not speech).
            lines.forEach(function (line) {
                var claimed = false;
                for (var i = 0; i < FIELD_KEYWORDS.length && !claimed; i++) {
                    var field = FIELD_KEYWORDS[i].field;
                    var res = LINE_LABEL_RE_BY_FIELD[field];
                    for (var j = 0; j < res.length; j++) {
                        var m = line.match(res[j]);
                        if (m) { setField(out, field, m[1]); claimed = true; break; }
                    }
                }
                if (!claimed) {
                    for (var k = 0; k < LINE_PRODUCT_RE.length && !claimed; k++) {
                        var pm = line.match(LINE_PRODUCT_RE[k]);
                        if (pm) { if (!out.productText) out.productText = cleanFreeText(pm[1]); claimed = true; }
                    }
                }
                if (!claimed) {
                    for (var q = 0; q < LINE_QTY_RE.length && !claimed; q++) {
                        if (LINE_QTY_RE[q].test(line)) { claimed = true; }
                    }
                }
                if (!claimed) unclaimedLines.push(line);
            });

            // Pass 2 — anything still missing: scan the remaining unclaimed
            // text as one block (handles prose-style emails with no
            // per-line labels, e.g. "Please arrange survey for vessel MV
            // Ocean Star at port Kakinada for client ABC Shipping").
            var remaining = unclaimedLines.join(' ');
            if (remaining && (!out.vessel || !out.client || !out.agent || !out.port || !out.survey)) {
                var matches = scanKeywordPositions(remaining).sort(function (a, b) { return a.start - b.start; });
                if (matches.length) {
                    for (var x = 0; x < matches.length; x++) {
                        var valueEnd = (x + 1 < matches.length) ? matches[x + 1].start : remaining.length;
                        var value = remaining.slice(matches[x].end, valueEnd).trim().replace(/^(is|are|=|:|-|,)\s*/i, '').replace(/[,;.]+$/, '').trim();
                        setField(out, matches[x].field, value);
                    }
                } else {
                    var tmpl = tryAssignTemplate(remaining);
                    if (tmpl) {
                        if (!out.vessel) out.vessel = cleanFreeText(tmpl.vessel);
                        if (!out.port) out.port = cleanFreeText(tmpl.port);
                        if (!out.client) out.client = cleanFreeText(tmpl.client);
                        if (!out.agent) out.agent = cleanFreeText(tmpl.agent);
                    }
                }
            }

            out.vessel = stripQuantityTail(out.vessel);
            out.client = stripQuantityTail(out.client);
            out.agent = stripQuantityTail(out.agent);
            out.port = stripQuantityTail(out.port);
            out.survey = stripQuantityTail(out.survey);

            var qty = extractQuantity(raw);
            if (qty) {
                out.quantity = qty.quantity;
                out.unit = qty.unit;
                if (!out.productText) {
                    var qtyIdx = raw.toLowerCase().indexOf(qty.matchText.toLowerCase());
                    if (qtyIdx !== -1) {
                        var afterQty = raw.slice(qtyIdx + qty.matchText.length);
                        // Bound the "trailing text is the product name" guess to
                        // whichever comes first: end of this line, or the next
                        // recognized field keyword — otherwise a quantity that
                        // appears mid-sentence, before the other fields (e.g.
                        // "Please load 250 MT Heavy Fuel Oil for vessel MV
                        // Pacific Dawn at Kakinada..."), would swallow the rest
                        // of the sentence into productText, duplicating what
                        // vessel/port/client/agent/survey already captured.
                        var nlIdx = afterQty.indexOf('\n');
                        var lineBounded = nlIdx === -1 ? afterQty : afterQty.slice(0, nlIdx);
                        var kwMatches = scanKeywordPositions(lineBounded);
                        var kwBoundary = kwMatches.length ? kwMatches[0].start : lineBounded.length;
                        var trailing = lineBounded.slice(0, kwBoundary).trim()
                            .replace(/^(is|are|=|:|-|,)\s*/i, '')
                            .replace(/[,;.]+$/, '')
                            .replace(/\b(for|at|to|through)\s*$/i, '')
                            .trim();
                        if (trailing) out.productText = trailing;
                    }
                }
            }
            return out;
        }

        // ── Build + render the confirmation panel (same pattern as the
        // rest of this page's field review UX) ────────────────────────
        function matchField(spokenText, candidates) {
            var clean = cleanFreeText(spokenText);
            if (!clean) return { status: 'empty', raw: '', matches: [] };
            var m = matchCandidates(clean, candidates);
            return { status: m.status, raw: clean, matches: m.matches };
        }

        function buildResult(parsed) {
            var portCandidates = getOptionCandidates('port');
            var clientCandidates = getOptionCandidates('client');
            var surveyCandidates = getOptionCandidates('surveyType');
            var clientFieldExists = $('.searchable-select[data-ss-root="client"]').length > 0;

            var result = {
                vessel: { free: true, value: cleanFreeText(parsed.vessel) },
                agent: { free: true, value: cleanFreeText(parsed.agent) },
                port: matchField(parsed.port, portCandidates),
                client: clientFieldExists ? matchField(parsed.client, clientCandidates) : null,
                survey: matchField(parsed.survey, surveyCandidates)
            };
            var cargoParts = [];
            if (parsed.quantity) cargoParts.push(parsed.quantity + ' ' + parsed.unit);
            if (parsed.productText) cargoParts.push(cleanFreeText(parsed.productText).toUpperCase());
            result.cargo = { free: true, value: cargoParts.join(' ') };
            return result;
        }

        function buildPickList(field, candidates, selectedValue, forceFullList) {
            var $sel = $('<select></select>').css({ width: '100%', border: '1px solid var(--border-color)', borderRadius: '8px', padding: '6px 8px', fontSize: '12.5px' });
            $sel.append($('<option value="">— Select —</option>'));
            var source = (!forceFullList && field.matches && field.matches.length) ? field.matches : candidates;
            source.forEach(function (c) {
                var $o = $('<option></option>').attr('value', c.value).text(c.label);
                if (selectedValue && String(c.value) === String(selectedValue)) $o.prop('selected', true);
                $sel.append($o);
            });
            return $sel;
        }

        function appendRow(label, key, field, editMode, allCandidates) {
            if (!field) return;
            var $row = $('<div class="pe-result-row"></div>');
            $row.append($('<div class="pe-result-label"></div>').text(label));
            var $val = $('<div class="pe-result-value"></div>').attr('data-pe-row', key);

            if (field.free) {
                if (editMode) {
                    $val.append($('<input type="text">').attr('data-pe-field', key).val(field.value));
                } else if (field.value) {
                    $val.addClass('ok').text(field.value + ' ✓');
                } else {
                    $val.addClass('bad').text('Not found in email');
                }
            } else if (field.status === 'empty') {
                if (editMode) $val.append(buildPickList(field, allCandidates, '', true));
                else $val.addClass('bad').text('Not mentioned');
            } else if (field.status === 'single') {
                if (editMode) $val.append(buildPickList(field, allCandidates, field.matches[0].value, true));
                else $val.addClass('ok').text(field.matches[0].label + ' ✓');
            } else if (field.status === 'multiple') {
                $val.addClass('warn');
                if (!editMode) $val.append($('<div style="margin-bottom:4px;"></div>').text('Multiple matches found —'));
                $val.append(buildPickList(field, allCandidates, '', editMode));
            } else {
                $val.addClass('bad');
                if (!editMode) $val.append($('<div style="margin-bottom:4px;"></div>').text('"' + field.raw + '" not found —'));
                $val.append(buildPickList(field, allCandidates, '', true));
            }
            $row.append($val);
            $resultList.append($row);
        }

        function renderResult(raw, result, editMode) {
            $heardText.text(raw);
            $resultList.empty();
            appendRow('Vessel', 'vessel', result.vessel, editMode);
            appendRow('Port', 'port', result.port, editMode, getOptionCandidates('port'));
            appendRow('Client', 'client', result.client, editMode, getOptionCandidates('client'));
            appendRow('Agent', 'agent', result.agent, editMode);
            appendRow('Survey Type', 'survey', result.survey, editMode, getOptionCandidates('surveyType'));
            appendRow('Qty / Product → Remarks', 'cargo', result.cargo, editMode);
        }

        // ── Applying the confirmed values to the real form ─────────────
        function clickOption(rootKey, value, isMulti) {
            if (!value) return;
            var $root = $('.searchable-select[data-ss-root="' + rootKey + '"]');
            if (!$root.length) return;
            var $opt = $root.find('.ss-options .ss-option').filter(function () {
                return $(this).attr('data-value') === String(value);
            }).first();
            if (!$opt.length) return;
            if (isMulti) {
                if (!$opt.hasClass('ss-selected')) $opt.trigger('click');
            } else {
                $opt.trigger('click');
            }
        }

        function applyToForm(fromEditMode) {
            var vesselVal, agentVal, cargoVal, portId, clientId, surveyId;
            if (fromEditMode) {
                vesselVal = String($resultList.find('input[data-pe-field="vessel"]').val() || '').trim();
                agentVal = String($resultList.find('input[data-pe-field="agent"]').val() || '').trim();
                cargoVal = String($resultList.find('input[data-pe-field="cargo"]').val() || '').trim();
                portId = $resultList.find('[data-pe-row="port"] select').val();
                clientId = $resultList.find('[data-pe-row="client"] select').val();
                surveyId = $resultList.find('[data-pe-row="survey"] select').val();
            } else {
                vesselVal = lastResult.vessel.value;
                agentVal = lastResult.agent.value;
                cargoVal = lastResult.cargo.value;
                portId = lastResult.port.status === 'single' ? lastResult.port.matches[0].value : '';
                clientId = lastResult.client && lastResult.client.status === 'single' ? lastResult.client.matches[0].value : '';
                surveyId = lastResult.survey.status === 'single' ? lastResult.survey.matches[0].value : '';
            }

            if (vesselVal) $('#vessel_name').val(vesselVal);
            if (agentVal) $('#agent_name').val(agentVal);
            if (cargoVal) {
                var $rem = $('#remarks');
                var existing = String($rem.val() || '').trim();
                $rem.val(existing ? existing + ' | ' + cargoVal : cargoVal);
            }
            clickOption('port', portId, false);
            clickOption('client', clientId, false);
            clickOption('surveyType', surveyId, true);

            closeModal();
        }

        function hasAnyRecognizedField(parsed) {
            return !!(parsed.vessel || parsed.client || parsed.agent || parsed.port || parsed.survey || parsed.quantity || parsed.productText);
        }

        function handleParse() {
            var raw = String($emailInput.val() || '').trim();
            if (!raw) {
                showError('Nothing pasted', 'Paste the email text into the box first, then tap Parse & Fill.');
                return;
            }
            lastRawText = raw;
            var parsed = parseEmailText(raw);
            if (!hasAnyRecognizedField(parsed)) {
                showError('Nothing recognized', 'We couldn’t find any of Vessel / Client / Agent / Port / Survey Type in that text. You can still fill the form manually below.');
                return;
            }
            lastResult = buildResult(parsed);
            renderResult(raw, lastResult, false);
            $confirmBtn.html('<i class="fa-solid fa-check me-1"></i>Confirm');
            showState('peResultState');
        }

        // ── Wiring ───────────────────────────────────────────────────
        $triggerBtn.on('click', function () {
            showState('peIdleState');
            openModal();
        });
        $parseBtn.on('click', handleParse);
        $clearBtn.on('click', function () { $emailInput.val('').focus(); });
        $backBtn.on('click', function () { showState('peIdleState'); });
        $editBtn.on('click', function () {
            renderResult(lastRawText, lastResult, true);
            $confirmBtn.html('<i class="fa-solid fa-check me-1"></i>Apply');
        });
        $confirmBtn.on('click', function () {
            var fromEdit = $confirmBtn.text().indexOf('Apply') !== -1;
            applyToForm(fromEdit);
        });
        $errorRetryBtn.on('click', function () { showState('peIdleState'); });
    });
})();
