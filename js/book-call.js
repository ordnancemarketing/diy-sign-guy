/* ============================================================
 * BOOK A CALL WITH DEAN — the ONE place to change the link.
 * Every "Book a Call with Dean" button on the site carries the
 * attribute data-book-call. This script points all of them at
 * BOOK_CALL_URL below.
 *
 * When Dean's Google Calendar booking page is ready, paste its
 * URL here, e.g.:
 *   var BOOK_CALL_URL = 'https://calendar.app.google/XXXXXXXX';
 * (Optionally also replace the mailto fallback in the HTML with
 *  one find-and-replace of: mailto:dean@diysignguy.com?subject=Book%20a%20call%20with%20Dean )
 * ============================================================ */
(function () {
  var BOOK_CALL_URL = 'mailto:dean@diysignguy.com?subject=Book%20a%20call%20with%20Dean';
  function apply() {
    var links = document.querySelectorAll('a[data-book-call]');
    for (var i = 0; i < links.length; i++) {
      links[i].setAttribute('href', BOOK_CALL_URL);
      if (/^https?:/i.test(BOOK_CALL_URL)) {
        links[i].setAttribute('target', '_blank');
        links[i].setAttribute('rel', 'noopener');
      }
      links[i].addEventListener('click', function () {
        if (typeof gtag === 'function') gtag('event', 'book_call_click', { source_page: location.pathname });
      });
    }
  }
  if (document.readyState !== 'loading') apply();
  else document.addEventListener('DOMContentLoaded', apply);
})();
