// PukiWikiMD - Inline media helpers for #ref (draw.io viewer)
// License: GPL v2 or (at your option) any later version
/* eslint-env browser */
(function () {
  'use strict'

  var VIEWER_SRC = 'https://viewer.diagrams.net/js/viewer-static.min.js'
  var viewerLoading = null

  function loadViewerScript () {
    if (window.GraphViewer) {
      return Promise.resolve()
    }
    if (viewerLoading) {
      return viewerLoading
    }
    viewerLoading = new Promise(function (resolve, reject) {
      var s = document.createElement('script')
      s.src = VIEWER_SRC
      s.async = true
      s.onload = function () { resolve() }
      s.onerror = function () { reject(new Error('draw.io viewer load failed')) }
      document.head.appendChild(s)
    })
    return viewerLoading
  }

  function setError (host, message) {
    if (!host) return
    host.innerHTML = ''
    var p = document.createElement('p')
    p.className = 'ref-media-error'
    p.textContent = message
    host.appendChild(p)
  }

  function renderDrawio (el) {
    var src = el.getAttribute('data-ref-drawio-src')
    var host = el.querySelector('.ref-drawio-host')
    if (!src || !host) return

    fetch(src, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status)
        }
        return res.text()
      })
      .then(function (xml) {
        if (!xml || xml.indexOf('<') === -1) {
          throw new Error('invalid drawio xml')
        }
        return loadViewerScript().then(function () { return xml })
      })
      .then(function (xml) {
        host.innerHTML = ''
        var div = document.createElement('div')
        div.className = 'mxgraph'
        div.style.maxWidth = '100%'
        div.setAttribute('data-mxgraph', JSON.stringify({
          highlight: '#0000ff',
          nav: true,
          resize: true,
          toolbar: 'zoom layers lightbox',
          xml: xml
        }))
        host.appendChild(div)
        if (window.GraphViewer && typeof window.GraphViewer.processElements === 'function') {
          window.GraphViewer.processElements()
        } else if (window.GraphViewer && typeof window.GraphViewer.createViewerForElement === 'function') {
          window.GraphViewer.createViewerForElement(div)
        }
      })
      ['catch'](function () { // eslint-disable-line dot-notation
        setError(host, 'draw.io 図の表示に失敗しました。リンクからファイルを開いてください。')
      })
  }

  function init () {
    var nodes = document.querySelectorAll('.ref-drawio[data-ref-drawio-src]')
    if (!nodes.length) return
    for (var i = 0; i < nodes.length; i++) {
      renderDrawio(nodes[i])
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }
})()
