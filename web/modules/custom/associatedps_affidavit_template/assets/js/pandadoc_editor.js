import { Editor } from 'https://esm.sh/pandadoc-editor@1.0.1';

const container = document.getElementById('pandadoc-editor-container');
if (container) {
  const token = container.dataset.sessionId || '';
  const callbackUrl = container.dataset.callbackUrl || '';
  const variablesJson = container.dataset.variables || '[]';
  const statusEl = document.getElementById('pandadoc-editor-status');
  const wrapper = document.getElementById('pandadoc-editor-wrapper');

  if (!token) {
    if (statusEl) {
      statusEl.textContent = 'Error: No editing session token available.';
      statusEl.classList.add('messages--error');
      statusEl.style.display = 'block';
    }
  } else {
    // Build a searchable token picker above the editor using the shared utility.
    var variables = [];
    try { variables = JSON.parse(variablesJson); } catch (e) {}
    if (variables.length > 0 && window.ApsTokenPicker) {
      window.ApsTokenPicker.init(wrapper, variables, {
        label: 'Search & Select to copy token name',
        idPrefix: 'pandadoc-token'
      });
    }

    // Watch for the iframe before the SDK replaces the container.
    var observer = new MutationObserver(function () {
      var iframe = wrapper.querySelector('iframe');
      if (iframe) {
        iframe.setAttribute('width', '100%');
        iframe.setAttribute('height', '100%');
        iframe.setAttribute('style', 'width:100%; height:calc(100vh - 150px); min-height:600px; border:none;');
        observer.disconnect();
      }
    });
    observer.observe(wrapper, { childList: true, subtree: true });

    var editor = new Editor(
      'pandadoc-editor-container',
      { token: token, debugMode: true },
      { region: 'com' }
    );

    editor
      .on('content.rendered', function () {
        if (statusEl) {
          statusEl.textContent = 'Editor loaded. You can now edit the template.';
          statusEl.classList.remove('messages--error');
          statusEl.classList.add('messages--status');
          statusEl.style.display = 'block';
        }
      })
      .on('document.loading.error', function (err) {
        console.error('[PandaDoc] document loading error:', err);
        if (statusEl) {
          statusEl.textContent = 'Error loading document in PandaDoc editor.';
          statusEl.classList.add('messages--error');
          statusEl.style.display = 'block';
        }
      })
      .on('template.loading.error', function (err) {
        console.error('[PandaDoc] template loading error:', err);
        if (statusEl) {
          statusEl.textContent = 'Error loading template in PandaDoc editor.';
          statusEl.classList.add('messages--error');
          statusEl.style.display = 'block';
        }
      });

    editor.open({
      fieldPlacementOnly: false,
      blocks: {
        pricingTable: { visible: true },
        'page-break': { visible: true },
        quote: { visible: true },
      },
    }).catch(function (err) {
      console.error('[PandaDoc Editor] open() failed:', err);
      if (statusEl) {
        statusEl.textContent = 'Failed to open the PandaDoc editor.';
        statusEl.classList.add('messages--error');
        statusEl.style.display = 'block';
      }
    });

    // "Done Editing" button.
    var doneBtn = document.createElement('button');
    doneBtn.type = 'button';
    doneBtn.className = 'button button--primary';
    doneBtn.style.marginTop = '10px';
    doneBtn.textContent = 'Done Editing \u2014 Return to APS';
    doneBtn.addEventListener('click', function () {
      editor.destroy();
      window.location.href = callbackUrl;
    });
    wrapper.appendChild(doneBtn);
  }
}
