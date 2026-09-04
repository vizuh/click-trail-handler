# Review-only translation batch

This directory contains only the German (`de_DE`), Brazilian Portuguese (`pt_BR`),
and Spanish (`es_ES`) drafts for the current ClickTrail 1.10.0 POT. Every entry is
marked fuzzy and requires native review. These files are not runtime catalogs.

English (US) remains the canonical source language; no duplicate English catalog is
created. UK and Australian English are optional regional variants and are outside
this batch.

Before runtime use, a native reviewer must check technical terms, settings, privacy
copy, diagnostics, placeholders, markup, plural forms, and the localized admin flow.
Only after that review may accepted catalogs move to `languages/` and be compiled.
