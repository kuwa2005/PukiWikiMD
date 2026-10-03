// PukiWikiMD - Drop .md files onto a wiki page to create a new page
// License: GPL v2 or (at your option) any later version
/* eslint-env browser */
window.addEventListener && window.addEventListener('DOMContentLoaded', function () {
  'use strict'

  function initPageDropMd () {
    if (!window.FormData || !window.fetch || !document.querySelector) {
      return
    }
    var propRoot = document.querySelector('#pukiwiki-site-properties')
    if (!propRoot) return

    var pluginNameE = propRoot.querySelector('.plugin-name')
    var pluginName = pluginNameE ? pluginNameE.value : ''
    // 編集画面は既存の添付 D&D を優先。プレビュー等も対象外
    if (pluginName === 'edit' || pluginName === 'preview') return

    var pageNameE = propRoot.querySelector('.page-name')
    var pageName = pageNameE ? pageNameE.value : ''

    var propsE = propRoot.querySelector('.site-props')
    if (!propsE || !propsE.value) return
    var siteProps
    try {
      siteProps = JSON.parse(propsE.value)
    } catch (e) {
      return
    }
    var uploadPath = siteProps.base_uri_pathname
    if (!uploadPath) return

    var csrfE = propRoot.querySelector('.csrf-token')
    var csrfToken = csrfE ? csrfE.value : ''

    var statusEl = document.createElement('div')
    statusEl.className = 'pkwk-page-dd-status'
    statusEl.setAttribute('aria-live', 'polite')
    statusEl.setAttribute('role', 'status')
    var bodyEl = document.getElementById('body') || document.body
    if (bodyEl.firstChild) {
      bodyEl.insertBefore(statusEl, bodyEl.firstChild)
    } else {
      bodyEl.appendChild(statusEl)
    }

    setupDocumentDrop(document.documentElement, pageName, uploadPath, csrfToken, statusEl)
  }

  function isMdFile (file) {
    if (!file || !file.name) return false
    return /\.(md|markdown)$/i.test(file.name)
  }

  function collectMdFiles (fileList) {
    var out = []
    if (!fileList || !fileList.length) return out
    for (var i = 0; i < fileList.length; i++) {
      if (isMdFile(fileList[i])) {
        out.push(fileList[i])
      }
    }
    return out
  }

  function hasFileItems (dataTransfer) {
    if (!dataTransfer || !dataTransfer.types) return false
    var types = dataTransfer.types
    if (types.indexOf) {
      return types.indexOf('Files') !== -1
    }
    for (var i = 0; i < types.length; i++) {
      if (types[i] === 'Files') return true
    }
    return false
  }

  function setStatus (statusEl, message, isError) {
    statusEl.textContent = message || ''
    if (isError) {
      statusEl.classList.add('pkwk-page-dd-status--error')
    } else {
      statusEl.classList.remove('pkwk-page-dd-status--error')
    }
  }

  function parseJsonResponse (response) {
    return response.text().then(function (text) {
      try {
        return JSON.parse(text)
      } catch (e) {
        if (/ログイン|log\s*in|password|認証|編集できません/i.test(text)) {
          throw new Error('ログインが必要です。ログインしてからやり直してください。')
        }
        if (/pkwk_chown|CACHEDIR|fopen\(\) failed/i.test(text)) {
          throw new Error('キャッシュディレクトリに書き込めません（権限を確認してください）')
        }
        var snippet = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 120)
        throw new Error(snippet
          ? ('サーバー応答の解析に失敗しました: ' + snippet)
          : 'サーバー応答の解析に失敗しました')
      }
    })
  }

  function setupDocumentDrop (root, pageName, uploadPath, csrfToken, statusEl) {
    var dragDepth = 0

    function clearDragover () {
      dragDepth = 0
      document.documentElement.classList.remove('pkwk-page-dragover')
      document.body.classList.remove('pkwk-page-dragover')
    }

    root.addEventListener('dragenter', function (e) {
      if (!hasFileItems(e.dataTransfer)) return
      e.preventDefault()
      dragDepth++
      document.documentElement.classList.add('pkwk-page-dragover')
      document.body.classList.add('pkwk-page-dragover')
    })

    root.addEventListener('dragover', function (e) {
      if (!hasFileItems(e.dataTransfer)) return
      e.preventDefault()
      e.dataTransfer.dropEffect = 'copy'
      document.documentElement.classList.add('pkwk-page-dragover')
      document.body.classList.add('pkwk-page-dragover')
    })

    root.addEventListener('dragleave', function () {
      dragDepth--
      if (dragDepth <= 0) {
        clearDragover()
      }
    })

    root.addEventListener('drop', function (e) {
      if (!hasFileItems(e.dataTransfer)) return
      // ファイルドロップ時はブラウザのナビゲートを防ぐ
      e.preventDefault()
      clearDragover()
      var files = collectMdFiles(e.dataTransfer.files)
      if (files.length === 0) {
        setStatus(statusEl, 'Markdown ファイル（.md）のみ新規ページになります', true)
        return
      }
      importFiles(files, pageName, uploadPath, csrfToken, statusEl)
    })
  }

  function importFiles (files, pageName, uploadPath, csrfToken, statusEl) {
    var index = 0
    var lastUri = ''
    var created = 0

    function next () {
      if (index >= files.length) {
        if (created > 0 && lastUri) {
          setStatus(statusEl, created + ' 件のページを作成しました。移動します…')
          window.location.href = lastUri
        }
        return
      }
      var file = files[index]
      index++
      setStatus(statusEl, 'ページ作成中: ' + file.name + ' (' + index + '/' + files.length + ')')

      var formData = new FormData()
      formData.append('plugin', 'importmd')
      formData.append('pcmd', 'api')
      if (pageName) {
        formData.append('refer', pageName)
      }
      formData.append('md_file', file)
      if (csrfToken) {
        formData.append('pkwk_csrf_token', csrfToken)
      }

      fetch(uploadPath, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      }).then(function (response) {
        return parseJsonResponse(response).then(function (obj) {
          if (!response.ok || !obj.ok) {
            throw new Error(obj.error || response.statusText || 'import failed')
          }
          return obj
        })
      }).then(function (obj) {
        created++
        lastUri = obj.uri || lastUri
        next()
      })['catch'](function (err) { // eslint-disable-line dot-notation
        setStatus(statusEl, String(err.message || err), true)
      })
    }

    next()
  }

  initPageDropMd()
})
