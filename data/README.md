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

- `vocab-overrides.json`, keyed `<lesson>:<kana>`. Fields: `kanji`, `kana`,
  `romaji`, `english`, `pos`.
- `kanji-overrides.json`, keyed `<lesson>:<kanji>`. Fields: `readings`,
  `meaning`, `examples`.

Corrections applied so far include a misspelled gloss for 泥棒, a wrong reading
for 末, missing example words for 重, and romaji spacing for phrase entries such
as 気が付く, where the generator would otherwise run the particles together.

After editing any of these, run `python3 tools/sync_tables.py` to push the
change into every chapter file, then `python3 tools/validate_chapters.py`.

## Editions

Chapter content targets **Genki 2nd edition**, matching the scanned PDFs in
Google Drive. The 3rd edition reorders some grammar points between lessons, so
grammar point lists sourced from 3rd edition material do not transfer.
