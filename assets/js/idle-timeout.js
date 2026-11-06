// ============================================
// Idle timeout - auto logout after 10 minutes of no activity
// Backs up the server-side timeout for the case where the user is
// sitting on one page without triggering any request to the server.
// ============================================
(function () {
    const TIMEOUT_MS = 10 * 60 * 1000; // 10 minutes
    let idleTimer = null;

    function resetTimer() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () {
            window.location.href = 'logout';
        }, TIMEOUT_MS);
    }

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
        document.addEventListener(evt, resetTimer, { passive: true });
    });

    resetTimer();
})();