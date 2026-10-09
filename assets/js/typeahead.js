/* WP YetiSearch typeahead: ARIA combobox, no innerHTML (spec §6.8). */
(() => {
  'use strict';

  const cfg = window.wpYetiSearch;
  if (!cfg || !cfg.endpoint) {
    return;
  }

  const parser = new DOMParser();
  const decode = (text) => parser.parseFromString(`<!doctype html><body>${text}`, 'text/html').body.textContent || '';

  const safeUrl = (url) => {
    try {
      const parsed = new URL(url, window.location.href);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '#';
    } catch {
      return '#';
    }
  };

  // Server HTML is escaped except <tag>…</tag>; rebuild it with text nodes only.
  const appendHighlighted = (el, html) => {
    const tag = String(cfg.highlightTag || 'mark').toLowerCase();
    const open = `<${tag}>`;
    const close = `</${tag}>`;
    let mark = null;
    for (const part of String(html || '').split(new RegExp(`(</?${tag}>)`, 'i'))) {
      const lower = part.toLowerCase();
      if (lower === open) {
        mark = document.createElement(tag);
      } else if (lower === close) {
        if (mark) {
          el.appendChild(mark);
          mark = null;
        }
      } else if (part !== '') {
        (mark || el).appendChild(document.createTextNode(decode(part)));
      }
    }
    if (mark) {
      el.appendChild(mark);
    }
  };

  let uid = 0;

  const bind = (input) => {
    if (input.dataset.yetisearchBound) {
      return;
    }
    input.dataset.yetisearchBound = '1';

    const id = `yetisearch-listbox-${++uid}`;
    const list = document.createElement('ul');
    list.id = id;
    list.className = 'yetisearch-listbox';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', cfg.i18n.label);
    list.hidden = true;

    const status = document.createElement('div');
    status.className = 'yetisearch-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    const parent = input.parentElement;
    if (parent && window.getComputedStyle(parent).position === 'static') {
      parent.style.position = 'relative';
    }
    input.insertAdjacentElement('afterend', list);
    list.insertAdjacentElement('afterend', status);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id);
    input.setAttribute('autocomplete', 'off');

    let timer = 0;
    let controller = null;
    let items = [];
    let active = -1;

    const close = () => {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    };

    const setActive = (index) => {
      const options = list.querySelectorAll('[role="option"]');
      options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
      active = index;
      if (index >= 0 && options[index]) {
        input.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    };

    const render = (results) => {
      items = results;
      list.replaceChildren();
      if (results.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'yetisearch-empty';
        empty.textContent = cfg.i18n.noResults;
        list.appendChild(empty);
      }
      results.forEach((item, i) => {
        const option = document.createElement('li');
        option.id = `${id}-option-${i}`;
        option.className = 'yetisearch-option';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');

        const link = document.createElement('a');
        link.href = safeUrl(item.url);
        link.tabIndex = -1;

        const title = document.createElement('span');
        title.className = 'yetisearch-title';
        appendHighlighted(title, item.title_html);
        link.appendChild(title);

        if (item.excerpt_html) {
          const excerpt = document.createElement('span');
          excerpt.className = 'yetisearch-excerpt';
          appendHighlighted(excerpt, item.excerpt_html);
          link.appendChild(excerpt);
        }

        option.appendChild(link);
        option.addEventListener('mousedown', (event) => event.preventDefault());
        list.appendChild(option);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      active = -1;
      status.textContent = cfg.i18n.resultsCount.replace('%d', String(results.length));
    };

    const search = async (term) => {
      if (controller) {
        controller.abort();
      }
      controller = new AbortController();
      const url = new URL(cfg.endpoint, window.location.href);
      url.searchParams.set('q', term);
      url.searchParams.set('limit', String(cfg.maxResults));
      url.searchParams.set('context', 'typeahead');
      if (cfg.lang) {
        url.searchParams.set('lang', cfg.lang);
      }
      try {
        const response = await fetch(url, {
          signal: controller.signal,
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (!response.ok) {
          close();
          return;
        }
        const data = await response.json();
        render(Array.isArray(data.items) ? data.items : []);
      } catch (error) {
        if (error.name !== 'AbortError') {
          close();
        }
      }
    };

    input.addEventListener('input', () => {
      window.clearTimeout(timer);
      const term = input.value.trim();
      if (term.length < cfg.minChars) {
        if (controller) {
          controller.abort();
        }
        close();
        return;
      }
      timer = window.setTimeout(() => search(term), cfg.debounce);
    });

    input.addEventListener('keydown', (event) => {
      if (list.hidden) {
        return;
      }
      const count = items.length;
      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault();
          if (count) {
            setActive((active + 1) % count);
          }
          break;
        case 'ArrowUp':
          event.preventDefault();
          if (count) {
            setActive(active <= 0 ? count - 1 : active - 1);
          }
          break;
        case 'Enter':
          if (active >= 0 && items[active]) {
            event.preventDefault();
            window.location.assign(safeUrl(items[active].url));
          }
          break;
        case 'Escape':
          event.preventDefault();
          close();
          break;
        default:
          break;
      }
    });

    input.addEventListener('blur', () => window.setTimeout(close, 150));
  };

  try {
    document.querySelectorAll(cfg.selector).forEach((el) => {
      if (el instanceof HTMLInputElement) {
        bind(el);
      }
    });
  } catch {
    // Invalid selector in settings: leave the native search untouched.
  }
})();
