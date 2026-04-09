<?php
// robot_dashboard.php

// --- 1. BÖLÜM: BACKEND VE API (Kaynak 2'den Alındı) ---
// Hataları arayüze yansıtma (JSON bozulmasın)
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// --- AYARLAR ---
$captureFolderName = 'captures';
$host = 'localhost';
$db   = 'robot_db';
$user = 'root';
$pass = ''; // Şifreniz varsa buraya yazın
$charset = 'utf8mb4';

// --- VERİTABANI BAĞLANTISI VE OTOMATİK KURULUM ---
$dsn = "mysql:host=$host;charset=$charset";
try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Veritabanını yoksa oluştur
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $db");
    $pdo->exec("USE $db");
    
    // Tablo: Durum
    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_status (
        id INT PRIMARY KEY DEFAULT 1,
        robot_id VARCHAR(50) DEFAULT 'WALLE_UNIT_01',
        on_mesafe FLOAT DEFAULT 0,
        durum VARCHAR(50) DEFAULT 'bekleniyor',
        egim_y FLOAT DEFAULT 0,
        alarm_durumu BOOLEAN DEFAULT 0,
        aktif_komut VARCHAR(20) DEFAULT 'START',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Varsayılan satır kontrolü
    $check = $pdo->query("SELECT count(*) FROM robot_status WHERE id=1")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT IGNORE INTO robot_status (id, robot_id) VALUES (1, 'WALLE_UNIT_01')");
    }

    // Tablo: Loglar
    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        olay VARCHAR(255) NOT NULL,
        tur VARCHAR(50) DEFAULT 'info',
        zaman TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

} catch (\PDOException $e) {
    // Veritabanı hatası varsa pdo'yu null yap, arayüz offline görünsün
    $pdo = null;
}

// Klasör oluşturma
$captureFolderDisk = __DIR__ . DIRECTORY_SEPARATOR . $captureFolderName;
if (!file_exists($captureFolderDisk)) { @mkdir($captureFolderDisk, 0777, true); }

// --- API İŞLEMLERİ ---
if (isset($_POST['action'])) {
    ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$pdo) {
        echo json_encode(['durum' => 'db_hatasi', 'msg' => 'Veritabanı Bağlantısı Yok']);
        exit;
    }

    handleApiRequest($pdo);
    exit;
}

// --- FONKSİYONLAR (Kaynak 2 Mantığı) ---
function handleApiRequest($pdo) {
    $act = $_POST['action'];
    
    if ($act == 'save_data') {
        // Robot veriyi buraya atar
        $om = floatval($_POST['on_mesafe'] ?? 0);
        $st = $_POST['durum'] ?? 'bilinmiyor';
        $eg = floatval($_POST['egim_y'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("UPDATE robot_status SET on_mesafe=?, durum=?, egim_y=? WHERE id=1");
            $stmt->execute([$om, $st, $eg]);
            // Robota aktif komutu geri dön (START/STOP)
            $row = $pdo->query("SELECT aktif_komut FROM robot_status WHERE id=1")->fetch();
            echo $row['aktif_komut'] ?? 'STOP';
        } catch (Exception $e) { echo 'STOP'; }
    }
    
    elseif ($act == 'get_data') {
        // Arayüz veriyi buradan çeker
        $stmt = $pdo->query("SELECT * FROM robot_status WHERE id=1");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) { echo json_encode(['durum' => 'veri_yok']); return; }

        // 15 saniye veri gelmezse offline say
        if ((time() - strtotime($data['updated_at'])) > 15) $data['durum'] = 'bağlantı_yok';
        
        $data['alarm_durumu'] = (bool)$data['alarm_durumu'];
        $data['zaman'] = $data['updated_at'];
        echo json_encode($data);
    }
    
    elseif ($act == 'get_images') {
        global $captureFolderDisk, $captureFolderName;
        $images = [];
        if (is_dir($captureFolderDisk)) {
            $files = scandir($captureFolderDisk);
            foreach ($files as $f) {
                if ($f == '.' || $f == '..') continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png'])) {
                    $full = $captureFolderDisk . DIRECTORY_SEPARATOR . $f;
                    $images[] = ['src' => $captureFolderName.'/'.$f, 'ts' => filemtime($full), 'time' => date("H:i:s", filemtime($full))];
                }
            }
            usort($images, function($a, $b){ return $b['ts'] - $a['ts']; });
        }
        echo json_encode(array_slice($images, 0, 12));
    }
    
    elseif ($act == 'get_logs') {
        $logs = $pdo->query("SELECT * FROM robot_logs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach($logs as &$l) $l['zaman'] = date("H:i:s", strtotime($l['zaman']));
        echo json_encode($logs);
    }
    
    elseif ($act == 'start_robot') {
        $pdo->query("UPDATE robot_status SET aktif_komut='START' WHERE id=1");
        addLog($pdo, "Sistem Manuel Başlatıldı", "info");
        echo json_encode(['status'=>'ok']);
    }
    
    elseif ($act == 'stop_robot') {
        $pdo->query("UPDATE robot_status SET aktif_komut='STOP' WHERE id=1");
        addLog($pdo, "ACİL DURDURMA AKTİF!", "warning");
        echo json_encode(['status'=>'ok']);
    }
    
    elseif ($act == 'log_detection') {
        addLog($pdo, "⚠ İNSAN/TEHDİT ALGILANDI", "danger");
    }
}

function addLog($pdo, $msg, $type) {
    $pdo->prepare("INSERT INTO robot_logs (olay, tur) VALUES (?, ?)")->execute([$msg, $type]);
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WALL-E Komuta Merkezi</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Share+Tech+Mono&family=Roboto:wght@300;400&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #0b0c10;
            --primary-gold: #ff9d00;
            --card-bg: rgba(11, 12, 16, 0.9);
            --text-main: #fff;
            --alert-red: #e74c3c;
            --hud-cyan: #00f3ff;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; transition: all 0.3s ease; }
        
        body { 
            background: var(--bg-dark);
            color: var(--text-main); 
            font-family: 'Roboto', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.alarm-mode { animation: redAlert 0.8s infinite alternate; }
        @keyframes redAlert { from {box-shadow: inset 0 0 0 red;} to {box-shadow: inset 0 0 100px red;} }
        /*YÜKLEME SAYFASI*/
        #welcome-screen {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: #000; z-index: 9999;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
        }
        .robot-container { position: relative; width: 100%; height: 300px; }
        .walle-wrapper { position: absolute; left: -200px; top: 50%; transform: translateY(-50%); width: 160px; height: 180px; }
        .head { position: absolute; top: 0; left: 30px; width: 100px; height: 60px; z-index: 5; animation: lookAround 4s infinite; transform-origin: bottom center; }
        .eye-housing { width: 45px; height: 50px; background: #bdc3c7; border-radius: 20px 20px 40px 40px; border: 4px solid #7f8c8d; float: left; margin-right: 5px; position: relative; box-shadow: inset 0 0 10px #000; }
        .eye-lens { width: 25px; height: 25px; background: #111; border-radius: 50%; position: absolute; top: 10px; left: 6px; border: 2px solid #333; }
        .eye-glint { width: 6px; height: 6px; background: rgba(255,255,255,0.8); border-radius: 50%; position: absolute; top: 14px; left: 12px; }
        .body-box { position: absolute; top: 55px; left: 10px; width: 140px; height: 110px; background: linear-gradient(180deg, #f39c12, #d35400); border-radius: 10px; border: 3px solid #5d4037; box-shadow: inset 0 0 20px rgba(0,0,0,0.6); z-index: 4; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .solar-charge-meter { width: 80%; height: 15px; background: #222; border: 1px solid #555; margin-bottom: 10px; display: flex; padding: 2px; }
        .charge-bar { height: 100%; width: 0%; background: #f1c40f; box-shadow: 0 0 5px #f1c40f; transition: width 0.2s; }
        .logo-text { font-family: 'Arial Black', sans-serif; font-size: 1.2rem; color: #333; letter-spacing: -1px; }
        .logo-circle { display:inline-block; width:8px; height:8px; background:#333; border-radius:50%; }
        .track-left, .track-right { position: absolute; bottom: -10px; width: 30px; height: 140px; background: #2c3e50; border-radius: 15px; border: 2px solid #111; z-index: 3; transform: skewX(-10deg); }
        .track-left { left: -10px; height: 100px; bottom: 0; }
        .track-right { right: -10px; height: 100px; bottom: 0; transform: skewX(10deg); }
        .tread-pattern { width: 100%; height: 100%; background: repeating-linear-gradient(0deg, #111, #111 5px, #34495e 5px, #34495e 10px); border-radius: 15px; animation: treadRoll 0.5s linear infinite; }
        @keyframes walleWalk { 0% { left: -250px; transform: translateY(-30%) rotate(0deg); } 40% { left: 44.8%; transform: translateY(-30%) rotate(0deg); } 50% { left: 44.8%; transform: translateY(-30%) rotate(2deg); } 60% { left: 44.8%; transform: translateY(-30%) rotate(0deg); } 100% { left: 150%; transform: translateY(-30%); } }
        @keyframes lookAround { 0%, 100% { transform: rotate(0deg); } 20% { transform: rotate(-15deg) translateY(5px); } 80% { transform: rotate(15deg) translateY(5px); } }
        @keyframes treadRoll { from {background-position: 0 0;} to {background-position: 0 20px;} }
        .loading-info { margin-top: 20px; font-family: 'Share Tech Mono', monospace; font-size: 2rem; color: var(--primary-gold); text-shadow: 0 0 10px var(--primary-gold); text-align: center; }
        #main-content { display: none; padding: 20px; opacity: 0; flex: 1; }
        .container { max-width: 1300px; margin: 0 auto; }

        /* Header */
        header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 30px; background: rgba(11, 12, 16, 0.8); backdrop-filter: blur(5px);
            border-bottom: 1px solid rgba(255, 157, 0, 0.2); margin-bottom: 30px; border-radius: 8px;
        }
        .header-left { display: flex; align-items: center; gap: 20px; }
        .logo-link { text-decoration: none; display: flex; align-items: center; }
        .logo-link img { height: 50px; margin-right: 15px; }
        .logo-link span { color: var(--primary-gold); font-family: 'Orbitron', sans-serif; font-size: 1.5rem; font-weight: bold; }
        .header-info { color: #8892b0; font-size: 0.9rem; font-family: 'Share Tech Mono'; border-left: 1px solid #333; padding-left: 20px; margin-left: 20px; }

        .btn-home {
            text-decoration: none; border: 1px solid var(--primary-gold); padding: 10px 25px;
            border-radius: 5px; color: var(--primary-gold); font-weight: bold;
            font-family: 'Orbitron', sans-serif; font-size: 0.9rem;
        }
        .btn-home:hover { background-color: var(--primary-gold); color: #000; box-shadow: 0 0 15px var(--primary-gold); }

        /* Kartlar */
        .grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 20px; }
        .card {
            background: var(--card-bg); border: 1px solid var(--primary-gold); border-radius: 8px; padding: 20px;
            box-shadow: 0 0 20px rgba(0,0,0,0.5); position: relative; overflow: hidden;
        }
        .card h3 { font-family: 'Orbitron', sans-serif; font-size: 1.2rem; color: var(--primary-gold); border-bottom: 1px dashed #333; padding-bottom: 10px; margin-bottom: 15px; }
        .big-val { font-family: 'Share Tech Mono', monospace; font-size: 3.5rem; font-weight: bold; color: #fff; text-shadow: 0 0 10px rgba(255,255,255,0.3); }
        .unit { font-size: 1rem; color: #8892b0; margin-left: 5px; }
        
        .btn {
            width: 100%; padding: 15px; margin-bottom: 10px; background: transparent;
            border: 1px solid var(--primary-gold); color: var(--primary-gold);
            font-family: 'Orbitron', sans-serif; font-weight: bold; cursor: pointer; text-transform: uppercase;
        }
        .btn:hover { background: var(--primary-gold); color: #000; box-shadow: 0 0 15px var(--primary-gold); }
        .btn-stop { border-color: var(--alert-red); color: var(--alert-red); }
        .btn-stop:hover { background: var(--alert-red); color: #fff; box-shadow: 0 0 15px var(--alert-red); }

        .logs { height: 200px; overflow-y: auto; font-family: 'Share Tech Mono'; font-size: 0.9rem; background: rgba(0,0,0,0.5); padding: 10px; border-radius: 5px; border: 1px solid #333; }
        .log-row { padding: 5px 0; border-bottom: 1px solid #222; display: flex; justify-content: space-between; }
        .log-danger { color: var(--alert-red); font-weight: bold; }
        .log-info { color: var(--hud-cyan); }

        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
        .p-item { aspect-ratio: 4/3; border: 1px solid #333; cursor: pointer; position: relative; overflow: hidden; border-radius: 4px;}
        .p-item img { width: 100%; height: 100%; object-fit: cover; transition: 0.3s; }
        .p-item:hover img { transform: scale(1.1); }
        .p-overlay { position: absolute; bottom: 0; width: 100%; background: rgba(0,0,0,0.8); color: #fff; font-family: 'Share Tech Mono'; text-align: center; font-size: 0.8rem; padding: 2px 0;}

        footer { background: #050608; padding: 20px; text-align: center; color: #444; font-size: 0.9rem; margin-top: auto; }

        #toast {
            position: fixed; top: -100px; right: 20px; background: #111; border-left: 5px solid var(--primary-gold);
            padding: 15px 30px; z-index: 10000; color: #fff; font-family: 'Orbitron'; box-shadow: 0 5px 20px rgba(0,0,0,0.5);
        }
        #toast.show { top: 30px; }
    </style>
</head>
<body>

    <div id="welcome-screen">
        <div class="robot-container">
            <div class="walle-wrapper" id="walleRobot">
                <div class="head">
                    <div class="eye-housing"><div class="eye-lens"><div class="eye-glint"></div></div></div>
                    <div class="eye-housing"><div class="eye-lens"><div class="eye-glint"></div></div></div>
                </div>
                <div class="body-box">
                    <div class="solar-charge-meter"><div class="charge-bar" id="solarBar"></div></div>
                    <div class="logo-text">WALL<span class="logo-circle"></span>E</div>
                    <div style="font-size:0.6rem; color:#333; margin-top:5px">SOLAR CHARGE LEVEL</div>
                </div>
                <div class="track-left"><div class="tread-pattern"></div></div>
                <div class="track-right"><div class="tread-pattern"></div></div>
            </div>
        </div>
        <div class="loading-info">
            <div>SOLAR ŞARJ DOLUYOR... <span id="pct">0%</span></div>
            <div style="font-size: 1rem; color: #7f8c8d; margin-top: 10px;">VERİTABANI BAĞLANTISI KURULUYOR</div>
        </div>
    </div>

    <div id="main-content">
        <div id="toast">Mesaj</div>

        <div class="container">
            <header>
                <div class="header-left">
                    <a href="index.html" class="logo-link">
                        <img src="logo.png" alt="Logo">
                        <span>WALL-E PROJECT</span>
                    </a>
                    <div class="header-info">
                        UNIT: 001 / LOC: EARTH<br>CLASS: CLEANER
                    </div>
                </div>
                
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div style="text-align: right;">
                        <div id="connStatus" style="font-size:1.2rem; font-weight:bold; font-family: 'Orbitron'; color:#7f8c8d">BAĞLANTI YOK</div>
                        <div style="font-size:0.8rem; opacity:0.5; font-family: 'Share Tech Mono'">SON SİNYAL: <span id="lastTime">--:--</span></div>
                    </div>
                    <a href="index.html" class="btn-home">ANASAYFA</a>
                </div>
            </header>

            <div class="grid">
                <div class="card" style="grid-column: span 4">
                    <h3>GÖREV DURUMU</h3>
                    <div id="st" class="big-val" style="font-size: 2rem; color: var(--primary-gold)">OFFLINE</div>
                </div>

                <div class="card" style="grid-column: span 4">
                    <h3>ENGEL MESAFESİ</h3>
                    <div class="big-val" id="dist">0<span class="unit">CM</span></div>
                </div>

                <div class="card" style="grid-column: span 4">
                    <h3>GYRO EĞİM</h3>
                    <div class="big-val" id="slope">0<span class="unit">°</span></div>
                </div>

                <div class="card" style="grid-column: span 6">
                    <h3>MANUEL KONTROL</h3>
                    <div style="display:flex; gap:10px;">
                        <button class="btn" onclick="cmd('start')">▶ GÖREVİ BAŞLAT</button>
                        <button class="btn btn-stop" onclick="cmd('stop')">■ ACİL DURDUR</button>
                    </div>
                </div>

                <div class="card" style="grid-column: span 6">
                    <h3>KARA KUTU LOGLARI</h3>
                    <div id="dbLogs" class="logs">Yükleniyor...</div>
                </div>

                <div class="card" style="grid-column: span 12">
                    <h3>GÖRSEL HAFIZA (KAMERA)</h3>
                    <div id="gallery" class="gallery"></div>
                </div>
            </div>
        </div>
    </div>

    <footer>
        © 2025 WALL-E Project
    </footer>
    <script>
        // --- AÇILIŞ SENARYOSU ---
        document.addEventListener('DOMContentLoaded', () => {
            const robot = document.getElementById('walleRobot');
            const bar = document.getElementById('solarBar');
            const pctTxt = document.getElementById('pct');
            const screen = document.getElementById('welcome-screen');
            const main = document.getElementById('main-content');

            // Yürüme Animasyonunu Başlat
            robot.style.animation = 'walleWalk 4.5s linear forwards';

            // Şarj Dolum Simülasyonu
            let p = 0;
            const intv = setInterval(() => {
                p += 1;
                bar.style.width = p + '%';
                pctTxt.innerText = p + '%';
                
                if(p >= 100) {
                    clearInterval(intv);
                    // Geçiş Yap
                    setTimeout(() => {
                        screen.style.opacity = 0;
                        setTimeout(() => {
                            screen.style.display = 'none';
                            main.style.display = 'block';
                            setTimeout(() => main.style.opacity = 1, 100);
                        }, 800);
                    }, 1000);
                }
            }, 40); // 4 saniyede dolar
        });

        // --- ANA PANEL MANTIĞI (KAYNAK 2 JAVASCRIPT) ---
        let lastImgCount = 0;
        let alarmState = false;

        function toast(msg, type='info') {
            const t = document.getElementById('toast');
            t.innerText = msg;
            t.style.borderLeftColor = (type === 'alert') ? 'var(--alert-red)' : 'var(--walle-yellow)';
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }

        async function cmd(action) {
            try {
                await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: action + '_robot'})
                });
                toast("KOMUT GÖNDERİLDİ: " + action.toUpperCase());
                updateLogs();
            } catch(e) { toast("HATA: Sunucu Yanıt Vermedi", "alert"); }
        }

        async function updateLogs() {
            try {
                const r = await fetch('', { method:'POST', body: new URLSearchParams({action:'get_logs'}) });
                const logs = await r.json();
                let html = '';
                logs.forEach(l => {
                    let cls = (l.tur === 'danger') ? 'log-danger' : 'log-info';
                    html += `<div class="log-row ${cls}">
                                <span>> ${l.olay}</span>
                                <span style="opacity:0.6; font-size:0.9rem">${l.zaman}</span>
                             </div>`;
                });
                document.getElementById('dbLogs').innerHTML = html;
            } catch(e){}
        }

        function updateData() {
            fetch('', { method:'POST', body: new URLSearchParams({action:'get_data'}) })
            .then(r => r.json())
            .then(d => {
                // Veri Eşleştirme
                document.getElementById('dist').innerText = d.on_mesafe || 0;
                document.getElementById('slope').innerText = parseFloat(d.egim_y || 0).toFixed(1);
                
                const timeStr = d.zaman ? d.zaman.split(' ')[1] : '--:--';
                document.getElementById('lastTime').innerText = timeStr;

                // Durum Metni
                let txt = "BEKLEMEDE";
                let isOnline = true;

                if (d.durum === 'bağlantı_yok') { txt = "BAĞLANTI KOPTU"; isOnline = false; }
                else if (d.durum === 'veri_yok') { txt = "VERİ YOK"; isOnline = false; }
                else if (d.durum && d.durum.includes('kacis')) txt = "MANEVRA: KAÇIŞ";
                else if (d.durum === 'analiz_ediliyor') txt = "ANALİZ YAPILIYOR";
                else if (d.aktif_komut === 'START') txt = "GÖREVDE (AKTİF)";

                document.getElementById('st').innerText = txt;
                
                const conn = document.getElementById('connStatus');
                conn.innerText = isOnline ? "ONLINE" : "OFFLINE";
                conn.style.color = isOnline ? "#2ecc71" : "#7f8c8d";

                // Alarm
                if(d.alarm_durumu && !alarmState) {
                    document.body.classList.add('alarm-mode');
                    toast("⚠️ TEHLİKE ALGILANDI!", "alert");
                    updateLogs();
                    alarmState = true;
                } else if (!d.alarm_durumu) {
                    document.body.classList.remove('alarm-mode');
                    alarmState = false;
                }
            }).catch(()=>{});

            // Resimler
            fetch('', { method:'POST', body: new URLSearchParams({action:'get_images', t:Date.now()}) })
            .then(r => r.json())
            .then(imgs => {
                if(imgs.length > lastImgCount && lastImgCount !== 0) toast("📸 Yeni Görüntü Alındı");
                lastImgCount = imgs.length;
                
                const g = document.getElementById('gallery');
                if(imgs.length === 0) g.innerHTML = '<div style="opacity:0.5; padding:10px;">Kayıtlı görüntü yok</div>';
                else {
                    let html = '';
                    imgs.forEach(i => {
                        html += `<div class="p-item" onclick="window.open('${i.src}')">
                                    <img src="${i.src}">
                                    <div class="p-overlay">${i.time}</div>
                                 </div>`;
                    });
                    g.innerHTML = html;
                }
            }).catch(()=>{});
        }

        // Döngüler
        setInterval(updateData, 1000); // 1 sn'de bir veri
        setInterval(updateLogs, 3000); // 3 sn'de bir log
        updateLogs(); // İlk açılışta çek
    </script>
</body>
</html>
