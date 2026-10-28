/* Painel do Teste A/B WhatsApp: versões e filas de grupos (adicionar/remover, letras, % efetivo), copiar e confirmações. */
(function () {
  'use strict';

  var box = document.getElementById('iwab-versions');
  var versionTpl = document.getElementById('iwab-version-tpl');
  var groupTpl = document.getElementById('iwab-group-tpl');
  var addVersion = document.getElementById('iwab-add-version');
  var seq = 0;

  function letter(i) { return i < 26 ? String.fromCharCode(65 + i) : String(i + 1); }
  function weightOf(el) { return Math.max(0, parseInt(el.querySelector('.iwab-weight').value, 10) || 0); }

  // Letras das versões, rótulos dos grupos (A1, A2…) e % efetivo (o tráfego é proporcional, a soma não precisa dar 100).
  function refresh() {
    if (!box) { return; }
    var versions = box.querySelectorAll('.iwab-version');
    var total = 0;
    var i;
    for (i = 0; i < versions.length; i++) { total += weightOf(versions[i]); }
    for (i = 0; i < versions.length; i++) {
      var w = weightOf(versions[i]);
      var l = letter(i);
      versions[i].querySelector('.iwab-letter').textContent = l;
      versions[i].querySelector('.iwab-share').textContent = w === 0 ? 'pausada' : (total ? '= ' + Math.round(w * 1000 / total) / 10 + '%' : '');
      var labels = versions[i].querySelectorAll('.iwab-group-label');
      for (var g = 0; g < labels.length; g++) { labels[g].textContent = l + (g + 1); }
    }
  }

  function addGroup(version) {
    var html = groupTpl.innerHTML.replace(/__v__/g, version.getAttribute('data-v')).replace(/__g__/g, 'n' + (seq++));
    var rows = version.querySelector('.iwab-group-rows');
    rows.insertAdjacentHTML('beforeend', html);
    refresh();
    var input = rows.lastElementChild && rows.lastElementChild.querySelector('input[type="text"]');
    if (input) { input.focus(); }
  }

  if (box && versionTpl && groupTpl && addVersion) {
    addVersion.addEventListener('click', function () {
      var key = 'n' + (seq++);
      box.insertAdjacentHTML('beforeend', versionTpl.innerHTML.replace(/__v__/g, key).replace(/\[groups\]\[0\]/g, '[groups][n' + (seq++) + ']'));
      refresh();
      var input = box.lastElementChild && box.lastElementChild.querySelector('.iwab-group-rows input[type="text"]');
      if (input) { input.focus(); }
    });
    box.addEventListener('input', function (e) {
      if (e.target.classList.contains('iwab-weight')) { refresh(); }
    });
    refresh();
  }

  function copy(btn) {
    var text = btn.getAttribute('data-copy');
    var done = function () {
      var old = btn.getAttribute('data-label') || btn.textContent;
      btn.setAttribute('data-label', old);
      btn.textContent = 'Copiado!';
      setTimeout(function () { btn.textContent = old; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { /* sem área de transferência: o texto continua visível */ }
    document.body.removeChild(ta);
  }

  function saved(el) {
    var id = el.querySelector('input[name$="[id]"]');
    return id && id.value !== '0';
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('.iwab-copy, .iwab-add-group, .iwab-remove-group, .iwab-remove-version, [data-confirm]');
    if (!el) { return; }
    if (el.classList.contains('iwab-copy')) { copy(el); return; }
    if (el.classList.contains('iwab-add-group')) { addGroup(el.closest('.iwab-version')); return; }
    if (el.classList.contains('iwab-remove-group')) {
      var tr = el.closest('tr');
      if (saved(tr) && !window.confirm('Remover este grupo? Ele só some ao salvar, e quem estava nele vai para o grupo que estiver recebendo na mesma versão.')) { return; }
      tr.parentNode.removeChild(tr);
      refresh();
      return;
    }
    if (el.classList.contains('iwab-remove-version')) {
      var version = el.closest('.iwab-version');
      if (saved(version.querySelector('.iwab-version-head')) && !window.confirm('Remover esta versão e os grupos dela? Ela só some ao salvar, e quem estava nela é sorteado de novo no próximo clique.')) { return; }
      version.parentNode.removeChild(version);
      refresh();
      return;
    }
    if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
})();
