# Data sources

## What is not committed

This is a public repo, so Genki's own chapter word lists stay local:

- `genki-vocab.json` and `genki-kanji.json`
- every `chapters/**/l<NN>.vocab.md`

A vocabulary list is the publisher's editorial selection, not a fact, and the
Japan Times has enforced that before. The vocabulary pages of
[genki-study-resources](https://sethclydesdale.github.io/genki-study-resources/)
now return "This Page Has Been Removed" for exactly this reason.

What is committed is our own work: the grammar explanations, example sentences,
contrasts and practice prompts in `chapters/`, the correction files below, and
the tooling.

Rebuild the local files on a fresh clone:

```bash
python3 tools/fetch_data.py
python3 tools/sync_tables.py
```

## Datasets

`genki-vocab.json` and `genki-kanji.json` come from
[cemulate/genki-db](https://github.com/cemulate/genki-db) (MIT), which indexes
the vocabulary and kanji of Genki I and II by lesson. `tools/fetch_data.py`
downloads them and refuses to overwrite anything if the response does not look
like the expected list of lesson-tagged records.

Vocabulary is grouped by part of speech and sorted by kana within each group,
which is the order Genki itself uses. `pos-groups.json` records where those
group boundaries fall for each lesson, since the dataset does not carry a part
of speech field.

## Correction files

The vendored files are never edited. Fixes live alongside them so that
re-downloading the upstream data does not silently discard our corrections:

- `vocab-overrides.json`, keyed `<lesson>:<kana>`. Corrects a word that already
  exists. Fields: `kanji`, `kana`, `romaji`, `english`, `pos`.
- `kanji-overrides.json`, keyed `<lesson>:<kanji>`. Fields: `readings`,
  `meaning`, `examples`.
- `vocab-additions.json`, keyed by lesson. Adds a word the dataset is missing.
  Each entry needs `kanji`, `kana`, `english` and `pos`. An addition is spliced
  in after the last dataset word sharing its part of speech, so Genki's own
  grouping order survives.

The dataset turned out to be incomplete. Checking each chapter against the
book's own word list found eleven missing words across five lessons, including
生まれる and お祈りする in Lesson 17, 片付ける in Lesson 18, それで in Lesson 19,
and 迎えに行く in Lesson 16. That is what `vocab-additions.json` exists for.

After editing any of these, run `python3 tools/sync_tables.py` to push the
change into every chapter file, then `python3 tools/validate_chapters.py`.

## Romaji convention

Generated romaji is wapuro style, kana-faithful, with `wo` for を and doubled
long vowels. Two cases need a hand-written `romaji` override, and two do not.
Keeping this consistent matters because the whole corpus feeds flashcards and,
later, speech.

Space it when a particle sits inside the phrase, or when the entry is a
multi-word set expression:

| entry | romaji |
|---|---|
| 気が付く | `ki ga tsuku` |
| 保険に入る | `hoken ni hairu` |
| そんなことはない | `sonna koto wa nai` |
| 目覚まし時計 | `mezamashi dokei` |

Leave it joined for する compounds and for lexicalised adverbs, which is what
the generator already produces:

| entry | romaji |
|---|---|
| 準備する | `junbisuru` |
| 交換する | `koukansuru` |
| 絶対に | `zettaini` |
| 本当に | `hontouni` |

One known generator limitation: は always renders as `ha`, so a particle は
inside a phrase (それでは, そんなことはない) needs an override to read `wa`.
Detecting particle は reliably is not worth the complexity.

## Editions

Chapter content targets **Genki 2nd edition**, matching the scanned PDFs in
Google Drive. The 3rd edition reorders some grammar points between lessons, so
grammar point lists sourced from 3rd edition material do not transfer.
