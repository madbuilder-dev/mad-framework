    {{-- Cores pelos tokens do tema: `var(--mad-…)` nas opções vira a cor do tema
         ativo (claro ou escuro) a cada desenho. Só sai quando o gráfico usa
         séries do PHP, marcadores ou donut com meta (MadChartConfig::themeTokens). --}}
    if (!window.__madChartTokens) {
        window.__madChartTokens = function (opts, el) {
            var cs = window.getComputedStyle(el || document.documentElement);
            var re = /^var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\)$/;
            var walk = function (v) {
                if (typeof v === 'string') {
                    var m = v.match(re);
                    if (!m) return v;
                    var got = cs.getPropertyValue(m[1]).trim();
                    return got || (m[2] ? m[2].trim() : v);
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
