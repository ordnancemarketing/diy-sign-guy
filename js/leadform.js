/* Shared lead / quote form behavior: mailing-address toggle + 10 MB attachment check (FormSubmit limit). */
(function () {
  var LIMIT = 10 * 1024 * 1024;
  function init(form) {
    var same = form.querySelector('input[name="mailing_same_as_install"]');
    var wrap = form.querySelector('.mailwrap');
    if (same && wrap) {
      var sync = function () { wrap.hidden = same.checked; };
      same.addEventListener('change', sync); sync();
    }
    var files = form.querySelectorAll('input[type=file]');
    var err = form.querySelector('.fileerr');
    var check = function () {
      var total = 0;
      for (var i = 0; i < files.length; i++) for (var j = 0; j < files[i].files.length; j++) total += files[i].files[j].size;
      var msg = total > LIMIT ? 'Those files add up to ' + (total / 1048576).toFixed(1) + ' MB. Please keep the total under 10 MB (smaller photos work fine).' : '';
      for (var k = 0; k < files.length; k++) files[k].setCustomValidity(msg);
      if (err) err.textContent = msg;
    };
    for (var f = 0; f < files.length; f++) files[f].addEventListener('change', check);
  }
  function ready() { var fs = document.querySelectorAll('form.leadform'); for (var i = 0; i < fs.length; i++) init(fs[i]); }
  if (document.readyState !== 'loading') ready(); else document.addEventListener('DOMContentLoaded', ready);
})();
