/* global josraRegenImagesConfig */
(function () {
    'use strict';

    var state = {
        total: 0,
        done: 0,
        errors: 0,
        paused: false,
        stopped: false,
        running: false,
        startTime: null,
        logEntries: 0
    };

    var els = {};

    function q(id) {
        return document.getElementById(id);
    }

    function ajaxPost(action, extraParams, callback) {
        var params = new URLSearchParams();
        // parámetros propios: "ajax"/"action" los intercepta el controlador de módulos de PS 1.7/8
        params.append('jrAjax', '1');
        params.append('jrAction', action);
        params.append('jrtoken', josraRegenImagesConfig.token);

        if (extraParams) {
            Object.keys(extraParams).forEach(function (key) {
                var value = extraParams[key];
                if (Array.isArray(value)) {
                    value.forEach(function (v) {
                        params.append(key + '[]', v);
                    });
                } else {
                    params.append(key, value);
                }
            });
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', josraRegenImagesConfig.ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                callback({ success: false, error: 'Respuesta no válida del servidor.' });
                return;
            }
            callback(data);
        };
        xhr.onerror = function () {
            callback({ success: false, error: 'Error de red.' });
        };
        xhr.send(params.toString());
    }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function watermarkOn() {
        var cb = q('josra-watermark');
        return cb && cb.checked ? 1 : 0;
    }

    function getCheckedTypeCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.josra-type-checkbox'));
    }

    function updateEstimate() {
        var counts = josraRegenImagesConfig.entityCounts || {};
        var total = 0;

        getCheckedTypeCheckboxes().forEach(function (cb) {
            if (cb.checked) {
                total += (counts[cb.getAttribute('data-entity')] || 0);
            }
        });

        q('josra-estimate-count').textContent = total.toLocaleString('es-ES');
    }

    function updateProgressBar() {
        var pct = state.total > 0 ? Math.round(((state.done + state.errors) / state.total) * 100) : 0;
        els.bar.style.width = pct + '%';
        els.bar.textContent = pct + '%';
        els.text.textContent = (state.done + state.errors) + ' / ' + state.total +
            (state.errors > 0 ? ' (' + state.errors + ' con error)' : '');
    }

    function appendToLog(items) {
        if (!items || !items.length) {
            return;
        }

        els.logWrapper.style.display = 'block';

        var html = items.map(function (row) {
            var cls = row.status === 'done' ? 'josra-log-ok' : 'josra-log-error';
            var icon = row.status === 'done' ? '✔' : '✘';
            return '<div class="' + cls + '">' + icon + ' [' + esc(row.entity) + ' #' + esc(row.id_entity) + ' - ' +
                esc(row.image_type_name) + '] ' + esc(row.message) + '</div>';
        }).join('');

        els.logContent.insertAdjacentHTML('beforeend', html);
        state.logEntries += items.length;

        // Limitamos el DOM a las últimas 500 entradas para no consumir memoria en catálogos grandes
        while (els.logContent.children.length > 500) {
            els.logContent.removeChild(els.logContent.firstChild);
        }

        q('josra-log-count').textContent = Math.min(state.logEntries, 500);
        els.logContent.scrollTop = els.logContent.scrollHeight;
    }

    function formatElapsed(ms) {
        var totalSeconds = Math.round(ms / 1000);
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        if (minutes > 0) {
            return minutes + ' min ' + seconds + ' s';
        }
        return seconds + ' s';
    }

    function renderErrors(errorSample) {
        if (!errorSample || !errorSample.length) {
            return;
        }
        els.errorLogWrapper.style.display = 'block';
        els.errorLog.innerHTML = errorSample.map(function (row) {
            return '<div>[' + esc(row.entity) + ' #' + esc(row.id_entity) + ' - ' + esc(row.image_type_name) + '] ' +
                esc(row.error_message || 'Error desconocido') + '</div>';
        }).join('');
    }

    function processLoop() {
        if (state.paused || state.stopped) {
            state.running = false;
            return;
        }

        state.running = true;

        ajaxPost('nextbatch', { batch_size: q('josra-batch-size').value, watermark: watermarkOn() }, function (resp) {
            if (!resp.success) {
                alert('Error procesando lote: ' + (resp.error || 'desconocido'));
                state.running = false;
                return;
            }

            if (resp.done) {
                finishRun();
                return;
            }

            appendToLog(resp.items);

            ajaxPost('progress', {}, function (progressResp) {
                if (progressResp.success) {
                    state.total = progressResp.total;
                    state.done = progressResp.done;
                    state.errors = progressResp.errors;
                    updateProgressBar();
                    renderErrors(progressResp.error_sample);
                }
                processLoop();
            });
        });
    }

    function finishRun() {
        state.running = false;
        els.btnPause.disabled = true;
        els.btnPause.style.display = 'inline-block';
        els.btnResume.style.display = 'none';
        els.btnStart.disabled = false;
        els.btnStart.textContent = 'Iniciar regeneración';

        els.bar.classList.remove('progress-bar-striped', 'active');
        els.bar.classList.add(state.errors > 0 ? 'progress-bar-warning' : 'progress-bar-success');

        // miniaturas en caché del back office: para que el panel muestre ya las nuevas
        ajaxPost('finish', {}, function () {});

        ajaxPost('progress', {}, function (progressResp) {
            if (progressResp.success) {
                state.total = progressResp.total;
                state.done = progressResp.done;
                state.errors = progressResp.errors;
                updateProgressBar();
                renderErrors(progressResp.error_sample);
            }

            var elapsed = state.startTime ? formatElapsed(Date.now() - state.startTime) : '';
            var text = '¡Terminado! ' + state.done + ' regeneradas correctamente';
            if (state.errors > 0) {
                text += ', ' + state.errors + ' con error (revisa el detalle abajo)';
            }
            if (elapsed) {
                text += '. Tiempo total: ' + elapsed + '.';
            }

            els.finishedText.textContent = text;
            els.finishedBanner.style.display = 'block';
            els.btnRetry.style.display = state.errors > 0 ? 'inline-block' : 'none';
        });
    }

    function startRun() {
        var typeIds = getCheckedTypeCheckboxes()
            .filter(function (cb) { return cb.checked; })
            .map(function (cb) { return cb.value; });

        if (!typeIds.length) {
            alert('Selecciona al menos un tamaño de imagen.');
            return;
        }

        var mode = document.querySelector('input[name="mode"]:checked').value;

        if (mode === 'force') {
            var ok = window.confirm(
                'Vas a regenerar desde cero, a partir de la imagen original, solo los tamaños marcados ' +
                '(los que no marques no se tocan). Esta acción no se puede deshacer. ¿Continuar?'
            );
            if (!ok) {
                return;
            }
        }

        els.btnStart.disabled = true;
        els.btnStart.textContent = 'Construyendo cola...';
        els.progressWrapper.style.display = 'block';
        els.bar.classList.remove('progress-bar-success', 'progress-bar-warning');
        els.bar.classList.add('progress-bar-striped', 'active');
        els.finishedBanner.style.display = 'none';
        els.logContent.innerHTML = '';
        els.errorLog.innerHTML = '';
        els.errorLogWrapper.style.display = 'none';
        els.logWrapper.style.display = 'none';
        state.logEntries = 0;
        state.startTime = Date.now();
        state.stopped = false;
        state.paused = false;

        els.btnRetry.style.display = 'none';
        var noimage = q('josra-noimage');
        ajaxPost('buildqueue', { types: typeIds, mode: mode, noimage: noimage && noimage.checked ? 1 : 0 }, function (resp) {
            if (!resp.success) {
                alert('Error al construir la cola: ' + (resp.error || 'desconocido'));
                els.btnStart.disabled = false;
                els.btnStart.textContent = 'Iniciar regeneración';
                return;
            }

            state.total = resp.total;
            state.done = 0;
            state.errors = 0;
            updateProgressBar();

            if (resp.total === 0) {
                els.btnStart.disabled = false;
                els.btnStart.textContent = 'Iniciar regeneración';
                alert('No hay nada que regenerar con esas opciones.' + (resp.reason ? '\n\n' + resp.reason : ''));
                return;
            }

            els.btnStart.textContent = 'Procesando...';
            els.btnPause.disabled = false;
            processLoop();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        els.btnStart = q('josra-btn-start');
        els.btnPause = q('josra-btn-pause');
        els.btnResume = q('josra-btn-resume');
        els.bar = q('josra-progress-bar');
        els.text = q('josra-progress-text');
        els.progressWrapper = q('josra-progress-wrapper');
        els.errorLogWrapper = q('josra-error-log');
        els.errorLog = q('josra-error-log-content');
        els.logWrapper = q('josra-log-wrapper');
        els.logContent = q('josra-log-content');
        els.finishedBanner = q('josra-finished-banner');
        els.finishedText = q('josra-finished-text');
        els.btnRetry = q('josra-btn-retry');

        els.btnRetry.addEventListener('click', function () {
            els.btnRetry.style.display = 'none';
            els.finishedBanner.style.display = 'none';
            ajaxPost('retryerrors', {}, function (resp) {
                if (!resp.success) {
                    alert('Error: ' + (resp.error || 'desconocido'));
                    return;
                }
                state.total = resp.total;
                state.done = resp.done;
                state.errors = resp.errors;
                state.startTime = Date.now();
                state.stopped = false;
                state.paused = false;
                updateProgressBar();
                els.bar.classList.remove('progress-bar-success', 'progress-bar-warning');
                els.bar.classList.add('progress-bar-striped', 'active');
                els.errorLog.innerHTML = '';
                els.errorLogWrapper.style.display = 'none';
                els.btnStart.disabled = true;
                els.btnStart.textContent = 'Procesando...';
                els.btnPause.disabled = false;
                processLoop();
            });
        });

        var modeRadios = document.querySelectorAll('input[name="mode"]');
        var forceWarning = q('josra-force-warning');

        modeRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                forceWarning.style.display = (this.value === 'force' && this.checked) ? 'block' : 'none';
            });
        });

        getCheckedTypeCheckboxes().forEach(function (cb) {
            cb.addEventListener('change', updateEstimate);
        });

        q('josra-select-all').addEventListener('click', function () {
            getCheckedTypeCheckboxes().forEach(function (cb) { cb.checked = true; });
            updateEstimate();
        });

        q('josra-select-none').addEventListener('click', function () {
            getCheckedTypeCheckboxes().forEach(function (cb) { cb.checked = false; });
            updateEstimate();
        });

        els.btnStart.addEventListener('click', startRun);

        els.btnPause.addEventListener('click', function () {
            state.paused = true;
            els.btnPause.style.display = 'none';
            els.btnResume.style.display = 'inline-block';
        });

        els.btnResume.addEventListener('click', function () {
            state.paused = false;
            els.btnResume.style.display = 'none';
            els.btnPause.style.display = 'inline-block';
            if (!state.running) {
                processLoop();
            }
        });

        updateEstimate();
    });
})();
