/**
 * Air Link WiFi - Administrative Panel Client Script
 * Vanilla JavaScript (zero dependencies)
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile Sidebar Toggle
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebar');

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('open');
        });

        document.addEventListener('click', (e) => {
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // 2. Omada Controller Diagnostic Test Button
    const testOmadaBtn = document.getElementById('testOmadaBtn');
    const testOmadaResult = document.getElementById('testOmadaResult');

    if (testOmadaBtn && testOmadaResult) {
        testOmadaBtn.addEventListener('click', async () => {
            testOmadaBtn.disabled = true;
            testOmadaBtn.innerText = 'Testing Connection...';
            testOmadaResult.style.display = 'block';
            testOmadaResult.className = 'alert alert-info';
            testOmadaResult.innerText = 'Connecting to Omada SDN Controller...';

            try {
                const res = await fetch('test-omada.php?action=test', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();

                if (data.success) {
                    testOmadaResult.className = 'alert alert-success';
                    testOmadaResult.innerText = data.message;
                } else {
                    testOmadaResult.className = 'alert alert-danger';
                    testOmadaResult.innerText = data.message || 'Connection test failed.';
                }
            } catch (err) {
                testOmadaResult.className = 'alert alert-danger';
                testOmadaResult.innerText = 'Network request failed. Ensure your web server can connect to the controller.';
            } finally {
                testOmadaBtn.disabled = false;
                testOmadaBtn.innerText = 'Test Omada Connection';
            }
        });
    }

    // 2.5 SonicPesa Diagnostic Test Button
    const testSonicPesaBtn = document.getElementById('testSonicPesaBtn');
    const testSonicPesaResult = document.getElementById('testSonicPesaResult');

    if (testSonicPesaBtn && testSonicPesaResult) {
        testSonicPesaBtn.addEventListener('click', async () => {
            testSonicPesaBtn.disabled = true;
            testSonicPesaBtn.innerText = 'Testing Connection...';
            testSonicPesaResult.style.display = 'block';
            testSonicPesaResult.className = 'alert alert-info';
            testSonicPesaResult.innerText = 'Connecting to SonicPesa API...';

            try {
                const res = await fetch('sonicpesa_test.php', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();

                if (data.success) {
                    testSonicPesaResult.className = 'alert alert-success';
                    testSonicPesaResult.innerText = data.message;
                } else {
                    testSonicPesaResult.className = 'alert alert-danger';
                    testSonicPesaResult.innerText = data.message || 'Connection test failed.';
                }
            } catch (err) {
                testSonicPesaResult.className = 'alert alert-danger';
                testSonicPesaResult.innerText = 'Network request failed. Ensure your server can reach api.sonicpesa.com.';
            } finally {
                testSonicPesaBtn.disabled = false;
                testSonicPesaBtn.innerText = 'Test Connection';
            }
        });
    }

    const testSwalaSmsBtn = document.getElementById('testSwalaSmsBtn');
    const testSwalaSmsResult = document.getElementById('testSwalaSmsResult');

    if (testSwalaSmsBtn && testSwalaSmsResult) {
        testSwalaSmsBtn.addEventListener('click', async () => {
            testSwalaSmsBtn.disabled = true;
            testSwalaSmsBtn.innerText = 'Testing...';
            testSwalaSmsResult.className = 'd-none';
            testSwalaSmsResult.innerText = '';

            try {
                const res = await fetch('swalasms_test.php', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();

                if (data.success) {
                    testSwalaSmsResult.className = 'alert alert-success';
                    testSwalaSmsResult.innerText = data.message;
                } else {
                    testSwalaSmsResult.className = 'alert alert-danger';
                    testSwalaSmsResult.innerText = data.message || 'Connection test failed.';
                }
            } catch (err) {
                testSwalaSmsResult.className = 'alert alert-danger';
                testSwalaSmsResult.innerText = 'Network request failed. Ensure your server can reach swalasms.com.';
            } finally {
                testSwalaSmsBtn.disabled = false;
                testSwalaSmsBtn.innerText = 'Test Connection';
            }
        });
    }
    // 3. Confirm Dialog on Critical Actions (Terminate Session, Disable Voucher, Delete)
    const confirmActions = document.querySelectorAll('.js-confirm');
    confirmActions.forEach(element => {
        element.addEventListener('click', (e) => {
            const message = element.dataset.confirmMessage || 'Are you sure you want to perform this action?';
            if (!window.confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // 4. Client-side Live Table Filter
    const searchInput = document.getElementById('tableSearchInput');
    const targetTable = document.querySelector('.data-table tbody');

    if (searchInput && targetTable) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();
            const rows = targetTable.querySelectorAll('tr');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});
