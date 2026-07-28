
<?php
// stream_proxy.php

function mostrar_pantalla_error($mensaje_error = "Error - Comuníquese con su proveedor") {
    header("Content-Type: text/html; charset=UTF-8");
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Player Proxy - Error</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            .modal-alerta-error {
                position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
                background-color: rgba(220, 53, 69, 0.95); color: #fff;
                padding: 15px 30px; border-radius: 8px; font-family: Arial, sans-serif;
                font-size: 16px; font-weight: bold; z-index: 99999; text-align: center;
                min-width: 300px; box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            }
        </style>
    </head>
    <body style="margin:0; padding:0; background:#000;">
        <div class="modal-alerta-error">
            <i class="fa-solid fa-circle-exclamation" style="margin-right: 10px;"></i>
            <?php echo htmlspecialchars($mensaje_error); ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 1. Validar la URL recibida
if (!isset($_GET['url']) || empty($_GET['url'])) {
    mostrar_pantalla_error("Falta el parámetro URL.");
}

$target_url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
if (!$target_url) {
    mostrar_pantalla_error("URL inválida.");
}

// Normalizar URL de Streamwish / Hlswish para asegurar que apunte al "embed" (/e/)
if (strpos($target_url, '/e/') === false) {
    $url_parts = parse_url($target_url);
    $path_clean = trim($url_parts['path'], '/');
    // Si es solo el ID, reconstruir al formato embed
    if (preg_match('/^[a-zA-Z0-9]+$/', $path_clean)) {
        $target_url = $url_parts['scheme'] . '://' . $url_parts['host'] . '/e/' . $path_clean;
    }
}

$url_info = parse_url($target_url);
$base_domain = $url_info['scheme'] . '://' . $url_info['host'];

// 2. Hacer la petición simulando un navegador real para evadir bloqueos básicos
$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Linux; Android 10; Samsung Galaxy A35) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36\r\n" .
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                    "Referer: " . $base_domain . "/\r\n" .
                    "Origin: " . $base_domain . "\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($target_url, false, $context);

if ($html === FALSE) {
    mostrar_pantalla_error("No se pudo conectar con el servidor de streaming.");
}

// 3. Función optimizada para romper el empaquetado 'Packer' de JavaScript
function desofuscar_packer($js) {
    if (!preg_match('/eval\(function\(p,a,c,k,e,d\).*?\}\(\'(.*?)\'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*\'(.*?)\'\.split\(\'\|\'\)/s', $js, $matches)) {
        return $js;
    }
    $p = $matches[1];
    $a = (int)$matches[2];
    $c = (int)$matches[3];
    $k = explode('|', $matches[4]);

    while ($c--) {
        if (!empty($k[$c])) {
            $search = base_convert_custom($c, $a);
            $p = preg_replace('/\b' . preg_quote($search, '/') . '\b/', $k[$c], $p);
        }
    }
    return $p;
}

function base_convert_custom($num, $base) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($base <= 36) return base_convert($num, 10, $base);
    $res = '';
    while ($num > 0) {
        $res = $chars[$num % $base] . $res;
        $num = intval($num / $base);
    }
    return $res == '' ? '0' : $res;
}

// Desofuscar si detectamos el bloque empaquetado clásico de Streamwish
if (strpos($html, 'eval(function(p,a,c,k,e,d)') !== false) {
    $html .= "\n" . desofuscar_packer($html);
}

// 4. Extracción estricta del archivo maestro de transmisión (.m3u8)
$real_m3u8 = "";
if (preg_match('/["\'](https?:\/\/[^"\']+\.m3u8[^"\']*)["\']/i', $html, $matches)) {
    $real_m3u8 = stripslashes($matches[1]);
}

// Si no se encuentra un m3u8 directo, buscar patrones alternativos de fuentes de video ocultas
if (!$real_m3u8 && preg_match('/(?:file|src|source)\s*:\s*["\']([^"\']+(?:mp4|m3u8|mpd)[^"\']*)["\']/i', $html, $matches)) {
    $real_m3u8 = stripslashes($matches[1]);
}

if (empty($real_m3u8)) {
    mostrar_pantalla_error("No se pudo extraer el enlace de video directo (.m3u8). El token expiró o cambió.");
}

// Convertir URL relativa a absoluta si es necesario
if (strpos($real_m3u8, '//') === 0) {
    $real_m3u8 = 'https:' . $real_m3u8;
}

// 5. RENDERIZADO FINAL CON DATOS EXTRAÍDOS
header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Player Proxy - Éxito</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="pVID.css"/>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        body, html { margin: 0; padding: 0; width: 100%; height: 100%; background: #000; font-family: sans-serif; color: #fff; overflow: hidden; }
        .vlc-box { position: fixed; top: 10px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.85); border: 1px solid #ffaa00; padding: 10px 20px; border-radius: 6px; z-index: 9999; text-align: center; max-width: 90%; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        .vlc-btn { background: #ffaa00; color: #000; border: none; padding: 5px 12px; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 5px; font-size: 13px; }
    </style>
</head>
<body style="margin:0; padding:0; background:#000;">

    <div class="vlc-box">
        <span style="font-size: 12px; color: #ddd; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            <i class="fa-solid fa-link" style="color:#ffaa00;"></i> <strong>HLS Directo Detectado:</strong>
        </span>
        <button class="vlc-btn" onclick="copiarEnlaceVLC()">Copiar Link M3U8 para VLC</button>
    </div>

    <div class="player-container" id="playerContainer" style="display: block; width:100%; height:100%;">
        <video id="myVideo" playsinline preload="metadata" style="width:100%; height:100%;"></video>
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
            <div class="progress-container" id="progressContainer">
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

    <script>
        const enlaceM3u8Real = "<?php echo $real_m3u8; ?>";

        function copiarEnlaceVLC() {
            navigator.clipboard.writeText(enlaceM3u8Real).then(() => {
                alert("¡Enlace .m3u8 copiado! Ya puedes pegarlo directamente en tu VLC.");
            }).catch(() => {
                const input = document.createElement('input');
                input.value = enlaceM3u8Real;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                alert("¡Enlace .m3u8 copiado!");
            });
        }

        document.addEventListener("DOMContentLoaded", function() {
            const video = document.getElementById('myVideo');
            
            if (enlaceM3u8Real) {
                // Verificar si se usa la integración personalizada de tu pVID.js
                if (typeof window.playCustomVideo === 'function') {
                    window.playCustomVideo(enlaceM3u8Real);
                } else {
                    // Inicialización limpia estándar usando hls.js para asegurar compatibilidad en Android
                    if (Hls.isSupported()) {
                        const hls = new Hls({
                            xhrSetup: function (xhr, url) {
                                // Forzar el envío del origen simulado para evitar bloqueos del stream en vivo (HTTP 403 Forbidden)
                                xhr.setRequestHeader("Referer", "<?php echo $base_domain; ?>/");
                            }
                        });
                        hls.loadSource(enlaceM3u8Real);
                        hls.attachMedia(video);
                    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                        video.src = enlaceM3u8Real;
                    }
                    
                    video.play().catch(e => console.log("Interacción requerida para reproducir."));
                }
            }
        });
    </script>
    <script src="pVID.js"></script>
</body>
</html>