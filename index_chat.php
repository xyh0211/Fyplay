<?php
/**
 * 修水模具产业园 · 三合一智能翻译器 (PHP + 前端)
 * 部署到PHP服务器即可直接使用，无需额外配置
 * 要求：PHP 7.0+，需开启 curl 扩展
 */

// ============================================================
//  配置
// ============================================================
define('YOUDAO_APP_ID', '7f9dfbd63ef24fc1');
define('YOUDAO_APP_KEY', 'Kehl0JnUzjATYPMaSYseEttRMFHUwEPP');

// ============================================================
//  API 代理处理
// ============================================================
$action = $_GET['action'] ?? '';

if ($action === 'translate') { handleTranslate(); exit; }
if ($action === 'tts') { handleTTS(); exit; }
if ($action === 'asr') { handleASR(); exit; }
if ($action === 'check') { handleCheck(); exit; }

// ============================================================
//  工具函数
// ============================================================
function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function truncate($q) {
    $len = mb_strlen($q, 'UTF-8');
    if ($len <= 20) return $q;
    return mb_substr($q, 0, 10, 'UTF-8') . $len . mb_substr($q, $len - 10, 10, 'UTF-8');
}

function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json;charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorOut($msg, $code = 400) {
    jsonOut(['errorCode' => $code, 'message' => $msg], 200);
}

// 语言代码映射: 前端代码 -> 有道代码
function mapLang($code) {
    $map = ['zh' => 'zh-CHS', 'en' => 'en', 'it' => 'it'];
    return $map[$code] ?? 'zh-CHS';
}

// ============================================================
//  文本翻译代理
// ============================================================
function handleTranslate() {
    $q = $_POST['q'] ?? '';
    $from = mapLang($_POST['from'] ?? 'zh');
    $to = mapLang($_POST['to'] ?? 'en');
    if (!$q) errorOut('请输入翻译文本');

    // 先尝试有道翻译
    $result = youdaoTranslate($q, $from, $to);
    if ($result && isset($result['errorCode']) && $result['errorCode'] === '0') {
        jsonOut([
            'errorCode' => '0',
            'translation' => $result['translation'][0] ?? '',
            'source' => 'youdao'
        ]);
    }

    // 有道失败，尝试 MyMemory
    $mmResult = mymemoryTranslate($q, $from, $to);
    if ($mmResult) {
        jsonOut([
            'errorCode' => '0',
            'translation' => $mmResult,
            'source' => 'mymemory'
        ]);
    }

    $errMsg = '翻译失败';
    if ($result && isset($result['errorCode'])) {
        $ec = $result['errorCode'];
        $errMsg = "翻译错误: {$ec}";
        if ($ec === '203') $errMsg .= ' (IP不在白名单)';
        else if ($ec === '108') $errMsg .= ' (应用ID无效)';
        else if ($ec === '110') $errMsg .= ' (应用未绑定服务)';
        else if ($ec === '202') $errMsg .= ' (签名校验失败)';
        else if ($ec === '205') $errMsg .= ' (接入方式不匹配)';
        else if ($ec === '401') $errMsg .= ' (账户欠费)';
    }
    errorOut($errMsg);
}

function youdaoTranslate($q, $from, $to) {
    $salt = uuid();
    $curtime = time();
    $input = truncate($q);
    $sign = hash('sha256', YOUDAO_APP_ID . $input . $salt . $curtime . YOUDAO_APP_KEY);

    $data = [
        'q' => $q, 'from' => $from, 'to' => $to,
        'appKey' => YOUDAO_APP_ID, 'salt' => $salt, 'sign' => $sign,
        'signType' => 'v3', 'curtime' => $curtime,
    ];

    $ch = curl_init('https://openapi.youdao.com/api');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno) return null;
    return json_decode($result, true);
}

function mymemoryTranslate($text, $from, $to) {
    $map = ['zh-CHS' => 'zh-CN', 'en' => 'en', 'it' => 'it'];
    $s = $map[$from] ?? 'zh-CN';
    $t = $map[$to] ?? 'en';
    $url = 'https://api.mymemory.translated.net/get?q=' . urlencode($text)
         . '&langpair=' . urlencode($s) . '|' . urlencode($t)
         . '&de=translator@example.com';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    if (!$result) return null;
    $data = json_decode($result, true);
    if (($data['responseStatus'] ?? 0) === 200 && !empty($data['responseData']['translatedText'])) {
        return str_replace(['&#39;', '"', '&amp;'], ["'", '"', '&'], $data['responseData']['translatedText']);
    }
    return null;
}

// ============================================================
//  TTS 语音合成代理
// ============================================================
function handleTTS() {
    $q = $_POST['q'] ?? '';
    $voiceName = $_POST['voiceName'] ?? 'youxiaomei';
    if (!$q) errorOut('缺少文本');

    $salt = uuid();
    $curtime = time();
    $input = truncate($q);
    $sign = hash('sha256', YOUDAO_APP_ID . $input . $salt . $curtime . YOUDAO_APP_KEY);

    $data = [
        'q' => $q, 'appKey' => YOUDAO_APP_ID, 'salt' => $salt, 'sign' => $sign,
        'signType' => 'v3', 'curtime' => $curtime, 'voiceName' => $voiceName, 'format' => 'mp3',
    ];

    $ch = curl_init('https://openapi.youdao.com/ttsapi');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $audioData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode === 200 && $audioData && strpos($contentType, 'audio/') === 0) {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($audioData));
        echo $audioData;
        exit;
    }
    $errData = json_decode($audioData, true);
    $ec = $errData['errorCode'] ?? $httpCode;
    errorOut("TTS错误: {$ec}");
}

// ============================================================
//  ASR 短语音识别代理
// ============================================================
function handleASR() {
    $base64Audio = $_POST['q'] ?? '';
    $langType = mapLang($_POST['langType'] ?? 'zh');
    $format = $_POST['format'] ?? 'wav';
    if (!$base64Audio) errorOut('缺少音频数据');

    $salt = uuid();
    $curtime = time();
    $input = truncate($base64Audio);
    $sign = hash('sha256', YOUDAO_APP_ID . $input . $salt . $curtime . YOUDAO_APP_KEY);

    $data = [
        'q' => $base64Audio, 'langType' => $langType,
        'appKey' => YOUDAO_APP_ID, 'salt' => $salt, 'sign' => $sign,
        'signType' => 'v3', 'curtime' => $curtime,
        'format' => $format, 'rate' => '16000', 'channel' => '1', 'type' => '1',
    ];

    $ch = curl_init('https://openapi.youdao.com/asrapi');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    if (!$result) errorOut('ASR请求失败');
    $data = json_decode($result, true);
    if (($data['errorCode'] ?? '') === '0' && !empty($data['result'])) {
        jsonOut(['errorCode' => '0', 'result' => $data['result'][0] ?? $data['result']]);
    }
    $ec = $data['errorCode'] ?? 'unknown';
    errorOut("ASR错误: {$ec}");
}

// ============================================================
//  健康检查
// ============================================================
function handleCheck() {
    $result = youdaoTranslate('Hello', 'en', 'zh-CHS');
    $youdaoOk = $result && ($result['errorCode'] ?? '') === '0';
    jsonOut([
        'status' => $youdaoOk ? 'ok' : 'error',
        'youdao' => $youdaoOk ? 'connected' : ('failed: ' . ($result['errorCode'] ?? 'unknown')),
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}

// ============================================================
//  HTML 前端界面
// ============================================================
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>修水模具产业园 · 三合一智能翻译器 | 手机版</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { background: linear-gradient(145deg, #EFF3EC 0%, #DDE6D8 100%); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', Roboto, 'Noto Sans', system-ui, sans-serif; padding: 12px; padding-bottom: 30px; min-height: 100vh; }
        .app-container { max-width: 600px; margin: 0 auto; }
        .hero { background: linear-gradient(135deg, #1A3A1A, #2C5E2C); border-radius: 28px; padding: 18px 20px; margin-bottom: 16px; color: #FEF7E6; box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
        .hero h1 { font-size: 1.4rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; line-height: 1.3; }
        .hero h1 span { font-size: 0.7rem; background: rgba(255,215,150,0.25); padding: 4px 10px; border-radius: 40px; }
        .hero-badges { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .badge { background: rgba(255,215,150,0.2); padding: 4px 12px; border-radius: 40px; font-size: 0.7rem; }
        .three-columns { display: flex; flex-direction: column; gap: 16px; }
        .column { width: 100%; }
        .card { background: rgba(255,255,250,0.98); border-radius: 28px; box-shadow: 0 6px 16px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid #E9E2D0; }
        .card-header { background: #FCF9F2; padding: 14px 18px; font-weight: 700; font-size: 1.1rem; border-bottom: 3px solid #D4AF37; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
        .card-header span { background: #F0E5D6; padding: 3px 10px; border-radius: 60px; font-size: 0.6rem; color: #6A4E2E; }
        .card-body { padding: 16px; }
        .lang-selector { background: #F2EFE8; border-radius: 50px; padding: 4px; display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .lang-option { background: transparent; border: none; padding: 8px 14px; border-radius: 40px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.75rem; flex: 1; text-align: center; min-width: 70px; font-family: inherit; }
        .lang-option.active { background: #2C5E2C; color: white; }
        .voice-btn { background: linear-gradient(145deg, #2C5E2C, #1F4520); border: none; padding: 12px 18px; border-radius: 50px; color: white; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; transition: 0.2s; margin-right: 8px; margin-bottom: 8px; font-size: 0.85rem; flex: 1; font-family: inherit; }
        .voice-btn.recording { background: #C53A1F; animation: pulse 1.2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(197,58,31,0.4); } 70% { box-shadow: 0 0 0 10px rgba(197,58,31,0); } 100% { box-shadow: 0 0 0 0 rgba(197,58,31,0); } }
        .btn-primary { background: #2C5E2C; border: none; padding: 10px 18px; border-radius: 50px; color: white; font-weight: 600; cursor: pointer; font-size: 0.8rem; flex: 1; text-align: center; font-family: inherit; }
        .btn-primary.playing { background: #7F5539; }
        .btn-secondary { background: #F0EDE2; border: none; padding: 10px 16px; border-radius: 40px; font-weight: 500; cursor: pointer; font-size: 0.75rem; flex: 1; text-align: center; font-family: inherit; }
        .btn-stop { background: #C53A1F; border: none; padding: 10px 16px; border-radius: 40px; color: white; font-weight: 500; cursor: pointer; font-size: 0.75rem; flex: 1; text-align: center; font-family: inherit; }
        .button-group { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        textarea { width: 100%; padding: 12px; border-radius: 20px; border: 1px solid #DACFBB; font-family: inherit; font-size: 0.85rem; resize: vertical; background: white; line-height: 1.4; }
        .trans-result-box { background: #FCFAF5; border-radius: 18px; padding: 12px; margin-top: 14px; border-left: 4px solid #D4AF37; }
        .intro-text { background: #FCFAF2; border-radius: 18px; padding: 14px; margin: 10px 0; line-height: 1.5; font-size: 0.82rem; max-height: 250px; overflow-y: auto; white-space: pre-wrap; }
        .qa-history { background: #FCF9F0; border-radius: 18px; padding: 12px; height: 280px; overflow-y: auto; margin-bottom: 12px; border: 1px solid #EADDBD; font-size: 0.8rem; }
        .qa-item { margin-bottom: 12px; }
        .question-bubble { background: #E9E5D6; padding: 8px 12px; border-radius: 16px 16px 16px 6px; font-weight: 600; font-size: 0.8rem; }
        .answer-bubble { background: #E2F0E2; padding: 8px 12px; border-radius: 16px 16px 6px 16px; margin-left: 10px; border-left: 3px solid #D4AF37; font-size: 0.8rem; line-height: 1.4; }
        .input-row { display: flex; gap: 8px; margin: 10px 0; }
        .input-row input { flex: 1; padding: 10px 14px; border-radius: 50px; border: 1px solid #DACFBB; font-size: 0.8rem; background: white; font-family: inherit; }
        .suggestions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .suggestion-chip { background: #EDE8DF; padding: 6px 12px; border-radius: 40px; font-size: 0.7rem; cursor: pointer; }
        .demo-note { background: #E8F0E8; border-radius: 14px; padding: 8px 10px; margin-bottom: 12px; font-size: 0.68rem; color: #2D5A2D; border-left: 3px solid #D4AF37; line-height: 1.4; }
        .footer { text-align: center; margin-top: 20px; color: #6C8062; font-size: 0.6rem; padding: 10px; }
        .loading { display: inline-block; width: 14px; height: 14px; border: 2px solid #2C5E2C; border-radius: 50%; border-top-color: transparent; animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(80px); background: #1A3A1A; color: #FEF7E6; padding: 10px 20px; border-radius: 30px; font-size: 0.8rem; font-weight: 500; box-shadow: 0 8px 32px rgba(0,0,0,0.15); opacity: 0; transition: all 0.4s ease; z-index: 2000; pointer-events: none; text-align: center; max-width: 90%; white-space: nowrap; }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .rec-bar { display: none; align-items: center; gap: 2px; height: 20px; margin: 6px 0 10px; justify-content: center; }
        .rec-bar.active { display: flex; }
        .rec-bar .b { width: 3px; background: #C53A1F; border-radius: 2px; height: 4px; animation: wave 0.8s ease-in-out infinite alternate; }
        .rec-bar .b:nth-child(1){animation-delay:0.0s;height:8px}
        .rec-bar .b:nth-child(2){animation-delay:0.1s;height:14px}
        .rec-bar .b:nth-child(3){animation-delay:0.2s;height:20px}
        .rec-bar .b:nth-child(4){animation-delay:0.3s;height:16px}
        .rec-bar .b:nth-child(5){animation-delay:0.15s;height:10px}
        .rec-bar .b:nth-child(6){animation-delay:0.25s;height:18px}
        .rec-bar .b:nth-child(7){animation-delay:0.05s;height:12px}
        @keyframes wave{0%{height:3px}100%{height:20px}}
        .hidden { display: none !important; }
        .engine-badge { display: inline-block; font-size: 0.6rem; padding: 2px 8px; border-radius: 10px; margin-left: 6px; font-weight: 600; }
        .engine-badge.youdao { background: #E8F0E8; color: #2C5E2C; }
        .engine-badge.mymemory { background: #FFF7E6; color: #B8860B; }
        input[type="file"]{display:none}
    </style>
</head>
<body>
<div class="app-container">
    <div class="hero">
        <h1>🏭 修水模具产业园<br>三合一智能翻译器 <span>手机版</span></h1>
        <div class="hero-badges">
            <span class="badge">🎤 语音直译</span>
            <span class="badge">📖 智能讲解</span>
            <span class="badge">💬 精准问答</span>
            <span class="badge">⏹️ 可停止播放</span>
        </div>
    </div>

    <div class="three-columns">
        <!-- 板块1：语音直译器 -->
        <div class="column">
            <div class="card">
                <div class="card-header">🎙️ 语音直译器 <span>中⇄英⇄意</span></div>
                <div class="card-body">
                    <div class="demo-note" id="transDemoNote">
                        📌 示例：修水模具产业园建筑面积53万平方米，月产量超过30万件...
                    </div>
                    <div class="lang-selector" id="transLangGroup">
                        <button data-src="zh" class="lang-option active">🇨🇳 中文</button>
                        <button data-src="en" class="lang-option">🇬🇧 English</button>
                        <button data-src="it" class="lang-option">🇮🇹 Italiano</button>
                        <span style="margin:0 2px;">→</span>
                        <button data-tgt="zh" class="lang-option">中文</button>
                        <button data-tgt="en" class="lang-option active">English</button>
                        <button data-tgt="it" class="lang-option">Italiano</button>
                    </div>
                    <div class="rec-bar" id="transRecBar">
                        <div class="b"></div><div class="b"></div><div class="b"></div><div class="b"></div>
                        <div class="b"></div><div class="b"></div><div class="b"></div>
                    </div>
                    <div class="button-group">
                        <button id="voiceTransBtn" class="voice-btn">🎤 语音输入</button>
                        <button id="stopVoiceBtn" class="btn-secondary">⏹️ 停止</button>
                    </div>
                    <textarea id="sourceText" rows="2" placeholder="输入或说出要翻译的文本...">修水模具产业园建筑面积53万平方米，月产量超过30万件，拥有300多名资深设计师。</textarea>
                    <div class="button-group">
                        <button id="translateBtn" class="btn-primary">🔄 翻译</button>
                        <button id="resetExampleBtn" class="btn-secondary">重置</button>
                        <button id="speakResultBtn" class="btn-primary" style="background:#7F5539;">🔊 朗读译文</button>
                        <button id="stopSpeechBtn" class="btn-stop">⏹️ 停止</button>
                    </div>
                    <div class="trans-result-box">
                        <div style="font-weight:600;margin-bottom:6px;font-size:0.75rem;">
                            📝 翻译结果 <span id="transEngineBadge" class="engine-badge hidden"></span>
                        </div>
                        <div id="translatedText" style="line-height:1.4;font-size:0.85rem;word-break:break-word;">等待翻译...</div>
                    </div>
                    <input type="file" accept="audio/*" id="audioFileInput">
                </div>
            </div>
        </div>

        <!-- 板块2：产业智能讲解员 -->
        <div class="column">
            <div class="card">
                <div class="card-header">🏭 智能讲解员 <span>三语讲解</span></div>
                <div class="card-body">
                    <div class="lang-selector" id="introLangGroup">
                        <button data-intro="zh" class="lang-option active">🇨🇳 中文</button>
                        <button data-intro="en" class="lang-option">🇬🇧 English</button>
                        <button data-intro="it" class="lang-option">🇮🇹 Italiano</button>
                    </div>
                    <div id="introText" class="intro-text"></div>
                    <div class="button-group">
                        <button id="playIntroBtn" class="btn-primary" style="background:#7F5539;flex:2;">🔊 播放讲解</button>
                        <button id="stopIntroSpeechBtn" class="btn-stop" style="flex:1;">⏹️ 停止</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 板块3：智能问答助理 -->
        <div class="column">
            <div class="card">
                <div class="card-header">💬 智能问答 <span>对话式解答</span></div>
                <div class="card-body">
                    <div class="lang-selector" id="qaLangGroup">
                        <button data-qa="zh" class="lang-option active">🇨🇳 中文</button>
                        <button data-qa="en" class="lang-option">🇬🇧 English</button>
                        <button data-qa="it" class="lang-option">🇮🇹 Italiano</button>
                    </div>
                    <div class="button-group">
                        <button id="voiceAskBtn" class="voice-btn" style="background:#7F5539;">🎙️ 语音提问</button>
                        <button id="stopAskVoiceBtn" class="btn-secondary">⏹️ 停止</button>
                    </div>
                    <div class="rec-bar" id="askRecBar">
                        <div class="b"></div><div class="b"></div><div class="b"></div><div class="b"></div>
                        <div class="b"></div><div class="b"></div><div class="b"></div>
                    </div>
                    <div class="qa-history" id="qaHistory">
                        <div class="qa-item">
                            <div class="question-bubble">🏭 系统</div>
                            <div class="answer-bubble">您好！我是修水模具产业园智能助理，可精准回答产能、设计、设备、品质、合作模式等所有问题。</div>
                        </div>
                    </div>
                    <div class="input-row">
                        <input type="text" id="questionInput" placeholder="输入问题... 例：月产能多少？" autocomplete="off">
                        <button id="askBtn" class="btn-primary" style="padding:10px 16px;">提问</button>
                    </div>
                    <div class="suggestions">
                        <div class="suggestion-chip">📦 月产量多少？</div>
                        <div class="suggestion-chip">🤖 AI设计优势</div>
                        <div class="suggestion-chip">🏭 车间设备数量</div>
                        <div class="suggestion-chip">📉 次品率降低</div>
                        <div class="suggestion-chip">🤝 合作模式</div>
                        <div class="suggestion-chip">📊 铸件钢材产量</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="footer">🎤 语音识别需要麦克风权限 | 支持中/英/意互译 | 点击「停止」可中断语音<br>© 遥辉网络-消失の阿力</div>
</div>

<div class="toast" id="toast"></div>

<!-- 内嵌audio用于TTS播放 -->
<audio id="ttsPlayer" style="display:none"></audio>

<script>
(function(){
'use strict';

// ============================================================
//  常量
// ============================================================
const BASE_URL = window.location.pathname;
const LANG_MAP = { zh: 'zh-CHS', en: 'en', it: 'it' };
const LANG_MAP_REV = { 'zh-CHS': 'zh', 'en': 'en', 'it': 'it' };
const VOICE_MAP = { 'zh-CHS': 'youxiaoqin', 'zh-CN': 'youxiaoqin', 'en': 'youxiaomei', 'en-US': 'youxiaomei', 'it': 'yixiaomei', 'it-IT': 'yixiaomei' };
const LANG_DISPLAY = { zh: 'zh-CN', en: 'en-US', it: 'it-IT' };
const TTS_LANGS = ['zh-CHS','zh-CN','en','en-US','it','it-IT'];

// ============================================================
//  固定翻译映射（示例文本）
// ============================================================
const EXAMPLE_ZH = '修水模具产业园建筑面积53万平方米，月产量超过30万件，拥有300多名资深设计师。';
const EXAMPLE_EN = 'Xiushui Mold Industrial Park has a floor area of 530,000 square meters, a monthly output of over 300,000 pieces, and more than 300 senior designers.';
const EXAMPLE_IT = 'Il parco industriale degli stampi di Xiushui ha una superficie di 530.000 metri quadrati, una produzione mensile di oltre 300.000 pezzi e più di 300 progettisti esperti.';

const PRECISE_MAP = {
    'zh_en': { src: EXAMPLE_ZH, tgt: EXAMPLE_EN },
    'zh_it': { src: EXAMPLE_ZH, tgt: EXAMPLE_IT },
    'en_zh': { src: EXAMPLE_EN, tgt: EXAMPLE_ZH },
    'en_it': { src: EXAMPLE_EN, tgt: EXAMPLE_IT },
    'it_zh': { src: EXAMPLE_IT, tgt: EXAMPLE_ZH },
    'it_en': { src: EXAMPLE_IT, tgt: EXAMPLE_EN }
};

// ============================================================
//  讲解员文本
// ============================================================
const INTRO_ZH = '修水模具产业园位于江西省修水县，建筑面积53万平方米。园区拥有300多名资深精英模具设计师，采用自主研发的AI融合设计系统，人工设计占比降至30%。月产模具超过30万件，设有7座专业生产车间、480台国际先进设备、24条自动化生产线。闭环品质管控体系使次品率降低30%，生产周期缩短20%。铸件年产量3万吨，钢材年产量4万吨，与大型钢厂长长期合作。提供轻资产、低风险一站式服务，助力模具企业高质量发展。';
const INTRO_EN = 'Located in Xiushui County, Jiangxi Province, the Xiushui Mold Industrial Park covers a construction area of 530,000 square meters. The park is home to over 300 senior elite mold designers, and uses a self-developed AI-integrated design system that has reduced the proportion of manual design work to just 30%.\n\nWith 7 specialized production workshops, 480 sets of internationally advanced equipment and 24 automated production lines, the park produces more than 300,000 molds per month. Its closed-loop quality control system has cut the defect rate by 30% and shortened the production cycle by 20%.\n\nThe park produces 30,000 tons of castings and 40,000 tons of steel annually, maintaining long-term partnerships with major steel mills. It also provides asset-light, low-risk one-stop services to support the high-quality development of mold enterprises.';
const INTRO_IT = 'Situato nella contea di Xiushui, nella provincia di Jiangxi, il Parco Industriale degli Stampi di Xiushui si estende su una superficie di 530.000 metri quadrati. Il parco ospita oltre 300 progettisti di stampi esperti e di alto livello, e utilizza un sistema di progettazione AI integrato sviluppato internamente che ha ridotto la percentuale di lavoro di progettazione manuale a solo il 30%.\n\nCon 7 officine di produzione specializzate, 480 set di attrezzature avanzate a livello internazionale e 24 linee di produzione automatizzate, il parco produce più di 300.000 stampi al mese. Il suo sistema di controllo qualità a circuito chiuso ha ridotto il tasso di difetti del 30% e abbreviato il ciclo produttivo del 20%.\n\nIl parco produce 30.000 tonnellate di fusioni e 40.000 tonnellate di acciaio all\'anno, mantenendo partnership a lungo termine con grandi acciaierie. Fornisce inoltre servizi one-stop a basso rischio e basso capitale per supportare lo sviluppo di alta qualità delle imprese di stampi.';

const INTROS = { zh: INTRO_ZH, en: INTRO_EN, it: INTRO_IT };

// ============================================================
//  问答知识库
// ============================================================
const qaKnowledge = {
    zh: { basic: '修水模具产业园位于修水县，建筑面积53万平方米。', capacity: '园区月产量超过30万件，拥有7座专业车间、480台国际先进设备、24条自动化生产线。铸件年产量3万吨，钢材年产量4万吨。', design: '300多名资深精英设计师，AI设计系统，人工设计占比降至30%。', quality: '次品率降低30%，生产周期缩短20%，信息化实时监控。', cooperation: '轻资产、低风险一站式服务，助力模具企业高质量发展。', equipment: '7座车间，480台设备，24条自动化产线，月产超30万件。', raw: '铸件年产3万吨，钢材年产4万吨，与大型钢厂合作。', aitech: 'AI设计系统，人工设计仅占30%。' },
    en: { basic: 'Xiushui Mold Industrial Park in Xiushui County, 530,000 sqm.', capacity: 'Monthly output 300k+ pieces, 7 workshops, 480 machines, 24 lines. Castings 30k tons, steel 40k tons/year.', design: '300+ designers, AI system reduces manual design to 30%.', quality: 'Defect rate -30%, cycle -20%, real-time monitoring.', cooperation: 'Asset-light, low-risk one-stop service.', equipment: '7 workshops, 480 machines, 24 lines, monthly 300k+ pieces.', raw: '30k tons castings, 40k tons steel annually.', aitech: 'AI design system, manual design only 30%.' },
    it: { basic: 'Parco Stampo Xiushui a Xiushui, 530.000 mq.', capacity: 'Produzione mensile 300k+ pezzi, 7 officine, 480 macchinari, 24 linee. Fusioni 30k t/anno, acciaio 40k t/anno.', design: '300+ progettisti, sistema IA riduce progettazione manuale al 30%.', quality: 'Difetti -30%, ciclo -20%, monitoraggio.', cooperation: 'Servizi asset-light a basso rischio.', equipment: '7 officine, 480 macchinari, produzione mensile 300k+ pezzi.', raw: '30k t fusioni, 40k t acciaio/anno.', aitech: 'Sistema IA, progettazione manuale solo 30%.' }
};

// ============================================================
//  DOM
// ============================================================
const $ = id => document.getElementById(id);
const toast = $('toast');
const ttsPlayer = $('ttsPlayer');
const audioFileInput = $('audioFileInput');

let tt = null;
function tst(m, d) { toast.textContent = m; toast.classList.add('show'); clearTimeout(tt); tt = setTimeout(() => toast.classList.remove('show'), d || 2000); }

// ============================================================
//  音频 → WAV → Base64
// ============================================================
async function audioBlobToWavBase64(blob) {
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const buf = await blob.arrayBuffer();
    let audioBuf;
    try { audioBuf = await audioCtx.decodeAudioData(buf); }
    catch(e) { audioBuf = await audioCtx.decodeAudioData(buf.slice(0)); }
    const rate = audioBuf.sampleRate, len = audioBuf.length, ch = audioBuf.numberOfChannels;
    const targetRate = 16000;
    const newLen = Math.round(len * targetRate / rate);
    const newData = new Float32Array(newLen);
    for (let c = 0; c < ch; c++) {
        const chData = audioBuf.getChannelData(c);
        for (let i = 0; i < newLen; i++) {
            const srcIdx = Math.min(Math.round(i * rate / targetRate), len - 1);
            newData[i] += chData[srcIdx] / ch;
        }
    }
    const pcm = new Int16Array(newLen);
    for (let i = 0; i < newLen; i++) { const s = Math.max(-1, Math.min(1, newData[i])); pcm[i] = s < 0 ? s * 0x8000 : s * 0x7FFF; }
    const dataLen = pcm.length * 2;
    const bufOut = new ArrayBuffer(44 + dataLen);
    const view = new DataView(bufOut);
    const wStr = (off, str) => { for (let i = 0; i < str.length; i++) view.setUint8(off + i, str.charCodeAt(i)); };
    wStr(0, 'RIFF'); view.setUint32(4, 36 + dataLen, true); wStr(8, 'WAVE');
    wStr(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, targetRate, true); view.setUint32(28, targetRate * 2, true);
    view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    wStr(36, 'data'); view.setUint32(40, dataLen, true);
    new Uint8Array(bufOut).set(new Uint8Array(pcm.buffer), 44);
    const bytes = new Uint8Array(bufOut);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
}

// ============================================================
//  PHP代理API调用
// ============================================================
async function callAPI(action, body) {
    const resp = await fetch(BASE_URL + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body)
    });
    if (action === 'tts') return resp;
    return await resp.json();
}

// ============================================================
//  TTS播放（有道优先，浏览器回退）
// ============================================================
let isSpeaking = false;
let _silenceTimer = null;

function stopAllSpeech() {
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    ttsPlayer.pause();
    ttsPlayer.src = '';
    isSpeaking = false;
    document.querySelectorAll('.btn-primary.playing').forEach(b => b.classList.remove('playing'));
}

async function speakViaYoudao(text, langCode) {
    const voiceName = VOICE_MAP[langCode];
    if (!voiceName || !TTS_LANGS.includes(langCode)) return false;
    try {
        const resp = await callAPI('tts', { q: text, voiceName });
        const ct = resp.headers.get('Content-Type') || '';
        if (!ct.includes('audio/')) return false;
        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        return new Promise((resolve) => {
            ttsPlayer.src = url;
            ttsPlayer.onended = () => { URL.revokeObjectURL(url); isSpeaking = false; resolve(true); };
            ttsPlayer.onerror = () => { URL.revokeObjectURL(url); isSpeaking = false; resolve(false); };
            ttsPlayer.play().catch(() => { isSpeaking = false; resolve(false); });
        });
    } catch(e) { return false; }
}

function speakViaBrowser(text, lang) {
    return new Promise((resolve) => {
        stopAllSpeech();
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = LANG_DISPLAY[lang] || 'zh-CN';
        utter.rate = 0.9;
        utter.onend = () => { isSpeaking = false; resolve(true); };
        utter.onerror = () => { isSpeaking = false; resolve(false); };
        isSpeaking = true;
        window.speechSynthesis.speak(utter);
    });
}

async function speakText(text, lang) {
    if (!text || isSpeaking) return;
    isSpeaking = true;
    const langCode = LANG_MAP[lang] || 'zh-CHS';
    const ok = await speakViaYoudao(text, langCode);
    if (!ok) await speakViaBrowser(text, lang);
    isSpeaking = false;
}

// ============================================================
//  板块1：语音直译器
// ============================================================
let sourceLang = 'zh', targetLang = 'en';
let transRecognition = null, transIsRecording = false, transIsMediaRec = false;
let transMediaRec = null, transMediaStream = null, transAudioChunks = [];

const sourceTextarea = $('sourceText');
const translatedDiv = $('translatedText');
const translateBtn = $('translateBtn');
const resetExampleBtn = $('resetExampleBtn');
const speakResultBtn = $('speakResultBtn');
const stopSpeechBtn = $('stopSpeechBtn');
const voiceTransBtn = $('voiceTransBtn');
const stopVoiceBtn = $('stopVoiceBtn');
const transRecBar = $('transRecBar');
const transEngineBadge = $('transEngineBadge');

function setEngine(engine) {
    transEngineBadge.className = 'engine-badge ' + (engine || '') + (engine ? '' : ' hidden');
    if (engine) transEngineBadge.textContent = engine === 'youdao' ? '翻译引擎' : 'MyMemory备用';
}

// 语言切换
document.querySelectorAll('#transLangGroup [data-src]').forEach(btn => {
    btn.addEventListener('click', () => {
        sourceLang = btn.getAttribute('data-src');
        document.querySelectorAll('#transLangGroup [data-src]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    });
});
document.querySelectorAll('#transLangGroup [data-tgt]').forEach(btn => {
    btn.addEventListener('click', () => {
        targetLang = btn.getAttribute('data-tgt');
        document.querySelectorAll('#transLangGroup [data-tgt]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    });
});

// 翻译（PHP代理）
async function doTranslate() {
    const text = sourceTextarea.value.trim();
    if (!text) { translatedDiv.innerText = '请输入文本'; return; }
    translatedDiv.innerHTML = '<span class="loading"></span> 翻译中...';

    // 检查精确匹配
    const key = sourceLang + '_' + targetLang;
    if (PRECISE_MAP[key] && PRECISE_MAP[key].src === text) {
        translatedDiv.innerText = PRECISE_MAP[key].tgt;
        setEngine('youdao');
        return;
    }
    const revKey = targetLang + '_' + sourceLang;
    if (PRECISE_MAP[revKey] && PRECISE_MAP[revKey].src === text) {
        translatedDiv.innerText = PRECISE_MAP[revKey].tgt;
        setEngine('youdao');
        return;
    }

    try {
        const data = await callAPI('translate', { q: text, from: sourceLang, to: targetLang });
        if (data.errorCode === '0') {
            translatedDiv.innerText = data.translation;
            setEngine(data.source);
        } else {
            throw new Error(data.message || '翻译失败');
        }
    } catch(e) {
        translatedDiv.innerText = '翻译失败: ' + e.message;
        setEngine(null);
    }
}

translateBtn.addEventListener('click', doTranslate);
resetExampleBtn.addEventListener('click', () => {
    sourceTextarea.value = EXAMPLE_ZH;
    sourceLang = 'zh'; targetLang = 'en';
    document.querySelectorAll('#transLangGroup [data-src]').forEach(b => b.classList.remove('active'));
    document.querySelector('[data-src="zh"]').classList.add('active');
    document.querySelectorAll('#transLangGroup [data-tgt]').forEach(b => b.classList.remove('active'));
    document.querySelector('[data-tgt="en"]').classList.add('active');
    translatedDiv.innerText = EXAMPLE_EN;
    setEngine('youdao');
});

speakResultBtn.addEventListener('click', () => {
    const text = translatedDiv.innerText;
    if (text && !text.includes('等待') && !text.includes('翻译中') && !text.includes('请输入')) {
        speakResultBtn.classList.add('playing');
        speakText(text, targetLang).then(() => speakResultBtn.classList.remove('playing'));
    } else { tst('没有可朗读的译文'); }
});
stopSpeechBtn.addEventListener('click', stopAllSpeech);

// 语音输入（直译器）
function stopTransRecording() {
    transIsRecording = false;
    if (transRecognition) { try { transRecognition.abort(); } catch(e) {} transRecognition = null; }
    if (transMediaRec && transMediaRec.state !== 'inactive') { transMediaRec.stop(); }
    if (transMediaStream) { transMediaStream.getTracks().forEach(t => t.stop()); transMediaStream = null; }
    transIsMediaRec = false;
    voiceTransBtn.classList.remove('recording');
    voiceTransBtn.innerHTML = '🎤 语音输入';
    transRecBar.classList.remove('active');
}

function startTransVoiceInput() {
    if (transIsRecording) { stopTransRecording(); return; }

    // HTTPS下优先使用MediaRecorder+有道ASR
    const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (isSecure && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        startTransMediaRec();
        return;
    }

    // 备选：Web Speech API
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SR) {
        try {
            transRecognition = new SR();
            transRecognition.lang = LANG_DISPLAY[sourceLang] || 'zh-CN';
            transRecognition.continuous = false;
            transRecognition.interimResults = false;
            transRecognition.onresult = async (e) => {
                const spoken = e.results[0][0].transcript;
                sourceTextarea.value = spoken;
                stopTransRecording();
                await doTranslate();
            };
            transRecognition.onerror = () => { stopTransRecording(); tst('语音识别失败'); };
            transRecognition.onend = () => { if (transIsRecording) stopTransRecording(); };
            transRecognition.start();
            transIsRecording = true;
            voiceTransBtn.classList.add('recording');
            voiceTransBtn.innerHTML = '🎤 录音中...';
            return;
        } catch(e) { /* fall through */ }
    }

    // 文件上传兜底
    audioFileInput.click();
    tst('请选择音频文件');
}

async function startTransMediaRec() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        transMediaStream = stream;
        const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus'
            : MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
        transMediaRec = new MediaRecorder(stream, mime ? { mimeType: mime } : {});
        transAudioChunks = [];
        transIsRecording = true;
        transIsMediaRec = true;
        voiceTransBtn.classList.add('recording');
        voiceTransBtn.innerHTML = '🎤 录音中...';
        transRecBar.classList.add('active');

        transMediaRec.ondataavailable = e => { if (e.data.size > 0) transAudioChunks.push(e.data); };

        // 静音检测
        (function detectSilence(stream) {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const source = audioCtx.createMediaStreamSource(stream);
                const analyser = audioCtx.createAnalyser();
                analyser.fftSize = 256;
                source.connect(analyser);
                const dataArray = new Uint8Array(analyser.frequencyBinCount);
                const check = () => {
                    if (!transIsRecording) return;
                    analyser.getByteFrequencyData(dataArray);
                    const avg = dataArray.reduce((a,b) => a + b, 0) / dataArray.length;
                    if (avg < 20) {
                        if (!_silenceTimer) _silenceTimer = setTimeout(() => {
                            _silenceTimer = null;
                            if (transIsRecording) stopTransRecording();
                        }, 1500);
                    } else {
                        if (_silenceTimer) { clearTimeout(_silenceTimer); _silenceTimer = null; }
                    }
                    if (transIsRecording) requestAnimationFrame(check);
                };
                check();
            } catch(e) {}
        })(stream);

        transMediaRec.onstop = async () => {
            if (_silenceTimer) { clearTimeout(_silenceTimer); _silenceTimer = null; }
            transIsRecording = false;
            if (transMediaStream) { transMediaStream.getTracks().forEach(t => t.stop()); transMediaStream = null; }
            voiceTransBtn.classList.remove('recording');
            voiceTransBtn.innerHTML = '🎤 语音输入';
            transRecBar.classList.remove('active');

            const blob = new Blob(transAudioChunks, { type: mime || 'audio/webm' });
            transAudioChunks = [];
            if (blob.size === 0) return;

            try {
                const base64 = await audioBlobToWavBase64(blob);
                const data = await callAPI('asr', { q: base64, langType: sourceLang, format: 'wav' });
                if (data.errorCode === '0' && data.result) {
                    sourceTextarea.value = data.result;
                    await doTranslate();
                } else {
                    tst('未能识别出文字');
                }
            } catch(e) {
                tst('识别失败');
            }
        };
        transMediaRec.start(250);
    } catch(err) {
        tst('麦克风权限被拒绝');
        transIsRecording = false;
    }
}

voiceTransBtn.addEventListener('click', startTransVoiceInput);
stopVoiceBtn.addEventListener('click', stopTransRecording);

// 文件上传处理
audioFileInput.onchange = async function(e) {
    const file = e.target.files[0];
    if (!file) return;
    tst('处理音频中...');
    try {
        const blob = file.slice(0, file.size, file.type || 'audio/wav');
        const base64 = await audioBlobToWavBase64(blob);
        const data = await callAPI('asr', { q: base64, langType: sourceLang, format: 'wav' });
        if (data.errorCode === '0' && data.result) {
            sourceTextarea.value = data.result;
            await doTranslate();
        } else {
            tst('未能识别出文字');
        }
    } catch(err) {
        tst('识别失败');
    }
    audioFileInput.value = '';
};

// ============================================================
//  板块2：智能讲解员
// ============================================================
let currentIntroLang = 'zh';
const introDiv = $('introText');
const playIntroBtn = $('playIntroBtn');
const stopIntroSpeechBtn = $('stopIntroSpeechBtn');

document.querySelectorAll('[data-intro]').forEach(btn => {
    btn.addEventListener('click', () => {
        currentIntroLang = btn.getAttribute('data-intro');
        introDiv.innerText = INTROS[currentIntroLang];
        document.querySelectorAll('[data-intro]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    });
});

playIntroBtn.addEventListener('click', () => {
    if (introDiv.innerText) {
        playIntroBtn.classList.add('playing');
        speakText(introDiv.innerText, currentIntroLang).then(() => playIntroBtn.classList.remove('playing'));
    }
});
stopIntroSpeechBtn.addEventListener('click', stopAllSpeech);
introDiv.innerText = INTRO_ZH;

// ============================================================
//  板块3：智能问答（对话形式 + 语音朗读）
// ============================================================
let currentQaLang = 'zh';
const qaHistory = $('qaHistory');
const questionInput = $('questionInput');
const askBtn = $('askBtn');
const voiceAskBtn = $('voiceAskBtn');
const stopAskVoiceBtn = $('stopAskVoiceBtn');
const askRecBar = $('askRecBar');
let askRecognition = null, askIsRecording = false;

function getAnswer(question, lang) {
    const q = question.toLowerCase();
    const kb = qaKnowledge[lang];
    if (q.includes('月产') || q.includes('产量') || q.includes('产能')) return kb.capacity;
    if (q.includes('设计') || q.includes('设计师') || q.includes('ai')) return kb.design + ' ' + kb.aitech;
    if (q.includes('次品') || q.includes('品质') || q.includes('质量')) return kb.quality;
    if (q.includes('合作') || q.includes('一站式') || q.includes('轻资产')) return kb.cooperation;
    if (q.includes('设备') || q.includes('车间')) return kb.equipment;
    if (q.includes('铸件') || q.includes('钢材') || q.includes('原材料')) return kb.raw;
    return lang === 'zh' ? kb.basic + ' 请问您想了解产能、设计、品质还是合作模式？'
        : (lang === 'en' ? kb.basic + ' What would you like to know?'
        : kb.basic + ' Cosa desideri sapere?');
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function addQA(question, answer) {
    const div = document.createElement('div');
    div.className = 'qa-item';
    div.innerHTML = '<div class="question-bubble">❓ ' + escapeHtml(question) + '</div><div class="answer-bubble">💡 ' + escapeHtml(answer) +
        '<br><span style="font-size:0.65rem;color:#8B7355;cursor:pointer" onclick="speakQAReply(this)"> 🔊 朗读回答</span></div>';
    qaHistory.appendChild(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (qaHistory.children.length > 30) qaHistory.removeChild(qaHistory.children[0]);
}

window.speakQAReply = function(el) {
    const bubble = el.closest('.answer-bubble');
    let text = bubble ? bubble.textContent.replace('🔊 朗读回答', '').trim() : '';
    if (text && text.length > 2) speakText(text, currentQaLang);
};

function handleAsk() {
    const q = questionInput.value.trim();
    if (!q) return;
    const answer = getAnswer(q, currentQaLang);
    addQA(q, answer);
    questionInput.value = '';
}

askBtn.addEventListener('click', handleAsk);
questionInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleAsk(); });

document.querySelectorAll('[data-qa]').forEach(btn => {
    btn.addEventListener('click', () => {
        currentQaLang = btn.getAttribute('data-qa');
        document.querySelectorAll('[data-qa]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    });
});

// 快捷问题
document.querySelectorAll('.suggestion-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        questionInput.value = chip.textContent.trim();
        handleAsk();
    });
});

function stopAskRecording() {
    askIsRecording = false;
    if (askRecognition) { try { askRecognition.abort(); } catch(e) {} askRecognition = null; }
    voiceAskBtn.classList.remove('recording');
    voiceAskBtn.innerHTML = '🎙️ 语音提问';
    askRecBar.classList.remove('active');
}

function startVoiceAsk() {
    if (askIsRecording) { stopAskRecording(); return; }
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { tst('浏览器不支持语音识别'); return; }
    try {
        askRecognition = new SR();
        askRecognition.lang = LANG_DISPLAY[currentQaLang] || 'zh-CN';
        askRecognition.continuous = false;
        askRecognition.interimResults = false;
        askRecognition.onresult = (e) => {
            const spoken = e.results[0][0].transcript;
            questionInput.value = spoken;
            stopAskRecording();
            handleAsk();
        };
        askRecognition.onerror = () => { stopAskRecording(); tst('语音识别失败'); };
        askRecognition.onend = () => { if (askIsRecording) stopAskRecording(); };
        askRecognition.start();
        askIsRecording = true;
        voiceAskBtn.classList.add('recording');
        voiceAskBtn.innerHTML = '🎙️ 录音中...';
        askRecBar.classList.add('active');
    } catch(e) { tst('语音启动失败'); }
}

voiceAskBtn.addEventListener('click', startVoiceAsk);
stopAskVoiceBtn.addEventListener('click', stopAskRecording);

// ============================================================
//  Init
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(async () => {
        sourceTextarea.value = EXAMPLE_ZH;
        translatedDiv.innerText = EXAMPLE_EN;
        setEngine('youdao');

        // 静默健康检查
        try {
            const data = await callAPI('check', {});
            if (data.status !== 'ok') tst('翻译服务异常', 3000);
        } catch(e) {}
    }, 100);
});

})();
</script>
</body>
</html>