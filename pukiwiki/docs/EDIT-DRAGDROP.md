# 編集画面 D&D / クリップボード貼り付け（添付）

`cmd=edit` の編集画面のみ有効です。

## 機能

1. **ファイル → textarea** — OS からファイルをドロップすると `plugin=attach&pcmd=api` へ POST し、ページに添付。成功後 `#ref(ファイル名)` をカーソル位置に挿入。ドロップが画像のみの場合は `#ref(ファイル名,middle)`。
2. **既存添付 → textarea** — ページ下部 `#attach` の各ファイルをドラッグし、textarea にドロップするとカーソル位置に `#ref` を挿入。
3. **クリップボード画像 → textarea** — Ctrl+V / Cmd+V でクリップボードに画像（PNG/JPEG/GIF/WebP 等）がある場合、attach API でアップロードし `#ref(ファイル名,middle)` を挿入。テキストのみの貼り付けは従来どおり。ファイル名は `paste-YYYYMMDD-HHmmss.png` 形式（複数枚は `-1` 等の連番）。
4. **未保存の新規ページ** — 初回保存前の編集画面でも、ページ名が有効かつ編集可能なら添付＋`#ref` 挿入が可能（ページ本体の作成は従来どおり保存時）。

## `#ref` 記法

| 条件 | 挿入例 |
|------|--------|
| 同一ページの添付 | `#ref(example.png)` |
| 別ページの添付 | `#ref(OtherPage/example.png)` |
| 貼り付け（paste）後の自動挿入 | `#ref(paste-20260610-123456.png,middle)` |

PukiWiki 推奨形式は `pagename/filename` です（`plugin/ref.inc.php` 参照）。

### 画像サイズ・ポップアップ（PukiWiki2026 拡張）

カンマ区切りで既存の `#ref` オプション（`left` / `center` / `right` / `wrap` / `around` 等）と併用できます。upstream 互換のため **`#ref(ファイル,オプション,...)`** 形式のみ（`{middle}` ブロック記法は未対応）。

| オプション | 挙動 |
|-----------|------|
| `small` | コンテナ幅の **30%** まで（`max-width:30%`） |
| `middle` | コンテナ幅の **50%** まで |
| `big` | コンテナ幅の **75%** まで |
| `full` | 元画像サイズ（`max-width:none`） |
| `640x480` 等 | 従来どおり固定ピクセル（`横x縦`） |
| `50%` 等 | 従来どおり拡大率（`数字%`） |
| `popup` | クリックで全画面ポップアップ（リンク遷移しない）。ESC または背景クリックで閉じる |

記法例:

```
#ref(photo.png,middle)
#ref(photo.png,big,popup)
#ref(photo.png,640x480,popup,center)
```

## マルチメディアのインライン表示

`#ref(ファイル名)` / `#ref(ファイル名,middle)` は拡張子に応じて表示が変わります（`noimg` で従来のリンクのみに戻せます）。

| 種類 | 拡張子例 | 表示 |
|------|----------|------|
| 画像 | png / jpg / gif / webp | `<img>`（従来どおり。`small`/`middle`/`big`/`full`/`popup` 可） |
| PDF | pdf | ページ内 iframe プレビュー＋「開く」リンク（サイズ未指定時は横幅いっぱい） |
| 音声 | mp3 / wav / ogg / m4a / aac 等 | `<audio controls>` |
| 動画 | mp4 / webm / ogv / mov 等 | `<video controls>` |
| draw.io | drawio / dio | diagrams.net ビューアで図を描画（CDN 要。サイズ未指定時は横幅いっぱい） |
| Office 等 | docx / xlsx / pptx / odt 等 | **ブラウザ内プレビュー不可**。種別付きダウンロードカード |

`small` / `middle` / `big` / `full` を付けたときはそのサイズ指定が優先されます（例: `#ref(a.pdf,middle)`）。

Office（Word / Excel / PowerPoint）はブラウザがネイティブ描画できないため、JPG のように埋め込めません。PDF・音声・動画・draw.io は埋め込み表示できます。

## セキュリティ

- 編集権限・ログイン（`$edit_auth`）・CSRF トークンは既存 attach と同様。
- ファイル種別・サイズは `PLUGIN_ATTACH_MAX_FILESIZE` 等の attach 制限に従います。

## 対応ブラウザ（paste）

| 環境 | 備考 |
|------|------|
| Chrome / Edge / Firefox（デスクトップ） | スクリーンショット貼り付け・画像コピーに対応 |
| Safari（macOS） | 概ね対応。OS / バージョンにより clipboard `items` の挙動差あり |
| モバイル | D&D 同様、paste による画像貼り付けは非対応または不安定なことが多い |
| 古いブラウザ | `fetch` / `FormData` / `clipboardData.items` 未対応時は機能無効（テキスト編集は従来どおり） |

## 関連ファイル

- `pukiwiki/skin/edit-dragdrop.js` — クライアント処理（D&D・paste）
- `pukiwiki/skin/ref-popup.js` — `#ref` の `popup` オプション用ライトボックス
- `pukiwiki/plugin/ref.inc.php` — サイズプリセット（`small` / `middle` / `big` / `full`）と `popup`
- `pukiwiki/plugin/attach.inc.php` — `pcmd=api` JSON API、添付一覧の `data-attach-*` 属性
- `pukiwiki/skin/pukiwiki.skin.php` — edit 時のみ script 読み込み
