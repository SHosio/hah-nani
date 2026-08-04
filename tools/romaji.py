"""Kana to wapuro-style romaji.

Matches the convention already used in cards/*.csv: kana-faithful rather than
Hepburn. を becomes "wo", long vowels are doubled (レポート -> repooto,
こうじょう -> koujou), ん is always "n", っ doubles the following consonant.
"""

BASIC = {
    "あ": "a", "い": "i", "う": "u", "え": "e", "お": "o",
    "か": "ka", "き": "ki", "く": "ku", "け": "ke", "こ": "ko",
    "が": "ga", "ぎ": "gi", "ぐ": "gu", "げ": "ge", "ご": "go",
    "さ": "sa", "し": "shi", "す": "su", "せ": "se", "そ": "so",
    "ざ": "za", "じ": "ji", "ず": "zu", "ぜ": "ze", "ぞ": "zo",
    "た": "ta", "ち": "chi", "つ": "tsu", "て": "te", "と": "to",
    "だ": "da", "ぢ": "ji", "づ": "zu", "で": "de", "ど": "do",
    "な": "na", "に": "ni", "ぬ": "nu", "ね": "ne", "の": "no",
    "は": "ha", "ひ": "hi", "ふ": "fu", "へ": "he", "ほ": "ho",
    "ば": "ba", "び": "bi", "ぶ": "bu", "べ": "be", "ぼ": "bo",
    "ぱ": "pa", "ぴ": "pi", "ぷ": "pu", "ぺ": "pe", "ぽ": "po",
    "ま": "ma", "み": "mi", "む": "mu", "め": "me", "も": "mo",
    "や": "ya", "ゆ": "yu", "よ": "yo",
    "ら": "ra", "り": "ri", "る": "ru", "れ": "re", "ろ": "ro",
    "わ": "wa", "ゐ": "i", "ゑ": "e", "を": "wo", "ん": "n",
    "ぁ": "a", "ぃ": "i", "ぅ": "u", "ぇ": "e", "ぉ": "o",
    "ゃ": "ya", "ゅ": "yu", "ょ": "yo", "ゎ": "wa",
    "ー": "",  # handled separately as a vowel extender
}

DIGRAPHS = {
    "きゃ": "kya", "きゅ": "kyu", "きょ": "kyo",
    "ぎゃ": "gya", "ぎゅ": "gyu", "ぎょ": "gyo",
    "しゃ": "sha", "しゅ": "shu", "しょ": "sho", "しぇ": "she",
    "じゃ": "ja", "じゅ": "ju", "じょ": "jo", "じぇ": "je",
    "ちゃ": "cha", "ちゅ": "chu", "ちょ": "cho", "ちぇ": "che",
    "ぢゃ": "ja", "ぢゅ": "ju", "ぢょ": "jo",
    "にゃ": "nya", "にゅ": "nyu", "にょ": "nyo",
    "ひゃ": "hya", "ひゅ": "hyu", "ひょ": "hyo",
    "びゃ": "bya", "びゅ": "byu", "びょ": "byo",
    "ぴゃ": "pya", "ぴゅ": "pyu", "ぴょ": "pyo",
    "みゃ": "mya", "みゅ": "myu", "みょ": "myo",
    "りゃ": "rya", "りゅ": "ryu", "りょ": "ryo",
    "てぃ": "ti", "でぃ": "di", "とぅ": "tu", "どぅ": "du",
    "ふぁ": "fa", "ふぃ": "fi", "ふぇ": "fe", "ふぉ": "fo",
    "うぃ": "wi", "うぇ": "we", "うぉ": "wo",
    "ヴぁ": "va", "ヴぃ": "vi", "ヴ": "vu", "ヴぇ": "ve", "ヴぉ": "vo",
}

VOWELS = "aiueo"


def katakana_to_hiragana(text):
    out = []
    for ch in text:
        code = ord(ch)
        # Katakana block maps onto hiragana with a fixed offset. ヴ (30F4) and
        # the prolonged mark ー (30FC) are left alone.
        if 0x30A1 <= code <= 0x30F3:
            out.append(chr(code - 0x60))
        else:
            out.append(ch)
    return "".join(out)


def to_romaji(kana):
    """Convert a kana string to wapuro romaji.

    Non-kana characters (ASCII, 〜, punctuation) pass through unchanged, so
    entries like 〜あいだに convert to 〜aidani.
    """
    text = katakana_to_hiragana(kana)
    out = []
    i = 0
    while i < len(text):
        pair = text[i:i + 2]
        if pair in DIGRAPHS:
            out.append(DIGRAPHS[pair])
            i += 2
            continue

        ch = text[i]

        if ch == "っ":
            # Double the consonant that starts the next syllable.
            rest = to_romaji(text[i + 1:])
            if rest and rest[0] not in VOWELS:
                out.append(rest[0])
            out.append(rest)
            return "".join(out)

        if ch == "ー":
            # Prolonged sound mark repeats the previous vowel.
            prev = "".join(out)
            if prev and prev[-1] in VOWELS:
                out.append(prev[-1])
            i += 1
            continue

        if ch in BASIC:
            out.append(BASIC[ch])
        else:
            out.append(ch)
        i += 1

    return "".join(out)


if __name__ == "__main__":
    import sys

    for arg in sys.argv[1:]:
        print(f"{arg}\t{to_romaji(arg)}")
