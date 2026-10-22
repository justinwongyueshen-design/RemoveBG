'use strict';
(function () {
    const $ = (id) => document.getElementById(id);
    const CFG = JSON.parse($('rbg-config').textContent);
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const MAX_CANVAS_PIXELS = 16777216; // iOS Safari limit; keeps export reliable everywhere

    const s = { file: null, origUrl: null, resultBlob: null, resultUrl: null, busy: false, bg: 'transparent', timer: null };

    const el = {
        alert: $('alert'), alertText: $('alert-text'), retry: $('btn-retry'),
        up: $('state-upload'), pre: $('state-preview'), res: $('state-result'),
        drop: $('dropzone'), input: $('file-input'),
        orig: $('orig-preview'), name: $('file-name'), meta: $('file-meta'),
        process: $('btn-process'), replace: $('btn-replace'), clear: $('btn-clear'),
        status: $('status'), statusText: $('status-text'),
        stage: $('stage'), layerRes: $('layer-result'), resImg: $('result-img'), origCmp: $('orig-compare'),
        range: $('cmp-range'), custom: $('custom-color'),
        dlT: $('btn-dl-transparent'), dlBg: $('btn-dl-bg'), hint: $('dl-hint'),
        reset: $('btn-reset'), another: $('btn-another'),
    };

    const fmtBytes = (n) => n >= 1048576 ? (n / 1048576).toFixed(2) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

    function showAlert(msg, canRetry) {
        el.alertText.textContent = msg;
        el.retry.hidden = !canRetry;
        el.alert.hidden = false;
    }
    function hideAlert() { el.alert.hidden = true; }

    function show(which) {
        el.up.hidden = which !== 'upload';
        el.pre.hidden = which !== 'preview';
        el.res.hidden = which !== 'result';
    }

    function revoke(url) { if (url) { URL.revokeObjectURL(url); } }

    function clearAll() {
        revoke(s.origUrl); revoke(s.resultUrl);
        Object.assign(s, { file: null, origUrl: null, resultBlob: null, resultUrl: null, busy: false });
        el.input.value = '';
        el.resImg.removeAttribute('src'); el.origCmp.removeAttribute('src'); el.orig.removeAttribute('src');
        stopTimer(); el.status.hidden = true;
        setBg('transparent'); el.range.value = 50; applyCompare();
        hideAlert(); show('upload');
    }

    /* ---------- file selection ---------- */
    function extType(file) {
        const m = /\.(jpe?g|png|webp)$/i.exec(file.name || '');
        if (!m) return '';
        const e = m[1].toLowerCase();
        return e === 'png' ? 'image/png' : e === 'webp' ? 'image/webp' : 'image/jpeg';
    }

    function loadImage(url) {
        return new Promise((resolve, reject) => {
            const im = new Image();
            im.onload = () => resolve(im);
            im.onerror = () => reject(new Error('load'));
            im.src = url;
        });
    }

    async function handleFile(file) {
        if (!file || s.busy) return;
        hideAlert();
        const type = file.type || extType(file);
        if (!CFG.types.includes(type)) { showAlert('Unsupported file type. Please choose a JPG, PNG or WebP image.', false); return; }
        if (file.size === 0) { showAlert('The selected file is empty.', false); return; }
        if (file.size > CFG.maxBytes) { showAlert('The image is too large. Maximum size is ' + fmtBytes(CFG.maxBytes) + '.', false); return; }

        const url = URL.createObjectURL(file);
        let im;
        try { im = await loadImage(url); }
        catch (e) { revoke(url); showAlert('This file could not be read as an image. It may be corrupted.', false); return; }
        if (im.naturalWidth > CFG.maxWidth || im.naturalHeight > CFG.maxHeight) {
            revoke(url);
            showAlert('The image dimensions are too large. Maximum is ' + CFG.maxWidth + ' x ' + CFG.maxHeight + ' pixels.', false);
            return;
        }
        revoke(s.origUrl);
        s.file = file; s.origUrl = url;
        el.orig.src = url;
        el.name.textContent = file.name;
        el.meta.textContent = fmtBytes(file.size) + ' \u00b7 ' + im.naturalWidth + ' x ' + im.naturalHeight + ' px';
        show('preview');
    }

    /* ---------- processing ---------- */
    function startTimer() {
        const t0 = Date.now();
        const tick = () => { el.statusText.textContent = 'Removing background\u2026 ' + Math.floor((Date.now() - t0) / 1000) + 's'; };
        tick(); s.timer = setInterval(tick, 1000);
    }
    function stopTimer() { if (s.timer) { clearInterval(s.timer); s.timer = null; } }

    function setBusy(b) {
        s.busy = b;
        el.process.disabled = b; el.replace.disabled = b; el.clear.disabled = b;
        el.status.hidden = !b;
        if (b) startTimer(); else stopTimer();
    }

    async function serverMessage(resp) {
        try { const j = await resp.json(); if (j && j.error && j.error.message) return j.error.message; } catch (e) { /* ignore */ }
        return 'Unexpected response from the server (' + resp.status + ').';
    }

    async function processImage() {
        if (s.busy || !s.file) return;
        hideAlert(); setBusy(true);
        const ctrl = new AbortController();
        const to = setTimeout(() => ctrl.abort(), (CFG.timeoutSeconds + 30) * 1000);
        try {
            const fd = new FormData();
            fd.append('image', s.file, s.file.name);
            const r = await fetch('process.php', {
                method: 'POST', body: fd, signal: ctrl.signal, credentials: 'same-origin',
                headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
            });
            if (!r.ok) throw new Error(await serverMessage(r));
            const data = await r.json();
            if (!data || !data.ok || typeof data.result_url !== 'string') throw new Error('Unexpected response from the server.');
            const target = new URL(data.result_url, location.href);
            if (target.origin !== location.origin) throw new Error('Unexpected response from the server.');

            const rr = await fetch(target.href, { credentials: 'same-origin', signal: ctrl.signal });
            if (!rr.ok) throw new Error(await serverMessage(rr));
            const blob = await rr.blob();
            if (blob.type !== 'image/png') throw new Error('The server returned an unexpected file.');
            await showResult(blob);
        } catch (e) {
            let msg = e && e.message ? e.message : 'Something went wrong.';
            if (e && e.name === 'AbortError') msg = 'Processing took too long. Please try again.';
            else if (e instanceof TypeError) msg = 'Network problem. Check your connection and try again.';
            showAlert(msg, true);
        } finally {
            clearTimeout(to); setBusy(false);
        }
    }

    async function showResult(blob) {
        revoke(s.resultUrl);
        s.resultBlob = blob; s.resultUrl = URL.createObjectURL(blob);
        const im = await loadImage(s.resultUrl);
        el.stage.style.setProperty('--ratio', String(im.naturalWidth / im.naturalHeight));
        el.resImg.src = s.resultUrl;
        el.origCmp.src = s.origUrl;
        el.range.value = 50; applyCompare(); setBg('transparent');
        show('result');
    }

    /* ---------- editor ---------- */
    function applyCompare() {
        el.stage.style.setProperty('--cmp', el.range.value + '%');
    }

    function setBg(value) {
        s.bg = value;
        const solid = value !== 'transparent';
        el.layerRes.classList.toggle('checker', !solid);
        el.layerRes.style.backgroundColor = solid ? value : '';
        el.layerRes.style.backgroundImage = solid ? 'none' : '';
        document.querySelectorAll('.bg-btn').forEach((b) => {
            const isCustom = b.id === 'custom-wrap';
            const on = isCustom ? (solid && value === el.custom.value.toLowerCase() && s.customActive) : b.dataset.bg === value && !s.customActive;
            b.classList.toggle('active', !!on);
            if (!isCustom) b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        el.dlBg.disabled = !solid;
        el.hint.textContent = solid ? 'Exports a PNG with ' + value.toUpperCase() + ' painted into the pixels.' : 'Pick White, Black or a custom colour to export with that background.';
    }

    document.querySelectorAll('.bg-btn[data-bg]').forEach((b) => b.addEventListener('click', () => { s.customActive = false; setBg(b.dataset.bg); }));
    el.custom.addEventListener('input', () => { s.customActive = true; setBg(el.custom.value.toLowerCase()); });

    function baseName() {
        const n = (s.file && s.file.name ? s.file.name : 'image').replace(/\.[^.]+$/, '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
        return 'removebg-' + (n || 'image');
    }

    function saveBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }

    el.dlT.addEventListener('click', () => {
        if (!s.resultBlob) return;
        saveBlob(s.resultBlob, baseName() + '.png');
    });

    el.dlBg.addEventListener('click', async () => {
        if (!s.resultUrl || s.bg === 'transparent') return;
        hideAlert();
        try {
            const im = await loadImage(s.resultUrl);
            let w = im.naturalWidth, h = im.naturalHeight;
            if (w * h > MAX_CANVAS_PIXELS) {
                const k = Math.sqrt(MAX_CANVAS_PIXELS / (w * h));
                w = Math.floor(w * k); h = Math.floor(h * k);
            }
            const c = document.createElement('canvas');
            c.width = w; c.height = h;
            const ctx = c.getContext('2d');
            if (!ctx) throw new Error('canvas');
            ctx.fillStyle = s.bg; ctx.fillRect(0, 0, w, h);
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(im, 0, 0, w, h);
            const blob = await new Promise((res) => c.toBlob(res, 'image/png'));
            if (!blob) throw new Error('blob');
            saveBlob(blob, baseName() + '-' + s.bg.replace('#', '') + '.png');
        } catch (e) {
            showAlert('The image could not be exported with a background. Try the transparent PNG instead.', false);
        }
    });

    el.range.addEventListener('input', applyCompare);
    el.reset.addEventListener('click', () => { s.customActive = false; setBg('transparent'); el.range.value = 50; applyCompare(); });
    el.another.addEventListener('click', clearAll);

    /* ---------- wiring ---------- */
    el.process.addEventListener('click', processImage);
    el.retry.addEventListener('click', () => { if (s.file && !s.busy) processImage(); });
    el.replace.addEventListener('click', () => { if (!s.busy) el.input.click(); });
    el.clear.addEventListener('click', () => { if (!s.busy) clearAll(); });
    el.input.addEventListener('change', () => handleFile(el.input.files[0]));

    el.drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.input.click(); } });
    ['dragenter', 'dragover'].forEach((t) => el.drop.addEventListener(t, (e) => { e.preventDefault(); el.drop.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach((t) => el.drop.addEventListener(t, (e) => { e.preventDefault(); el.drop.classList.remove('dragover'); }));
    el.drop.addEventListener('drop', (e) => { const f = e.dataTransfer && e.dataTransfer.files[0]; if (f) handleFile(f); });
    // Stop the browser from navigating to a file dropped outside the drop zone.
    ['dragover', 'drop'].forEach((t) => window.addEventListener(t, (e) => e.preventDefault()));
})();
