<div class="panel" id="josra-regenimages-panel">
    <div class="panel-heading">
        <i class="icon-picture"></i> Josra - Regenerar imágenes (AJAX)
    </div>

    <div class="alert alert-info">
        Regenera las miniaturas por lotes vía AJAX, sin sobrecargar el servidor. Puedes cerrar la pestaña
        y volver luego: el progreso queda guardado en base de datos.
    </div>

    <div class="josra-body">
    <form id="josra-regenimages-form" onsubmit="return false;">

        <div class="form-group">
            <label>1. ¿Qué tamaños quieres regenerar?</label>
            <button type="button" class="btn btn-sm btn-default btn-outline-secondary" id="josra-select-all">Marcar todo</button>
            <button type="button" class="btn btn-sm btn-default btn-outline-secondary" id="josra-select-none">Desmarcar todo</button>

            <div class="josra-grid">
                {foreach from=$entities key=ekey item=entity}
                {if $image_types[$ekey]|@count}
                <div class="josra-entity-card">
                        <div class="josra-card-heading">
                            {$entity.label|escape:'html':'UTF-8'}
                            <span class="badge">{$entity_counts[$ekey]|number_format:0:',':'.'} imágenes origen</span>
                        </div>
                        <div class="josra-card-body">
                            {foreach from=$image_types[$ekey] item=type}
                            <div class="checkbox"><label><input type="checkbox" class="josra-type-checkbox" name="types[]"
                                       value="{$type.id_image_type|intval}" data-entity="{$ekey|escape:'html':'UTF-8'}" checked> {$type.name|escape:'html':'UTF-8'} <span class="text-muted">({$type.width|intval}&times;{$type.height|intval}px)</span>
                                {if $type.fitment == 'crop'}<span class="label label-warning josra-fit" title="Rellena la medida y recorta lo que sobra">recortar</span>
                                {elseif $type.fitment == 'bound'}<span class="label label-info josra-fit" title="Mantiene la proporción de la original, sin rellenar ni ampliar">proporción original</span>
                                {elseif $type.fitment == 'fit'}<span class="label label-default josra-fit" title="Mete la foto entera y rellena lo que sobra">ajustar y rellenar</span>{/if}
                            </label></div>
                            {/foreach}
                        </div>
                </div>
                {/if}
                {/foreach}
            </div>
            {if $has_fitment}
            <p class="help-block">
                La etiqueta de cada tamaño es su encaje (Diseño &gt; Ajustes de imágenes{if $image_settings_url} &middot; <a href="{$image_settings_url|escape:'html':'UTF-8'}" target="_blank">abrir</a>{/if}).
                Si cambias el encaje de un tamaño, regenéralo con <strong>«Forzar»</strong>: las miniaturas que ya existen no cambian solas.
            </p>
            {/if}

            <p id="josra-estimate" class="text-muted" style="margin-top:8px;">
                <i class="icon-info-circle"></i> Estimación: <strong><span id="josra-estimate-count">0</span></strong> archivos a generar con la selección actual.
            </p>
        </div>

        <div class="form-group">
            <label>2. Modo</label>
            <div class="radio"><label>
                <input type="radio" name="mode" value="missing" checked>
                <strong>Solo las que faltan</strong> <span class="text-muted">(rápido; para imágenes nuevas o formatos nuevos como webp)</span>
            </label></div>
            <div class="radio"><label>
                <input type="radio" name="mode" value="mismatch">
                <strong>Las que faltan o tienen otra medida</strong> <span class="text-muted">(después de cambiar el ancho o el alto de un tamaño; no detecta cambios de encaje)</span>
            </label></div>
            <div class="radio"><label>
                <input type="radio" name="mode" value="force">
                <strong>Forzar regeneración completa</strong> <span class="text-muted">(después de cambiar el encaje, o para rehacerlo todo)</span>
            </label></div>
            <div class="checkbox"><label>
                <input type="checkbox" id="josra-noimage" checked>
                Incluir las imágenes de «sin foto» (las que salen en productos, categorías o marcas sin imagen)
            </label></div>
            {if $watermark_modules}
            <div class="checkbox"><label>
                <input type="checkbox" id="josra-watermark">
                Aplicar la marca de agua ({$watermark_modules|escape:'html':'UTF-8'}) a cada imagen de producto al terminarla
            </label></div>
            <p class="help-block">Márcalo solo en modo «Forzar» y con todos los tamaños que llevan marca de agua: sobre una miniatura que no se ha regenerado, la marca se pondría dos veces.</p>
            {/if}

            <div id="josra-force-warning" class="alert alert-warning" style="display:none; margin-top:10px;">
                <i class="icon-warning"></i>
                <strong>Atención:</strong> en modo "forzar" se regenera desde cero, a partir de la imagen
                <strong>original</strong>, cada tamaño que hayas marcado arriba, sustituyendo la miniatura
                antigua por la nueva (intercambio atómico, sin dejar la web con imágenes rotas mientras se procesa).
                Los tamaños que NO marques no se tocan para nada. Esta acción no se puede deshacer.
            </div>
        </div>

        <div class="form-group">
            <label>3. Formatos</label>
            <p class="form-control-static">
                {foreach from=$image_formats item=fmt name=fmts}<span class="label label-default">{$fmt|escape:'html':'UTF-8'}</span>{if !$smarty.foreach.fmts.last} {/if}{/foreach}
            </p>
            <p class="help-block">Se generan los mismos formatos que en la regeneración de PrestaShop (siempre jpg, más los activados en Diseño &gt; Ajustes de imágenes). Así la tienda nunca enlaza un .webp o .avif que no exista.</p>
        </div>

        <div class="form-group">
            <label for="josra-batch-size">Tamaño de lote (imágenes por petición AJAX)</label>
            <input type="number" id="josra-batch-size" name="batch_size" min="1" max="200" value="{$batch_size}" style="width:100px;">
            <p class="help-block">Más bajo = menos carga en el servidor pero más peticiones. 20-30 es un buen valor por defecto.</p>
        </div>

        <div class="form-group">
            <button type="button" id="josra-btn-start" class="btn btn-primary btn-lg">
                <i class="icon-play"></i> Iniciar regeneración
            </button>
            <button type="button" id="josra-btn-pause" class="btn btn-default" disabled>
                <i class="icon-pause"></i> Pausar
            </button>
            <button type="button" id="josra-btn-resume" class="btn btn-default" style="display:none;">
                <i class="icon-play"></i> Reanudar
            </button>
            <button type="button" id="josra-btn-retry" class="btn btn-default" style="display:none;">
                <i class="icon-refresh"></i> Reintentar las que dieron error
            </button>
        </div>
    </form>

    <div id="josra-progress-wrapper" style="display:none;">
        <div class="progress" style="height:26px;">
            <div id="josra-progress-bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%; line-height:26px;">
                0%
            </div>
        </div>
        <p id="josra-progress-text">0 / 0</p>
    </div>

    <div id="josra-finished-banner" class="alert alert-success" style="display:none;">
        <i class="icon-check"></i> <strong id="josra-finished-text"></strong>
    </div>

    <div id="josra-log-wrapper" style="display:none;">
        <h4>Actividad <small class="text-muted">(últimas <span id="josra-log-count">0</span> imágenes procesadas)</small></h4>
        <div id="josra-log-content"></div>
    </div>

    <div id="josra-error-log" style="display:none;">
        <h4>Errores</h4>
        <div id="josra-error-log-content"></div>
    </div>
</div>
</div>

<script type="text/javascript">
    var josraRegenImagesConfig = {
        ajaxUrl: {$ajax_url|json_encode},
        token: {$token|json_encode},
        entityCounts: {$entity_counts|json_encode}
    };
</script>
