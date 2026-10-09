/* WP YetiSearch admin: batched maintenance over AJAX, no innerHTML with server data. */
(() => {
  'use strict';

  const cfg = window.wpYetiSearchAdmin;
  if (!cfg || !cfg.nonce || !cfg.i18n || typeof ajaxurl === 'undefined') {
    return;
  }
  const i18n = cfg.i18n;

  const post = async (action, data) => {
    const body = new URLSearchParams({ action: `yetisearch_${action}`, _ajax_nonce: cfg.nonce });
    Object.entries(data || {}).forEach(([k, v]) => body.set(k, String(v)));
    const response = await fetch(ajaxurl, { method: 'POST', body, credentials: 'same-origin' });
    const json = await response.json();
    if (!json || json.success !== true) {
      throw new Error((json && json.data && json.data.error) || i18n.requestFailed);
    }
    return json.data;
  };

  const setStatus = (id, text) => {
    const el = document.getElementById(id);
    if (el) {
      el.textContent = text;
    }
  };

  const reindexBtn = document.getElementById('yetisearch-reindex');
  if (reindexBtn) {
    reindexBtn.addEventListener('click', async () => {
      const force = document.getElementById('yetisearch-reindex-force');
      const useForce = !!(force && force.checked);
      if (useForce && !window.confirm('Drop and rebuild the whole index?')) {
        return;
      }
      reindexBtn.disabled = true;
      const progress = document.getElementById('yetisearch-reindex-progress');
      if (progress) {
        progress.value = 0;
        progress.hidden = false;
      }
      try {
        let page = 1;
        for (;;) {
          const result = await post('reindex', { page, per_page: 100, force: useForce && page === 1 ? 1 : 0 });
          const total = Number(result.total) || 0;
          const done = total > 0 ? Math.min(100, Math.round((page / Math.max(page, 1)) * 100)) : 100;
          if (progress) {
            progress.value = result.finished ? 100 : done;
          }
          setStatus('yetisearch-reindex-status', `${result.processed} / ${total}`);
          if (result.finished) {
            break;
          }
          page += 1;
        }
        setStatus('yetisearch-reindex-status', i18n.done);
      } catch (error) {
        setStatus('yetisearch-reindex-status', String((error && error.message) || error));
      } finally {
        reindexBtn.disabled = false;
        if (progress) {
          progress.hidden = true;
        }
      }
    });
  }

  const embedBtn = document.getElementById('yetisearch-embed');
  if (embedBtn) {
    embedBtn.addEventListener('click', async () => {
      embedBtn.disabled = true;
      try {
        for (;;) {
          const result = await post('embed', {});
          setStatus('yetisearch-embed-status', `${result.embedded} embedded, ${result.pending} pending`);
          if (!result.pending) {
            break;
          }
        }
      } catch (error) {
        setStatus('yetisearch-embed-status', String((error && error.message) || error));
      } finally {
        embedBtn.disabled = false;
      }
    });
  }

  const wire = (id, action, statusId, doneText) => {
    const btn = document.getElementById(id);
    if (!btn) {
      return;
    }
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await post(action, {});
        setStatus(statusId, doneText);
      } catch (error) {
        setStatus(statusId, String((error && error.message) || error));
      } finally {
        btn.disabled = false;
      }
    });
  };

  wire('yetisearch-calibrate', 'calibrate', 'yetisearch-embed-status', i18n.calibrated);
  wire('yetisearch-cache-clear', 'cache_clear', 'yetisearch-cache-status', i18n.cleared);
  wire('yetisearch-cache-warmup', 'cache_warmup', 'yetisearch-cache-status', i18n.warmed);
  wire('yetisearch-health-refresh', 'health', 'yetisearch-health-result', i18n.refreshed);
  wire('yetisearch-probe', 'probe', 'yetisearch-probe-result', i18n.probed);

  const logsOutput = document.getElementById('yetisearch-log-output');
  const refreshLogs = async () => {
    if (!logsOutput) {
      return;
    }
    try {
      const result = await post('logs', { lines: 200 });
      logsOutput.replaceChildren();
      (result.lines || []).forEach((row) => {
        const line = document.createElement('div');
        line.textContent = `${row.time || ''} [${row.level || ''}] ${row.message || ''}`;
        logsOutput.appendChild(line);
      });
      if ((result.lines || []).length === 0) {
        logsOutput.textContent = '—';
      }
    } catch (error) {
      logsOutput.textContent = String((error && error.message) || error);
    }
  };

  const logsRefreshBtn = document.getElementById('yetisearch-logs-refresh');
  if (logsRefreshBtn) {
    logsRefreshBtn.addEventListener('click', refreshLogs);
    refreshLogs();
  }

  const dirCheckBtn = document.getElementById('yetisearch-dircheck');  if (dirCheckBtn) {
    dirCheckBtn.addEventListener('click', async () => {
      const input = document.getElementById('yetisearch-db_custom_dir');
      const resultEl = document.getElementById('yetisearch-dircheck-result');
      const show = (text) => {
        if (resultEl) {
          resultEl.textContent = text;
        }
      };
      const errorMessages = {
        not_absolute: i18n.dirInvalid,
        not_a_directory: i18n.dirNotDirectory,
        not_creatable: i18n.dirNotCreatable,
        not_writable: i18n.dirNotWritable,
      };
      try {
        const result = await post('dircheck', { path: input ? input.value : '' });
        if (result.ok) {
          show(i18n.dirOk);
        } else {
          show(errorMessages[result.error] || i18n.dirInvalid);
        }
      } catch (error) {
        show(String((error && error.message) || error));
      }
    });
  }

  const checklistDismissBtn = document.getElementById('yetisearch-checklist-dismiss');  if (checklistDismissBtn) {
    checklistDismissBtn.addEventListener('click', () => {
      const panel = document.getElementById('yetisearch-checklist');
      if (panel) {
        panel.hidden = true;
      }
      post('checklist_dismiss', {}).catch((error) => {
        window.console.debug('checklist dismissal failed', error);
      });
    });
  }

  const previewInput = document.getElementById('yetisearch-preview-input');
  const previewResults = document.getElementById('yetisearch-preview-results');
  if (previewInput && previewResults && cfg.restEndpoint) {
    let previewTimer = 0;
    const renderPreview = (items) => {
      previewResults.replaceChildren();
      if (items.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'yetisearch-empty';
        empty.textContent = i18n.previewEmpty;
        previewResults.appendChild(empty);
        return;
      }
      items.forEach((item, index) => {
        const option = document.createElement('li');
        option.id = `yetisearch-preview-option-${index}`;
        option.className = 'yetisearch-option';
        option.setAttribute('role', 'option');
        option.textContent = String((item && item.title) || '');
        previewResults.appendChild(option);
      });
    };
    previewInput.addEventListener('input', () => {
      window.clearTimeout(previewTimer);
      const term = previewInput.value.trim();
      if (term.length < 2) {
        previewResults.replaceChildren();
        return;
      }
      previewTimer = window.setTimeout(async () => {
        try {
          const url = new URL(cfg.restEndpoint, window.location.href);
          url.searchParams.set('q', term);
          url.searchParams.set('limit', '5');
          const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error(i18n.requestFailed);
          }
          const data = await response.json();
          renderPreview(Array.isArray(data.items) ? data.items : []);
        } catch (error) {
          previewResults.replaceChildren();
          const failed = document.createElement('li');
          failed.className = 'yetisearch-empty';
          failed.textContent = String((error && error.message) || error);
          previewResults.appendChild(failed);
        }
      }, 300);
    });
  }

  const logsClearBtn = document.getElementById('yetisearch-logs-clear');  if (logsClearBtn) {
    logsClearBtn.addEventListener('click', async () => {
      if (!window.confirm('Clear the error log?')) {
        return;
      }
      try {
        await post('logs_clear', {});
        refreshLogs();
      } catch (error) {
        setStatus('yetisearch-cache-status', String((error && error.message) || error));
      }
    });
  }
})();
