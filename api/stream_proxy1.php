<?php
// stream_proxy.php

// Función auxiliar para renderizar la página con el modal de error sin romper el diseño
function mostrar_pantalla_error($mensaje_error = "Error - Comuníquese con su proveedor") {
    header("Content-Type: text/html; charset=UTF-8");
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Player Proxy - Error</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="cssmp4.css"/>
        <style>
            /* Modal de alerta sobrepuesto arriba del reproductor */
            .modal-alerta-error {
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background-color: rgba(220, 53, 69, 0.95);
                color: #fff;
                padding: 15px 30px;
                border-radius: 8px;
                font-family: Arial, sans-serif;
                font-size: 16px;
                font-weight: bold;
                z-index: 99999;
                box-shadow: 0 4px 15px rgba(0,0,0,0.5);
                text-align: center;
                border: 1px solid #ff8585;
                min-width: 300px;
            }
        </style>
    </head>
    <body style="margin:0; padding:0; background:#000;">

        <div class="modal-alerta-error">
            <i class="fa-solid fa-circle-exclamation" style="margin-right: 10px;"></i>
            <?php echo htmlspecialchars($mensaje_error); ?>
        </div>

        <div class="player-container" id="playerContainer" style="display: block; pointer-events: none; opacity: 0.4;">
            <video id="myVideo" playsinline preload="metadata"></video>
            <div class="loader" id="spinner" style="display:none;"></div>
            <div class="controls-overlay" id="controlsOverlay"></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Validaciones iniciales (Reemplazadas de JSON a pantalla de error amigable)
if (!isset($_GET['url']) || empty($_GET['url'])) {
    mostrar_pantalla_error();
}

$target_url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
if (!$target_url) {
    mostrar_pantalla_error();
}

$url_parts = parse_url($target_url);
$base_domain = $url_parts['scheme'] . '://' . $url_parts['host'];

$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36\r\n" .
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8\r\n" .
                    "Accept-Language: es-ES,es;q=0.9,en;q=0.8\r\n" .
                    "Referer: " . $base_domain . "/\r\n" . 
                    "Origin: " . $base_domain . "\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($target_url, false, $context);

if ($html === FALSE) {
    mostrar_pantalla_error();
}

// ==========================================
// FUNCIÓN PARA DESOFUSCAR 'PACKER' (eval(function(p,a,c,k,e,d)))
// ==========================================
function unpacker($js) {
    if (!preg_match('/eval\(function\(p,a,c,k,e,d\).*?\}\(\'(.*?)\'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*\'(.*?)\'\.split\(\'\|\'\)/s', $js, $matches)) {
        return $js;
    }

    $p = $matches[1];
    $a = (int)$matches[2];
    $c = (int)$matches[3];
    $k = explode('|', $matches[4]);

    while ($c--) {
        if (!empty($k[$c])) {
            $p = preg_replace_with_boundaries($c, $k[$c], $a, $p);
        }
    }
    return $p;
}

function preg_replace_with_boundaries($index, $word, $base, $source) {
    $search = base_convert_string($index, $base);
    return preg_replace('/\b' . preg_quote($search, '/') . '\b/', $word, $source);
}

function base_convert_string($num, $base) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($base <= 36) {
        return base_convert($num, 10, $base);
    }
    $res = '';
    while ($num > 0) {
        $res = $chars[$num % $base] . $res;
        $num = intval($num / $base);
    }
    return $res == '' ? '0' : $res;
}

// ==========================================
// EXTRACCIÓN Y BÚSQUEDA DE FUENTES (VIDEO Y CC)
// ==========================================

if (strpos($html, 'eval(function(p,a,c,k,e,d)') !== false) {
    $html .= "\n/* --- Bloque Desofuscado --- */\n" . unpacker($html);
}

$video_urls = [];
$subtitles_urls = [];

$html_normalized = preg_replace('/1d:\/\//i', 'https://', $html);

// 1. Buscar videos y subtítulos en etiquetas HTML5 estándar
if (preg_match_all('/<(?:source|video)\b[^>]*src=["\']([^"\']+)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $video_urls[] = $url; }
}
if (preg_match_all('/<track\b[^>]*src=["\']([^"\']+)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $subtitles_urls[] = $url; }
}

// 2. Buscar URLs directas en el código
if (preg_match_all('/["\'](https?:\/\/[^"\']+\.(?:mp4|m3u8|mpd|webm)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $video_urls[] = $url; }
}
if (preg_match_all('/["\'](https?:\/\/[^"\']+\.(?:vtt|srt)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $subtitles_urls[] = $url; }
}

// 3. Buscar variables JS estructuradas u ocultas
if (preg_match_all('/(?:file|src|source|\"1o\"|\"1c\"|\"1g\")\s*[:=]\s*["\']([^"\']+\.(?:mp4|m3u8|mpd|webm)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $video_urls[] = $url; }
}
if (preg_match_all('/(?:subtitle|cc|track|vtt|sub|\"caption\")\s*[:=]\s*["\']([^"\']+\.(?:vtt|srt)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $subtitles_urls[] = $url; }
}

// 4. Buscar dentro de estructuras de <span>
if (preg_match_all('/<span\b[^>]*>(https?:\/\/[^<]+\.(?:mp4|m3u8|vtt|srt)[^<]*)<\/span>/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) {
        $url = trim($url);
        if (preg_match('/\.(?:vtt|srt)/i', $url)) {
            $subtitles_urls[] = $url;
        } else {
            $video_urls[] = $url;
        }
    }
}

// Limpieza y formateo de URLs finales
function clean_proxied_urls($urls_array, $base_domain) {
    $urls_array = array_unique($urls_array);
    $cleaned = [];
    foreach ($urls_array as $url) {
        $url = stripslashes(trim($url));
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $base_domain . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $cleaned[] = $url;
        }
    }
    return array_values($cleaned);
}

$final_urls = clean_proxied_urls($video_urls, $base_domain);
$final_subs = clean_proxied_urls($subtitles_urls, $base_domain);

// ==========================================
// RESPUESTA Y RENDERIZADO DEL REPRODUCTOR
// ==========================================
if (!empty($final_urls)) {
    $currentStreamUrl = $final_urls[0];
    header("Content-Type: text/html; charset=UTF-8");
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Player Proxy</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="pVID.css"/>
        <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
        <script type="text/javascript" src="scrolltv.js"></script>
    </head>
    <body style="margin:0; padding:0; background:#000;">

        <div class="player-container" id="playerContainer" style="display: none;">
            <video id="myVideo" playsinline preload="metadata">
                <?php 
                foreach ($final_subs as $index => $sub_url) {
                    $label = ($index === 0) ? "Español (External)" : "Subtítulo " . ($index + 1);
                    echo '<track src="' . htmlspecialchars($sub_url) . '" kind="subtitles" srclang="es" label="' . htmlspecialchars($label) . '">';
                }
                ?>
            </video>
            <div class="toast-notification" id="toastHUD">Audio: Español</div>
            <div class="loader" id="spinner"></div>
            <button class="center-play-btn" id="playBtn">
                <i class="fa-solid fa-play" style="margin-left: 4px;"></i>
            </button>
            <div class="controls-overlay" id="controlsOverlay">
                <div class="time-display">
                    <span id="currentTime">00:00:00</span>
                    <span id="duration">00:00:00</span>
                </div>
                <div class="progress-container" id="progressContainer" tabindex="0">
                    <div class="progressBar" id="progressBar"></div>
                </div>
                <div class="bottom-buttons">
                    <div class="left-buttons">
                        <button class="btn-ctrl" id="btnBack"><i class="fa-solid fa-arrow-rotate-left"></i></button>
                        <button class="btn-ctrl" id="btnForward"><i class="fa-solid fa-arrow-rotate-right"></i></button>
                    </div>
                    <div class="right-buttons">
                        <button class="btn-ctrl" id="audioBtn"><i class="fa-solid fa-headphones"></i></button>
                        <button class="btn-ctrl" id="ccBtn">cc</button>
                        <button class="btn-ctrl" id="aspectBtn"><i class="fa-solid fa-expand"></i></button>
                        <button class="quality-btn-badge" id="qualityBtn">AUTO</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="pVID.js"></script>
        <script type="text/javascript">
            const currentStreamUrl = "<?php echo $currentStreamUrl; ?>";

            function reproducirUrl() {
                if (!currentStreamUrl) return;

                if (typeof window.playCustomVideo === 'function') {
                    window.playCustomVideo(currentStreamUrl);
                } else {
                    console.error("Error - Comuníquese con su proveedor.");
                }
            }

            document.addEventListener("DOMContentLoaded", function() {
                reproducirUrl();
            });
        </script>
    </body>
    </html>
    <?php
} else {
    // Si no se encuentra ningún link multimedia válido tras analizar el HTML
    mostrar_pantalla_error();
}
?>