/* Minimal Sankey renderer – pure SVG, no external dependencies */
(function (global) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';

    function svgEl(tag, attrs) {
        var e = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        return e;
    }

    function fmt(v) {
        return Number(v).toLocaleString('de-DE', { maximumFractionDigits: 2 });
    }

    function makeTooltip(container) {
        var existing = container.querySelector('.sankey-tooltip');
        if (existing) {
            return existing;
        }

        var tip = document.createElement('div');
        tip.className = 'sankey-tooltip';

        tip.style.cssText = [
            'position:absolute',
            'display:none',
            'background:rgba(15,23,42,0.88)',
            'color:#f1f5f9',
            'font-family:Segoe UI,Arial,sans-serif',
            'font-size:12px',
            'line-height:1.5',
            'padding:6px 10px',
            'border-radius:6px',
            'pointer-events:none',
            'white-space:nowrap',
            'box-shadow:0 2px 8px rgba(0,0,0,0.25)',
            'z-index:999'
        ].join(';');

        var pos = window.getComputedStyle(container).position;
        if (pos === 'static') container.style.position = 'relative';

        container.appendChild(tip);
        return tip;
    }

    function showTip(tip, svgEl, html, mouseX, mouseY) {
        tip.innerHTML = html;
        tip.style.display = 'block';
        /* Kurz warten bis Breite bekannt ist, dann positionieren */
        var tw = tip.offsetWidth  || 120;
        var th = tip.offsetHeight || 36;
        var cw = svgEl.parentElement.offsetWidth;
        var ch = svgEl.parentElement.offsetHeight;
        var x  = mouseX + 14;
        var y  = mouseY - th / 2;
        if (x + tw > cw) x = mouseX - tw - 14;
        if (y < 4)       y = 4;
        if (y + th > ch) y = ch - th - 4;
        tip.style.left = x + 'px';
        tip.style.top  = y + 'px';
    }

    function hideTip(tip) {
        tip.style.display = 'none';
    }

    function drawSankey(containerId, rows, opts) {
        var container = document.getElementById(containerId);
        if (!container) return;

        var o = Object.assign({
            nodeWidth:   18,
            nodePadding: 10,
            fontSize:    13,
            labelColor:  '#1e293b',
            linkOpacity: 0.45,
            showValues:  false,
            staticMode:  false,
            colors:      ['#2563eb','#16a34a','#ea580c','#7c3aed','#db2777',
                          '#ca8a04','#0891b2','#dc2626','#059669','#9333ea'],
            nodeColors:  {}
        }, opts);

        var W = container.offsetWidth  || 600;
        var H = container.offsetHeight || 400;

        if (!rows || !rows.length) {
            container.innerHTML = '<p style="color:#64748b;text-align:center;padding:20px;font-family:Segoe UI,Arial,sans-serif">Keine Daten &ndash; bitte Variablen konfigurieren und sicherstellen, dass deren Werte &gt; 0 sind.</p>';
            return;
        }

        /* ── Build graph ─────────────────────────────────────────────── */
        var nodeMap = Object.create(null);
        var links = rows.map(function (r) {
            var s = String(r[0]), t = String(r[1]), v = Number(r[2]), u = r[3] ? String(r[3]) : '', info = r[4] ? String(r[4]) : '';
            if (!nodeMap[s]) nodeMap[s] = { id: s, inV: 0, outV: 0, col: -1 };
            if (!nodeMap[t]) nodeMap[t] = { id: t, inV: 0, outV: 0, col: -1 };
            nodeMap[s].outV += v;
            nodeMap[t].inV  += v;
            var info = r[4] ? String(r[4]) : '';
            return { s: s, t: t, v: v, u: u, info: info };
        });
        var nodes = Object.keys(nodeMap).map(function (k) { return nodeMap[k]; });

        /* ── Assign columns ──────────────────────────────────────────── */
        nodes.forEach(function (n) { if (n.inV === 0) n.col = 0; });
        for (var pass = 0; pass <= nodes.length; pass++) {
            var changed = false;
            links.forEach(function (l) {
                var src = nodeMap[l.s], tgt = nodeMap[l.t];
                if (src.col >= 0 && tgt.col <= src.col) { tgt.col = src.col + 1; changed = true; }
            });
            if (!changed) break;
        }
        nodes.forEach(function (n) { if (n.col < 0) n.col = 0; });

        var numCols = nodes.reduce(function (m, n) { return Math.max(m, n.col + 1); }, 1);

        /* ── Group by column ─────────────────────────────────────────── */
        var cols = [];
        for (var c = 0; c < numCols; c++) cols.push([]);
        nodes.forEach(function (n) { cols[n.col].push(n); });

        /* ── Assign colors ───────────────────────────────────────────── */
        var fallbackIdx = 0;
        var cfn = Object.assign(Object.create(null), o.nodeColors);
        var seenOrder = [];
        rows.forEach(function (r) {
            [String(r[0]), String(r[1])].forEach(function (id) {
                if (seenOrder.indexOf(id) === -1) seenOrder.push(id);
            });
        });
        seenOrder.forEach(function (id) {
            if (!cfn[id]) cfn[id] = o.colors[fallbackIdx++ % o.colors.length];
        });

        /* ── X/Y positions ───────────────────────────────────────────── */
        var xStep = numCols > 1 ? (W - o.nodeWidth) / (numCols - 1) : (W - o.nodeWidth) / 2;
        nodes.forEach(function (n) { n.x = Math.round(n.col * xStep); });

        cols.forEach(function (colNodes) {
            colNodes.sort(function (a, b) { return Math.max(b.inV, b.outV) - Math.max(a.inV, a.outV); });
            var total = colNodes.reduce(function (s, n) { return s + Math.max(n.inV, n.outV); }, 0);
            var padTop = 4;
            var padBottom = 20;
            var rawAvail = H - padTop - padBottom - o.nodePadding * Math.max(colNodes.length - 1, 0);
            var avail = Math.max(20, rawAvail);
            var y = padTop;
            colNodes.forEach(function (n) {
                n.h = Math.max(4, Math.max(n.inV, n.outV) / total * avail);
                n.y = y;
                y  += n.h + o.nodePadding;
            });
        });

        /* ── Link attachment offsets ─────────────────────────────────── */
        var srcOff = Object.create(null);
        var tgtOff = Object.create(null);
        nodes.forEach(function (n) { srcOff[n.id] = n.y; tgtOff[n.id] = n.y; });

        /* ── Node-Tooltip helper ────────────────────────────────────── */
        function nodeTooltip(n, unit) {
            var u = unit ? '&nbsp;' + unit : '';
            var html = '<b>' + n.id + '</b>';
            if (o.staticMode) {
                var val = Math.max(n.inV, n.outV);
                html += '<br>' + fmt(val) + u;
            } else {
                if (n.inV  > 0) html += '<br>Eingang:&nbsp;'  + fmt(n.inV)  + u;
                if (n.outV > 0) html += '<br>Ausgang:&nbsp;' + fmt(n.outV) + u;
            }
            return html;
        }

        /* ── Render ──────────────────────────────────────────────────── */
        var svg  = svgEl('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H });
        var defs = svgEl('defs', {});
        svg.appendChild(defs);

        var tip = makeTooltip(container);

        /* Track mouse position relative to container */
        var mouseX = container.__sankeyMouseX || 0;
        var mouseY = container.__sankeyMouseY || 0;

        svg.addEventListener('mousemove', function (e) {
            var rect = container.getBoundingClientRect();
            mouseX = e.clientX - rect.left;
            mouseY = e.clientY - rect.top;

            container.__sankeyMouseX = mouseX;
            container.__sankeyMouseY = mouseY;
        });

        /* ── Links ───────────────────────────────────────────────────── */
        links.forEach(function (l, i) {
            var sn = nodeMap[l.s], tn = nodeMap[l.t];
            var lhS = sn.outV > 0 ? (l.v / sn.outV) * sn.h : 4;
            var lhT = tn.inV  > 0 ? (l.v / tn.inV)  * tn.h : 4;

            var x0 = sn.x + o.nodeWidth, y0 = srcOff[l.s];
            var x1 = tn.x,               y1 = tgtOff[l.t];
            srcOff[l.s] += lhS;
            tgtOff[l.t] += lhT;

            var cx = (x0 + x1) / 2;

            var gid  = 'sk_g' + i;
            var grad = svgEl('linearGradient', { id: gid, x1: '0%', y1: '0%', x2: '100%', y2: '0%' });
            grad.appendChild(svgEl('stop', { offset: '0%',   'stop-color': cfn[l.s] }));
            grad.appendChild(svgEl('stop', { offset: '100%', 'stop-color': cfn[l.t] }));
            defs.appendChild(grad);

            var d = [
                'M', x0, y0, 'C', cx, y0, cx, y1, x1, y1,
                'L', x1, y1 + lhT, 'C', cx, y1 + lhT, cx, y0 + lhS, x0, y0 + lhS, 'Z'
            ].join(' ');

            var path = svgEl('path', {
                d: d,
                fill: 'url(#' + gid + ')',
                'fill-opacity': o.linkOpacity,
                stroke: 'none',
                cursor: 'default'
            });

            path.addEventListener('mouseenter', function () {
                path.setAttribute('fill-opacity', Math.min(1, o.linkOpacity * 2));
                showTip(tip, svg,
                    '<b>' + l.s + '</b> &rarr; <b>' + l.t + '</b><br>' + fmt(l.v) + (l.u ? '&nbsp;' + l.u : '') + (l.info ? ' (' + l.info + ')' : ''),
                    mouseX, mouseY);
            });
            path.addEventListener('mousemove', function () {
                showTip(tip, svg,
                    '<b>' + l.s + '</b> &rarr; <b>' + l.t + '</b><br>' + fmt(l.v) + (l.u ? '&nbsp;' + l.u : '') + (l.info ? ' (' + l.info + ')' : ''),
                    mouseX, mouseY);
            });
            path.addEventListener('mouseleave', function () {
                path.setAttribute('fill-opacity', o.linkOpacity);
                hideTip(tip);
            });

            svg.appendChild(path);
        });

        /* ── Nodes + Labels ──────────────────────────────────────────── */
        nodes.forEach(function (n) {
            var rect = svgEl('rect', {
                x: n.x, y: n.y,
                width: o.nodeWidth, height: n.h,
                fill: cfn[n.id], rx: 3, ry: 3,
                cursor: 'default'
            });

            /* Einheit aus erstem ein- oder ausgehenden Link ermitteln */
            var nodeUnit = '';
            links.forEach(function(l) {
                if (!nodeUnit && (l.s === n.id || l.t === n.id)) nodeUnit = l.u;
            });
            rect.addEventListener('mouseenter', function () {
                rect.setAttribute('filter', 'brightness(1.25)');
                showTip(tip, svg, nodeTooltip(n, nodeUnit), mouseX, mouseY);
            });
            rect.addEventListener('mousemove', function () {
                showTip(tip, svg, nodeTooltip(n, nodeUnit), mouseX, mouseY);
            });
            rect.addEventListener('mouseleave', function () {
                rect.removeAttribute('filter');
                hideTip(tip);
            });

            svg.appendChild(rect);

            var onLeft = n.x < W / 2;
            var tx     = onLeft ? n.x + o.nodeWidth + 6 : n.x - 6;
            var t = svgEl('text', {
                x: tx, y: n.y + n.h / 2,
                dy: '0.35em',
                'text-anchor':  onLeft ? 'start' : 'end',
                'font-family':  'Segoe UI,Arial,sans-serif',
                'font-size':    o.fontSize,
                'font-weight':  '500',
                fill:           o.labelColor,
                'pointer-events': 'none'
            });
            t.textContent = n.id.endsWith('-')
                ? n.id.slice(0, -1)
                : n.id;

            if (o.showValues) {
                var dispVal = Math.max(n.inV, n.outV);

                var nodeInfo = '';

                links.forEach(function(l) {
                    // Keine Zusatzinfo bei Sammel-/Durchgangs-Nodes in der Mitte
                    if (n.inV > 0 && n.outV > 0) {
                        return;
                    }

                    // Links: Node ist Quelle
                    if (!nodeInfo && l.s === n.id && l.info) {
                        nodeInfo = l.info;
                    }

                    // Rechts: Node ist Ziel
                    if (!nodeInfo && l.t === n.id && l.info) {
                        nodeInfo = l.info;
                    }
                });

                var displayName = n.id.endsWith('-')
                    ? n.id.slice(0, -1)
                    : n.id;

                t.textContent =
                    displayName + '  ' +
                    fmt(dispVal) +
                    (nodeUnit ? ' ' + nodeUnit : '') +
                    (nodeInfo ? ' (' + nodeInfo + ')' : '');
            }

            svg.appendChild(t);
        });

        var oldSvg = container.querySelector('svg');

        if (oldSvg) {
            oldSvg.replaceWith(svg);
        } else {
            container.appendChild(svg);
        }

        if (!container.contains(tip)) {
            container.appendChild(tip);
        }
    }

    global.drawSankey = drawSankey;

}(window));