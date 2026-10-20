/**
 * Final Word report clean-up (report_generator.php), run on the rendered .docx (PizZip) —
 * body, headers, footers, foot/endnotes — so template text and filled-in values are treated alike:
 *
 *  1. Dates in one format: "20th October 2026" (20/10/2026, 20.10.2026, 2026-10-20, 20-Oct-26,
 *     Oct 20, 2026, 20 OCT 2026 … — full month name; day first when the order is ambiguous)
 *  2. Vessel prefix once: M/V "MV. DENSA SEAL" → M/V "DENSA SEAL"
 *  3. Repeated words ("the the", "Port Port") and doubled punctuation (".." → ".", ",," → ",")
 *  4. No space before , ; : . and a / an before vowel / consonant words
 *  5. Ordinal suffixes as superscript (20 + superscript "th")
 *
 * 1–4 work on each paragraph's whole text, across Word's runs; formatting of the text that stays
 * is untouched. Fields inside text boxes nested in another paragraph are cleaned, the outer one not.
 */
(function (global) {
    var PARTS = /^word\/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$/;
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var MON_RE = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
    var VESSEL = '(?:M\\s*\\/\\s*V|M\\.\\s*V\\.?|MV\\.?)';

    /* ---------- helpers ---------- */

    function ordinal(n) {
        var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
        return n + (s[(v - 20) % 10] || s[v] || s[0]);
    }
    function monthIndex(name) {
        var k = name.slice(0, 3).toLowerCase();
        for (var i = 0; i < 12; i++) if (MONTHS[i].slice(0, 3).toLowerCase() === k) return i;
        return -1;
    }
    function year(y) {
        y = parseInt(y, 10);
        return y < 100 ? 2000 + y : y;
    }
    function validDate(d, m, y) {
        if (m < 0 || m > 11 || d < 1 || y < 1900 || y > 2100) return false;
        return d <= new Date(y, m + 1, 0).getDate();
    }
    function fmt(d, m, y) {
        return validDate(d, m, y) ? ordinal(d) + ' ' + MONTHS[m] + ' ' + y : null;
    }
    function decode(t) {
        return t.replace(/&(amp|lt|gt|quot|apos|#\d+|#x[0-9a-f]+);/gi, function (all, e) {
            var l = e.toLowerCase();
            if (l === 'amp') return '&'; if (l === 'lt') return '<'; if (l === 'gt') return '>';
            if (l === 'quot') return '"'; if (l === 'apos') return "'";
            return String.fromCharCode(l[1] === 'x' ? parseInt(l.slice(2), 16) : parseInt(l.slice(1), 10));
        });
    }
    function encode(t) {
        return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ---------- text rules: each returns edits {s, e, rep} on the paragraph text ---------- */

    function each(re, text, fn) {
        var edits = [], m;
        re.lastIndex = 0;
        while ((m = re.exec(text)) !== null) {
            var ed = fn(m);
            if (ed) edits.push(ed);
            if (m[0] === '') re.lastIndex++;
        }
        return edits;
    }

    var RULES = [
        // 2026-10-20 / 2026/10/20
        function (t) {
            return each(/(^|[^\d\/.\-])(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})(?![\d\/.\-]*\d)/g, t, function (m) {
                var r = fmt(+m[4], +m[3] - 1, +m[2]);
                return r && { s: m.index + m[1].length, e: m.index + m[0].length, rep: r };
            });
        },
        // 20/10/2026, 20-10-2026, 20.10.26 (day first unless only month-first is valid)
        function (t) {
            return each(/(^|[^\d\/.\-])(\d{1,2})([\-\/.])(\d{1,2})\3(\d{4}|\d{2})(?![\d\/.\-]*\d)/g, t, function (m) {
                var a = +m[2], b = +m[4], y = year(m[5]);
                var r = fmt(a, b - 1, y) || fmt(b, a - 1, y);
                return r && { s: m.index + m[1].length, e: m.index + m[0].length, rep: r };
            });
        },
        // 20 Oct 2026, 20th of October, 2026, 20-OCT-26, 20 Oct
        function (t) {
            var re = new RegExp('(^|[^0-9A-Za-z])(\\d{1,2})(?:st|nd|rd|th)?(?:\\s+of)?[\\s\\-.]*' + MON_RE + '\\b\\.?'
                + '((?:[\\s,.]*|[\\s,.]*-[\\s]*)(\\d{4}(?![\\d:])|(?<=-\\s*|\')\\d{2}(?![\\d:])))?', 'gi');
            return each(re, t, function (m) {
                var d = +m[2], mi = monthIndex(m[3]);
                if (!validDate(d, mi, 2000)) return null;            // leap-safe check before the year
                var e = m.index + m[0].length, y = m[5] ? year(m[5]) : null;
                if (y !== null && !validDate(d, mi, y)) { y = null; e -= m[4].length; }   // "20 Oct 0600 hrs": not a year
                return { s: m.index + m[1].length, e: e, rep: ordinal(d) + ' ' + MONTHS[mi] + (y !== null ? ' ' + y : '') };
            });
        },
        // Oct 20, 2026 / October 20th 2026
        function (t) {
            var re = new RegExp('(^|[^0-9A-Za-z])' + MON_RE + '\\.?\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s+(\\d{4})(?!\\d)', 'gi');
            return each(re, t, function (m) {
                var r = fmt(+m[3], monthIndex(m[2]), +m[4]);
                return r && { s: m.index + m[1].length, e: m.index + m[0].length, rep: r };
            });
        },
        // M/V "MV. NAME" → M/V "NAME" (second vessel prefix removed)
        function (t) {
            var re = new RegExp('\\b(' + VESSEL + ')(\\s*["“\'‘]?\\s*)' + VESSEL + '(?![A-Za-z])\\s*', 'g');
            return each(re, t, function (m) {
                return { s: m.index + m[1].length + m[2].length, e: m.index + m[0].length, rep: '' };
            });
        },
        // repeated words: "the the", "Port Port"
        function (t) {
            return each(/\b([A-Za-z]{2,})\b(?:[ \t]+\1\b)+/gi, t, function (m) {
                return { s: m.index + m[1].length, e: m.index + m[0].length, rep: '' };
            });
        },
        // doubled punctuation: ".." → "." (an ellipsis "..." stays), ",," → ",", ". ," → "."
        function (t) {
            return each(/([^.]|^)(\.\.)(?!\.)|([,;:])\s*\3|\.\s*,/g, t, function (m) {
                if (m[2]) return { s: m.index + m[1].length, e: m.index + m[1].length + 1, rep: '' };
                if (m[3]) return { s: m.index + 1, e: m.index + m[0].length, rep: '' };
                return { s: m.index + 1, e: m.index + m[0].length, rep: '' };
            });
        },
        // no space before , ; and before a sentence-ending full stop
        function (t) {
            return each(/(\S)( +)([,;]|\.(?=\s|$))/g, t, function (m) {
                if (/\d/.test(m[1]) && m[3] === '.') return null;
                return { s: m.index + 1, e: m.index + 1 + m[2].length, rep: '' };
            });
        },
        // a / an
        function (t) {
            return each(/\b(a|an|A|An)([ \t]+)([a-z][a-z\-]*)/g, t, function (m) {
                var w = m[3], vowel = /^[aeiou]/.test(w), an = m[1].toLowerCase() === 'an';
                if (/^(one|once|uni|use|usu|uti|eu|ewe|ure)/.test(w)) vowel = false;
                if (/^(hour|honest|honou?r|heir)/.test(w)) vowel = true;
                if (vowel === an) return null;
                var rep = vowel ? m[1] + 'n' : m[1].slice(0, 1);
                return { s: m.index, e: m.index + m[1].length, rep: rep };
            });
        }
    ];

    /* ---------- apply edits to a paragraph's <w:t> nodes ---------- */

    function applyEdits(nodes, edits) {
        // non-overlapping, last first so earlier offsets stay valid
        edits.sort(function (a, b) { return a.s - b.s; });
        var kept = [], end = -1;
        edits.forEach(function (e) { if (e.s >= end) { kept.push(e); end = e.e; } });
        for (var k = kept.length - 1; k >= 0; k--) {
            var ed = kept[k], off = 0, placed = false;
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i], len = n.text.length, ns = off, ne = off + len;
                off = ne;
                var last = i === nodes.length - 1;
                if (ne < ed.s || (ne === ed.s && !last && ed.e > ed.s)) continue;
                if (ns >= ed.e && placed) break;
                var a = Math.max(ed.s, ns) - ns, b = Math.min(ed.e, ne) - ns;
                n.text = n.text.slice(0, a) + (placed ? '' : ed.rep) + n.text.slice(Math.max(a, b));
                n.changed = true;
                placed = true;
            }
        }
    }

    function cleanParagraph(p) {
        var nodes = [], re = /<w:t(\s[^>]*)?>([^<]*)<\/w:t>/g, m;
        while ((m = re.exec(p)) !== null) nodes.push({ start: m.index, end: m.index + m[0].length, text: decode(m[2]), changed: false });
        if (!nodes.length) return p;
        RULES.forEach(function (rule) {
            var text = nodes.map(function (n) { return n.text; }).join('');
            var edits = rule(text);
            if (edits.length) applyEdits(nodes, edits);
        });
        var out = '', pos = 0;
        nodes.forEach(function (n) {
            out += p.slice(pos, n.start);
            out += n.changed ? '<w:t xml:space="preserve">' + encode(n.text) + '</w:t>' : p.slice(n.start, n.end);
            pos = n.end;
        });
        return out + p.slice(pos);
    }

    function cleanText(xml) {
        // innermost paragraphs (a paragraph never holds another one except through a text box)
        return xml.replace(/<w:p(?:\s[^>]*)?>(?:(?!<w:p[\s>])[\s\S])*?<\/w:p>/g, cleanParagraph);
    }

    /* ---------- superscript ordinals ---------- */

    var ORDINAL = /(^|[^0-9A-Za-z])(\d+)(st|nd|rd|th)(?![A-Za-z0-9])/gi;
    var AFTER_VERT = /<w:(rtl|cs|em|lang|eastAsianLayout|specVanish|oMath)\b/;   // rPr children after vertAlign

    function superRPr(rPr) {
        var vert = '<w:vertAlign w:val="superscript"/>';
        if (!rPr) return '<w:rPr>' + vert + '</w:rPr>';
        rPr = rPr.replace(/<w:vertAlign\b[^>]*\/>/g, '');
        var m = rPr.match(AFTER_VERT);
        if (m) return rPr.slice(0, m.index) + vert + rPr.slice(m.index);
        return rPr.replace(/<\/w:rPr>$/, vert + '</w:rPr>');
    }

    function splitText(text, rPr) {
        var out = '', last = 0, m, changed = false;
        ORDINAL.lastIndex = 0;
        while ((m = ORDINAL.exec(text)) !== null) {
            var n = parseInt(m[2], 10);
            if (ordinal(n).slice(-2) !== m[3].toLowerCase()) continue;
            var sufStart = m.index + m[1].length + m[2].length;
            out += text.slice(last, sufStart)
                + '</w:t></w:r><w:r>' + superRPr(rPr) + '<w:t>' + m[3] + '</w:t></w:r>'
                + '<w:r>' + (rPr || '') + '<w:t xml:space="preserve">';
            last = sufStart + m[3].length;
            changed = true;
        }
        return changed ? out + text.slice(last) : null;
    }

    function superscriptOrdinals(xml) {
        return xml.replace(/<w:r(?:\s[^>]*)?>(?:(?!<w:r[\s>])[\s\S])*?<\/w:r>/g, function (run) {
            var rPrM = run.match(/^<w:r(?:\s[^>]*)?>\s*(<w:rPr>[\s\S]*?<\/w:rPr>)/);
            var rPr = rPrM ? rPrM[1] : '';
            if (/<w:vertAlign w:val="superscript"\/>/.test(rPr)) return run;
            return run.replace(/<w:t(\s[^>]*)?>([^<]*)<\/w:t>/g, function (t, attrs, text) {
                var split = splitText(text, rPr);
                return split === null ? t : '<w:t xml:space="preserve">' + split + '</w:t>';
            });
        });
    }

    function processXml(xml) {
        return superscriptOrdinals(cleanText(xml));
    }

    global.polishDocxReport = function (zip) {
        Object.keys(zip.files).forEach(function (name) {
            if (!PARTS.test(name)) return;
            var xml = zip.file(name).asText();
            var out = processXml(xml);
            if (out !== xml) zip.file(name, out);
        });
        return zip;
    };
    global.polishDocxReport._processXml = processXml;
    global.polishDocxReport._cleanText = function (s) {
        return decode(cleanParagraph('<w:p><w:r><w:t>' + encode(s) + '</w:t></w:r></w:p>').replace(/<[^>]+>/g, ''));
    };
})(typeof window !== 'undefined' ? window : globalThis);
