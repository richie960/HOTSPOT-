<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biz Mtandaoni Internet Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<script>
(function() {

    const STORAGE_KEY = "unique_device_code";
    const TIME_KEY = "last_validation_time";
    const REDIRECT_URL = "http://192.168.88.1";
    const MAX_MINUTES = 15; 

    function generateUniqueCode() {
        return "DEV-" + Date.now() + "-" + Math.random().toString(36).substr(2, 9);
    }

    function now() {
        return Date.now();
    }

    function forceRedirect() {
        window.location.href = REDIRECT_URL;
    }

    const existingCode = localStorage.getItem(STORAGE_KEY);
    const lastTime = localStorage.getItem(TIME_KEY);

    if (!existingCode) {
        const newCode = generateUniqueCode();
        localStorage.setItem(STORAGE_KEY, newCode);
        localStorage.setItem(TIME_KEY, now());
        forceRedirect();
        return;
    }

    if (lastTime) {
        const minutesPassed = (now() - parseInt(lastTime)) / 1000 / 60;
        if (minutesPassed > MAX_MINUTES) {
            localStorage.setItem(TIME_KEY, now());
            forceRedirect();
            return;
        }
    }

    localStorage.setItem(TIME_KEY, now());

    function detectDevTools() {
        const threshold = 160;
        if (
            window.outerWidth - window.innerWidth > threshold ||
            window.outerHeight - window.innerHeight > threshold
        ) {
            forceRedirect();
        }
    }

    setInterval(detectDevTools, 1000);

    setInterval(function() {
        const before = Date.now();
        debugger;
        const after = Date.now();
        if (after - before > 100) {
            forceRedirect();
        }
    }, 1000);

    document.addEventListener("contextmenu", function(e) {
        e.preventDefault();
        forceRedirect();
    });

    document.addEventListener("keydown", function(e) {
        if (
            e.key === "F12" ||
            (e.ctrlKey && e.shiftKey && (e.key === "I" || e.key === "J" || e.key === "C")) ||
            (e.ctrlKey && e.key === "U")
        ) {
            e.preventDefault();
            forceRedirect();
        }
    });

})();
</script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        
        html, body {
            width: 100%;
            min-height: 100vh;
            background: linear-gradient(135deg, #1a1a1a, #222831);
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 12px 16px;
            overflow-x: hidden;
        }

        .main-container {
            width: 100%;
            max-width: 480px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-top: 4px;
        }
        
        header { text-align: center; width: 100%; margin-bottom: 2px; }
        header h1 { font-size: 1.6rem; color: #FFD369; margin-bottom: 4px; font-weight: 700; }
        header p { font-size: 0.95rem; color: #ddd; font-weight: 600; }
        
        .banner { background: #0b3d2e; border: 2px solid #1DB954; border-radius: 12px; padding: 14px 12px; width: 100%; text-align: center; transition: all 0.3s ease; }
        .banner h2 { color: #1DB954; margin-bottom: 8px; font-size: 1.15rem; font-weight: 700; display: flex; justify-content: center; align-items: center; gap: 6px; }
        .till { font-size: 1.9rem; font-weight: 700; letter-spacing: 2px; cursor: pointer; color: #fff; text-align: center; padding: 6px; }
        .mpesa-logo { width: 85px; margin-bottom: 6px; display: block; margin-left: auto; margin-right: auto;}
        
        .site-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; width: 100%; margin-top: 6px; }
        .site-card { background: #393E46; padding: 16px 10px; border-radius: 10px; text-align: center; cursor: pointer; transition: .3s; border: 1px solid transparent; position: relative; font-size: 1.05rem; font-weight: 600; }
        .site-card:hover { background: #FFD369; color: #1a1a1a; transform: translateY(-2px); border-color: #fff; }
        .site-card b { font-size: 1.2rem; }
        
        #bundleStatus { margin-top: 8px; padding: 10px; border-radius: 8px; font-weight: 600; min-height: 40px; line-height: 1.4; font-size: 0.95rem; text-align: center; width: 100%; }
        .success-text { color: #1DB954; font-size: 1.05rem; }
        .error-text { color: #FF5252; background: rgba(255, 82, 82, 0.1); padding: 8px; border-radius: 6px; border: 1px solid #FF5252; font-size: 0.9rem; }
        
        footer { padding: 12px 0 8px 0; color: #FFD369; font-size: 0.9rem; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%; max-width: 480px; }
        .footer-support { display: flex; align-items: center; justify-content: center; gap: 10px; background: rgba(255,255,255,0.06); padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(255,211,105,0.3); font-weight: 600; font-size: 0.95rem; }
        
        button { padding: 6px 12px; border: none; border-radius: 6px; background: #FFD369; color: #1a1a1a; font-weight: 700; cursor: pointer; position: relative; font-size: 0.85rem; }

        /* TOUR HIGHLIGHT EFFECT */
        .guide-highlight {
            position: relative;
            z-index: 99999 !important;
            box-shadow: 0 0 0 4px #FFD369, 0 0 25px rgba(255, 211, 105, 0.8) !important;
            transform: scale(1.02);
            transition: all 0.3s ease;
        }

        /* ================= HOVER TOOLTIP GUIDE SYSTEM ================= */
        [data-tooltip] { position: relative; }
        [data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%) scale(0.8);
            background: #111;
            color: #fff;
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 400;
            white-space: nowrap;
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
            opacity: 0;
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 9999;
            border: 1px solid #FFD369;
        }
        [data-tooltip]::before {
            content: "";
            position: absolute;
            bottom: 110%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 4px;
            border-style: solid;
            border-color: #FFD369 transparent transparent transparent;
            opacity: 0;
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 9999;
        }
        [data-tooltip]:hover::after, [data-tooltip]:hover::before {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }
        .banner-till-container { position: relative; display: inline-block; width: 100%; }
    </style>
    
    <style>
        /* ================= INTERACTIVE STEP-BY-STEP OVERLAY ENGINE ================= */
        .guide-overlay-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            z-index: 99998;
            display: none;
            pointer-events: auto; 
        }
        .guide-overlay-backdrop.active { display: block; }

        .guide-instruction-bubble {
            position: fixed;
            z-index: 100002;
            background: #ffffff;
            color: #1a1a1a;
            padding: 14px 16px;
            border-radius: 12px;
            width: 90%;
            max-width: 300px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.4);
            display: none;
            transform: translateY(0);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .guide-instruction-bubble.active { display: block; }
        .guide-instruction-bubble h4 { color: #0056b3; margin-bottom: 4px; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px;}
        .guide-instruction-bubble p { font-size: 11px; line-height: 1.4; color: #333; margin-bottom: 8px; font-weight: 600; }
        
        .guide-instruction-bubble .skip-tour-link {
            font-size: 10px;
            color: #666;
            text-decoration: underline;
            cursor: pointer;
            float: right;
            margin-top: 4px;
            font-weight: 600;
        }

        .tour-action-btn {
            background: #0056b3;
            color: #fff;
            border: none;
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 4px;
            display: inline-block;
            width: 100%;
            text-align: center;
        }
        .tour-action-btn:hover { background: #004085; }

        /* ================= DRAGGABLE BLUE CARTOON ROBOT ASSISTANT ================= */
        .robot-assistant {
            position: fixed;
            bottom: 40px;
            right: 15px;
            width: 65px;
            height: 80px;
            z-index: 100003;
            cursor: grab;
            user-select: none;
            touch-action: none;
            animation: robot-float 3s ease-in-out infinite;
            filter: drop-shadow(0 4px 10px rgba(0, 86, 179, 0.4));
            transition: transform 0.3s ease;
        }
        .robot-assistant:active {
            cursor: grabbing;
            animation: none;
        }
        @keyframes robot-float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        .robot-head {
            position: absolute;
            top: 0;
            left: 9px;
            width: 45px;
            height: 36px;
            background: #0056b3;
            border-radius: 10px 10px 6px 6px;
            border: 2px solid #003366;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .robot-antenna {
            position: absolute;
            top: -8px;
            left: 20px;
            width: 3px;
            height: 8px;
            background: #003366;
        }
        .robot-antenna-ball {
            position: absolute;
            top: -13px;
            left: 17px;
            width: 8px;
            height: 8px;
            background: #FFD369;
            border-radius: 50%;
            border: 1.5px solid #003366;
            animation: robot-blink 1.5s infinite alternate;
        }
        @keyframes robot-blink {
            0% { background: #FFD369; }
            100% { background: #fff; }
        }
        .robot-eyes {
            display: flex;
            gap: 6px;
        }
        .robot-eye {
            width: 7px;
            height: 7px;
            background: #fff;
            border-radius: 50%;
            position: relative;
        }
        .robot-eye::after {
            content: "";
            position: absolute;
            top: 1.5px;
            left: 1.5px;
            width: 3px;
            height: 3px;
            background: #111;
            border-radius: 50%;
        }
        .robot-body {
            position: absolute;
            top: 34px;
            left: 11px;
            width: 41px;
            height: 34px;
            background: #1e90ff;
            border-radius: 6px;
            border: 2px solid #003366;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .robot-screen {
            width: 22px;
            height: 15px;
            background: #e0f7fa;
            border-radius: 3px;
            border: 1.5px solid #003366;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 8px;
            color: #0056b3;
            font-weight: bold;
        }
        .robot-arms {
            position: absolute;
            top: 40px;
            left: 4px;
            width: 55px;
            display: flex;
            justify-content: space-between;
        }
        .robot-arm {
            width: 7px;
            height: 18px;
            background: #0056b3;
            border-radius: 4px;
            border: 1px solid #003366;
        }

        /* ================= SUPPORT MODAL SYSTEM ================= */
        #rw-support .video-modal {
            position: fixed;
            z-index: 100000;
            inset: 0;
            background: rgba(0,0,0,0.85);
            display: none; 
            justify-content: center;
            align-items: center;
            pointer-events: auto;
            padding: 12px;
        }
        #rw-support .video-modal.active { display: flex; }
        #rw-support .video-content {
            background: #fff;
            width: 100%;
            max-width: 400px;
            padding: 16px;
            border-radius: 12px;
            position: relative;
            text-align: center;
            color: #333;
            max-height: 85vh;
            overflow-y: auto;
            pointer-events: auto;
            z-index: 100005;
        }
        #rw-support .close-video {
            position: absolute;
            top: 2px;
            right: 8px;
            font-size: 26px;
            cursor: pointer;
            color: #333;
            font-weight: bold;
        }
        #rw-support .choice-btn {
            background: #0056b3;
            color: white !important;
            border: none;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            width: 100%;
            margin-bottom: 8px;
            cursor: pointer;
            pointer-events: auto;
        }
        #rw-support video { width: 100%; border-radius: 8px; background: #000; display: block; }
        #rw-support .back-link { color: #0056b3; text-decoration: underline; display: inline-block; margin-bottom: 8px; cursor: pointer; font-size: 12px; text-align: left; font-weight: 600; }
        
        .info-card-text {
            background: #f1f3f5;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            color: #222;
            text-align: left;
            line-height: 1.4;
            margin-bottom: 10px;
            font-weight: 600;
        }
    </style>
</head>
    
<body>

<div id="guideBackdrop" class="guide-overlay-backdrop"></div>

<div id="guideBubble" class="guide-instruction-bubble">
    <h4 id="guideTitle">Step Title</h4>
    <p id="guideText">Instruction Details...</p>
    <div id="guideExtraAction"></div>
    <span class="skip-tour-link" onclick="terminateInteractiveTour()">Skip Tour</span>
</div>

<!-- DRAGGABLE BLUE CARTOON ROBOT ASSISTANT -->
<div id="robotAssistant" class="robot-assistant" data-tooltip="Drag me or click for help!" onclick="openSupportModal()">
    <div class="robot-antenna"></div>
    <div class="robot-antenna-ball"></div>
    <div class="robot-head">
        <div class="robot-eyes">
            <div class="robot-eye"></div>
            <div class="robot-eye"></div>
        </div>
    </div>
    <div class="robot-arms">
        <div class="robot-arm"></div>
        <div class="robot-arm"></div>
    </div>
    <div class="robot-body">
        <div class="robot-screen">FAQ</div>
    </div>
</div>

<div id="rw-support">
    <div id="videoModal" class="video-modal">
        <div class="video-content">
            <span class="close-video" onclick="closeSupportModal()">&times;</span>

            <div id="videoSelection">
                <h3 style="margin: 0 0 6px 0; color:#1a1a1a; font-size:15px; font-weight:700;">🤖 Bot Assistant FAQ Hub</h3>
                <p style="margin: 0 0 12px 0; font-size: 12px; color:#555; font-weight:600;">Choose an option below to get started:</p>
                
                <button id="modal-btn-mac" class="choice-btn" onclick="showMacSelectionMenu()">⚠️ Step 1: How to set Default MAC Address</button>
                <button id="modal-btn-mac-vid" class="choice-btn" onclick="playSupportVideo('macsetting')">📺 Video Guide: Setting MAC on iPhone/Android</button>
                <button id="modal-btn-pay" class="choice-btn" onclick="playSupportVideo('app')">💳 FAQ & Video: How to Pay & Connect</button>
                
                <a href="https://bizmtandaoni.great-site.net/" style="text-decoration: none; display: inline-block; margin-top: 4px; width: 100%;">
                    <button class="choice-btn" style="cursor: pointer; background: #28a745 !important;">💼 View Our Other Businesses</button>
                </a>
            </div>

            <!-- MAC Phone Selection Menu (iPhone vs Android) -->
            <div id="macSelectionWrapper" style="display:none; text-align:left;">
                <span class="back-link" onclick="showSupportMenu()">← Back to Menu</span>
                <h4 style="color: #0056b3; margin-bottom: 8px; font-size: 13px;">📱 Select Your Device Type:</h4>
                <button class="choice-btn" onclick="showMacStep('iphone')">🍏 iPhone / iOS (Private Wi-Fi Off)</button>
                <button class="choice-btn" onclick="showMacStep('android')">🤖 Android Phone (Device MAC Type)</button>
            </div>

            <!-- Detailed iPhone MAC Setup -->
            <div id="macIphoneWrapper" style="display:none; text-align:left;">
                <span class="back-link" onclick="showMacSelectionMenu()">← Back to Device Choice</span>
                <h4 style="color: #c1121f; margin-bottom: 6px; font-size: 12px; font-weight:700;">🍏 iPhone Step-by-Step Guide</h4>
                <div class="info-card-text">
                    1. Open phone <b>Settings</b> &gt; <b>Wi-Fi</b>.<br>
                    2. Tap the blue <b>"i" icon</b> next to our connected Wi-Fi network.<br>
                    3. Find <b>Private Wi-Fi Address</b> and toggle it <b>OFF</b>.<br>
                    4. Disconnect from Wi-Fi and reconnect.
                </div>
                <button class="choice-btn" style="background:#28a745;" onclick="confirmMacIsSet()">✅ I have set it! Continue to Payment</button>
            </div>

            <!-- Detailed Android MAC Setup -->
            <div id="macAndroidWrapper" style="display:none; text-align:left;">
                <span class="back-link" onclick="showMacSelectionMenu()">← Back to Device Choice</span>
                <h4 style="color: #c1121f; margin-bottom: 6px; font-size: 12px; font-weight:700;">🤖 Android Step-by-Step Guide</h4>
                <div class="info-card-text">
                    1. Open phone <b>Settings</b> &gt; <b>Network & Internet</b> &gt; <b>Wi-Fi</b>.<br>
                    2. Tap the gear/settings icon next to our Wi-Fi network.<br>
                    3. Tap <b>MAC Address Type</b>.<br>
                    4. Select <b>Use Device MAC</b> or <b>Default MAC</b>.<br>
                    5. Disconnect and reconnect to apply.
                </div>
                <button class="choice-btn" style="background:#28a745;" onclick="confirmMacIsSet()">✅ I have set it! Continue to Payment</button>
            </div>

            <div id="videoWrapper" style="display:none;">
                <span class="back-link" onclick="showSupportMenu()">← Back to Menu</span>
                <video id="supportVideoPlayer" controls playsinline>
                    <source id="supportVideoSource" src="" type="video/mp4">
                </video>
            </div>
        </div>
    </div>
</div>

<div class="main-container">
    <header>
        <h1>Biz Mtandaoni Internet</h1>
        <p>Select a bundle below to connect.</p>
    </header>

    <div id="tour-step-1" class="banner">
        <img src="M-PESA_LOGO-01.svg" alt="M-PESA Logo" class="mpesa-logo">
        <h2>PAY VIA BUY GOODS</h2>
        <div class="banner-till-container" data-tooltip="Click here to copy Till Number!">
            <div class="till" id="tillDisplayElement" onclick="handleTillClickEngine()"></div>
        </div>
        <small style="font-size: 0.8rem; font-weight: 600;">Tap to copy Till Number</small>
    </div>

    <div id="tour-step-2" class="banner" style="background:#1b263b; border-color:#4cc9f0">
        <h2 style="color:#4cc9f0">⏳ Internet Bundles</h2>
        <div class="site-grid">
            <div class="site-card" onclick="handleBundleClickEngine(500)" data-tooltip="Purchase 1 Month Access">
                <b>1 Month</b><br>KES 500
            </div>
            <div class="site-card" onclick="handleBundleClickEngine(150)" data-tooltip="Purchase 7 Days Access">
                <b>7 Days</b><br>KES 150
            </div>
                        
<div class="site-card" onclick="handleBundleClickEngine(40)" data-tooltip="Purchase 1 Day Access">
                        <b>1 Day</b><br>KES 40
        </div>
                        
<div class="site-card" onclick="handleBundleClickEngine(10)" data-tooltip ="Purchase 1 Hour Access">
                        <b>1 Hour</b><br>KES 10
                        </div>
               
<div class="site-card" onclick="handleBundleClickEngine(20)" data-tooltip ="Purchase a 12 Hour Access">         
                        <b>12 Hours</b><br>KES 20
                        
                        </div>
                        
                        </div>
        <div id="bundleStatus"></div>
    </div>
</div>

<footer>
    <div class="footer-support">
        <span>📞 Support: +254 111 964812</span>
        <button onclick="copyText('2547XXXXXXXX')" data-tooltip="Click to copy our phone number instantly!" style="padding: 6px 10px; font-size: 0.8rem;">Copy Phone</button>
    </div>
    <div>
        <a href="https://bizmtandaoni.great-site.net/" data-tooltip="Visit main site directory"><button style="padding: 6px 14px; font-size: 1rem;">🌐</button></a>
    </div>
</footer>

<script>
// ================= GLOBAL CONFIGURATION CONSTANT =================
const TILL_NUMBER = "1672087";

(function() {
    // Automatically bind the Till Number to the HTML display element on load
    window.addEventListener("DOMContentLoaded", () => {
        const tillEl = document.getElementById("tillDisplayElement");
        if (tillEl) tillEl.innerText = TILL_NUMBER;
    });

    const GUIDE_SEEN_KEY = "biz_interactive_tour_completed";
    let activeTourStep = 0;

    const stepsConfig = [
        {
            elementId: "tour-step-1",
            title: "Step 1: Till Number Visible",
            text: `Your Till number '${TILL_NUMBER}' is shown right here. Tap it to copy before paying.`,
            hasExtraBtn: false,
            placement: "bottom"
        },
        {
            elementId: "tour-step-2",
            title: "Step 2: MAC Configuration",
            text: "Before picking your package, check your phone settings. Click below to view the configuration guide.",
            hasExtraBtn: true,
            placement: "top"
        },
        {
            elementId: "robotAssistant",
            title: "Step 3: Bot Assistant & FAQ",
            text: "Click our blue robot assistant anytime to open the FAQ Hub.",
            hasExtraBtn: false,
            placement: "top"
        }
    ];

    window.addEventListener("DOMContentLoaded", () => {
        const hasFinishedTour = localStorage.getItem(GUIDE_SEEN_KEY);
        if (!hasFinishedTour) {
            setTimeout(startInteractiveTour, 500);
        }
    });

    function startInteractiveTour() {
        activeTourStep = 0;
        document.getElementById("guideBackdrop").classList.add("active");
        renderCurrentStep();
    }

    function removeAllHighlights() {
        document.querySelectorAll('.guide-highlight').forEach(el => {
            el.classList.remove('guide-highlight');
        });
    }

    function renderCurrentStep() {
        removeAllHighlights();

        if (activeTourStep >= stepsConfig.length) {
            terminateInteractiveTour();
            return;
        }

        const currentConf = stepsConfig[activeTourStep];
        const targetEl = document.getElementById(currentConf.elementId);
        const bubble = document.getElementById("guideBubble");

        if (!targetEl) {
            activeTourStep++;
            renderCurrentStep();
            return;
        }

        // Apply visual highlight class to active element
        targetEl.classList.add('guide-highlight');

        const rect = targetEl.getBoundingClientRect();
        
        document.getElementById("guideTitle").innerText = currentConf.title;
        document.getElementById("guideText").innerText = currentConf.text;

        const extraDiv = document.getElementById("guideExtraAction");
        if (currentConf.hasExtraBtn) {
            extraDiv.innerHTML = `<button class="tour-action-btn" onclick="openMacGuideFromTour()">📖 Open MAC Setup Guide</button>`;
        } else {
            extraDiv.innerHTML = `<button class="tour-action-btn" onclick="advanceTourStep()">Got it, Next →</button>`;
        }
        
        bubble.classList.add("active");

        let targetTop = rect.bottom + window.scrollY + 12;
        let targetLeft = rect.left + window.scrollX + (rect.width / 2) - 150;

        if (targetLeft < 10) targetLeft = 10;
        if (targetLeft + 300 > window.innerWidth) targetLeft = window.innerWidth - 310;
        
        if (targetTop + 180 > window.innerHeight + window.scrollY) {
            targetTop = rect.top + window.scrollY - 180;
        }

        bubble.style.top = targetTop + "px";
        bubble.style.left = targetLeft + "px";
    }

    window.advanceTourStep = function() {
        activeTourStep++;
        renderCurrentStep();
    };

    window.openMacGuideFromTour = function() {
        removeAllHighlights();
        document.getElementById("guideBackdrop").classList.remove("active");
        document.getElementById("guideBubble").classList.remove("active");
        const modal = document.getElementById("videoModal");
        modal.classList.add("active");
        showMacSelectionMenu();
    };

    window.handleTillClickEngine = function() {
        copyText(TILL_NUMBER);
        if (localStorage.getItem(GUIDE_SEEN_KEY) !== "true" && activeTourStep === 0) {
            activeTourStep = 1;
            renderCurrentStep();
        }
    };

    window.handleBundleClickEngine = function(amount) {
        let clickCount = parseInt(localStorage.getItem("bundle_click_count") || "0");
        
        if (clickCount < 3) {
            localStorage.setItem("bundle_click_count", clickCount + 1);
            startBundle(amount);
        } else {
            proceedToPaymentDirectly(amount);
        }
    };

    window.terminateInteractiveTour = function() {
        removeAllHighlights();
        localStorage.setItem(GUIDE_SEEN_KEY, "true");
        document.getElementById("guideBackdrop").classList.remove("active");
        document.getElementById("guideBubble").classList.remove("active");
        const modal = document.getElementById("videoModal");
        modal.classList.remove("active");
    };
})();

const MIKROTIK_IP = "192.168.88.1";
let userPhone = localStorage.getItem('phone') || null;
let lastAmount = localStorage.getItem('lastAmount') || 0;
let bundleInterval = null;
let pendingBundleAmount = 0;

window.onload = () => {
    if (userPhone && lastAmount > 0) {
        checkSession(userPhone, lastAmount, true); 
    }
};

function copyText(txt) {
    navigator.clipboard.writeText(txt).then(() => alert("✅ Copied: " + txt));
}

function startBundle(amount) {
    if (bundleInterval) { 
        alert("A payment check is already in progress."); 
        return; 
    }

    pendingBundleAmount = amount;
    const modal = document.getElementById("videoModal");
    modal.classList.add("active");
    showMacSelectionMenu();
}

function proceedToPaymentDirectly(amount) {
    if (bundleInterval) { 
        alert("A payment check is already in progress."); 
        return; 
    }

    const phone = prompt("Enter M-Pesa phone number (e.g., 2547XXXXXXXX)", userPhone || "");
    if (!phone) return;

    const paymentConfirmed = confirm(`📲 Please go to M-Pesa on your phone, pay KES ${amount} to Till ${TILL_NUMBER}.\n\nClick OK once the payment transaction has successfully gone through on your phone to activate verification.`);
    
    if (!paymentConfirmed) {
        document.getElementById("bundleStatus").innerHTML = `<div class="error-text">❌ Payment verification cancelled. Please complete payment when ready.</div>`;
        return;
    }

    userPhone = phone;
    lastAmount = amount;
    localStorage.setItem('phone', phone);
    localStorage.setItem('lastAmount', amount);

    document.getElementById("bundleStatus").innerHTML = 
        `📲 Payment submitted.<br>⏳ Checking M-Pesa confirmation for KES ${amount}...`;

    bundleInterval = setInterval(() => checkSession(phone, amount, false), 3000);
}

window.confirmMacIsSet = function() {
    closeSupportModal();
    terminateInteractiveTour();

    const phone = prompt("Enter M-Pesa phone number (e.g., 2547XXXXXXXX)", userPhone || "");
    if (!phone) return;

    const amount = pendingBundleAmount;
    const paymentConfirmed = confirm(`📲 Please go to M-Pesa on your phone, pay KES ${amount} to Till ${TILL_NUMBER}.\n\nClick OK once the payment transaction has successfully gone through on your phone to activate verification.`);
    
    if (!paymentConfirmed) {
        document.getElementById("bundleStatus").innerHTML = `<div class="error-text">❌ Payment verification cancelled. Please complete payment when ready.</div>`;
        return;
    }

    userPhone = phone;
    lastAmount = amount;
    localStorage.setItem('phone', phone);
    localStorage.setItem('lastAmount', amount);

    document.getElementById("bundleStatus").innerHTML = 
        `📲 Payment submitted.<br>⏳ Checking M-Pesa confirmation for KES ${amount}...`;

    bundleInterval = setInterval(() => checkSession(phone, amount, false), 3000);
};

async function checkSession(phone, amount, isAutoCheck) {
    try {
        const res = await fetch("verify1.php", {
            method: "POST",
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: "bundle_check", phone, expected: amount })
        });

        const data = await res.json();

        if (data.status === "success") {
            if (bundleInterval) clearInterval(bundleInterval);
            bundleInterval = null;
            
            document.getElementById("bundleStatus").innerHTML = 
                `<span class="success-text">✅ <b>Authenticated!</b><br>Expires: ${data.expiry}</span>`;

            setTimeout(() => {
                window.location.href = `http://${MIKROTIK_IP}/login?username=${encodeURIComponent(data.mikrotik.username)}&password=${encodeURIComponent(data.mikrotik.password)}`;
            }, 1000);
        } 
        else if (data.status === "partial") {
            document.getElementById("bundleStatus").innerHTML = 
                `⚠️ Received KES ${data.paid}. Need balance: <b>KES ${data.balance}</b>`;
        } 
        else if (data.status === "error") {
            if (bundleInterval) {
                clearInterval(bundleInterval);
                bundleInterval = null;
            }

            let errorMsg = data.message;
            if (errorMsg.includes("Device limit")) {
                document.getElementById("bundleStatus").innerHTML = `
                    <div class="error-text">
                        <b>🚫 Access Denied</b><br>
                        ${errorMsg}<br>
                        <button onclick="localStorage.clear(); location.reload();" style="margin-top:8px; font-size:0.75rem; padding:6px 10px;">Try Different Number</button>
                    </div>`;
            } else {
                document.getElementById("bundleStatus").innerHTML = `<div class="error-text">❌ ${errorMsg}</div>`;
            }
        }
    } catch (err) {
        console.error("Network error:", err);
    }
}
</script>

<script>
(function() {
    const robot = document.getElementById("robotAssistant");
    const modal = document.getElementById("videoModal");
    const video = document.getElementById("supportVideoPlayer");
    const source = document.getElementById("supportVideoSource");
    const selection = document.getElementById("videoSelection");
    const wrapper = document.getElementById("videoWrapper");
    const macSelectionWrapper = document.getElementById("macSelectionWrapper");
    const macIphoneWrapper = document.getElementById("macIphoneWrapper");
    const macAndroidWrapper = document.getElementById("macAndroidWrapper");

    let isDragging = false, hasMoved = false, startX, startY, initialX, initialY;

    robot.addEventListener("mousedown", dragStart);
    robot.addEventListener("touchstart", dragStart, { passive: false });

    function dragStart(e) {
        isDragging = true;
        hasMoved = false;
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        startX = clientX;
        startY = clientY;
        
        const rect = robot.getBoundingClientRect();
        initialX = rect.left;
        initialY = rect.top;

        document.addEventListener("mousemove", drag);
        document.addEventListener("touchmove", drag, { passive: false });
        document.addEventListener("mouseup", dragEnd);
        document.addEventListener("touchend", dragEnd);
    }

    function drag(e) {
        if (!isDragging) return;
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        
        const dx = clientX - startX;
        const dy = clientY - startY;

        if (Math.abs(dx) > 5 || Math.abs(dy) > 5) {
            hasMoved = true;
        }

        robot.style.left = (initialX + dx) + "px";
        robot.style.top = (initialY + dy) + "px";
        robot.style.bottom = "auto";
        robot.style.right = "auto";
    }

    function dragEnd() {
        isDragging = false;
        document.removeEventListener("mousemove", drag);
        document.removeEventListener("touchmove", drag);
        document.removeEventListener("mouseup", dragEnd);
        document.removeEventListener("touchend", dragEnd);

        if (!hasMoved) {
            openSupportModal();
        }
    }

    window.openSupportModal = function() {
        modal.classList.add("active");
        showSupportMenu();
    };

    window.closeSupportModal = function() {
        modal.classList.remove("active");
        if (video) video.pause();
    };

    window.showSupportMenu = function() {
        selection.style.display = "block";
        macSelectionWrapper.style.display = "none";
        macIphoneWrapper.style.display = "none";
        macAndroidWrapper.style.display = "none";
        wrapper.style.display = "none";
        if (video) video.pause();
    };

    window.showMacSelectionMenu = function() {
        selection.style.display = "none";
        macSelectionWrapper.style.display = "block";
        macIphoneWrapper.style.display = "none";
        macAndroidWrapper.style.display = "none";
        wrapper.style.display = "none";
    };

    window.showMacStep = function(type) {
        macSelectionWrapper.style.display = "none";
        if (type === 'iphone') {
            macIphoneWrapper.style.display = "block";
        } else {
            macAndroidWrapper.style.display = "block";
        }
    };

    window.playSupportVideo = function(type) {
        selection.style.display = "none";
        macSelectionWrapper.style.display = "none";
        macIphoneWrapper.style.display = "none";
        macAndroidWrapper.style.display = "none";
        wrapper.style.display = "block";
        
        source.src = type === 'macsetting' ? "videos/mac_setting.mp4" : "videos/app_guide.mp4";
        video.load();
        video.play().catch(err => console.log("Auto-play prevented:", err));
    };
})();
</script>

</body>
</html>