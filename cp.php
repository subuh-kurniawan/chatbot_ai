<?php
// 1. Include and check connection
include "admin/fungsi/koneksi.php";

/** @var mysqli $koneksi */
if (!$koneksi) {
    die("Database connection error.");
}

// Set charset to ensure emojis and special characters work
mysqli_set_charset($koneksi, "utf8mb4");
$sql = mysqli_query($koneksi, "SELECT * FROM datasekolah");
$data = mysqli_fetch_assoc($sql);
$apiKey = null;
$apiId  = null;

// --- 1. Select API Key with Atomic Transaction ---
$koneksi->begin_transaction();

try {
    // Select the key with the lowest usage and lock the row (FOR UPDATE)
    $query = "SELECT id, api_key FROM api_keys ORDER BY usage_count ASC, id ASC LIMIT 1 FOR UPDATE";
    $result = $koneksi->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $apiKey = $row['api_key'];
        $apiId  = $row['id'];

        // Update usage_count inside the lock
        $update = $koneksi->prepare("UPDATE api_keys SET usage_count = usage_count + 1 WHERE id = ?");
        if ($update) {
            $update->bind_param("i", $apiId);
            $update->execute();
            $update->close();
        }
    }

    $koneksi->commit();
} catch (Exception $e) {
    // If something fails, rollback so the lock is released
    $koneksi->rollback();
    error_log("API Key Selection Error: " . $e->getMessage());
}

// --- 2. Fallback Mechanism ---
// Use a secure fallback if DB is empty or fails
if (!$apiKey) {
    $apiKey = "APKEY"; // Note: Move to .env for security
}

$apiKeyJson = json_encode([$apiKey]);

// --- 3. Fetch Supported Models ---
$models = [];
$sql_model = "SELECT model_name FROM api_model 
              WHERE is_supported = 1 
              AND is_active = 1 
              AND guna_model = 2 
              ORDER BY id ASC";

$res_model = $koneksi->query($sql_model);

if ($res_model && $res_model->num_rows > 0) {
    while ($row = $res_model->fetch_assoc()) {
        $models[] = $row['model_name'];
    }
}

// Default to gemini-1.5-flash if no active models in DB
$model = !empty($models) ? $models[0] : "gemini-1.5-flash";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yaya Tata - Multimodal AI Chatbot (Dokumen, Gambar & Web Search)</title>
    <!-- Load Tailwind CSS from CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Open Graph - Mengganti variabel PHP dengan placeholder statis -->
    <meta property="og:title" content="Yaya Tata - Multimodal AI Chatbot">
    <meta property="og:description" content="Masuki dunia game futuristik dengan AI Yata. Pelajari, mainkan, dan temukan strategi masa depan!">
    <meta property="og:image" content="https://placehold.co/1200x630/3b82f6/ffffff?text=AI+Chatbot">
    <meta property="og:url" content="#">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="AI Yata">

    <!-- Twitter Card - Mengganti variabel PHP dengan placeholder statis -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="AI Yata: Gerbang Game Masa Depan">
    <meta name="twitter:description" content="Masuki dunia game futuristik dengan AI Yata. Pelajari, mainkan, dan temukan strategi masa depan!">
    <meta name="twitter:image" content="https://placehold.co/1200x630/3b82f6/ffffff?text=AI+Chatbot">
    
    <!-- Load Remixicon for icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
    <!-- Load Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- PUSTAKA UNTUK PEMROSESAN FILE & MARKDOWN -->
    <script src="https://cdn.jsdelivr.net/npm/marked@4.0.10/marked.min.js"></script>
    <!-- Mammoth.js for DOCX text extraction -->
    <script src="https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js"></script>
    <!-- SheetJS for XLSX parsing (dan Export) -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <!-- PDF.js for PDF text extraction -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    
    <style>
        /* Mengatur HTML dan Body untuk mengisi seluruh viewport */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            overflow: hidden; /* Mencegah scroll pada body, hanya main area yang scroll */
        }
        
        body {
            font-family: 'Inter', sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }
        
        /* CONTAINER UTAMA - Mengatur layout Flex */
        .app-container {
            width: 100%;
            max-width: 100vw; /* Memastikan tidak melebihi lebar viewport */
            height: 100vh; /* Menggunakan tinggi viewport penuh */
            position: relative;
            display: flex;
            flex-direction: column; /* Konten ditumpuk vertikal */
        }

        /* Custom styling for the contenteditable placeholder */
        [contenteditable]:empty:before {
            content: attr(data-placeholder);
            color: #9ca3af;
            pointer-events: none;
            display: block;
        }
        
        /* Menggunakan kelas untuk memastikan Markdown dirender dengan baik */
        .markdown-body {
            line-height: 1.6;
            color: #1f2937;
        }
        .markdown-body pre {
            background-color: #f3f4f6;
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .markdown-body p, .markdown-body ul, .markdown-body ol {
            margin-bottom: 0.5rem;
        }
        
        /* Animasi berkedip untuk indikator mendengarkan */
        .animate-pulse-red {
            animation: pulse-red 1s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse-red {
            0%, 100% { opacity: 1; background-color: rgb(239 68 68 / var(--tw-bg-opacity)); }
            50% { opacity: .5; background-color: rgb(239 68 68 / var(--tw-bg-opacity)); }
        }
        @keyframes wobble {
            0% { transform: translateX(0) translateY(10px) rotate(0deg) scale(0.95); opacity: 0; }
            15% { transform: translateX(-2%) rotate(-3deg) scale(1); opacity: 1; }
            30% { transform: translateX(2%) rotate(3deg); }
            45% { transform: translateX(-1%) rotate(-2deg); }
            60% { transform: translateX(1%) rotate(2deg); }
            75% { transform: translateX(0) rotate(-1deg); }
            100% { transform: translateX(0) rotate(0deg) scale(1); opacity: 1; }
        }

        .wobble {
            animation: wobble 0.6s ease-out;
        }
        #chat-history {
            font-size: var(--chat-font-size, 16px);
        }

        @keyframes gradientMove {
             0% { background-position: 0% 50%; }
             50% { background-position: 100% 50%; }
             100% { background-position: 0% 50%; }
           }

            /* Animasi gelembung */
            @keyframes bubbleFloat {
              0% { transform: translateY(0) scale(1); opacity: 0.6; }
              100% { transform: translateY(-100vh) scale(1.2); opacity: 0; }
            }

            .animated-gradient {
              background: linear-gradient(-45deg, #3b82f6, #8b5cf6, #ec4899, #f59e0b);
              background-size: 400% 400%;
              animation: gradientMove 12s ease infinite;
            }

            .bubble {
              position: fixed;
              bottom: -50px;
              background: rgba(255, 255, 255, 0.15);
              border-radius: 50%;
              animation: bubbleFloat linear infinite;
              pointer-events: none;
              backdrop-filter: blur(2px);
            }

            /* Variasi ukuran & kecepatan bubble */
            .bubble:nth-child(1) { left: 10%; width: 40px; height: 40px; animation-duration: 14s; }
            .bubble:nth-child(2) { left: 25%; width: 20px; height: 20px; animation-duration: 10s; }
            .bubble:nth-child(3) { left: 40%; width: 60px; height: 60px; animation-duration: 18s; }
            .bubble:nth-child(4) { left: 65%; width: 30px; height: 30px; animation-duration: 12s; }
            .bubble:nth-child(5) { left: 80%; width: 50px; height: 50px; animation-duration: 20s; }
        
        /* STYLE UNTUK SUMBER WEB YANG DITINGKATKAN */
        .sources-bar {
            background-color: #e0f2fe; /* blue-50 */
            padding: 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid #93c5fd; /* blue-300 */
        }
        .source-link {
            transition: color 0.15s, background-color 0.15s;
            display: block;
            padding: 0.25rem 0.5rem;
            margin: 0 -0.5rem;
            border-radius: 0.25rem;
        }
        .source-link:hover {
            background-color: #bfdbfe; /* blue-200 */
            color: #1d4ed8; /* blue-700 */
        }
        /* Styling Dasar untuk Link */
        a {
            position: relative;
            text-decoration: none;
            display: inline-block;

            background-color: #490ced36;
            padding: 10px 40px 10px 15px;
            margin: 5px;
            border-radius: 5px;
            font-family: Arial, sans-serif;
            
            transition: 0.3s ease;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.2);
        }

        /* ICON DIBUAT DENGAN CSS PSEUDO ELEMENT */
        a::after {
            content: "";
            position: absolute;
            right: 12px;
            top: 50%;
            width: 18px;
            height: 18px;
            transform: translateY(-50%);
            
            background-color: white; /* Warna icon */
            mask: url('https://cdn.jsdelivr.net/npm/lucide-static/icons/link.svg') no-repeat center;
            mask-size: contain;
        }

        a:hover {
            background-color: #2980b9;
            transform: translateY(-1px);
            box-shadow: 3px 3px 7px rgba(0,0,0,0.3);
        }

        a:hover::after {
            right: 8px; /* icon bergeser saat hover */
        }
/* --- BASE STYLE (default untuk layar sedang & besar) --- */
/* === BASE (Desktop Default) === */
#file-preview-area {
    display: grid;
    grid-auto-flow: column;
    grid-template-rows: repeat(2, auto);
    gap: 8px;

    max-height: 90px;
    max-width: 260px;

    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 4px;
}

/* === HP KECIL (≤ 480px) — Fokus vertikal === */
@media (max-width: 480px) {
    #file-preview-area {
        grid-auto-flow: row;
        grid-template-columns: repeat(2, 1fr);

        max-width: 100%;
        max-height: 150px;

        overflow-y: auto;
        overflow-x: hidden;
    }

    footer {
        padding-bottom: 10px !important;
    }
}

/* === TABLET (481px – 768px) — Grid adaptif === */
@media (max-width: 768px) {
    #file-preview-area {
        max-width: 200px;
        max-height: 100px;
    }
}

/* === DEVICE BESAR (≥ 1024px) === */
@media (min-width: 1024px) {
    #file-preview-area {
        max-width: 240px;
        max-height: 100px;
    }
}

/* === ULTRA WIDE (≥ 1440px) === */
@media (min-width: 1440px) {
    #file-preview-area {
        max-width: 330px;
        max-height: 130px;
    }
}

/* Scrollbar */
#file-preview-area::-webkit-scrollbar {
    height: 6px;
    width: 6px;
}
#file-preview-area::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}
/* === MOBILE VIEW (≤ 480px) === */
@media (max-width: 480px) {

    /* Izinkan elemen di dalam input area untuk membungkus */
    .input-area {
        flex-wrap: wrap;
        padding-top: 10px;
        gap: 6px;
    }

    /* Pindahkan tombol ke baris pertama */
    #attachment-btn,
    #history-modal-btn,
    #send-btn {
        order: -1;                    /* Naik ke atas */
    }

    /* Atur tata letak tombol biar rapi */
    #attachment-btn,
    #history-modal-btn {
        margin-right: 6px;
    }

    #send-btn {
        margin-left: auto;            /* Geser ke kanan */
    }

    /* File preview di bawah tombol */
    #file-preview-area {
        order: 0;
        width: 100%;
        max-width: 100%;
        margin-top: 5px;
    }

    /* Input teks tetap di baris paling bawah */
    #user-input {
        order: 1;
        width: 100%;
    }
}

    </style>
    <script>
        // Set worker URL untuk PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'gemini-blue': '#1a73e8',
                        'primary': '#1a73e8', // Mendefinisikan warna utama agar mudah dipakai di CSS
                    }
                }
            }
        }
    </script>
</head>
<body class="animated-gradient">

    <!-- gelembung animasi -->
    <div class="bubble"></div>
    <div class="bubble"></div>
    <div class="bubble"></div>
    <div class="bubble"></div>
    <div class="bubble"></div>

    <!-- CONTAINER UTAMA -->
    <div class="app-container">

        <!-- HEADER (FIXED) -->
        <header class="fixed w-full top-0 z-20 flex justify-center backdrop-blur-md bg-white/20 py-3 shadow-xl border-b border-white/50">
            <button id="new-chat-btn-header"
                    class="px-10 py-1.5 bg-white/90 text-gray-800 text-sm font-semibold rounded-full border border-gray-300 shadow-md hover:bg-white transition duration-150 transform hover:scale-[1.02]"
                    title="Mulai Chat Baru">
                Chat Baru <i class="ri-sparkling-2-line ml-1"></i>
            </button>
        </header>

        <!-- MAIN CHAT HISTORY AREA (Mengambil sisa ruang dan scrollable) -->
        <main id="chat-history" 
              class="flex-grow w-full mx-auto p-6 sm:p-8 overflow-y-auto space-y-4"
              style="padding-top: 80px; padding-bottom: 120px;">
            <!-- Riwayat percakapan akan dimuat di sini -->
            <div class="text-center text-white/80 mt-10 p-2">

    <!-- Logo -->
    

    <h1 class="text-4xl sm:text-5xl font-extrabold mb-3 drop-shadow-lg">
        Yaya Tata <i class="ri-gamepad-line"></i>
        <img src="admin/foto/<?php echo $data['logo']; ?>" alt="Logo" 
         class="mx-auto w-20 h-20 mb-4 drop-shadow-xl rounded-full">
    </h1>

    <p class="text-xl font-medium drop-shadow-md">
        Multimodal AI Chatbot: Dokumen, Gambar, & Pencarian Web
    </p>

    <p class="mt-4 text-sm text-white/70">
        Mulai percakapan dengan mengetik atau melampirkan file.
    </p>
</div>

        </main>
        
        <!-- USER INPUT FOOTER AREA (FIXED) -->
        <footer class="fixed w-full bottom-0 z-10 p-2 pt-2 flex justify-center">
            <div class="max-w-7xl w-full">
                <form action="#" class="typing-form space-y-1">
                    
                    <!-- Slider Bar - Dibuat lebih ringkas -->
                    <div class="flex items-center gap-2 px-3 py-1 bg-white/30 rounded-lg shadow-inner">
                        <label for="fontSizeSlider" class="text-sm text-gray-800 font-medium whitespace-nowrap">Ukuran Teks:</label>
                        <input
                            type="range"
                            id="fontSizeSlider"
                            min="12"
                            max="32"
                            value="16"
                            class="flex-grow h-1 rounded-lg appearance-none cursor-pointer accent-gemini-blue bg-gray-400"
                        />
                    </div>

                    <!-- Input Area -->
                   <div class="relative flex items-center p-2 input-area 
    backdrop-blur-lg bg-white/80 border border-gray-300
    rounded-3xl shadow-xl focus-within:ring-4
    focus-within:ring-gemini-blue/50 focus-within:border-gemini-blue 
    transition-all">
                        <!-- Hidden File Input -->
                        <input type="file" id="file-input" accept="image/*,.docx,.xlsx,.pdf" multiple hidden>

                        <!-- Attachment Button -->
                        <button type="button" id="attachment-btn" class="flex-shrink-0 flex items-center text-gray-600 text-xl hover:text-gemini-blue p-2 rounded-full hover:bg-gray-100 transition duration-150" title="Lampirkan File">
                            <i class="fas fa-paperclip"></i>
                        </button>

                        <!-- History Button -->
                        <button type="button" id="history-modal-btn" class="flex-shrink-0 flex items-center text-gray-600 text-xl hover:text-gemini-blue p-2 rounded-full hover:bg-gray-100 transition duration-150" title="Riwayat Lokal">
                            <i class="fas fa-clock"></i>
                        </button>

                        <!-- File Preview -->
                        <div id="file-preview-area"
     class="flex-shrink-0 pl-2"></div>

                        <!-- Text Input -->
                        <div
                            contenteditable="true"
                            id="user-input"
                            data-placeholder="Tanyakan apa saja, atau ketuk mikrofon untuk input suara (ID)..."
                            class="flex-grow min-h-[40px] max-h-40 overflow-y-auto bg-transparent rounded-lg text-gray-800 outline-none p-2 text-base"
                        ></div>

                        <!-- Send Button -->
                        <button type="submit" id="send-btn" class="flex-shrink-0 text-white bg-gemini-blue text-xl p-3 rounded-full ml-2 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-blue-600 transition duration-150 transform hover:scale-105" title="Kirim" disabled>
                            <i id="send-icon" class="fas fa-arrow-up"></i>
                        </button>
                    </div>
                </form>
            </div>
        </footer>

    </div>
    
    <!-- Toast/Notification Container (Untuk mengganti alert/confirm) -->
    <div id="toast-container" class="fixed bottom-24 left-1/2 transform -translate-x-1/2 space-y-2 z-50">
        <!-- Toasts will appear here -->
    </div>
    
    <!-- HISTORY MANAGER MODAL (Centered Modal) -->
    <div id="history-manager-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-[100] flex items-center justify-center">
        <!-- Inner Modal Container. Dibuat lebih kecil (max-h-[80vh]) dan transisi diperhalus. -->
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden flex flex-col transition-all duration-300 transform opacity-0 scale-95">
            <!-- Modal Header -->
            <div class="p-5 border-b flex justify-between items-center bg-gray-50">
                <h2 class="text-xl font-bold text-gray-800"><i class="fas fa-clock text-gemini-blue mr-2"></i>Kelola Riwayat Percakapan</h2>
                <button id="modal-close-btn" class="text-gray-400 hover:text-gray-600 p-2">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <!-- Modal Content (Tabs: Save vs Load) -->
            <div class="p-5 flex-grow overflow-y-auto">
                
                <!-- Save Current Session -->
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <h3 class="font-semibold text-lg text-blue-700 mb-2">Simpan Sesi Saat Ini</h3>
                    <div class="flex space-x-2">
                        <input type="text" id="save-session-name" placeholder="Masukkan Nama Sesi (cth: Draft Laporan Q3)" class="flex-grow p-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <button id="modal-save-btn" class="flex-shrink-0 px-4 py-2 bg-gemini-blue text-white rounded-lg hover:bg-blue-600 transition disabled:opacity-50">
                            <i class="fas fa-save mr-1"></i> Simpan
                        </button>
                    </div>
                    <p id="save-status-info" class="text-xs text-gray-500 mt-2">Jumlah pesan saat ini: <span id="current-chat-count">0</span>.</p>
                </div>

                <!-- Load Existing Sessions -->
                <h3 class="font-semibold text-lg text-gray-700 mb-3 border-b pb-1">Muat Riwayat Tersimpan</h3>
                <div id="saved-sessions-list" class="space-y-3">
                    <!-- Daftar riwayat akan dirender di sini -->
                    <p id="no-sessions-msg" class="text-gray-500 text-center py-4 hidden">Tidak ada sesi yang tersimpan.</p>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="p-4 border-t flex justify-end bg-gray-50">
                <button id="modal-export-btn" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition mr-2">
                    <i class="fas fa-file-export mr-1"></i> Ekspor Semua (JSON)
                </button>
                <button id="modal-new-chat-btn" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition">
                    <i class="fas fa-plus-circle mr-1"></i> Mulai Chat Baru
                </button>
            </div>

        </div>
    </div>


   <script type="module">
        // Global Variables
        const apiKey = <?php echo $apiKeyJson; ?>;
         const MODEL_NAME = '<?php echo $model; ?>';
        const dataSek = "<?php echo $data['nama']; ?>";
        const modelName = <?php echo json_encode($model); ?>;
        const apiUrl = `https://generativelanguage.googleapis.com/v1beta/models/${modelName}:generateContent?key=${apiKey}`;
        
        // PERINGATAN KRITIS: Fungsi di bawah ini menggunakan localStorage yang TIDAK diperbolehkan
        // di lingkungan ini. Wajib diganti dengan Firestore untuk data persisten.
        const ALL_SESSIONS_KEY = 'multimodalAllChatSessions'; 
        
        // STT Global Variables
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        let recognition = null;
        let isRecognizing = false;

        // TTS Global Variables
        const synthesis = window.speechSynthesis;
        let currentUtterance = null;
        let preferredVoice = null;
        let isSpeaking = false; 

        // State Chat
        let chatHistory = []; 
        let attachedFiles = []; 
        let currentSessionId = null; // ID sesi aktif saat ini

        // DOM Elements
        const chatHistoryEl = document.getElementById('chat-history');
        const userInputEl = document.getElementById('user-input');
        const sendBtn = document.getElementById('send-btn');
        const attachmentBtn = document.getElementById('attachment-btn');
        const fileInput = document.getElementById('file-input');
        const filePreviewArea = document.getElementById('file-preview-area');
        const sendIconEl = document.getElementById('send-icon');
        const newChatBtnHeader = document.getElementById('new-chat-btn-header');

        // New History Modal Elements
        const historyModalBtn = document.getElementById('history-modal-btn');
        const historyManagerModal = document.getElementById('history-manager-modal');
        const innerModal = historyManagerModal.querySelector('.bg-white'); // Elemen modal bagian dalam
        const modalCloseBtn = document.getElementById('modal-close-btn');
        const modalSaveBtn = document.getElementById('modal-save-btn');
        const modalExportBtn = document.getElementById('modal-export-btn');
        const modalNewChatBtn = document.getElementById('modal-new-chat-btn');
        const savedSessionsList = document.getElementById('saved-sessions-list');
        const saveSessionNameInput = document.getElementById('save-session-name');
        const currentChatCountEl = document.getElementById('current-chat-count');
        const noSessionsMsg = document.getElementById('no-sessions-msg');

        // --- TTS CONTROL FUNCTIONS ---
        
        /**
         * Mencari suara TTS yang diinginkan ("Sara" atau fallback ke suara ID/generik.
         */
        function setPreferredVoice() {
            // Tunggu hingga suara dimuat (asinkron)
            synthesis.onvoiceschanged = () => {
                const voices = synthesis.getVoices();
                
                // Prioritas 1: Suara dengan nama "Sara"
                let voice = voices.find(v => v.name.includes('Sara') && v.lang.startsWith('id'));
                
                // Prioritas 2: Suara Indonesia (id-ID)
                if (!voice) {
                    voice = voices.find(v => v.lang.startsWith('id-'));
                }
                
                // Prioritas 3: Suara Female (generik)
                if (!voice && voices.length > 0) {
                    voice = voices.find(v => v.name.toLowerCase().includes('female')) || voices[0];
                }

                preferredVoice = voice;

                if (preferredVoice) {
                    console.log(`TTS Voice selected: ${preferredVoice.name} (${preferredVoice.lang})`);
                } else {
                    console.warn("TTS: No suitable voice found (Sara, ID, or generic).");
                }
            };
            // Panggil secara eksplisit jika suara sudah dimuat
            if (synthesis.getVoices().length > 0) {
                synthesis.onvoiceschanged();
            }
        }
        
        /**
         * Menghentikan pemutaran TTS yang sedang berlangsung.
         */
        function stopSpeaking() {
            if (synthesis.speaking || synthesis.pending) {
                synthesis.cancel();
                isSpeaking = false;
                // Reset semua tombol stop ke mode play
                document.querySelectorAll('.tts-btn').forEach(btn => {
                    btn.classList.add('tts-play-btn');
                    btn.classList.remove('tts-stop-btn');
                    btn.innerHTML = '<i class="fas fa-play"></i>';
                    btn.title = 'Putar Respon';
                });
            }
        }
        
        /**
         * Memulai pemutaran TTS untuk teks yang diberikan.
         * @param {string} text - Teks yang akan diucapkan.
         * @param {HTMLElement} buttonEl - Elemen tombol yang memicu pemutaran.
         */
        function speakText(text, buttonEl) {
            if (!synthesis || !preferredVoice) {
                return showToast('TTS tidak tersedia atau suara belum dimuat.', 'error');
            }

            // 1. Hentikan pemutaran yang sedang berlangsung
            stopSpeaking();
            
            // 2. Set tombol saat ini ke mode 'Stop'
            buttonEl.classList.add('tts-stop-btn');
            buttonEl.classList.remove('tts-play-btn');
            buttonEl.innerHTML = '<i class="fas fa-stop text-red-500"></i>';
            buttonEl.title = 'Hentikan Pemutaran';

            // 3. Siapkan Utterance
            currentUtterance = new SpeechSynthesisUtterance(text);
            currentUtterance.voice = preferredVoice;
            currentUtterance.lang = preferredVoice.lang;
            
            // 4. Set listener
            currentUtterance.onstart = () => {
                isSpeaking = true;
            };

            currentUtterance.onend = () => {
                isSpeaking = false;
                // Reset tombol ke mode 'Play'
                buttonEl.classList.add('tts-play-btn');
                buttonEl.classList.remove('tts-stop-btn');
                buttonEl.innerHTML = '<i class="fas fa-play"></i>';
                buttonEl.title = 'Putar Respon';
            };

            currentUtterance.onerror = (event) => {
                isSpeaking = false;
                console.error('SpeechSynthesisUtterance.onerror', event.error);
                showToast('TTS: Terjadi kesalahan saat memutar suara.', 'error');
                // Reset tombol ke mode 'Play'
                buttonEl.classList.add('tts-play-btn');
                buttonEl.classList.remove('tts-stop-btn');
                buttonEl.innerHTML = '<i class="fas fa-play"></i>';
                buttonEl.title = 'Putar Respon';
            };

            // 5. Speak!
            synthesis.speak(currentUtterance);
        }

        // --- HISTORY MANAGEMENT FUNCTIONS (MENGGUNAKAN localStorage - HARUS DIGANTI KE FIRESTORE) ---
        
        /**
         * Loads all saved chat sessions from localStorage.
         * @returns {Array} Array of session objects.
         */
        function loadAllSessions() {
            // !!! PERINGATAN: HARUS DIGANTI DENGAN FIRESTORE !!!
            try {
                const sessionsJson = localStorage.getItem(ALL_SESSIONS_KEY);
                return sessionsJson ? JSON.parse(sessionsJson) : [];
            } catch (e) {
                console.error("Error loading all sessions (localStorage):", e);
                return [];
            }
        }

        /**
         * Saves the array of all sessions back to localStorage.
         * @param {Array} sessionsArray 
         */
        function saveAllSessions(sessionsArray) {
            // !!! PERINGATAN: HARUS DIGANTI DENGAN FIRESTORE !!!
            localStorage.setItem(ALL_SESSIONS_KEY, JSON.stringify(sessionsArray));
        }

        function generateUUID() {
            // Generate a simple UUID
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }
        
        /**
         * Renders the chat history array to the DOM.
         * @param {Array} historyArray 
         */
        function displayHistory(historyArray) {
             chatHistoryEl.innerHTML = '';
             chatHistory = historyArray;
             chatHistory.forEach(msg => {
                 // Pastikan untuk merender semua properti yang dimuat
                 renderMessage(msg.role, msg.content, msg.attachments || [], true, msg.sources || []);
             });
             chatHistoryEl.scrollTop = chatHistoryEl.scrollHeight;
             currentChatCountEl.textContent = chatHistory.length;
        }
        
        /**
         * Menampilkan Modal Riwayat dan merender sesi tersimpan.
         */
        function openHistoryModal() {
            stopSpeaking(); // Hentikan TTS saat modal dibuka
            historyManagerModal.classList.remove('hidden');
            // Gunakan requestAnimationFrame untuk memastikan transisi dimulai di frame berikutnya
            requestAnimationFrame(() => {
                innerModal.classList.remove('opacity-0', 'scale-95');
                innerModal.classList.add('opacity-100', 'scale-100');
            });
            
            renderSavedSessions();
            currentChatCountEl.textContent = chatHistory.length;
            if (currentSessionId) {
                saveSessionNameInput.value = loadAllSessions().find(s => s.id === currentSessionId)?.name || '';
            } else {
                 saveSessionNameInput.value = '';
            }
        }

        /**
         * Menyembunyikan Modal Riwayat.
         */
        function closeHistoryModal() {
            innerModal.classList.remove('opacity-100', 'scale-100');
            innerModal.classList.add('opacity-0', 'scale-95');
            
            // Tunggu hingga transisi selesai (300ms) sebelum menyembunyikan kontainer
            setTimeout(() => {
                historyManagerModal.classList.add('hidden');
            }, 300);
        }

        /**
         * Merender daftar sesi yang tersimpan di dalam modal.
         */
        function renderSavedSessions() {
            const sessions = loadAllSessions();
            savedSessionsList.innerHTML = '';

            if (sessions.length === 0) {
                noSessionsMsg.classList.remove('hidden');
                return;
            }
            noSessionsMsg.classList.add('hidden');

            sessions.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp)); // Urutkan dari yang terbaru

            sessions.forEach(session => {
                const date = new Date(session.timestamp).toLocaleString('id-ID', {
                    dateStyle: 'medium',
                    timeStyle: 'short'
                });
                
                const sessionEl = document.createElement('div');
                sessionEl.className = `flex items-center p-3 border rounded-lg shadow-sm transition hover:bg-gray-50 ${session.id === currentSessionId ? 'border-gemini-blue bg-blue-50' : 'border-gray-200'}`;
                sessionEl.innerHTML = `
                    <div class="flex-grow">
                        <p class="font-medium text-gray-800 truncate" title="${session.name}">${session.name}</p>
                        <p class="text-xs text-gray-500">Disimpan: ${date} | Pesan: ${session.history.length}</p>
                    </div>
                    <div class="flex space-x-2 ml-4">
                        <button data-id="${session.id}" class="load-session-btn px-3 py-1 bg-green-500 text-white text-sm rounded-md hover:bg-green-600 transition">
                            Muat
                        </button>
                        <button data-id="${session.id}" class="delete-session-btn px-3 py-1 bg-red-500 text-white text-sm rounded-md hover:bg-red-600 transition">
                            Hapus
                        </button>
                    </div>
                `;
                savedSessionsList.appendChild(sessionEl);
            });
            
            // Tambahkan listener setelah elemen dirender
            savedSessionsList.querySelectorAll('.load-session-btn').forEach(btn => {
                btn.addEventListener('click', (e) => handleLoadSessionById(e.target.dataset.id));
            });
            savedSessionsList.querySelectorAll('.delete-session-btn').forEach(btn => {
                btn.addEventListener('click', (e) => handleDeleteSessionById(e.target.dataset.id));
            });
        }
        
        /**
         * Menangani penyimpanan sesi chat aktif saat ini.
         */
        function handleSaveNewSession() {
            let sessionName = saveSessionNameInput.value.trim();
            
            if (chatHistory.length === 0) {
                return showToast('Riwayat chat kosong, tidak ada yang bisa disimpan.', 'info');
            }
            
            if (sessionName.length === 0) {
                const now = new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
                sessionName = `Sesi Tanpa Nama (${now})`;
            }

            const sessions = loadAllSessions();
            
            // Simplifikasi riwayat untuk menghindari duplikasi data gambar Base64 yang tidak perlu 
            // di dalam array sesi, karena Base64 sudah disimpan saat pesan dibuat.
            const sessionToSave = {
                id: generateUUID(),
                name: sessionName,
                timestamp: new Date().toISOString(),
                history: chatHistory.map(msg => ({
                    role: msg.role,
                    content: msg.content,
                    attachments: msg.attachments.map(att => ({
                        fileName: att.fileName,
                        mimeType: att.mimeType,
                        data: att.data, 
                        isTextContent: att.isTextContent || false 
                    })) || [],
                    sources: msg.sources || [] 
                }))
            };
            
            // Cek apakah sesi ini sudah pernah disimpan dan hanya perlu di-update (jika ada ID aktif)
            if (currentSessionId && sessions.some(s => s.id === currentSessionId)) {
                // Hapus sesi lama dan tambahkan versi terbaru dengan ID yang sama
                const index = sessions.findIndex(s => s.id === currentSessionId);
                sessions.splice(index, 1);
                sessionToSave.id = currentSessionId;
                sessions.push(sessionToSave);
                saveAllSessions(sessions);
                showToast(`Sesi **"${sessionName}"** berhasil diperbarui!`, 'success');
            } else {
                 sessions.push(sessionToSave);
                 saveAllSessions(sessions);
                 currentSessionId = sessionToSave.id; // Tetapkan sebagai sesi aktif
                 showToast(`Sesi baru **"${sessionName}"** berhasil disimpan!`, 'success');
            }
            
            renderSavedSessions();
            saveSessionNameInput.value = sessionName; // Pertahankan nama
        }

        /**
         * Memuat sesi yang dipilih berdasarkan ID.
         */
        function handleLoadSessionById(id) {
            const sessions = loadAllSessions();
            const sessionToLoad = sessions.find(s => s.id === id);

            if (sessionToLoad) {
                stopSpeaking(); // Hentikan TTS saat memuat sesi baru
                currentSessionId = id;
                displayHistory(sessionToLoad.history);
                closeHistoryModal();
                showToast(`Riwayat **"${sessionToLoad.name}"** berhasil dimuat.`, 'success');
            } else {
                showToast('Sesi tidak ditemukan.', 'error');
            }
        }
        
        /**
         * Menghapus sesi yang dipilih berdasarkan ID.
         */
        function handleDeleteSessionById(id) {
            let sessions = loadAllSessions();
            const index = sessions.findIndex(s => s.id === id);

            if (index !== -1) {
                const deletedName = sessions[index].name;
                sessions.splice(index, 1);
                saveAllSessions(sessions);
                
                // Jika yang dihapus adalah sesi aktif saat ini, reset ID aktif
                if (currentSessionId === id) {
                    currentSessionId = null;
                    saveSessionNameInput.value = ''; 
                    stopSpeaking(); // Hentikan TTS jika sesi aktif dihapus
                }
                
                showToast(`Sesi **"${deletedName}"** berhasil dihapus.`, 'success');
                renderSavedSessions(); 
            } else {
                showToast('Sesi tidak ditemukan.', 'error');
            }
        }
        
        /**
         * Menginisialisasi atau mereset tampilan chat.
         */
        function startNewChat() {
            stopSpeaking(); // Hentikan TTS saat chat baru dimulai
            closeHistoryModal();
            showToast('Chat baru dimulai. Sesi sebelumnya dinonaktifkan.', 'info');
            
            chatHistory = [];
            attachedFiles = [];
            currentSessionId = null; 
            saveSessionNameInput.value = '';
            renderFilePreviews();
            updateSendButtonState();
            
            // Initial greeting
            const initialMessage = {
                role: 'model',
                content: `Halo! Saya adalah Yaya Tata, asisten AI yang dikembangkan oleh ${dataSek}. Saya dirancang untuk **analisis gambar mendalam** dan **pencarian web *grounded*** yang akurat. Anda bisa unggah **Gambar (PNG, JPG)**, **PDF**, **DOCX**, **XLSX**, atau tanyakan apa saja.`,
                attachments: [],
                sources: [] 
            };
            chatHistory.push(initialMessage);
            renderMessage(initialMessage.role, initialMessage.content, initialMessage.attachments, true);
            currentChatCountEl.textContent = chatHistory.length;
            chatHistoryEl.scrollTop = chatHistoryEl.scrollHeight;
        }

        // --- SAVE/LOAD FILE HISTORY FUNCTIONS (Backup only) ---

        function exportAsJson() {
            closeHistoryModal();
            const sessionsToSave = loadAllSessions();
            if (sessionsToSave.length === 0) {
                 return showToast('Tidak ada riwayat tersimpan untuk diekspor.', 'info');
            }
            
            const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(sessionsToSave, null, 2));
            const downloadAnchorNode = document.createElement('a');
            downloadAnchorNode.setAttribute("href", dataStr);
            downloadAnchorNode.setAttribute("download", `gemini_all_chat_sessions_${Date.now()}.json`);
            document.body.appendChild(downloadAnchorNode);
            downloadAnchorNode.click();
            downloadAnchorNode.remove();
            
            showToast('Semua sesi berhasil diekspor sebagai **JSON**!', 'success');
        }

        // --- UTILITY UI FUNCTIONS (Same as previous version) ---
        
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toast-container');
            const colorClass = type === 'success' ? 'bg-green-500' : (type === 'info' ? 'bg-blue-500' : 'bg-red-500');
            
            const toast = document.createElement('div');
            toast.className = `p-3 rounded-xl shadow-lg text-white ${colorClass} transition-opacity duration-300 opacity-0 min-w-[250px] text-center`;
            
            if (message.includes('**')) {
                 toast.innerHTML = message.replace(/\*\*(.*?)\*\*/g, '<b>$1</b>');
            } else {
                 toast.textContent = message;
            }
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '1';
            }, 10);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.addEventListener('transitionend', () => toast.remove());
            }, 3000);
        }
        
        function getExportableContent(bubbleId) {
            const bubble = document.getElementById(bubbleId);
            if (!bubble) return "";

            const clone = bubble.cloneNode(true);
            
            // Hapus aksi bar dan sumber dari konten yang diekspor
            clone.querySelector('.action-bar')?.remove();
            clone.querySelector('.sources-bar')?.remove(); 
            
            const contentElement = clone.querySelector('.markdown-body');
            
            // Hapus atribut target="_blank" dan rel="noopener noreferrer" dari semua tautan
            contentElement.querySelectorAll('a').forEach(link => {
                link.removeAttribute('target');
                link.removeAttribute('rel');
            });
            
            return contentElement ? contentElement.innerHTML : '';
        }

        function downloadFile(data, filename, mimeType) {
            const blob = new Blob([data], { type: mimeType });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            URL.revokeObjectURL(url);
        }

        function handleCopy(bubbleId, buttonEl) {
            const rawContent = getExportableContent(bubbleId);
            const contentToCopy = (new DOMParser().parseFromString(rawContent, 'text/html')).documentElement.textContent.trim();
            
            if (!contentToCopy) {
                return showToast('Konten kosong, tidak ada yang bisa disalin.', 'info');
            }

            const tempInput = document.createElement('textarea');
            tempInput.value = contentToCopy;
            document.body.appendChild(tempInput);
            tempInput.select();
            
            let successful = false;
            try {
                successful = document.execCommand('copy');
                if (successful) {
                    showToast('Teks berhasil disalin!', 'success');
                    buttonEl.querySelector('i').className = 'fas fa-check text-green-400';
                    setTimeout(() => {
                        buttonEl.querySelector('i').className = 'far fa-copy'; 
                    }, 1000);
                } else {
                    showToast('Gagal menyalin. Silakan salin secara manual.', 'info');
                }
            } catch (err) {
                showToast('Gagal menyalin. Silakan salin secara manual.', 'info');
            } finally {
                document.body.removeChild(tempInput);
            }
        }
        
        function exportAsWord(bubbleId) {
            const innerHTML = getExportableContent(bubbleId);
            if (!innerHTML) return showToast('Konten kosong, tidak ada yang bisa diekspor.', 'info');
            
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='utf-8'>
                    <title>AI_Response_Word</title>
                    <style>body { font-family: 'Inter', sans-serif; line-height: 1.6; padding: 20px; }</style>
                </head>
                <body>${innerHTML}</body>
                </html>
            `;
            
            downloadFile(htmlContent, 'AI_Response.doc', 'application/msword');
            showToast('Ekspor sebagai **Word (.doc)** berhasil! Format mungkin dasar.', 'success');
        }

        function exportAsPDF(bubbleId) {
            const innerHTML = getExportableContent(bubbleId);
            if (!innerHTML) return showToast('Konten kosong, tidak ada yang bisa diekspor.', 'info');
            
            const htmlContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='utf-8'>
                    <title>AI_Response_PDF_Print</title>
                    <style>
                        body { font-family: 'Inter', sans-serif; padding: 20px; line-height: 1.6; }
                        pre { background-color: #f3f4f6; padding: 10px; border-radius: 5px; overflow-x: auto; }
                    </style>
                </head>
                <body>${innerHTML}</body>
                </html>
            `;
            
            downloadFile(htmlContent, 'AI_Response.html', 'text/html'); 
            showToast('File **HTML** diunduh. Buka file tersebut dan **gunakan "Cetak sebagai PDF"** di browser Anda untuk hasil PDF yang sempurna.', 'info');
        }

        function exportAsExcel(bubbleId) {
            const rawText = (new DOMParser().parseFromString(getExportableContent(bubbleId), 'text/html')).documentElement.textContent.trim();
            if (!rawText) return showToast('Konten kosong, tidak ada yang bisa diekspor.', 'info');
            
            const lines = rawText.split('\n');
            
            const processedData = lines.map(line => {
                let row = [line];
                if (line.includes(',')) {
                    row = line.split(',');
                } else if (line.includes('|')) {
                    row = line.split('|').map(cell => cell.trim()).filter(cell => cell.length > 0);
                }
                return row; 
            }).filter(row => row.length > 0 && row.some(cell => cell.trim() !== ''));

            if (processedData.length === 0) {
                return showToast('Konten tidak terlihat seperti data tabular, tidak dapat diekspor ke Excel.', 'info');
            }
            
            const ws = XLSX.utils.aoa_to_sheet(processedData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "AI_Data");
            
            XLSX.writeFile(wb, "AI_Response.xlsx");
            
            showToast('Ekspor sebagai **Excel (.xlsx)** berhasil!', 'success');
        }

        function exportAsPPT() {
             showToast('Fitur **PowerPoint (.pptx)** tidak didukung. Silakan salin teks dan buat presentasi secara manual.', 'error');
        }


        // --- SPEECH-TO-TEXT LOGIC ---

        if (SpeechRecognition) {
            recognition = new SpeechRecognition();
            recognition.continuous = false; 
            recognition.interimResults = false;
            recognition.lang = 'id-ID'; 

            recognition.onstart = () => {
                isRecognizing = true;
                sendBtn.classList.remove('bg-gemini-blue', 'hover:bg-blue-600'); 
                sendBtn.classList.add('bg-red-500', 'animate-pulse-red');
                sendIconEl.className = 'fas fa-microphone-slash';
                sendBtn.title = 'Mendengarkan... Ketuk untuk berhenti.';
                userInputEl.setAttribute('data-placeholder', 'Mendengarkan...');
                userInputEl.focus();
                sendBtn.disabled = false; // Pastikan tombol aktif untuk menghentikan
            };

            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                userInputEl.textContent = transcript;
                userInputEl.dispatchEvent(new Event('input')); 
            };

            recognition.onerror = (event) => {
                console.error('Speech recognition error:', event.error);
                if (event.error !== 'no-speech' && event.error !== 'aborted') {
                    renderMessage('model', `**Error Input Suara:** ${event.error}. Pastikan mikrofon Anda berfungsi.`);
                }
                recognition.stop(); 
            };

            recognition.onend = () => {
                isRecognizing = false;
                userInputEl.setAttribute('data-placeholder', 'Tanyakan apa saja, atau ketuk mikrofon untuk input suara (ID)...');
                
                // Jika ada teks hasil transkripsi, kirim pesan secara otomatis
                if (userInputEl.textContent.trim().length > 0) {
                    sendMessage();
                }

                updateSendButtonState(); // Restores the state
            };
        } else {
            console.warn("Speech Recognition not supported in this browser.");
        }

        function startVoiceInput() {
            if (recognition) {
                if (!isRecognizing) {
                    try {
                        userInputEl.textContent = ''; 
                        updateSendButtonState();
                        recognition.start();
                    } catch (e) {
                         if (e.name !== 'InvalidStateError') { 
                            console.error("Error starting recognition:", e);
                            renderMessage('model', `**Error Mikrofon:** Gagal memulai. Mungkin sudah aktif atau izin mikrofon diperlukan.`);
                         } else {
                            recognition.stop(); 
                         }
                    }
                } else {
                    recognition.stop();
                }
            } else {
                 renderMessage('model', `**Peringatan:** Input Suara tidak didukung di browser ini. Silakan gunakan input teks.`);
            }
        }

        
        // --- UI & STATE HANDLERS ---
        
        userInputEl.addEventListener('input', updateSendButtonState);
        userInputEl.addEventListener('keydown', handleKey); 
        fileInput.addEventListener('change', handleFileInputChange);
        attachmentBtn.addEventListener('click', () => fileInput.click());
        newChatBtnHeader.addEventListener('click', startNewChat); 

        // New History Modal Listeners
        historyModalBtn.addEventListener('click', openHistoryModal);
        modalCloseBtn.addEventListener('click', closeHistoryModal);
        historyManagerModal.addEventListener('click', (e) => {
            if (e.target.id === 'history-manager-modal') {
                closeHistoryModal(); // Tutup jika mengklik di luar konten modal
            }
        });
        modalSaveBtn.addEventListener('click', handleSaveNewSession);
        modalNewChatBtn.addEventListener('click', startNewChat);
        modalExportBtn.addEventListener('click', exportAsJson);

        // Panggil untuk memuat suara TTS saat inisialisasi
        setPreferredVoice();
        
        /**
         * Logic state tombol kirim yang diperbarui:
         * - Mikrofon aktif jika kotak teks kosong, terlepas dari apakah ada file terlampir.
         * - Panah Kirim aktif jika ada teks ATAU file, atau sedang merekam (dimana ikonnya sudah diubah).
         */
        function updateSendButtonState() {
            const hasText = userInputEl.textContent.trim().length > 0;
            const hasFiles = attachedFiles.length > 0;
            
            // Kondisi baru: Mikrofon aktif jika TIDAK ADA teks DAN STT didukung/tidak sedang aktif.
            const isTextEmpty = !hasText;

            // Jika kotak teks kosong, dan STT didukung/tidak sedang mengenali -> Gunakan Mikrofon
            if (isTextEmpty && SpeechRecognition && !isRecognizing) {
                sendBtn.classList.remove('bg-gemini-blue', 'hover:bg-blue-600', 'animate-pulse-red', 'bg-red-500');
                sendBtn.classList.add('bg-gray-400', 'hover:bg-gray-500'); 
                sendBtn.removeEventListener('click', sendMessage);
                sendBtn.addEventListener('click', startVoiceInput);
                sendIconEl.className = 'fas fa-microphone';
                sendBtn.title = 'Start Voice Input (ID)';
                sendBtn.disabled = false; // Mikrofon selalu siap jika teks kosong
            } else {
                // Jika ada teks, atau sedang merekam, atau tidak ada STT -> Gunakan Tombol Kirim
                sendBtn.classList.remove('bg-gray-400', 'hover:bg-gray-500', 'animate-pulse-red', 'bg-red-500');
                sendBtn.classList.add('bg-gemini-blue', 'hover:bg-blue-600');
                sendBtn.removeEventListener('click', startVoiceInput);
                sendBtn.addEventListener('click', sendMessage);
                sendIconEl.className = 'fas fa-arrow-up';
                sendBtn.title = 'Send Message';
                
                // Tombol kirim nonaktif hanya jika TIDAK ADA teks DAN TIDAK ADA file
                sendBtn.disabled = !(hasText || hasFiles);
            }
        }

        function handleKey(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault(); 
                if (!sendBtn.disabled && sendIconEl.className.includes('arrow-up')) {
                    sendMessage();
                }
            }
        }

        /**
         * Menambahkan pesan ke riwayat chat (DOM rendering saja).
         */
        function renderMessage(role, content, attachments = [], isInitialLoad = false, sources = []) {
            const isUser = role === 'user';
            const messageContainer = document.createElement('div');
            messageContainer.className = `flex ${isUser ? 'justify-end' : 'justify-start'}`;

            let contentHTML = '';
            
            const bubbleId = `bubble-${Date.now()}-${Math.floor(Math.random() * 1000)}`;

            if (isUser) {
                // Proses lampiran untuk pesan pengguna
                if (attachments.length > 0) {
                    const fileHtml = attachments.map(file => {
                        const isImage = file.mimeType.startsWith('image/');
                        let content = '';

                        if (isImage) {
                            // Tampilkan gambar untuk lampiran gambar
                            content = `<img src="${file.data}" alt="${file.fileName}" class="max-h-42 object-contain rounded-lg shadow-md mb-3 border border-gray-300">`;
                        } else {
                            // Tampilkan ikon dan nama file untuk dokumen
                            const iconClass = file.mimeType.includes('pdf') ? 'fas fa-file-pdf text-red-500' : 
                                             file.mimeType.includes('spreadsheet') ? 'fas fa-file-excel text-green-600' : 
                                             file.mimeType.includes('document') ? 'fas fa-file-word text-blue-500' : 'fas fa-file text-gray-700';
                            
                            // Jika ada konten teks yang diekstrak, tambahkan indikasi
                            const textContentIndicator = file.isTextContent ? 
                                `<span class="text-xs font-semibold text-gray-700 ml-1">(Teks Diekstrak)</span>` : '';

                            content = `<div class="inline-flex items-center space-x-1 text-xs text-gray-500 bg-gray-200 px-2 py-1 rounded-full mr-2 mb-2">
                                        <i class="${iconClass}"></i>
                                        <span>${file.fileName}</span>
                                        ${textContentIndicator}
                                       </div>`;
                        }

                        return content;

                    }).join('');
                    
                    contentHTML += `<div class="flex flex-wrap gap-2 mb-2">${fileHtml}</div>`;
                }
                
                contentHTML += `<div class="text-base break-words">${content}</div>`;

            } else {
                contentHTML += `<div class="markdown-body">${marked.parse(content)}</div>`;
            }


            const bubble = document.createElement('div');
            bubble.className = `
  p-4 max-w-7xl rounded-3xl shadow-lg transition-all duration-300 
  backdrop-blur-xl border wobble
  ${isUser 
    ? 'bg-indigo-700/50 backdrop-blur-lg text-white border-white/20 rounded-br-none hover:shadow-red-500/20' 
    : 'bg-white/80 text-gray-900 border-gray-300/30 rounded-tl-none hover:shadow-gray-500/10'}
`;

            
            if (!isUser) {
                bubble.id = bubbleId;
            }
            
            bubble.innerHTML = contentHTML;

            // START: Logic baru untuk memaksa tautan terbuka di tab baru (Target: Links inside markdown-body)
            if (!isUser) {
                const markdownBody = bubble.querySelector('.markdown-body');
                if (markdownBody) {
                    markdownBody.querySelectorAll('a').forEach(link => {
                        link.setAttribute('target', '_blank');
                        link.setAttribute('rel', 'noopener noreferrer'); // Best practice for security
                    });
                }
            }
            // END: Logic baru untuk memaksa tautan terbuka di tab baru

            // --- TAMBAHKAN SUMBER WEB (GROUNDING) YANG DITINGKATKAN ---
            if (!isUser && sources.length > 0) {
                let sourcesHTML = '<div class="sources-bar mt-3 text-xs text-gray-600 space-y-1">';
                sourcesHTML += '<div class="font-semibold mb-1 flex items-center text-blue-800"><i class="fas fa-search text-xs mr-2"></i>Sumber Web (Grounding):</div>';

                sources.forEach((source, index) => {
                    // FIX: Menambahkan rel="noopener noreferrer" untuk memastikan pembukaan tab baru
                    sourcesHTML += `<a href="${source.uri}" target="_blank" rel="noopener noreferrer" class="source-link flex items-center space-x-2 text-blue-600" title="${source.title}">
                    <i class="ri-home-line text-xs flex-shrink-0"></i>
                                       
                                        <span class="truncate">${source.title}</span>
                                    </a>`;
                });
                
                sourcesHTML += '</div>';
                
                bubble.innerHTML += sourcesHTML;
            }
            // --- AKHIR TAMBAH SUMBER WEB YANG DITINGKATKAN ---

            // --- ADD ACTION BAR FOR MODEL RESPONSE ---
            if (!isUser) {
                // Tombol Like/Dislike diganti dengan Play/Stop TTS
                const actionBarHTML = `
                    <div class="action-bar flex items-center space-x-3 text-gray-500 mt-2 pt-2 border-t border-gray-200 relative">
                        <button class="tts-btn tts-play-btn hover:text-gemini-blue p-1 rounded-full hover:bg-gray-200 transition" title="Putar Respon">
                            <i class="fas fa-play"></i>
                        </button>
                        
                        <div class="relative group">
                            <button class="export-toggle hover:text-gray-800 p-1 rounded-full hover:bg-gray-200 transition" title="Ekspor & Bagikan">
                                <i class="fas fa-external-link-alt"></i>
                            </button>
                            <!-- KELAS UNTUK MUNCUL DI SISI KANAN: right-0, bottom-full -->
                            <div class="export-menu hidden absolute bottom-full left-0 mb-2 w-56 bg-indigo-700/80 backdrop-blur-xl border border-gray-200 rounded-lg shadow-xl z-20 p-2 space-y-1 text-sm text-white">
                                <div class="font-bold text-xs text-white uppercase pb-1 border-b">Opsi Ekspor</div>
                                <a href="#" data-action="word" class="export-link flex items-center space-x-2 p-2 hover:bg-gray-900 rounded-lg transition"><i class="fas fa-file-word text-blue-500"></i><span>Ekspor sebagai Word doc</span></a>
                                <a href="#" data-action="pdf" class="export-link flex items-center space-x-2 p-2 hover:bg-gray-900 rounded-lg transition"><i class="fas fa-file-pdf text-red-500"></i><span>Ekspor sebagai HTML/PDF</span></a>
                                <a href="#" data-action="excel" class="export-link flex items-center space-x-2 p-2 hover:bg-gray-900 rounded-lg transition"><i class="fas fa-file-excel text-green-600"></i><span>Ekspor sebagai Excel Sheet</span></a>
                                <a href="#" data-action="ppt" class="export-link flex items-center space-x-2 p-2 hover:bg-gray-900 rounded-lg transition"><i class="fas fa-file-powerpoint text-orange-500"></i><span>Buat presentasi PowerPoint (Simulasi)</span></a>
                            </div>
                        </div>

                        <button class="copy-btn hover:text-gray-800 p-1 rounded-full hover:bg-gray-200 transition" title="Salin Teks">
                            <i class="far fa-copy"></i>
                        </button>
                        
                        <button class="more-options-btn hover:text-gray-800 p-1 rounded-full hover:bg-gray-200 transition" title="Opsi Lainnya">
                            <i class="fas fa-angle-down"></i>
                        </button>
                    </div>
                `;
                bubble.innerHTML += actionBarHTML;
            }
            // --- END ADD ACTION BAR ---


            messageContainer.appendChild(bubble);
            chatHistoryEl.appendChild(messageContainer);
            
            if (!isInitialLoad) {
                chatHistoryEl.scrollTop = chatHistoryEl.scrollHeight; 
            }

            // --- ADD ACTION BAR LISTENERS ---
            if (!isUser) {
                const exportToggle = bubble.querySelector('.export-toggle');
                const exportMenu = bubble.querySelector('.export-menu');
                const copyBtn = bubble.querySelector('.copy-btn');
                const exportLinks = bubble.querySelectorAll('.export-link');
                const ttsBtn = bubble.querySelector('.tts-btn'); // New TTS Button

                // 1. TTS Listener
                if (ttsBtn) {
                    const rawContent = getExportableContent(bubbleId);
                    // Dapatkan teks bersih tanpa markdown atau tag HTML untuk TTS
                    const contentToSpeak = (new DOMParser().parseFromString(rawContent, 'text/html')).documentElement.textContent.trim();
                    
                    ttsBtn.addEventListener('click', (e) => {
                        e.stopPropagation(); 
                        
                        if (ttsBtn.classList.contains('tts-play-btn')) {
                            speakText(contentToSpeak, ttsBtn);
                        } else {
                            // Ini adalah tombol stop
                            stopSpeaking();
                        }
                    });
                }
                
                if (exportToggle && exportMenu) {
                    const toggleMenu = (event) => {
                        event.stopPropagation(); 
                        exportMenu.classList.toggle('hidden');
                    };

                    exportToggle.addEventListener('click', toggleMenu);

                    document.addEventListener('click', (event) => {
                        if (!exportMenu.contains(event.target) && !exportToggle.contains(event.target)) {
                            exportMenu.classList.add('hidden');
                        }
                    });
                }
                
                if (copyBtn) {
                    copyBtn.addEventListener('click', () => {
                        handleCopy(bubbleId, copyBtn);
                    });
                }
                
                exportLinks.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const action = link.getAttribute('data-action');
                        exportMenu.classList.add('hidden'); 

                        switch (action) {
                            case 'word':
                                exportAsWord(bubbleId);
                                break;
                            case 'pdf':
                                exportAsPDF(bubbleId);
                                break;
                            case 'excel':
                                exportAsExcel(bubbleId);
                                break;
                            case 'ppt':
                                exportAsPPT(); 
                                break;
                        }
                    });
                });

                // Hapus listeners yang tidak lagi diperlukan (Feedback buttons)
                bubble.querySelectorAll('.feedback-btn').forEach(btn => btn.remove());

                bubble.querySelector('.more-options-btn')?.addEventListener('click', () => {
                    showToast('Opsi lainnya tidak diimplementasikan saat ini.', 'info');
                });
            }
        }

        // --- FILE PROCESSING LOGIC (Same as previous version) ---

        function arrayBufferToBase64(buffer, mimeType) {
            let binary = '';
            const bytes = new Uint8Array(buffer);
            const len = bytes.byteLength;
            for (let i = 0; i < len; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return `data:${mimeType};base64,${btoa(binary)}`;
        }

        function removeFile(index) {
            attachedFiles.splice(index, 1);
            renderFilePreviews();
            updateSendButtonState();
        }

       function renderFilePreviews() {
    filePreviewArea.innerHTML = '';

    const maxPreview = 4;
    const totalFiles = attachedFiles.length;
    const previewCount = Math.min(totalFiles, maxPreview);

    // Render maksimal 4 file
    for (let index = 0; index < previewCount; index++) {
        const file = attachedFiles[index];

        const iconClass =
            file.mimeType.startsWith('image/') ? 'fas fa-image' :
            file.mimeType.includes('pdf') ? 'fas fa-file-pdf text-red-500' :
            file.mimeType.includes('spreadsheet') ? 'fas fa-file-excel text-green-600' :
            file.mimeType.includes('document') ? 'fas fa-file-word text-blue-500' :
            'fas fa-file';

        const previewEl = document.createElement('div');

        // FULL RESPONSIVE STYLE
        previewEl.className =
            'flex items-center space-x-1 text-xs sm:text-sm ' +
            'bg-indigo-600 text-white backdrop-blur-md px-3 py-1 rounded-full ' +
            'max-w-full sm:max-w-fit overflow-hidden';

        previewEl.innerHTML = `
            <i class="${iconClass} text-xs sm:text-sm"></i>
            <span class="truncate max-w-[70px] sm:max-w-[120px] md:max-w-[150px]">
                ${file.fileName}
            </span>
            <button class="text-gray-200 hover:text-red-400" onclick="removeFile(${index})">
                <i class="fas fa-times text-[10px] sm:text-xs"></i>
            </button>
        `;

        filePreviewArea.appendChild(previewEl);
    }

    // Jika lebih dari 4 → tambah badge “+X berkas”
    if (totalFiles > maxPreview) {
        const moreBadge = document.createElement('div');
        moreBadge.className =
            'flex items-center justify-center text-xs sm:text-sm font-semibold ' +
            'bg-gray-300 text-gray-700 backdrop-blur-md px-3 py-1 rounded-full';
        moreBadge.textContent = `+${totalFiles - maxPreview} berkas`;
        filePreviewArea.appendChild(moreBadge);
    }

    window.removeFile = removeFile;
}

        async function extractTextFromPdf(file) {
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            let fullText = '';
            const numPages = pdf.numPages;
            for (let i = 1; i <= numPages; i++) {
                const page = await pdf.getPage(i);
                const textContent = await page.getTextContent();
                fullText += textContent.items.map(item => item.str).join(' ') + '\n\n';
            }
            return fullText.trim();
        }

        async function extractTextFromDocx(file) {
            const arrayBuffer = await file.arrayBuffer();
            const result = await mammoth.extractRawText({ arrayBuffer: arrayBuffer });
            return result.value.trim();
        }

        async function extractTextFromXlsx(file) {
            const data = await file.arrayBuffer();
            const workbook = XLSX.read(data, { type: 'array' });
            let fullText = '';
            
            workbook.SheetNames.forEach(sheetName => {
                const worksheet = workbook.Sheets[sheetName];
                const csv = XLSX.utils.sheet_to_csv(worksheet, { FS: ' | ' }); 
                fullText += `--- Sheet: ${sheetName} ---\n${csv}\n\n`;
            });
            return fullText.trim();
        }

        async function handleFileInputChange(event) {
            const files = Array.from(event.target.files);

            for (const file of files) {
                const reader = new FileReader();
                reader.onload = async (e) => {
                    const mimeType = file.type;
                    const fileName = file.name;
                    let attachmentData = null;
                    let isTextContent = false;
                    
                    try {
                        if (mimeType.startsWith('image/')) {
                            // Untuk gambar, simpan Base64 untuk dikirim langsung ke API
                            attachmentData = arrayBufferToBase64(e.target.result, mimeType);
                        } else if (fileName.endsWith('.pdf')) {
                            const text = await extractTextFromPdf(file);
                            attachmentData = `[CONTENT: ${fileName}]\n${text}`;
                            isTextContent = true;
                        } else if (fileName.endsWith('.docx')) {
                            const text = await extractTextFromDocx(file);
                            attachmentData = `[CONTENT: ${fileName}]\n${text}`;
                            isTextContent = true;
                        } else if (fileName.endsWith('.xlsx')) {
                            const text = await extractTextFromXlsx(file);
                            attachmentData = `[CONTENT: ${fileName}]\n${text}`;
                            isTextContent = true;
                        } else {
                            console.warn(`Unsupported file type: ${mimeType}`);
                            return;
                        }

                        if (attachmentData) {
                            attachedFiles.push({ mimeType, data: attachmentData, fileName, isTextContent });
                            renderFilePreviews();
                            updateSendButtonState();
                        }
                    } catch (error) {
                        console.error(`Error processing file ${fileName}:`, error);
                        renderMessage('model', `**Error:** Gagal memproses file **${fileName}**. File mungkin rusak atau formatnya tidak didukung.`);
                    }
                };

                reader.readAsArrayBuffer(file);
            }

            event.target.value = null; 
        }

        // --- GEMINI API LOGIC (Ditingkatkan) ---
        
        /**
         * Mengubah format riwayat chat lokal menjadi format 'contents' API yang diperlukan.
         * Ini memastikan lampiran file dan konten dokumen yang diekstrak dikirim kembali untuk konteks.
         */
        function mapHistoryToApiContents(history) {
            return history.map(message => {
                const parts = [];
                const docTextContent = [];

                // 1. Tambahkan bagian untuk lampiran
                message.attachments?.forEach(file => {
                    if (file.mimeType.startsWith('image/')) {
                        // Ambil data Base64
                        const base64String = file.data.split(',')[1]; 
                        const mimeTypeOnly = file.mimeType.split(';')[0];
                        parts.push({
                            inlineData: {
                                mimeType: mimeTypeOnly, 
                                data: base64String
                            }
                        });
                    } else if (file.isTextContent) {
                         // Kumpulkan konten teks dari dokumen (PDF, DOCX, XLSX)
                         docTextContent.push(file.data);
                    }
                });

                // 2. Tambahkan bagian teks utama (Prompt Pengguna ATAU Respons Model)
                const mainText = message.content || '';
                // Gabungkan prompt utama dengan konten teks dokumen
                const fullText = [mainText, ...docTextContent].filter(Boolean).join('\n\n---\n\n');
                
                if (fullText.trim().length > 0) {
                    parts.push({ text: fullText.trim() });
                }
                
                // Jika pesan tidak memiliki bagian apa pun (walaupun ini seharusnya tidak terjadi), lewati
                if (parts.length === 0) return null;

                const role = message.role === 'model' ? 'model' : 'user';

                return { role: role, parts: parts };
            }).filter(msg => msg !== null);
        }

        /**
         * Memanggil Gemini API dengan fitur Google Search Grounding diaktifkan.
         * @param {Array<Object>} contents - Seluruh riwayat percakapan dalam format API.
         * @param {string} systemInstruction - Instruksi sistem.
         */
        async function callGeminiAPI(contents, systemInstruction) {
            const payload = {
                contents: contents, // Menggunakan seluruh riwayat percakapan untuk konteks
                generationConfig: { 
                    temperature: 0.2, // Sedikit dinaikkan untuk kreativitas
                },
                tools: [{ "google_search": {} }], 
                systemInstruction: {
                    parts: [{ text: systemInstruction }]
                }
            };

            let attempt = 0;
            const maxAttempts = 5;
            let response = null;

            while (attempt < maxAttempts) {
                try {
                    const fetchResponse = await fetch(apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });

                    if (!fetchResponse.ok) {
                        if (fetchResponse.status === 429 || fetchResponse.status >= 500) {
                            throw new Error(`Retryable HTTP error: ${fetchResponse.status}`);
                        }
                        const errorBody = await fetchResponse.json();
                        throw new Error(`API Error: ${errorBody.error?.message || fetchResponse.statusText}`);
                    }

                    const result = await fetchResponse.json();
                    const candidate = result.candidates?.[0];

                    if (candidate && candidate.content?.parts?.[0]?.text) {
                        const text = candidate.content.parts[0].text;
                        
                        let sources = [];
                        const groundingMetadata = candidate.groundingMetadata;
                        
                        if (groundingMetadata && groundingMetadata.groundingAttributions) {
                            sources = groundingMetadata.groundingAttributions
                                .map(attribution => ({
                                    uri: attribution.web?.uri,
                                    title: attribution.web?.title,
                                }))
                                .filter(source => source.uri && source.title); 
                        }

                        return { text, sources };
                    }
                    throw new Error("Respons Gemini tidak mengandung teks yang valid.");

                } catch (error) {
                    if (attempt < maxAttempts - 1 && error.message.includes('Retryable HTTP error')) {
                        const delay = Math.pow(2, attempt) * 1000;
                        await new Promise(resolve => setTimeout(resolve, delay));
                        attempt++;
                    } else {
                        throw error;
                    }
                }
            }
            throw new Error("Failed to get response after multiple retries.");
        }

        async function sendMessage() {
            stopSpeaking(); // MANDATORY: Hentikan TTS saat pesan baru dikirim
            
            const userPrompt = userInputEl.textContent.trim();
            
            if (userPrompt.length === 0 && attachedFiles.length === 0) return;
            
            const attachmentsToSend = attachedFiles.map(f => ({ ...f })); 

            // 1. Catat pesan pengguna baru ke chatHistory aktif (termasuk lampiran yang akan diproses)
            const newUserMessage = { 
                role: 'user', 
                content: userPrompt, 
                attachments: attachmentsToSend,
                sources: []
            };
            chatHistory.push(newUserMessage);

            renderMessage('user', userPrompt, attachmentsToSend); // Render pesan pengguna

            // 2. Map seluruh chatHistory (termasuk pesan baru) ke format API 'contents'
            const apiContents = mapHistoryToApiContents(chatHistory);
            
            // Reset input UI
            userInputEl.textContent = '';
            attachedFiles = [];
            renderFilePreviews();
            updateSendButtonState();
            sendBtn.disabled = true;

            const loadingId = 'loading-' + Date.now();
            renderMessage('model', `<div id="${loadingId}" class="flex items-center space-x-2 text-gray-500">
                <i class="fas fa-spinner fa-spin"></i>
                <span>Mencari dan menganalisis respons...</span>
            </div>`);

            try {
                // SYSTEM INSTRUCTION YANG DITINGKATKAN:
                const systemInstruction = `
                    Anda adalah Yaya Tata, asisten AI canggih dari ${dataSek}. Tugas utama Anda adalah memberikan analisis yang kritis, mendalam, dan akurat.
                    
                    **Penting:** Jawab dengan memperhatikan **KONTEKS** dari riwayat percakapan di atas.

                    **Prioritas Multimodal & Dokumen:**
                    1.  Jika input menyertakan **GAMBAR**, lakukan **analisis visual yang mendalam** (misalnya, identifikasi objek, deskripsi suasana, analisis data dalam grafik/tabel). Berikan respons yang terstruktur dan detail.
                    2.  Jika input menyertakan **TEKS DOKUMEN** (PDF, DOCX, XLSX), lakukan **ringkasan eksekutif**, ekstrak **poin-poin kunci**, dan jawab pertanyaan berdasarkan konten tersebut.

                    **Prioritas Web Grounding:**
                    1.  **Selalu aktifkan Google Search** untuk grounding.
                    2.  Setiap kali informasi non-faktual atau terkini diminta, **wajib gunakan sumber web terbaru** untuk memvalidasi dan memperluas jawaban.
                    3.  Utamakan akurasi, sintesis informasi dari berbagai sumber, dan hindari spekulasi.
                    
                    **PENANGANAN TAUTAN MEDIA (PENTING):** Jika hasil pencarian web relevan dan menyediakan tautan langsung ke file, audio, atau video (misalnya, file laporan, video YouTube, atau klip audio), **sertakan tautan tersebut secara eksplisit di dalam teks respons Anda** menggunakan format Markdown: \`[Deskripsi Tautan](URL)\`.
                    
                    **Format Respons:** Jawab menggunakan bahasa Indonesia yang formal, lugas, dan profesional.
                `;
                
                // Gunakan apiContents (seluruh riwayat)
                const { text: responseText, sources: responseSources } = await callGeminiAPI(apiContents, systemInstruction);

                const loadingEl = document.getElementById(loadingId);
                if (loadingEl && loadingEl.parentElement) {
                    loadingEl.parentElement.parentElement.remove();
                }

                // Render balasan model
                renderMessage('model', responseText, [], false, responseSources);
                
                // 3. Catat balasan model ke chatHistory aktif
                chatHistory.push({ role: 'model', content: responseText, attachments: [], sources: responseSources });
                currentChatCountEl.textContent = chatHistory.length; 

            } catch (error) {
                console.error("Gemini API Error:", error);
                const loadingEl = document.getElementById(loadingId);
                if (loadingEl && loadingEl.parentElement) {
                    loadingEl.parentElement.parentElement.remove();
                }
                renderMessage('model', `**Error Koneksi:** Gagal menghubungi layanan AI atau mencari informasi web. Silakan coba lagi. (${error.message})`);
                
                // Hapus pesan pengguna yang baru ditambahkan dari riwayat agar tidak merusak konteks selanjutnya
                chatHistory.pop(); 
            } finally {
                sendBtn.disabled = false; 
                updateSendButtonState();
            }
        }
        
        // --- INITIALIZATION ---
        const slider = document.getElementById('fontSizeSlider');

let currentFontSize = parseInt(localStorage.getItem('chatFontSize')) || 16;
document.documentElement.style.setProperty('--chat-font-size', `${currentFontSize}px`);
slider.value = currentFontSize;

slider.addEventListener('input', (e) => {
  const newSize = parseInt(e.target.value);
  document.documentElement.style.setProperty('--chat-font-size', `${newSize}px`);
  localStorage.setItem('chatFontSize', newSize);
});
        startNewChat(); 
        updateSendButtonState();
        (function() {
    // 1. Matikan Klik Kanan
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });

    // 2. Blokir Shortcut Keyboard (F12, Ctrl+Shift+I, Ctrl+U, dll)
    document.onkeydown = function(e) {
        if (
            e.keyCode === 123 || // F12
            (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || // Ctrl+Shift+I/J/C
            (e.ctrlKey && e.keyCode === 85) // Ctrl+U (View Source)
        ) {
            return false;
        }
    };

    // 3. Debugger Trap (Membuat DevTools 'Lag' atau Berhenti)
    // Script ini akan memicu pause otomatis jika konsol dibuka
    setInterval(function() {
        (function() {
            return false;
        }['constructor']('debugger')['call']());
    }, 50);
})();
    </script>
</body>
</html>
