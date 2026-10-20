/**
 * Word reports: ordinal suffixes as superscript ("20th October 2026" → 20 + superscript "th").
 * Runs over the rendered .docx (PizZip) — body, headers, footers, foot/endnotes — so every
 * ordinal in the report is formatted, whether it came from a form field or the template text.
 * A suffix is only touched when it is the right one for its number (1st, 2nd, 3rd, 11th, 22nd…).
 */
(function (global) {
    var PARTS = /^word\/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$/;
    var ORDINAL = /(^|[^0-9A-Za-z])(\d+)(st|nd|rd|th)(?![A-Za-z0-9])/gi;
    // w:rPr children that come after w:vertAlign in the schema order
    var AFTER_VERT = /<w:(rtl|cs|em|lang|eastAsianLayout|specVanish|oMath)\b/;

    function rightSuffix(n, suf) {
        var v = n % 100, s = ['th', 'st', 'nd', 'rd'];
        return (s[(v - 20) % 10] || s[v] || s[0]) === suf.toLowerCase();
    }

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
            if (!rightSuffix(parseInt(m[2], 10), m[3])) continue;
            var sufStart = m.index + m[1].length + m[2].length;
            out += text.slice(last, sufStart)
                + '</w:t></w:r><w:r>' + superRPr(rPr) + '<w:t>' + m[3] + '</w:t></w:r>'
                + '<w:r>' + (rPr || '') + '<w:t xml:space="preserve">';
            last = sufStart + m[3].length;
            changed = true;
        }
        return changed ? out + text.slice(last) : null;
    }

    function processXml(xml) {
        // one run at a time; a run never nests another run
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

    global.superscriptDocxOrdinals = function (zip) {
        Object.keys(zip.files).forEach(function (name) {
            if (!PARTS.test(name)) return;
            var xml = zip.file(name).asText();
            var out = processXml(xml);
            if (out !== xml) zip.file(name, out);
        });
        return zip;
    };
    global.superscriptDocxOrdinals._processXml = processXml;
})(typeof window !== 'undefined' ? window : globalThis);
