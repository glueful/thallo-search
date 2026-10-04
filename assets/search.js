/* The Search block (search block spec §3.1): live suggestions as an accessible combobox, and the
   icon's panel. Every block emits this script tag; it runs once.

   Ownership of asynchronous suggestions: typing invalidates at once (the sequence number moves, the
   active option clears, any pending timer is cancelled); only the request itself waits for the
   debounce. A response is shown only if it answers the latest query and the list was not
   dismissed since. Escape, Tab and closing the panel invalidate too, so neither a timer nor a late
   response can reopen what the visitor closed. Titles go in as text; the snippet is the server's
   escaped, highlighted HTML. */
(function () {
  'use strict';
  if (window.thalloSearch) { return; }

  var DEBOUNCE = 150;
  var MESSAGES = {
    rebuilding: 'Search is being rebuilt. Please try again later.',
    unavailable: 'Suggestions are unavailable',
    no_matches: 'No results',
    scope_unavailable: "Search isn't available right now"
  };

  function enhance(root) {
    if (root.hasAttribute('data-search-ready')) { return; }
    root.setAttribute('data-search-ready', '');
    var input = root.querySelector('input[role="combobox"]');
    var list = root.querySelector('[role="listbox"]');
    var status = root.querySelector('[data-search-status]');
    var trigger = root.querySelector('[data-search-trigger]');
    var panel = root.querySelector('[data-search-panel]');
    if (!input || !list) { return; }
    var live = root.getAttribute('data-live') !== '0';
    var scope = root.getAttribute('data-scope') || '';
    var locale = root.getAttribute('data-locale') || '';
    var seq = 0;
    var timer = null;
    var dismissed = false;
    var composing = false;
    var active = -1;
    var options = [];

    function setStatus(text) { if (status) { status.textContent = text || ''; } }

    function setActive(index) {
      active = index;
      options.forEach(function (option, i) { option.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
      if (index >= 0 && options[index]) {
        input.setAttribute('aria-activedescendant', options[index].id);
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function closeList() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      setActive(-1);
    }

    function invalidate() {
      if (timer) { clearTimeout(timer); timer = null; }
      seq++;
      dismissed = true;
    }

    function option(id, href, build) {
      var li = document.createElement('li');
      li.id = id;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.setAttribute('data-href', href);
      build(li);
      li.addEventListener('mousedown', function (e) { e.preventDefault(); });
      li.addEventListener('click', function () { window.location.assign(href); });
      return li;
    }

    function render(data, q) {
      list.innerHTML = '';
      options = [];
      setActive(-1);
      var state = data && data.state;
      if (state === 'rebuilding' || state === 'unavailable' || state === 'scope_unavailable' || state === 'no_query') {
        setStatus(MESSAGES[state] || '');
        closeList();
        return;
      }
      setStatus(state === 'results' ? '' : (MESSAGES[state] || ''));
      (data.items || []).forEach(function (item, i) {
        options.push(option(list.id + '-' + i, item.href, function (li) {
          var title = document.createElement('span');
          title.className = 'thallo-search-form__title';
          title.textContent = item.title;
          li.appendChild(title);
          if (item.kind_label && scope === '') {
            var kind = document.createElement('span');
            kind.className = 'thallo-search-form__kind';
            kind.textContent = item.kind_label;
            li.appendChild(kind);
          }
          if (item.price) {
            var price = document.createElement('span');
            price.className = 'thallo-search-form__price';
            price.textContent = item.price;
            li.appendChild(price);
          }
          if (item.snippet) {
            var snippet = document.createElement('span');
            snippet.className = 'thallo-search-form__snippet';
            snippet.innerHTML = item.snippet; // the server's escaped text and <mark> tags
            li.appendChild(snippet);
          }
        }));
      });
      options.push(option(list.id + '-see-all', data.see_all || ('/search?q=' + encodeURIComponent(q)), function (li) {
        li.className = 'thallo-search-form__see-all';
        li.textContent = 'See all results for “' + q + '”';
      }));
      options.forEach(function (o) { list.appendChild(o); });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function dispatch(mine, q) {
      var params = new URLSearchParams({ q: q, scope: scope, locale: locale });
      fetch('/_search/suggest?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error('status ' + r.status); } return r.json(); })
        .then(function (body) {
          if (mine !== seq || dismissed) { return; }
          render((body && body.data) || {}, q);
        })
        .catch(function () {
          if (mine !== seq || dismissed) { return; }
          setStatus(MESSAGES.unavailable);
          closeList();
        });
    }

    input.addEventListener('input', function () {
      if (timer) { clearTimeout(timer); timer = null; }
      seq++;
      dismissed = false;
      setActive(-1);
      if (!live) { return; }
      var q = input.value.trim();
      if (q === '') { setStatus(''); closeList(); return; }
      var mine = seq;
      timer = setTimeout(function () { timer = null; dispatch(mine, q); }, DEBOUNCE);
    });
    input.addEventListener('compositionstart', function () { composing = true; });
    input.addEventListener('compositionend', function () { composing = false; });

    input.addEventListener('keydown', function (e) {
      if (e.isComposing || composing) { return; }
      var open = !list.hidden && options.length > 0;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!open) { return; }
        e.preventDefault();
        var step = e.key === 'ArrowDown' ? 1 : -1;
        setActive(active < 0 ? (step > 0 ? 0 : options.length - 1) : (active + step + options.length) % options.length);
      } else if (e.key === 'Enter') {
        if (open && active >= 0) {
          e.preventDefault();
          window.location.assign(options[active].getAttribute('data-href'));
        }
      } else if (e.key === 'Escape') {
        invalidate();
        if (open) {
          e.preventDefault();
          closeList();
        } else if (panel && !panel.hidden) {
          e.preventDefault();
          closePanel();
        }
      } else if (e.key === 'Tab') {
        invalidate();
        closeList();
      }
    });

    function closePanel() {
      invalidate();
      closeList();
      if (!panel) { return; }
      panel.hidden = true;
      if (trigger) {
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
      }
    }

    if (trigger && panel) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        if (panel.hidden) {
          panel.hidden = false;
          trigger.setAttribute('aria-expanded', 'true');
          input.focus();
        } else {
          closePanel();
        }
      });
    }

    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) {
        invalidate();
        closeList();
        if (panel && !panel.hidden) {
          panel.hidden = true;
          if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
        }
      }
    });
  }

  function init(scopeEl) {
    (scopeEl || document).querySelectorAll('[data-search-block]').forEach(enhance);
  }

  window.thalloSearch = { init: init };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); });
  } else {
    init();
  }
})();
