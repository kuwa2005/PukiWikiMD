---
frozen: true
---

# 書き方

保存される本文は Markdown です。表示は [CommonMark](https://spec.commonmark.org/) に、表・取り消し線・タスクリスト・自動リンクを加えた GitHub Flavored Markdown、さらに脚注・定義リスト・見出し属性・ハイライト・front matter を足したものです。

[toc]

## 見出し

```markdown
# 見出し1
## 見出し2
### 見出し3 {#custom-id}
```

`{#custom-id}` を付けると、その見出しの id になります。付けない見出しにも id が付きます。

## 段落、強調、コード

空行で段落が分かれます。

```markdown
**太字** *斜体* ~~取り消し~~ ==マーカー==

`インラインコード`

<u>下線</u>
```

## リスト

```markdown
- 箇条書き
  - 入れ子
1. 番号
   1. 入れ子
- [ ] 未了
- [x] 完了
```

## リンク

```markdown
[[Help|この Wiki のヘルプ]]
[CommonMark](https://spec.commonmark.org/)
https://example.com/
```

URL だけの段落・箇条書きは閲覧時に OGP カードになります。

## 画像

```markdown
![代替テキスト](https://example.com/image.png)
```

## 引用とコード

引用は行頭を `>` にします。

> 引用です。

コードはバッククォート 3 つで囲みます。開始行に言語名を書けます。

```text
コード
```

## 表

```markdown
| 項目 | 内容 |
| --- | --- |
| 書式 | Markdown |
| ファイル | .md |
```

## 脚注

```markdown
本文です[^1]。

[^1]: 脚注です。
```

## 定義リスト

```markdown
PukiWikiMD
:   Markdown ファイルでページを保存する Wiki
```

## 目次

見出し一覧を入れたい位置に、その行だけ次を書きます。

```markdown
[toc]
```

## front matter

ファイル先頭だけ有効です。`frozen: true` のページは編集できません。

```markdown
---
frozen: true
---
```

## 使わないもの

`*見出し`、`''太字''`、`[[表示>ページ]]`、`#plugin` といった旧 PukiWiki 記法は解釈しません。
