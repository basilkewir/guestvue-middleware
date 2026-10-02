/**
 * media-upload.js — browser file upload for movie / episode source fields.
 *
 * Injects an "Upload from computer" widget right after the stream-source field
 * (any page that has #stream_source), uploads the selected file to the panel in
 * 4 MB chunks (resumable), then fills #stream_source with the server path.
 *
 * Endpoint: POST ./media_upload?op=init|chunk|status|complete|abort
 * (see MediaUploadController.php)
 */
(function () {
    'use strict';

    var CHUNK = 4 * 1024 * 1024;
    var ENDPOINT = './media_upload';
    var ACCEPT = 'video/*,.mkv,.mp4,.avi,.mov,.m4v,.mpg,.mpeg,.ts,.m2ts,.flv,.wmv,.webm,.vob,.rmvb,.3gp';

    var WIDGET_HTML =
        '<div class="form-group row mb-4" data-media-upload>' +
        '<label class="col-md-4 col-form-label">Upload from computer</label>' +
        '<div class="col-md-8">' +
        '<div class="custom-file">' +
        '<input type="file" class="custom-file-input" id="media_upload_file" accept="' + ACCEPT + '">' +
        '<label class="custom-file-label" for="media_upload_file" id="media_upload_label">Choose video file...</label>' +
        '</div>' +
        '<div class="progress mt-2 mb-0" id="media_upload_progress" style="display:none;height:1.25rem;">' +
        '<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" ' +
        'style="width:0%;min-width:2.5rem;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>' +
        '</div>' +
        '<small class="text-muted d-block mt-1" id="media_upload_status" style="display:none;"></small>' +
        '</div>' +
        '</div>';

    function fmtSize(b) {
        if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
        return b + ' B';
    }

    function toast(msg) {
        try {
            if (window.jQuery && typeof window.jQuery.toast === 'function') {
                window.jQuery.toast(msg);
                return;
            }
        } catch (e) { /* fall through */ }
        if (window.console) window.console.log('[media-upload] ' + msg);
    }

    function titleFrom(name) {
        return String(name)
            .replace(/\.[^.]+$/, '')
            .replace(/[._]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /** Deterministic 32-hex id from name+size+mtime → resume across reloads. */
    function hashId(name, size, modified) {
        var s = name + ':' + size + ':' + modified;
        var seeds = [33, 31, 37, 131];
        var vals = [5381, 52711, 271, 17];
        for (var k = 0; k < 4; k++) {
            var v = vals[k];
            for (var i = 0; i < s.length; i++) {
                v = ((v * seeds[k]) + s.charCodeAt(i)) >>> 0;
            }
            vals[k] = v;
        }
        var hex = '';
        for (var j = 0; j < 4; j++) {
            hex += ('00000000' + vals[j].toString(16)).slice(-8);
        }
        return hex;
    }

    function api(op, uploadId, offset, body, headers, extra) {
        var url = ENDPOINT + '?op=' + op;
        if (uploadId) url += '&upload_id=' + encodeURIComponent(uploadId);
        if (offset !== undefined && offset !== null) url += '&offset=' + offset;
        if (extra) {
            for (var k in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, k)) {
                    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
                }
            }
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers || { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) {
            return res.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return { result: false, error: 'HTTP ' + res.status };
                }
            });
        });
    }

    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function sendChunk(id, offset, slice, attempt) {
        var headers = {
            'Content-Type': 'application/octet-stream',
            'X-Requested-With': 'XMLHttpRequest'
        };
        return api('chunk', id, offset, slice, headers).catch(function (err) {
            if (attempt < 5) {
                return delay(1000 * (attempt + 1)).then(function () {
                    return sendChunk(id, offset, slice, attempt + 1);
                });
            }
            throw err;
        });
    }

    function applyResult(r, els) {
        var src = document.getElementById('stream_source');
        if (src) {
            src.value = r.path;
            if (window.jQuery) {
                window.jQuery(src).trigger('change');
            } else {
                try { src.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) { /* noop */ }
            }
        }
        var nameInput = document.getElementById('stream_display_name');
        if (nameInput && !nameInput.value && !nameInput.readOnly) {
            nameInput.value = titleFrom(r.name || '');
            if (window.jQuery) {
                window.jQuery(nameInput).trigger('change');
            }
        }
        els.status.style.display = '';
        els.status.className = 'text-success d-block mt-1';
        els.status.textContent = 'Uploaded: ' + r.path;
        els.bar.style.width = '100%';
        els.bar.textContent = '100%';
        els.bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
        toast('File uploaded: ' + (r.name || ''));
    }

    function startUpload(file, els, input) {
        if (els.busy) return;
        els.busy = true;
        els.input.disabled = true;
        els.status.style.display = '';
        els.status.className = 'text-muted d-block mt-1';
        els.progress.style.display = '';
        els.bar.className = 'progress-bar progress-bar-striped progress-bar-animated';
        els.bar.style.width = '0%';
        els.bar.textContent = '0%';
        els.status.textContent = 'Starting upload...';

        var id = hashId(file.name, file.size, file.lastModified || 0);
        var received = 0;

        function progress(done) {
            var pct = file.size ? Math.floor((done * 100) / file.size) : 0;
            els.bar.style.width = pct + '%';
            els.bar.textContent = pct + '%';
            els.bar.setAttribute('aria-valuenow', String(pct));
            els.status.textContent = fmtSize(done) + ' / ' + fmtSize(file.size) + ' (' + pct + '%)';
        }

        function pump() {
            if (received >= file.size) return Promise.resolve();
            var slice = file.slice(received, Math.min(received + CHUNK, file.size));
            return sendChunk(id, received, slice, 0).then(function (r) {
                if (!r.result) {
                    if (r.conflict) {
                        received = r.received || 0;
                        progress(received);
                        return pump();
                    }
                    throw new Error(r.error || 'Chunk upload failed');
                }
                received = r.received;
                progress(received);
                return pump();
            });
        }

        api('init', id, null, null, null, { name: file.name, size: file.size }).then(function (r) {
            if (!r.result) throw new Error(r.error || 'Init failed');
            received = r.received || 0;
            progress(received);
            return pump();
        }).then(function () {
            return api('complete', id, null, null);
        }).then(function (r) {
            if (!r.result) throw new Error(r.error || 'Finalize failed');
            applyResult(r, els);
        }).catch(function (err) {
            els.status.style.display = '';
            els.status.className = 'text-danger d-block mt-1';
            els.status.textContent = 'Upload failed: ' + (err && err.message ? err.message : err);
            toast('Upload failed: ' + (err && err.message ? err.message : err));
        }).then(function () {
            els.busy = false;
            els.input.disabled = false;
            if (input) input.value = '';
        });
    }

    /**
     * Locate the element the widget is inserted after. The source field sits
     * inside a `.stream-url` form-group on the movie/episode forms; fall back
     * to any form-group and finally to the input's own parent so a template
     * change can never silently hide the widget again.
     */
    function findAnchor(src) {
        if (!src || !src.closest) return src ? src.parentNode : null;
        return src.closest('.stream-url') || src.closest('.form-group') || src.parentNode;
    }

    /** @returns {string|null} why it did not mount, or null on success. */
    function initWidget() {
        var src = document.getElementById('stream_source');
        if (!src) return 'no #stream_source on this page';
        if (document.querySelector('[data-media-upload]')) return null;

        var anchor = findAnchor(src);
        if (!anchor) return 'no anchor found for #stream_source';
        anchor.insertAdjacentHTML('afterend', WIDGET_HTML);

        var input = document.getElementById('media_upload_file');
        var label = document.getElementById('media_upload_label');
        var progress = document.getElementById('media_upload_progress');
        var bar = progress ? progress.querySelector('.progress-bar') : null;
        var status = document.getElementById('media_upload_status');
        if (!input || !label || !progress || !bar || !status) return 'widget markup incomplete';

        var els = {
            input: input,
            label: label,
            progress: progress,
            bar: bar,
            status: status,
            busy: false
        };

        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) return;
            label.textContent = f.name + ' (' + fmtSize(f.size) + ')';
            startUpload(f, els, input);
        });

        console.info('[media-upload] mounted after', anchor.className || anchor.tagName);
        return null;
    }

    // Mount with a short retry window: some pages assemble their form after
    // DOMContentLoaded, so a single early attempt can run before #stream_source
    // exists and the widget would silently never appear.
    var attempts = 0;
    function tryMount() {
        var why = initWidget();
        if (why === null) return;
        if (attempts === 0) console.warn('[media-upload] not mounted yet: ' + why);
        if (++attempts < 20) setTimeout(tryMount, 250);
        else console.error('[media-upload] giving up: ' + why);
    }

    tryMount();
})();
