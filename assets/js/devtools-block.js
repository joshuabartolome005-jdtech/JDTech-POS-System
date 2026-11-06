// ============================================
// DevTools / right-click deterrent
// NOTE: This does NOT provide real security. It only discourages casual
// use of the browser's DevTools (e.g. by staff). Anyone who knows how to
// use their browser's menu, or disables JavaScript first, can bypass this
// entirely. Never rely on this to protect sensitive data - the server-side
// login and permission checks are what actually keep the system secure.
// ============================================
document.addEventListener('contextmenu', function (e) {
    e.preventDefault();
});

document.addEventListener('keydown', function (e) {
    const key = e.key;

    // F12
    if (key === 'F12') {
        e.preventDefault();
        return;
    }

    // Ctrl+Shift+I / Ctrl+Shift+J / Ctrl+Shift+C (DevTools variants)
    if (e.ctrlKey && e.shiftKey && ['I', 'i', 'J', 'j', 'C', 'c'].includes(key)) {
        e.preventDefault();
        return;
    }

    // Ctrl+U (view page source)
    if (e.ctrlKey && (key === 'U' || key === 'u')) {
        e.preventDefault();
        return;
    }
});