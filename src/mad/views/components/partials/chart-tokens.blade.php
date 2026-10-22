    {{-- Cores pelos tokens do tema: `var(--mad-…)` nas opções vira a cor do tema
         ativo (claro ou escuro) a cada desenho. Só sai quando as opções têm token
         (MadChartConfig::themeTokens): paleta automática, tons, séries do PHP,
         marcadores, donut com meta. Token em color-mix() sai em rgb. A paleta
         automática (--mad-chart-auto-N) sai já ajustada ao fundo do card
         (__madChartAutoPalette). --}}
    if (!window.__madChartTokens) {
        window.__madChartTokens = function (opts, el) {
            var cs = window.getComputedStyle(el || document.documentElement);
            // A reserva pode ser outro var(): var(--mad-chart-auto-1, var(--mad-chart-1, #2563eb)).
            var re = /^var\(\s*(--[\w-]+)\s*(?:,\s*(.+))?\)$/;
            var pal = window.__madChartAutoPalette ? window.__madChartAutoPalette(el || document.documentElement) : null;
            var walk = function (v) {
                if (typeof v === 'string') {
                    var m = v.match(re);
                    if (!m) return v;
                    // O preenchimento usa a cor ajustada enquanto for a mesma cor da série.
                    var a = pal && m[1].match(/^--mad-chart-auto-(fill-)?([1-9]|1[0-2])$/);
                    if (a && pal[a[2] - 1] && (!a[1] || cs.getPropertyValue(m[1]).trim() === cs.getPropertyValue('--mad-chart-auto-' + a[2]).trim())) {
                        return pal[a[2] - 1];
                    }
                    var got = cs.getPropertyValue(m[1]).trim() || (m[2] ? m[2].trim() : v);
                    if (got !== v && re.test(got)) return walk(got);
                    return window.__madCssColor ? window.__madCssColor(got) : got;
                }
                if (Array.isArray(v)) return v.map(walk);
                if (v && typeof v === 'object') {
                    var o = {};
                    for (var k in v) { if (Object.prototype.hasOwnProperty.call(v, k)) o[k] = walk(v[k]); }
                    return o;
                }
                return v;
            };
            return walk(opts);
        };
    }
