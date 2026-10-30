# Seed documents

`cleaning/` is the bilingual **Cleaning starter pack** (ADR-0001): 8 original template regulations in
`en/` and `ru/` plus the manifest `pack.json` (titles, tags, department codes, roles).

- Authored here by the planner. **web-core moves this folder in M1** (`git mv`) to
  `apps/web/resources/starter-packs/cleaning/`; after that the apps path is canonical and this README
  points there. The ai golden test (`apps/ai/tests/golden/cleaning.yaml`, DOMAIN_CONTRACT §10.7) reads
  the pack from that path (`STARTER_PACK_DIR`, default `../web/resources/starter-packs/cleaning`).
- Wording is load-bearing: the golden RAG set was verified against these exact texts with the
  foundation `content_terms()`/`coverage()` (every answerable question has full term coverage in its
  target section; every refusal question ≤ 0.25 against every document). Editing a document requires
  re-running the golden set.
- The pack is a template, not company policy and not legal advice. Imported documents are drafts and
  must be approved by an admin (ADR-0005). No emoji, no images, no external links.
