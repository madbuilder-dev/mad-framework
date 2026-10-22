    {{-- Peças comuns de <mad-chart> e <mad-db-chart>, definidas uma vez por página:
         - __madCssColor: cor CSS que o ECharts não lê (color-mix, oklch…) vira rgb;
         - __madChartAutoPalette: as 12 cores automáticas do tema (--mad-chart-auto-N)
           prontas para o fundo do card: contraste de 3:1, cores vizinhas que não
           se confundem, só a família da marca e neutros — nada no matiz de
           sucesso, aviso ou alerta (fw#499) — e, com marca no tom do alerta
           (vermelha), os neutros na frente (fw#498);
         - __madChartTheme: tema da marca quando o tema do app pede
           (`--mad-chart-theme: brand`, ligado no preset ERP) ou o gráfico força
           (`theme="brand"`); null = tema clássico do ECharts, como sempre;
         - __madChartFit: rótulo de pizza/donut que não cabe ao lado da fatia sai
           do desenho e vai para a legenda (ao lado, ou embaixo no estreito),
           com o mesmo texto — o ECharts cortava ("Próximo do ..."). --}}
    if (!window.__madCssColor) {
        window.__madCssColor = function (v) {
            if (typeof v !== 'string') return v;
            var s = v.trim();
            if (!/^(color-mix|color|oklch|oklab|lab|lch|hwb|light-dark)\(/i.test(s)) return s;
            var cache = window.__madCssColorCache || (window.__madCssColorCache = {});
            if (cache[s]) return cache[s];
            try {
                if (window.CSS && CSS.supports && !CSS.supports('color', s)) return s;
                var cv = window.__madCssColorCv || (window.__madCssColorCv = document.createElement('canvas'));
                cv.width = cv.height = 1;
                var ctx = cv.getContext('2d', { willReadFrequently: true });
                ctx.clearRect(0, 0, 1, 1);
                ctx.fillStyle = s;
                ctx.fillRect(0, 0, 1, 1);
                var d = ctx.getImageData(0, 0, 1, 1).data;
                return (cache[s] = d[3] === 255
                    ? 'rgb(' + d[0] + ',' + d[1] + ',' + d[2] + ')'
                    : 'rgba(' + d[0] + ',' + d[1] + ',' + d[2] + ',' + (d[3] / 255).toFixed(3) + ')');
            } catch (e) {
                return s;
            }
        };
    }

    if (!window.__madChartAutoPalette) {
        window.__madChartAutoPalette = function (el) {
            var cs = window.getComputedStyle(el || document.documentElement);
            var raw = [];
            for (var i = 1; i <= 8; i++) {
                var v = cs.getPropertyValue('--mad-chart-auto-' + i).trim();
                if (!v) return null;
                raw.push(v);
            }
            var rgb = function (v) {
                var s = String(window.__madCssColor(v) || '').trim(), m;
                if (!/^(#|rgb)/i.test(s)) {
                    try {
                        var c2 = (window.__madCssColorCv || (window.__madCssColorCv = document.createElement('canvas'))).getContext('2d');
                        c2.fillStyle = '#000'; c2.fillStyle = s; s = String(c2.fillStyle);
                    } catch (e) {}
                }
                if ((m = s.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i))) {
                    var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
                    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
                }
                m = s.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,/]\s*([\d.]+)\s*)?\)$/i);
                return m && (m[4] === undefined || +m[4] >= 1) ? [+m[1], +m[2], +m[3]] : null;
            };
            // Fundo de verdade: o do 1º ancestral pintado (o card), senão o do tema.
            var bg = null;
            for (var n = el; n && n.nodeType === 1 && !bg; n = n.parentElement) bg = rgb(window.getComputedStyle(n).backgroundColor || '');
            bg = bg || rgb(cs.getPropertyValue('--mad-bg').trim()) || [255, 255, 255];
            var primary = rgb(cs.getPropertyValue('--mad-primary').trim());
            var danger = rgb(cs.getPropertyValue('--mad-danger').trim());
            var key = raw.join('|') + '|' + bg + '|' + primary + '|' + danger;
            var cache = window.__madChartPaletteCache || (window.__madChartPaletteCache = {});
            if (cache[key]) return cache[key].slice();

            var lin = function (x) { x /= 255; return x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
            var lum = function (c) { return 0.2126 * lin(c[0]) + 0.7152 * lin(c[1]) + 0.0722 * lin(c[2]); };
            var lab = function (c) {
                var r = lin(c[0]), g = lin(c[1]), b = lin(c[2]);
                var l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
                var m2 = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
                var s2 = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
                return [0.2104542553 * l + 0.7936177850 * m2 - 0.0040720468 * s2, 1.9779984951 * l - 2.4285922050 * m2 + 0.4505937099 * s2, 0.0259040371 * l + 0.7827717662 * m2 - 0.8086757660 * s2];
            };
            var dist = function (a, b) { var x = lab(a), y = lab(b); return Math.sqrt(Math.pow(x[0] - y[0], 2) + Math.pow(x[1] - y[1], 2) + Math.pow(x[2] - y[2], 2)); };
            var hueSat = function (c) {
                var r = c[0] / 255, g = c[1] / 255, b = c[2] / 255, mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
                if (!d) return [0, 0];
                var h = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
                return [(h * 60 + 360) % 360, d / (1 - Math.abs(mx + mn - 1))];
            };
            // OKLab → sRGB; fora da gama, perde croma até caber.
            var fromLab = function (L, A, B) {
                for (var k = 0; ; k++) {
                    var l = Math.pow(L + 0.3963377774 * A + 0.2158037573 * B, 3);
                    var m3 = Math.pow(L - 0.1055613458 * A - 0.0638541728 * B, 3);
                    var s3 = Math.pow(L - 0.0894841775 * A - 1.2914855480 * B, 3);
                    var c = [4.0767416621 * l - 3.3077115913 * m3 + 0.2309699292 * s3, -1.2684380046 * l + 2.6097574011 * m3 - 0.3413193965 * s3, -0.0041960863 * l - 0.7034186147 * m3 + 1.7076147010 * s3];
                    if (k >= 14 || c.every(function (x) { return x > -0.001 && x < 1.001; })) {
                        return c.map(function (x) { x = Math.min(1, Math.max(0, x)); return 255 * (x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055); });
                    }
                    A *= 0.8; B *= 0.8;
                }
            };
            // Mesma cor (matiz e croma), mais clara ou mais escura até a luminância pedida.
            var toLum = function (c, y) {
                var o = lab(c), up = lum(c) < y, lo = up ? o[0] : 0, hi = up ? 1 : o[0];
                for (var k = 0; k < 24; k++) { var L = (lo + hi) / 2; if (lum(fromLab(L, o[1], o[2])) < y) lo = L; else hi = L; }
                return fromLab(up ? hi : lo, o[1], o[2]);
            };
            // Contraste de 3:1 com o fundo (elemento gráfico, WCAG 1.4.11), com folga para o arredondamento.
            var yb = lum(bg), dark = yb < 0.18;
            var yMin = dark ? 3 * (yb + 0.05) - 0.05 + 0.004 : 0;
            var yMax = dark ? 1 : (yb + 0.05) / 3 - 0.05 - 0.004;
            var fit = function (c) { var y = lum(c); return y > yMax ? toLum(c, yMax) : y < yMin ? toLum(c, yMin) : c; };

            var cols = raw.map(rgb);
            // Croma e matiz (OKLCH): neutro = croma baixo; família da marca = matiz a até 30° dela.
            var lch = function (c) { var o = lab(c); return [o[0], Math.sqrt(o[1] * o[1] + o[2] * o[2]), (Math.atan2(o[2], o[1]) * 180 / Math.PI + 360) % 360]; };
            var hueGap = function (a, b) { var d = Math.abs(a - b); return Math.min(d, 360 - d); };
            // A marca é a 1ª cor automática do tema (os tokens resolvem no body;
            // o --mad-primary do contêiner do gráfico pode ser outro); neutra ou
            // ausente, vale o --mad-primary.
            var brand = cols[0] && lch(cols[0])[1] >= 0.06 ? cols[0] : primary;
            var pl = brand ? lch(brand) : null;
            var neutral = function (i) {
                return (dark ? [[203, 213, 225], [100, 116, 139], [148, 163, 184]] : [[51, 65, 85], [148, 163, 184], [100, 116, 139]])[i % 3];
            };
            var isNeutral = function (c) { return lch(c)[1] < 0.06; };
            // Só a família da marca e neutros (fw#499): verde, âmbar, vermelho e o
            // azul de informação são status — entram por `colors`, tom explícito
            // ou farol, nunca por acaso na paleta automática.
            cols = cols.map(function (c) {
                return c && (isNeutral(c) || (pl && pl[1] >= 0.06 && hueGap(lch(c)[2], pl[2]) <= 30)) ? c : null;
            });
            // Marca no tom do alerta: a 1ª série não pode parecer erro. Quatro
            // neutros na frente (grafite, ardósia, os cinzas do tema), a marca depois.
            var dl = danger ? lch(danger) : null;
            if (pl && dl && pl[1] >= 0.1 && dl[1] >= 0.1 && hueGap(pl[2], dl[2]) <= 25) {
                var neu = [neutral(0), neutral(1)], fam = [];
                cols.forEach(function (c) { if (c) (isNeutral(c) ? neu : fam).push(c); });
                for (var q = 2; neu.length < 4; q++) neu.push(neutral(q));
                cols = neu.concat(fam).slice(0, 8);
            }
            // 12 cores: o que faltar (status tirado, 9ª–12ª) nasce da família —
            // marca e neutro alternados — e a passada abaixo varia a luminosidade.
            for (var f = 0; f < 12; f++) if (!cols[f]) cols[f] = f >= 8 && cols[f - 8] ? cols[f - 8] : (f % 2 && brand ? brand : neutral(f));
            // Variação de luminosidade sem chegar ao preto (claro) nem ao branco (escuro).
            var Llo = dark ? 0 : 0.3, Lhi = dark ? 0.9 : 1;
            // Cor igual (ou quase) a uma anterior anda na luminosidade, dentro do
            // contraste de 3:1 com o card, até se separar.
            var out = new Array(12), done = [];
            var steps = [0, -0.14, 0.14, -0.26, 0.26, -0.07, 0.07, -0.2, 0.2, -0.33, 0.33];
            cols.forEach(function (c, i) {
                var o = lab(c), best = null, bestD = -1;
                steps.some(function (d) {
                    var cand = fit(d ? fromLab(Math.min(Lhi, Math.max(Llo, o[0] + d)), o[1], o[2]) : c);
                    var md = done.reduce(function (a2, p) { return Math.min(a2, dist(cand, p)); }, 9);
                    if (md > bestD) { best = cand; bestD = md; }
                    return md >= 0.08;
                });
                done.push(best);
                out[i] = 'rgb(' + Math.round(best[0]) + ',' + Math.round(best[1]) + ',' + Math.round(best[2]) + ')';
            });
            cache[key] = out;
            return out.slice();
        };
    }

    if (!window.__madChartTheme) {
        window.__madChartTheme = function (el, pref, isDark) {
            var cs = window.getComputedStyle(el);
            var mode = pref === 'brand' || pref === 'classic' ? pref : (cs.getPropertyValue('--mad-chart-theme').trim() || 'classic');
            if (mode !== 'brand') return null;
            var col = function (name, fb) {
                var v = cs.getPropertyValue(name).trim();
                return v ? window.__madCssColor(v) : fb;
            };
            var text    = col('--mad-text', isDark ? '#e4e4e7' : '#1a1d21');
            var muted   = col('--mad-text-muted', isDark ? '#a1a1aa' : '#6b7280');
            var axis    = col('--mad-chart-axis', isDark ? '#3f3f46' : '#e6e8ec');
            var grid    = col('--mad-chart-grid', isDark ? '#27272a' : '#eef0f3');
            var surface = col('--mad-bg', isDark ? '#18181b' : '#ffffff');
            var font    = cs.fontFamily || 'sans-serif';
            var palette = window.__madChartAutoPalette(el);
            if (!palette) {
                palette = [];
                for (var i = 1; i <= 8; i++) {
                    var c = col('--mad-chart-' + i, '');
                    if (c) palette.push(c);
                }
            }
            return {
                color: palette,
                backgroundColor: 'transparent',
                textStyle: { fontFamily: font, color: text },
                title: { textStyle: { color: text, fontFamily: font, fontWeight: 600 }, subtextStyle: { color: muted, fontFamily: font } },
                legend: { textStyle: { color: muted, fontFamily: font, fontSize: 12 }, icon: 'roundRect', itemWidth: 10, itemHeight: 10, itemGap: 14 },
                tooltip: {
                    backgroundColor: surface, borderColor: axis, borderWidth: 1, padding: [8, 10],
                    textStyle: { color: text, fontFamily: font, fontSize: 12 },
                    extraCssText: 'box-shadow:0 4px 12px rgba(16,24,40,.08);border-radius:8px;'
                },
                categoryAxis: {
                    axisLine: { show: true, lineStyle: { color: axis } }, axisTick: { show: false },
                    axisLabel: { color: muted, fontFamily: font }, splitLine: { show: false, lineStyle: { color: [grid] } }
                },
                valueAxis: {
                    axisLine: { show: false, lineStyle: { color: axis } }, axisTick: { show: false },
                    axisLabel: { color: muted, fontFamily: font }, splitLine: { show: true, lineStyle: { color: [grid] } }
                },
                // Rótulo na cor do texto do tema, sem o contorno branco do
                // ECharts (no escuro virava um halo em volta da letra).
                bar: { barMaxWidth: 48, label: { color: text, textBorderWidth: 0 } },
                line: { symbolSize: 6, lineStyle: { width: 2 }, label: { color: text, textBorderWidth: 0 } },
                pie: { itemStyle: { borderColor: surface, borderWidth: 1 }, label: { color: text, textBorderWidth: 0 } },
                __madFont: font
            };
        };
    }

    if (!window.__madChartFit) {
        window.__madChartFit = function (opt, el, font) {
            var W = el.clientWidth, H = el.clientHeight;
            var series = opt && opt.series;
            if (!W || !H || !Array.isArray(series)) return { option: opt, key: 'none' };
            var min = Math.min(W, H) / 2;
            var px = function (v, of) {
                if (typeof v === 'string' && v.slice(-1) === '%') return parseFloat(v) / 100 * of;
                return +v || 0;
            };
            var cv = window.__madChartMeasureCv || (window.__madChartMeasureCv = document.createElement('canvas'));
            var ctx = cv.getContext('2d');
            var fam = font || 'sans-serif';

            for (var si = 0; si < series.length; si++) {
                var s = series[si];
                if (!s || s.type !== 'pie' || !s.label || s.label.show === false) continue;
                if (s.label.position && s.label.position !== 'outside' && s.label.position !== 'outer') continue;
                var data = Array.isArray(s.data) ? s.data : [];
                if (!data.length) continue;

                var sum = 0;
                data.forEach(function (d) { sum += +(d && typeof d === 'object' ? d.value : d) || 0; });
                ctx.font = (s.label.fontSize || 12) + 'px ' + fam;
                var texts = {}, maxW = 0, widths = [];
                data.forEach(function (d, i) {
                    var name = d && typeof d === 'object' ? String(d.name) : String(i);
                    var value = d && typeof d === 'object' ? d.value : d;
                    var p = { name: name, value: value, percent: sum ? +(value / sum * 100).toFixed(2) : 0, dataIndex: i, data: d, seriesIndex: si, seriesName: s.name };
                    var f = s.label.formatter, t;
                    try {
                        t = typeof f === 'function' ? f(p)
                            : typeof f === 'string' ? f.replace(/\{b\}/g, name).replace(/\{c\}/g, value).replace(/\{d\}/g, p.percent)
                            : name;
                    } catch (e) { t = name; }
                    t = String(t == null ? name : t);
                    texts[name] = t;
                    var w = Math.ceil(ctx.measureText(t).width) + 4;
                    widths.push(w);
                    if (w > maxW) maxW = w;
                });

                var radius = Array.isArray(s.radius) ? s.radius : [0, s.radius == null ? '75%' : s.radius];
                var rOut = px(radius[1], min);
                var center = Array.isArray(s.center) ? s.center : ['50%', '50%'];
                var cx = px(center[0], W);
                var ll = s.labelLine || {};
                var lead = (ll.length != null ? +ll.length : 15) + (ll.length2 != null ? +ll.length2 : 15) + 5 + 4;
                if (maxW <= Math.min(cx, W - cx) - rOut - lead) continue;

                // Não cabe: sem rótulo no desenho, texto inteiro na legenda.
                var lg = opt.legend && opt.legend.show ? opt.legend : null;
                // Legenda ao lado ocupa até meia largura (texto maior quebra a linha).
                var textW = Math.min(maxW, Math.max(80, W * 0.5 - 18));
                var side = lg ? (lg.orient === 'vertical' || lg.right !== undefined) : (W - (textW + 18) - 24 >= 150);
                var out = Object.assign({}, opt);
                // Largura fixa só quando o texto passa do limite: a quebra do
                // ECharts mede letra a letra e quebrava texto que cabia.
                var wrapAt = function (legend, w) {
                    var ts = Object.assign({}, (legend && legend.textStyle) || {});
                    if (w > 0) { ts.width = Math.floor(w); ts.overflow = 'break'; }
                    return ts;
                };
                var ratio = rOut > 0 ? 1 / rOut : 0;
                var place, key;
                if (side) {
                    var area = W - (textW + 18) - 24;
                    var R = Math.max(30, Math.min(area / 2 - 6, H / 2 - 12, rOut));
                    place = { cx: 8 + area / 2, cy: H / 2, R: R };
                    var lines = data.length * (maxW > textW ? 2 : 1);
                    out.legend = Object.assign({}, lg || {}, {
                        show: true, orient: 'vertical', right: 8, left: 'auto', top: 'middle', bottom: 'auto',
                        type: lines * 22 > H - 16 ? 'scroll' : 'plain',
                        textStyle: wrapAt(lg, maxW > textW ? textW : 0)
                    });
                    key = 'side:' + Math.round(textW) + ':' + Math.round(R);
                } else {
                    var rowW = W - 16, rows = 1, cur = 0;
                    widths.forEach(function (w) {
                        var item = Math.min(w, rowW - 18) + 18 + 14;
                        if (cur && cur + item > rowW) { rows++; cur = 0; }
                        cur += item;
                    });
                    var legendH = rows * 22 + 8;
                    var R2 = Math.max(30, Math.min(W / 2 - 12, (H - legendH) / 2 - 8, rOut));
                    place = { cx: W / 2, cy: (H - legendH) / 2, R: R2 };
                    out.legend = Object.assign({}, lg || {}, {
                        show: true, orient: 'horizontal', left: 'center', right: 'auto', top: 'auto', bottom: 4, type: 'plain',
                        textStyle: wrapAt(lg, maxW > rowW - 32 ? rowW - 32 : 0)
                    });
                    key = 'below:' + rows + ':' + Math.round(R2);
                }
                out.legend.formatter = function (name) { return texts[name] !== undefined ? texts[name] : name; };

                // Todas as séries do mesmo centro (texto do centro, meta no anel) andam juntas.
                var k = place.R * ratio;
                out.series = series.map(function (t) {
                    if (!t || (t.type !== 'pie' && t.type !== 'gauge')) return t;
                    var tc = Array.isArray(t.center) ? t.center : ['50%', '50%'];
                    if (px(tc[0], W) !== cx) return t;
                    var c = Object.assign({}, t, { center: [place.cx, place.cy] });
                    var r = Array.isArray(t.radius) ? t.radius : [0, t.radius == null ? '75%' : t.radius];
                    c.radius = t.type === 'gauge' ? px(r[1], min) * k : [px(r[0], min) * k, px(r[1], min) * k];
                    if (t === s) {
                        c.label = Object.assign({}, t.label, { show: false });
                        c.labelLine = Object.assign({}, t.labelLine || {}, { show: false });
                    }
                    return c;
                });
                return { option: out, key: key };
            }
            return { option: opt, key: 'labels' };
        };
    }
