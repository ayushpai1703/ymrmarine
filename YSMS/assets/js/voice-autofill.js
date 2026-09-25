/**
 * Voice Auto-Fill for the Assign Vessel form — assign_vessel.php only.
 *
 * 100% browser-native (window.SpeechRecognition / webkitSpeechRecognition).
 * No external API, no server round-trip for recognition itself — speech is
 * converted to text entirely on-device by the browser. The only network
 * calls this file makes are the ones the form's own JS already makes
 * (searchable-select clicks fire the same `change` events the manual flow
 * uses, which is what triggers assign_vessel.php's existing report-number
 * preview AJAX — nothing new is added here).
 *
 * Matching strategy, and why: this schema has real master tables for
 * ports/clients/survey_types (rendered as .searchable-select widgets on
 * this page), but Vessel Name and Agent Name are plain free-text columns
 * on `surveys` — there is no vessels/agents master table to validate
 * against. So Port/Client/Survey Type get real "search the database,
 * disambiguate, or say not-found" behaviour (matched against the exact
 * option list the server already rendered into this page's DOM — no new
 * AJAX endpoint needed, and nothing here can select an id the server
 * didn't already put on the page). Vessel/Agent are filled as free text
 * and always shown for the user to verify before Confirm, same as typing
 * them by hand.
 *
 * Nothing is written into the real form fields until the user presses
 * Confirm (or Apply, from Edit mode) — Speak Again / Edit / closing the
 * modal never touches the actual form.
 */
(function () {
    'use strict';

    // Kept as its own top-level constant so a future language (Telugu/Hindi)
    // is a one-line change — see the task notes for this feature.
    var VOICE_LANGUAGE = 'en-IN';

    $(document).ready(function () {
        var $form = $('#assignVesselForm');
        if (!$form.length || !document.getElementById('voiceFillModal')) return;

        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        var recognition = null;
        var isListening = false;
        var recognitionErrored = false;
        var finalTranscript = '';
        var lastRawText = '';
        var lastResult = null;

        var $triggerBtn = $('#voiceFillTriggerBtn');
        var $micBtn = $('#vfMicBtn');
        var $stopBtn = $('#vfStopBtn');
        var $liveTranscript = $('#vfLiveTranscript');
        var $heardText = $('#vfHeardText');
        var $resultList = $('#vfResultList');
        var $speakAgainBtn = $('#vfSpeakAgainBtn');
        var $editBtn = $('#vfEditBtn');
        var $confirmBtn = $('#vfConfirmBtn');
        var $processingSub = $('#vfProcessingState .vf-sub');
        var $errorTitle = $('#vfErrorTitle');
        var $errorMsg = $('#vfErrorMsg');
        var $errorRetryBtn = $('#vfErrorRetryBtn');
        var modalEl = document.getElementById('voiceFillModal');
        var bsModal = null;

        function showState(id) {
            $('.vf-state').removeClass('active');
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
            showState('vfErrorState');
            openModal();
        }

        $(modalEl).on('hidden.bs.modal', function () {
            if (isListening) stopListening();
        });

        // ── Browser support ────────────────────────────────────────────
        if (!SR) {
            $triggerBtn.on('click', function () {
                showError(
                    'Voice input isn\'t supported here',
                    'Voice input is not supported in this browser. Please use Google Chrome or Microsoft Edge. The form below still works exactly as before — just fill it in manually.'
                );
            });
            return;
        }

        // ── Text normalisation + fuzzy matching ────────────────────────
        function normalize(s) {
            return String(s || '')
                .toLowerCase()
                .replace(/[.,;:!?"'()]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }
        function cleanFreeText(s) {
            return String(s || '').replace(/[.,;]+$/, '').trim();
        }
        function titleCase(s) {
            return String(s || '').replace(/\w\S*/g, function (t) {
                return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase();
            });
        }
        function levenshtein(a, b) {
            var m = a.length, n = b.length, i, j;
            var dp = [];
            for (i = 0; i <= m; i++) dp[i] = [i];
            for (j = 0; j <= n; j++) dp[0][j] = j;
            for (i = 1; i <= m; i++) {
                for (j = 1; j <= n; j++) {
                    dp[i][j] = a[i - 1] === b[j - 1]
                        ? dp[i - 1][j - 1]
                        : 1 + Math.min(dp[i - 1][j - 1], dp[i - 1][j], dp[i][j - 1]);
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
        // into the page (real client_id/port_id/survey_type_id values) —
        // this is the "search the database" step; it's just already sitting
        // in the DOM instead of needing a fresh AJAX round-trip.
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
        function removeSubstring(text, sub) {
            var idx = text.toLowerCase().indexOf(sub.toLowerCase());
            if (idx === -1) return text;
            return (text.slice(0, idx) + ' ' + text.slice(idx + sub.length)).replace(/\s+/g, ' ').trim();
        }

        // ── Transcript parsing ──────────────────────────────────────────
        // Longer/more specific phrases listed first within each field so
        // e.g. "shipping agent" is recognised before the bare "agent".
        var FIELD_KEYWORDS = [
            { field: 'vessel', words: ['vessel name', 'ship name', 'vessel', 'ship'] },
            { field: 'port', words: ['port name', 'port', 'location', 'berth', 'anchorage'] },
            { field: 'client', words: ['client name', 'client', 'principal', 'customer'] },
            { field: 'agent', words: ['shipping agent', 'local agent', 'agent name', 'agent'] },
            { field: 'survey', words: ['survey type', 'job type', 'survey'] },
            { field: 'quantity', words: ['quantity', 'qty', 'amount'] },
            { field: 'product', words: ['product', 'grade', 'fuel', 'cargo'] }
        ];
        var QTY_RE = /(\d+(?:\.\d+)?)\s*(metric\s*tons?|mts?\b|tons?\b|kgs?\b|kilograms?\b|liters?\b|litres?\b|kls?\b)/i;

        function matchLeadingKeyword(segment) {
            var norm = segment.trim();
            var lower = norm.toLowerCase();
            for (var i = 0; i < FIELD_KEYWORDS.length; i++) {
                var group = FIELD_KEYWORDS[i];
                for (var j = 0; j < group.words.length; j++) {
                    var kw = group.words[j];
                    if (lower === kw || lower.indexOf(kw + ' ') === 0) {
                        var rest = norm.slice(kw.length).trim();
                        rest = rest.replace(/^(is|are|=|:|-)\s*/i, '').trim();
                        return { field: group.field, value: rest };
                    }
                }
            }
            return null;
        }

        function tryAssignTemplate(text) {
            var m = text.match(/assign\s+(.+?)\s+at\s+(.+?)\s+for\s+(.+?)\s+through\s+(.+?)\s*$/i);
            if (!m) return null;
            return { vessel: m[1].trim(), port: m[2].trim(), client: m[3].trim(), agent: m[4].trim() };
        }

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

        function parseTranscript(raw) {
            var out = { vessel: '', port: '', client: '', agent: '', survey: '', quantity: '', unit: '', productText: '', _unclaimed: [], _needsVocabScan: false };

            var tmpl = tryAssignTemplate(raw);
            if (tmpl) {
                out.vessel = tmpl.vessel; out.port = tmpl.port; out.client = tmpl.client; out.agent = tmpl.agent;
            }

            var segments = raw.split(/\s*(?:,|;|\.(?!\d)|\band\b)\s*/i).map(function (s) { return s.trim(); }).filter(Boolean);
            var hadAnyKeyword = !!tmpl;
            segments.forEach(function (seg) {
                var m = matchLeadingKeyword(seg);
                if (m) {
                    hadAnyKeyword = true;
                    if (m.value) {
                        if (m.field === 'vessel' && !out.vessel) out.vessel = m.value;
                        else if (m.field === 'port' && !out.port) out.port = m.value;
                        else if (m.field === 'client' && !out.client) out.client = m.value;
                        else if (m.field === 'agent' && !out.agent) out.agent = m.value;
                        else if (m.field === 'survey' && !out.survey) out.survey = m.value;
                        else if (m.field === 'product' && !out.productText) out.productText = m.value;
                        else out._unclaimed.push(seg);
                    }
                } else {
                    out._unclaimed.push(seg);
                }
            });

            var qty = extractQuantity(raw);
            if (qty) {
                out.quantity = qty.quantity;
                out.unit = qty.unit;
                if (!out.productText) {
                    var segWithQty = segments.filter(function (s) { return s.toLowerCase().indexOf(qty.matchText.toLowerCase()) !== -1; })[0] || raw;
                    var idx = segWithQty.toLowerCase().indexOf(qty.matchText.toLowerCase());
                    if (idx !== -1) {
                        var trailing = segWithQty.slice(idx + qty.matchText.length).trim();
                        if (trailing) out.productText = trailing;
                    }
                }
            }

            var foundCount = [out.vessel, out.port, out.client, out.agent].filter(Boolean).length;
            out._needsVocabScan = !hadAnyKeyword && foundCount === 0;
            return out;
        }

        function vocabScanFallback(raw, portCandidates, clientCandidates) {
            var text = raw;
            var port = bestSubstringMatch(text, portCandidates);
            if (port) text = removeSubstring(text, port.matchedText);
            var client = bestSubstringMatch(text, clientCandidates);
            if (client) text = removeSubstring(text, client.matchedText);
            var words = text.replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
            var vessel = '', agent = '';
            if (words.length) {
                var half = Math.ceil(words.length / 2);
                vessel = words.slice(0, half).join(' ');
                agent = words.slice(half).join(' ');
            }
            return { port: port, client: client, vessel: vessel, agent: agent };
        }

        // ── Build the confirmation result from a parsed transcript ─────
        function matchField(spokenText, candidates) {
            var clean = cleanFreeText(spokenText);
            if (!clean) return { status: 'empty', raw: '', matches: [] };
            var m = matchCandidates(clean, candidates);
            return { status: m.status, raw: clean, matches: m.matches };
        }

        function buildResult(raw, parsed) {
            var portCandidates = getOptionCandidates('port');
            var clientCandidates = getOptionCandidates('client');
            var surveyCandidates = getOptionCandidates('surveyType');
            var clientFieldExists = $('.searchable-select[data-ss-root="client"]').length > 0;

            if (parsed._needsVocabScan) {
                var vs = vocabScanFallback(raw, portCandidates, clientFieldExists ? clientCandidates : []);
                if (!parsed.vessel) parsed.vessel = vs.vessel;
                if (!parsed.agent) parsed.agent = vs.agent;
                if (!parsed.port && vs.port) parsed.port = vs.port.label;
                if (!parsed.client && vs.client) parsed.client = vs.client.label;
            }
            if (!parsed.survey && parsed._unclaimed.length) {
                for (var i = 0; i < parsed._unclaimed.length; i++) {
                    var m = matchCandidates(parsed._unclaimed[i], surveyCandidates);
                    if (m.status === 'single' || m.status === 'multiple') { parsed.survey = parsed._unclaimed[i]; break; }
                }
            }

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

        // ── Rendering the confirmation panel ───────────────────────────
        function buildPickList(field, candidates, selectedValue, forceFullList) {
            var $sel = $('<select></select>').css({ width: '100%', border: '1px solid var(--border-color)', borderRadius: '8px', padding: '6px 8px', fontSize: '12.5px' });
            $sel.append($('<option value="">— Select —</option>'));
            // Edit mode always offers every real option (the user may want a
            // port/client/survey type voice matching never suggested); the
            // inline "multiple matches" picker deliberately narrows to the
            // close-scoring candidates only, to keep that list short.
            var source = (!forceFullList && field.matches && field.matches.length) ? field.matches : candidates;
            source.forEach(function (c) {
                var $o = $('<option></option>').attr('value', c.value).text(c.label);
                if (selectedValue && String(c.value) === String(selectedValue)) $o.prop('selected', true);
                $sel.append($o);
            });
            return $sel;
        }

        function appendRow(label, key, field, editMode, allCandidates) {
            if (!field) return; // e.g. client field doesn't exist for a locked Client-role user
            var $row = $('<div class="vf-result-row"></div>');
            $row.append($('<div class="vf-result-label"></div>').text(label));
            var $val = $('<div class="vf-result-value"></div>').attr('data-vf-row', key);

            if (field.free) {
                if (editMode) {
                    $val.append($('<input type="text">').attr('data-vf-field', key).val(field.value));
                } else if (field.value) {
                    $val.addClass('ok').text(field.value + ' ✓');
                } else {
                    $val.addClass('bad').text('Not captured');
                }
            } else if (field.status === 'empty') {
                if (editMode) {
                    $val.append(buildPickList(field, allCandidates, '', true));
                } else {
                    $val.addClass('bad').text('Not mentioned');
                }
            } else if (field.status === 'single') {
                if (editMode) {
                    $val.append(buildPickList(field, allCandidates, field.matches[0].value, true));
                } else {
                    $val.addClass('ok').text(field.matches[0].label + ' ✓');
                }
            } else if (field.status === 'multiple') {
                $val.addClass('warn');
                if (!editMode) $val.append($('<div style="margin-bottom:4px;"></div>').text('Multiple matches found —'));
                $val.append(buildPickList(field, allCandidates, '', editMode));
            } else { // none
                $val.addClass('bad');
                if (!editMode) $val.append($('<div style="margin-bottom:4px;"></div>').text('"' + field.raw + '" not found —'));
                $val.append(buildPickList(field, allCandidates, ''));
            }
            $row.append($val);
            $resultList.append($row);
        }

        function renderResult(raw, result, editMode) {
            $heardText.text('"' + raw + '"');
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
                vesselVal = String($resultList.find('input[data-vf-field="vessel"]').val() || '').trim();
                agentVal = String($resultList.find('input[data-vf-field="agent"]').val() || '').trim();
                cargoVal = String($resultList.find('input[data-vf-field="cargo"]').val() || '').trim();
                portId = $resultList.find('[data-vf-row="port"] select').val();
                clientId = $resultList.find('[data-vf-row="client"] select').val();
                surveyId = $resultList.find('[data-vf-row="survey"] select').val();
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

        // ── Speech recognition plumbing ─────────────────────────────────
        function createRecognition() {
            recognition = new SR();
            recognition.lang = VOICE_LANGUAGE;
            recognition.continuous = false;
            recognition.interimResults = true;
            recognition.maxAlternatives = 1;

            recognition.onstart = function () {
                isListening = true;
                recognitionErrored = false;
                finalTranscript = '';
                $liveTranscript.html('&nbsp;');
                showState('vfListeningState');
            };
            recognition.onresult = function (event) {
                var interim = '';
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    var piece = event.results[i][0].transcript;
                    if (event.results[i].isFinal) finalTranscript += piece;
                    else interim += piece;
                }
                var shown = (finalTranscript + interim).trim();
                $liveTranscript.text(shown ? '"' + shown + '"' : ' ');
            };
            recognition.onerror = function (event) {
                isListening = false;
                recognitionErrored = true;
                var err = event.error;
                if (err === 'not-allowed' || err === 'permission-denied' || err === 'service-not-allowed') {
                    showError('Microphone permission denied', 'Please allow microphone access for this site, then try again. The form below stays fully usable — just fill it in manually.');
                } else if (err === 'no-speech') {
                    showError('No speech detected', 'We didn’t catch anything. Tap the mic and try again, speaking clearly.');
                } else if (err === 'audio-capture') {
                    showError('No microphone found', 'Please connect a microphone and try again.');
                } else if (err === 'network') {
                    showError('Network error', 'Voice recognition needs an internet connection. Please check your connection and try again.');
                } else {
                    showError('Voice recognition error', 'Something went wrong (' + err + '). Please try again or fill the form manually.');
                }
            };
            recognition.onend = function () {
                isListening = false;
                if (recognitionErrored) return;
                var text = finalTranscript.trim();
                if (!text) {
                    showError('No speech detected', 'We didn’t catch anything. Tap the mic and try again, speaking clearly.');
                    return;
                }
                processTranscript(text);
            };
        }
        function startListening() {
            if (!recognition) createRecognition();
            try { recognition.start(); } catch (e) { /* already running — ignore */ }
        }
        function stopListening() {
            try { recognition.stop(); } catch (e) { /* not running — ignore */ }
        }

        function processTranscript(raw) {
            showState('vfProcessingState');
            $processingSub.text('Voice captured successfully. Matching against your Ports, Clients & Survey Types…');
            lastRawText = raw;
            // Small delay purely so "Processing" is perceptible — matching
            // itself is synchronous (all data is already in the page's DOM).
            setTimeout(function () {
                var parsed = parseTranscript(raw);
                lastResult = buildResult(raw, parsed);
                renderResult(raw, lastResult, false);
                $confirmBtn.html('<i class="fa-solid fa-check me-1"></i>Confirm');
                showState('vfResultState');
            }, 350);
        }

        // ── Wiring ───────────────────────────────────────────────────
        $triggerBtn.on('click', function () {
            $confirmBtn.html('<i class="fa-solid fa-check me-1"></i>Confirm');
            showState('vfIdleState');
            openModal();
        });
        $micBtn.on('click', startListening);
        $stopBtn.on('click', stopListening);
        $speakAgainBtn.on('click', function () { showState('vfIdleState'); });
        $editBtn.on('click', function () {
            renderResult(lastRawText, lastResult, true);
            $confirmBtn.html('<i class="fa-solid fa-check me-1"></i>Apply');
        });
        $confirmBtn.on('click', function () {
            var fromEdit = $confirmBtn.text().indexOf('Apply') !== -1;
            applyToForm(fromEdit);
        });
        $errorRetryBtn.on('click', function () { showState('vfIdleState'); });
    });
})();
