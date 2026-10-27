/**
 * MadMap — desenha o <mad-map> (marcadores, camadas, legenda) e o
 * <mad-map-field> (pino arrastável + busca de endereço) com o Leaflet que o
 * pacote serve em lib/mad/leaflet/. Nenhum CDN: só os blocos do mapa vêm do
 * OpenStreetMap (ou do `tiles=` da tag).
 *
 * Carregado sob demanda pelo <script> que a própria tag emite: cada tag se
 * põe em `window.__madMapQueue` e este arquivo monta a fila ao chegar. Tag que
 * chega depois (MadWire, cortina, aba) chama `MadMap.mount(el)` direto.
 *
 * Configuração: <script type="application/json" data-map-config> dentro da
 * tag (Mad\Chart\MadMap no PHP). Textos dos registros chegam crus e entram no
 * DOM só por textContent.
 */
(function () {
    'use strict';
    if (window.MadMap && window.MadMap.__ready) return;

    var self = document.currentScript
        || document.querySelector('script[data-mad-map-lib]')
        || document.querySelector('script[src*="mad-map.js"]');
    var src = self ? self.src : 'lib/mad/mad-map.js';
    var query = src.indexOf('?') >= 0 ? src.slice(src.indexOf('?')) : '';
    var base = src.split('?')[0].replace(/mad-map\.js$/, '');
    var LEAF = base + 'leaflet/';

    var OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    var OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';
    var BRAZIL = [-15.78, -47.93];

    // ── carga do Leaflet ────────────────────────────────────────────────

    function css(href, mark) {
        if (document.querySelector('link[' + mark + ']')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        l.setAttribute(mark, '');
        document.head.appendChild(l);
    }

    function js(href, mark) {
        return new Promise(function (resolve, reject) {
            var s = document.querySelector('script[' + mark + ']');
            if (s && s.__madLoaded) { resolve(); return; }
            if (!s) {
                s = document.createElement('script');
                s.src = href;
                s.setAttribute(mark, '');
                document.head.appendChild(s);
            }
            s.addEventListener('load', function () { s.__madLoaded = true; resolve(); });
            s.addEventListener('error', reject);
        });
    }

    css(base + 'mad-map.css' + query, 'data-mad-map-css');

    var leafletReady = null;
    var clusterReady = null;

    function loadLeaflet(cluster) {
        css(LEAF + 'leaflet.css' + query, 'data-mad-leaflet-css');
        if (!leafletReady) {
            leafletReady = (window.L && window.L.map) ? Promise.resolve() : js(LEAF + 'leaflet.js' + query, 'data-mad-leaflet');
        }
        if (!cluster) return leafletReady;
        return leafletReady.then(function () {
            css(LEAF + 'MarkerCluster.css' + query, 'data-mad-mc-css');
            css(LEAF + 'MarkerCluster.Default.css' + query, 'data-mad-mcd-css');
            if (!clusterReady) {
                clusterReady = window.L.markerClusterGroup ? Promise.resolve() : js(LEAF + 'leaflet.markercluster.js' + query, 'data-mad-leaflet-mc');
            }
            return clusterReady;
        });
    }

    // ── peças ───────────────────────────────────────────────────────────

    function readConfig(el) {
        var node = el.querySelector(':scope > script[data-map-config]');
        if (!node) return null;
        try { return JSON.parse(node.textContent || '{}'); } catch (e) { return null; }
    }

    function status(el, text) {
        var s = el.querySelector('[data-map-status]');
        if (!s) return;
        if (text) { s.textContent = text; s.hidden = false; } else { s.hidden = true; }
    }

    function iconNode(name) {
        if (!name || !window.lucide || !window.lucide.icons || typeof window.lucide.createElement !== 'function') return null;
        var pascal = name.replace(/(^|-)([a-z0-9])/g, function (_, __, c) { return c.toUpperCase(); });
        var def = window.lucide.icons[pascal];
        if (!def) return null;
        try {
            var svg = window.lucide.createElement(def);
            svg.setAttribute('aria-hidden', 'true');
            return svg;
        } catch (e) {
            return null;
        }
    }

    function pin(color, icon) {
        var span = document.createElement('span');
        span.className = 'mad-map-pin';
        if (color) span.style.setProperty('--mad-map-pin', color);
        // `tabler:nome`: o bloco Tabler do lucide.min.js baixa o desenho e troca o <i>.
        if (icon && icon.indexOf('tabler:') === 0 && window.lucide && typeof window.lucide.createIcons === 'function') {
            var i = document.createElement('i');
            i.setAttribute('data-lucide', icon);
            span.appendChild(i);
            try { window.lucide.createIcons({ root: span }); } catch (e) { /* sem ícone: pino continua */ }
            return window.L.divIcon({ className: 'mad-map-pin-wrap', html: span, iconSize: [28, 36], iconAnchor: [14, 34], popupAnchor: [0, -30], tooltipAnchor: [0, -30] });
        }
        var svg = iconNode(icon);
        if (svg) span.appendChild(svg); else span.classList.add('is-dot');
        return window.L.divIcon({ className: 'mad-map-pin-wrap', html: span, iconSize: [28, 36], iconAnchor: [14, 34], popupAnchor: [0, -30], tooltipAnchor: [0, -30] });
    }

    function pointContent(p) {
        var box = document.createElement('div');
        box.className = 'mad-map-pop';
        if (p.title) {
            var t = document.createElement('div');
            t.className = 'mad-map-pop-title';
            t.textContent = p.title;
            box.appendChild(t);
        }
        if (p.popover) {
            var c = document.createElement('div');
            c.className = 'mad-map-pop-body';
            c.textContent = p.popover;
            box.appendChild(c);
        }
        return box.childNodes.length ? box : null;
    }

    function clickPoint(root, cfg, li, layer, p) {
        root.dispatchEvent(new CustomEvent('mad-map-click', {
            bubbles: true,
            detail: { name: cfg.name, layer: li, id: p.id, lat: p.lat, lng: p.lng },
        }));
        var c = layer.click;
        if (!c) return;
        if (c.action) {
            var wrapper = root.closest('[mad-component]');
            if (wrapper && window.MadWire && typeof window.MadWire.call === 'function') window.MadWire.call(wrapper, c.action, [p.id]);
            return;
        }
        if (c.target && window.Mad && typeof window.Mad.go === 'function') {
            var params = {};
            params[c.param || 'id'] = p.id;
            var url = (c.url && typeof window.Mad._resolveUrlTemplate === 'function') ? window.Mad._resolveUrlTemplate(c.url, params) : undefined;
            window.Mad.go(c.target, c.method || 'onEdit', params, url);
        }
    }

    function baseMap(el, cfg) {
        var canvas = el.querySelector('[data-map-canvas]');
        var map = window.L.map(canvas, { scrollWheelZoom: false, worldCopyJump: true });
        window.L.tileLayer(cfg.tiles || OSM_TILES, { maxZoom: 19, attribution: OSM_ATTR }).addTo(map);
        // Roda do mouse só depois de clicar no mapa: rolar a página por cima
        // dele não deve dar zoom.
        map.on('click focus', function () { map.scrollWheelZoom.enable(); });
        canvas.addEventListener('mouseleave', function () { map.scrollWheelZoom.disable(); });

        // Mapa aberto em aba/cortina escondida nasce com tamanho 0.
        if (typeof ResizeObserver === 'function') {
            var ro = new ResizeObserver(function () {
                if (!document.body.contains(el)) { ro.disconnect(); return; }
                map.invalidateSize();
            });
            ro.observe(canvas);
            el.__madMapRO = ro;
        }
        return map;
    }

    function destroy(el) {
        if (el.__madMapRO) { try { el.__madMapRO.disconnect(); } catch (e) {} el.__madMapRO = null; }
        if (el.__madMap) { try { el.__madMap.remove(); } catch (e) {} el.__madMap = null; }
    }

    // ── <mad-map> ───────────────────────────────────────────────────────

    /**
     * Marcadores por camada, no mapa. Com `cluster`, UM grupo de agrupamento
     * para todas as camadas: marcadores de camadas diferentes no mesmo ponto
     * se juntam (mad-framework#477 — com um grupo por camada, um cobria o
     * outro). Desligar uma camada tira os marcadores dela do grupo.
     *
     * @return {function(number, boolean)} liga/desliga a camada `li`
     */
    function layerGroups(L, map, layers, cluster, markersOf) {
        var shared = (cluster && L.markerClusterGroup)
            ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 })
            : null;
        var groups = layers.map(function (layer, li) {
            var markers = markersOf(layer, li);
            if (shared) {
                if (!layer.hidden) shared.addLayers(markers);
                return markers;
            }
            var group = L.layerGroup(markers);
            if (!layer.hidden) group.addTo(map);
            return group;
        });
        if (shared) shared.addTo(map);
        return function (li, on) {
            var g = groups[li];
            if (!g) return;
            if (shared) {
                if (on) shared.addLayers(g); else shared.removeLayers(g);
            } else if (on) {
                g.addTo(map);
            } else {
                map.removeLayer(g);
            }
        };
    }

    function mountMap(el, cfg) {
        var L = window.L;
        var map = baseMap(el, cfg);
        el.__madMap = map;
        var bounds = L.latLngBounds([]);

        var toggle = layerGroups(L, map, cfg.layers || [], !!cfg.cluster, function (layer, li) {
            var markers = [];
            (layer.points || []).forEach(function (p) {
                var m = L.marker([p.lat, p.lng], {
                    icon: pin(p.color || layer.color, layer.icon),
                    title: p.title || '',
                    alt: p.title || '',
                    keyboard: true,
                });
                var content = pointContent(p);
                if (layer.click) {
                    if (content) m.bindTooltip(content, { direction: 'top', className: 'mad-map-tip' });
                    m.on('click', function () { clickPoint(el, cfg, li, layer, p); });
                    m.on('keypress', function (e) {
                        if (e.originalEvent && e.originalEvent.key === 'Enter') clickPoint(el, cfg, li, layer, p);
                    });
                } else {
                    if (content) m.bindPopup(content, { className: 'mad-map-popup' });
                    m.on('click', function () { clickPoint(el, cfg, li, layer, p); });
                }
                markers.push(m);
                if (!layer.hidden) bounds.extend([p.lat, p.lng]);
            });
            return markers;
        });

        el.querySelectorAll('[data-map-layer-toggle]').forEach(function (box) {
            box.addEventListener('change', function () {
                toggle(parseInt(box.getAttribute('data-map-layer-toggle'), 10), box.checked);
            });
        });

        if (cfg.center) {
            map.setView(cfg.center, cfg.zoom || 12);
        } else if (bounds.isValid()) {
            var ne = bounds.getNorthEast();
            var sw = bounds.getSouthWest();
            if (ne.equals(sw)) map.setView(ne, cfg.zoom || 14);
            else map.fitBounds(bounds, { padding: [28, 28], maxZoom: cfg.zoom || 15 });
        } else {
            map.setView(BRAZIL, cfg.zoom || 4);
        }
    }

    // ── <mad-map-field> ─────────────────────────────────────────────────

    var lastGeocode = 0;

    function geocode(text, country) {
        var q = String(text || '').trim();
        if (q.length < 3) return Promise.resolve(null);
        var params = new URLSearchParams({ format: 'jsonv2', limit: '1', addressdetails: '0' });
        var cep = q.match(/^\s*(\d{5})-?(\d{3})\s*$/);
        if (cep) params.set('postalcode', cep[1] + '-' + cep[2]);
        else params.set('q', q);
        if (country) params.set('countrycodes', country);
        // Política do Nominatim: no máximo uma consulta por segundo.
        var wait = Math.max(0, lastGeocode + 1100 - Date.now());
        return new Promise(function (r) { setTimeout(r, wait); }).then(function () {
            lastGeocode = Date.now();
            return fetch('https://nominatim.openstreetmap.org/search?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'Accept-Language': document.documentElement.lang || 'pt-BR' },
                credentials: 'omit',
                referrerPolicy: 'strict-origin-when-cross-origin',
            });
        }).then(function (res) {
            return res.ok ? res.json() : [];
        }).then(function (rows) {
            var r = Array.isArray(rows) && rows[0];
            if (!r) return null;
            var lat = parseFloat(r.lat), lng = parseFloat(r.lon);
            return (isFinite(lat) && isFinite(lng)) ? [lat, lng] : null;
        });
    }

    function mountField(el, cfg) {
        var L = window.L;
        var map = baseMap(el, cfg);
        el.__madMap = map;
        var latIn = el.querySelector('[data-map-lat]');
        var lngIn = el.querySelector('[data-map-lng]');
        var coords = el.querySelector('[data-map-coords]');
        var search = el.querySelector('[data-map-search]');
        var searchBtn = el.querySelector('[data-map-search-btn]');
        var clearBtn = el.querySelector('[data-map-clear]');
        var marker = null;
        // Posição posta pela busca automática (geocode-from) pode ser trocada
        // por ela de novo; a que o usuário arrastou/clicou, não.
        var auto = false;

        var msg = null;
        if (search) {
            msg = document.createElement('span');
            msg.className = 'mad-map-search-msg';
            msg.setAttribute('aria-live', 'polite');
            search.parentNode.appendChild(msg);
        }
        function say(text) { if (msg) msg.textContent = text || ''; }

        function emit(input) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function write(lat, lng) {
            latIn.value = lat === null ? '' : lat.toFixed(7);
            lngIn.value = lng === null ? '' : lng.toFixed(7);
            emit(latIn);
            emit(lngIn);
            if (coords) coords.textContent = lat === null ? '' : latIn.value + ', ' + lngIn.value;
        }

        function place(lat, lng, opts) {
            opts = opts || {};
            if (!marker) {
                marker = L.marker([lat, lng], { icon: pin('', ''), draggable: !cfg.disabled, autoPan: true, keyboard: true }).addTo(map);
                marker.on('dragend', function () {
                    var ll = marker.getLatLng();
                    auto = false;
                    write(ll.lat, ll.lng);
                });
            } else {
                marker.setLatLng([lat, lng]);
            }
            if (opts.fly) map.setView([lat, lng], Math.max(map.getZoom() || 0, cfg.zoom || 16));
            if (opts.write) write(lat, lng);
        }

        function clear() {
            if (marker) { map.removeLayer(marker); marker = null; }
            auto = false;
            write(null, null);
        }

        var lat0 = parseFloat(String(latIn.value).replace(',', '.'));
        var lng0 = parseFloat(String(lngIn.value).replace(',', '.'));
        if (isFinite(lat0) && isFinite(lng0) && !(lat0 === 0 && lng0 === 0)) {
            place(lat0, lng0);
            map.setView([lat0, lng0], cfg.zoom || 16);
        } else {
            map.setView(cfg.center || BRAZIL, cfg.zoom || (cfg.center ? 12 : 4));
        }

        if (!cfg.disabled) {
            map.on('click', function (e) {
                auto = false;
                place(e.latlng.lat, e.latlng.lng, { write: true });
            });
            if (clearBtn) clearBtn.addEventListener('click', clear);
        }

        function run(text, fromForm) {
            say(cfg.text && cfg.text.searching);
            return geocode(text, cfg.country).then(function (ll) {
                if (!ll) { say(cfg.text && cfg.text.notFound); return; }
                say('');
                if (fromForm) auto = true;
                place(ll[0], ll[1], { write: true, fly: true });
            }).catch(function () { say(cfg.text && cfg.text.notFound); });
        }

        if (search && !cfg.disabled) {
            var t = null;
            search.addEventListener('input', function () {
                clearTimeout(t);
                var v = search.value;
                if (v.trim().length < 5) return;
                t = setTimeout(function () { run(v, false); }, 900);
            });
            search.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                clearTimeout(t);
                run(search.value, false);
            });
            if (searchBtn) searchBtn.addEventListener('click', function () { clearTimeout(t); run(search.value, false); });
        }

        var from = cfg.geocodeFrom || [];
        if (from.length && !cfg.disabled) {
            var scope = el.closest('form, [mad-component]') || document;
            var ft = null;
            var lastText = '';
            var onChange = function () {
                clearTimeout(ft);
                ft = setTimeout(function () {
                    var parts = from.map(function (n) {
                        var f = scope.querySelector('[name="' + n.replace(/"/g, '') + '"]');
                        return f ? String(f.value || '').trim() : '';
                    }).filter(Boolean);
                    var text = parts.join(', ');
                    if (!text || text === lastText) return;
                    lastText = text;
                    if (search) search.value = text;
                    // Posição escolhida à mão (ou que veio do registro) fica;
                    // o usuário busca de novo pelo botão, se quiser.
                    if (marker && !auto) return;
                    run(text, true);
                }, 1200);
            };
            scope.addEventListener('change', function (e) {
                var n = e.target && e.target.getAttribute && e.target.getAttribute('name');
                if (n && from.indexOf(n) >= 0) onChange();
            });
        }
    }

    // ── montagem ────────────────────────────────────────────────────────

    function mount(el) {
        if (!el || !el.isConnected) return;
        var cfg = readConfig(el);
        if (!cfg) return;
        destroy(el);
        var needCluster = !!cfg.cluster;
        loadLeaflet(needCluster).then(function () {
            if (!el.isConnected) return;
            status(el, '');
            if (window.L.Icon && window.L.Icon.Default) window.L.Icon.Default.imagePath = LEAF + 'images/';
            if (cfg.field) mountField(el, cfg); else mountMap(el, cfg);
        }).catch(function () {
            status(el, (cfg.text && cfg.text.loadError) || 'Map unavailable');
        });
    }

    window.MadMap = { __ready: true, mount: mount, destroy: destroy, geocode: geocode, _layerGroups: layerGroups };

    var queue = window.__madMapQueue || [];
    window.__madMapQueue = [];
    queue.forEach(mount);
    document.querySelectorAll('[data-mad-map]').forEach(function (el) {
        if (!el.__madMap && queue.indexOf(el) < 0) mount(el);
    });
})();
