# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A self-contained React component (`japanese_flashcards.jsx`) for studying Japanese verb conjugations from GENKI II Lesson 22 (第22課). No build system, no package.json, no external dependencies beyond React.

## Architecture

Single-file React component (~364 lines) with everything inline:

- **Data**: Two hardcoded arrays — `ruleCards` (30 grammar rule cards) and `verbCards` (92 verb transformation cards) covering 5 forms: Passive (受身形), Potential (可能形), Causative (使役形), Causative-Passive (使役受身形), Volitional (意向形)
- **State**: React `useState` hooks manage filtering, card deck, navigation, flip animation, and scoring
- **Styling**: Inline styles with a dark theme (#080810), form-based color accents, and 3D flip animation
- **Fonts**: Google Fonts CDN — Noto Serif JP (Japanese text), Space Mono (UI elements)

## Usage

Drop the component into any React project. No build step, environment variables, or configuration needed. All study data is ephemeral (no persistence across reloads).

## Key Patterns

- Fisher-Yates shuffle for card randomization
- `getFiltered()` dynamically filters cards by form and type before shuffling
- Each card has: Japanese text, romaji, answer/back, example sentence with translation
- Form colors defined in `formAccents` object
