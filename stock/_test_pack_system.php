<?php 
/**
 * Pack System Test Page
 * Quick test to verify all unified functionality
 */
?>
<?php
/**
 * File neutralized on 2025-10-01 cleanup. Original test harness removed.
 * Refer to BACKUPS/REMOVAL_MANIFEST_2025-10-01.txt for audit trail.
 */
http_response_code(410); // Gone
echo 'Deprecated test file removed.';
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pack System Test</title>
    <link rel="stylesheet" href="assets/css/pack-unified.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f8f9fa;
        }
        .test-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-button {
            background: #007acc;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            margin: 5px;
            font-size: 14px;
        }
        .test-button:hover {
            background: #005a9e;
        }
        .status-display {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
            font-family: monospace;
        }
        .input-test {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <h1>📦 Pack System Test Page</h1>
    
    <div class="test-panel">
        <h2>Auto-Save Status Test</h2>
        <div class="auto-save-status">
            <span id="save-status" class="status-display">IDLE</span>
            <span id="last-save-time"></span>
        </div>
        
        <input type="text" class="input-test" placeholder="Type here to test auto-save..." 
               onchange="if(window.packSystem) window.packSystem.saveProgress()">
        <textarea class="input-test" placeholder="Test textarea auto-save..." rows="3"></textarea>
        <select class="input-test">
            <option>Test Select Option 1</option>
            <option>Test Select Option 2</option>
        </select>
    </div>

    <div class="test-panel">
        <h2>Toast System Test</h2>
        <button class="test-button" onclick="if(window.packSystem) window.packSystem.showToast('Success message!', 'success')">
            Show Success Toast
        </button>
        <button class="test-button" onclick="if(window.packSystem) window.packSystem.showToast('Info message!', 'info')">
            Show Info Toast
        </button>
        <button class="test-button" onclick="if(window.packSystem) window.packSystem.showToast('Warning message!', 'warning')">
            Show Warning Toast
        </button>
        <button class="test-button" onclick="if(window.packSystem) window.packSystem.showToast('Error message!', 'error')">
            Show Error Toast
        </button>
    </div>

    <div class="test-panel">
        <h2>System Functions Test</h2>
        <button class="test-button" onclick="testPackSystem()">
            Run Full System Test
        </button>
        <button class="test-button" onclick="debugPackSystem()">
            Debug System Status
        </button>
        <button class="test-button" onclick="showSystemInfo()">
            Show System Info
        </button>
        <button class="test-button" onclick="showLockDiagnostic()">
            Show Lock Diagnostic
        </button>
    </div>

    <div class="test-panel">
        <h2>Manual Save Test</h2>
        <button class="test-button" onclick="if(window.packSystem) window.packSystem.saveProgress()">
            Manual Save
        </button>
        <button class="test-button" onclick="if(window.packSystem) { window.packSystem.setAutoSaveStatus('saving'); setTimeout(() => window.packSystem.setAutoSaveStatus('saved', 'manual test'), 2000); }">
            Test Status Animation
        </button>
    </div>

    <div class="test-panel">
        <h2>Console Output</h2>
        <p>Open browser developer tools (F12) to see console output and system diagnostics.</p>
        <div class="status-display">
            Check console for:<br>
            • System initialization messages<br>
            • Auto-save events<br>
            • Toast notifications<br>
            • Lock system status<br>
            • Error messages
        </div>
    </div>

    <!-- System Requirements (same as pack.view.php) -->
    <script>
        // Boot data simulation
        window.DISPATCH_BOOT = {
            transfer_id: 'TEST123',
            staff_id: 999,
            staff_name: 'Test User',
            session_id: 'test_session_' + Date.now(),
            timestamp: Math.floor(Date.now() / 1000),
            config: {
                auto_save_interval: 30,
                lock_check_interval: 15,
                toast_duration: 4000
            }
        };
    </script>
    
    <!-- Load the unified system -->
    <script src="assets/js/pack-unified.js"></script>
    
    <script>
        // Initialize immediately for testing
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🧪 Test page loaded - Pack System should initialize...');
            
            // Log system status after short delay
            setTimeout(() => {
                console.log('🔍 Checking system status...');
                if (window.packSystem) {
                    console.log('✅ Pack System Ready');
                    console.log('Modules:', Object.keys(window.packSystem.modules));
                    console.log('Version:', window.packSystem.getVersion());
                } else {
                    console.error('❌ Pack System failed to initialize');
                }
            }, 1000);
        });
    </script>
</body>
</html>