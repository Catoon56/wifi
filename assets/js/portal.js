/**
 * Air Link WiFi - Captive Portal Client Script
 * Vanilla JavaScript (zero dependencies)
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Auto-format voucher code input: Uppercase and remove whitespace
    const voucherInput = document.getElementById('voucher_code');
    if (voucherInput) {
        voucherInput.addEventListener('input', (e) => {
            const start = voucherInput.selectionStart;
            const end = voucherInput.selectionEnd;
            voucherInput.value = voucherInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            voucherInput.setSelectionRange(start, end);
        });
        // Auto-focus input on page load
        voucherInput.focus();
    }

    // 2. AJAX Voucher Activation (if form exists)
    const loginForm = document.getElementById('portalLoginForm');
    const submitBtn = document.getElementById('submitBtn');
    const alertBox = document.getElementById('portalAlert');

    if (loginForm && submitBtn) {
        loginForm.addEventListener('submit', async (e) => {
            // If standard submission is requested or JS fails, allow native POST
            if (loginForm.dataset.nativeSubmit === 'true') {
                return;
            }

            e.preventDefault();
            const code = voucherInput.value.trim();

            if (!code) {
                showAlert('Please enter your voucher code.', 'danger');
                voucherInput.focus();
                return;
            }

            // Set loading state
            submitBtn.disabled = true;
            const originalText = submitBtn.innerText;
            submitBtn.innerText = 'Connecting to Internet...';
            hideAlert();

            const formData = new FormData(loginForm);

            try {
                const response = await fetch(loginForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (data.success) {
                    submitBtn.innerText = 'Authorized! Redirecting...';
                    // Redirect to status page
                    const redirectUrl = data.redirect || 'status.php';
                    window.location.href = redirectUrl;
                } else {
                    showAlert(data.error || 'Connection failed. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerText = originalText;
                }
            } catch (err) {
                // Fallback to regular HTTP form post if AJAX network error occurs
                loginForm.dataset.nativeSubmit = 'true';
                loginForm.submit();
            }
        });
    }

    // 3. Live Countdown Timer (Status & Payment Success Pages)
    const countdownEl = document.getElementById('countdownTimer');
    if (countdownEl && countdownEl.dataset.remaining) {
        let remainingSeconds = parseInt(countdownEl.dataset.remaining, 10);
        const sessionId = countdownEl.dataset.sessionId || '';

        function renderCountdown() {
            if (remainingSeconds <= 0) {
                countdownEl.innerText = '00h 00m 00s (Expired)';
                const badge = document.getElementById('statusBadge');
                if (badge) {
                    badge.innerText = 'Disconnected';
                    badge.className = 'status-badge status-disconnected';
                }
                return;
            }

            const days = Math.floor(remainingSeconds / 86400);
            const hours = Math.floor((remainingSeconds % 86400) / 3600);
            const minutes = Math.floor((remainingSeconds % 3600) / 60);
            const seconds = remainingSeconds % 60;

            const pad = (n) => String(n).padStart(2, '0');

            if (days > 0) {
                countdownEl.innerText = `${days}d ${pad(hours)}h ${pad(minutes)}m ${pad(seconds)}s`;
            } else {
                countdownEl.innerText = `${pad(hours)}h ${pad(minutes)}m ${pad(seconds)}s`;
            }

            remainingSeconds--;
        }

        renderCountdown();
        const timerInterval = setInterval(renderCountdown, 1000);

        // Server heartbeat every 60 seconds to sync remaining time accurately
        if (sessionId) {
            setInterval(async () => {
                try {
                    const res = await fetch(`../api/session/status.php?session_id=${encodeURIComponent(sessionId)}`);
                    const data = await res.json();
                    if (data.success && typeof data.remaining_seconds === 'number') {
                        remainingSeconds = data.remaining_seconds;
                        if (!data.active) {
                            clearInterval(timerInterval);
                            remainingSeconds = 0;
                            renderCountdown();
                        }
                    }
                } catch (_) {}
            }, 60000);
        }
    }

    // 4. Copy Voucher Code to Clipboard (Payment Success page)
    const copyBtn = document.getElementById('copyVoucherBtn');
    const voucherText = document.getElementById('voucherCodeDisplay');
    if (copyBtn && voucherText) {
        copyBtn.addEventListener('click', () => {
            const textToCopy = voucherText.innerText.trim();
            navigator.clipboard.writeText(textToCopy).then(() => {
                const prev = copyBtn.innerText;
                copyBtn.innerText = 'Copied!';
                setTimeout(() => { copyBtn.innerText = prev; }, 2000);
            }).catch(() => {
                // Fallback for older browsers
                const temp = document.createElement('textarea');
                temp.value = textToCopy;
                document.body.appendChild(temp);
                temp.select();
                document.execCommand('copy');
                document.body.removeChild(temp);
                copyBtn.innerText = 'Copied!';
                setTimeout(() => { copyBtn.innerText = 'Copy Code'; }, 2000);
            });
        });
    }

    // Alert Helpers
    function showAlert(message, type) {
        if (!alertBox) return;
        alertBox.className = `alert alert-${type}`;
        alertBox.innerText = message;
        alertBox.style.display = 'block';
    }

    function hideAlert() {
        if (!alertBox) return;
        alertBox.style.display = 'none';
    }
});
