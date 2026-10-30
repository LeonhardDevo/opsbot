# ADR-0001 — Vertical-first: cleaning & field-service companies, shipped with a starter pack

- Status: accepted
- Date: 2026-10-22
- Related: PLAN.md §2–§3, DOMAIN_CONTRACT.md §16 (seed), §10.4 (compensation disclaimer)

## Context

The idea itself names the main risk: a generic "chat with your documents" bot loses to Notion AI,
Glean, ChatGPT Team/Enterprise and Slack AI, which already sit where office workers are. Our
advantage only exists where those tools are absent: **deskless staff** (cleaners, technicians,
couriers, shift workers) who live in Telegram, have no corporate e-mail, never open a SaaS, and whose
questions are operational ("the client says we scratched the table — what do I do?").

## Decision

1. OpsBot is positioned and demoed for **cleaning and facility-service companies (10–200 field
   staff)**. The product core stays domain-neutral (documents, departments, incidents, tasks), but
   every first-run surface (landing, onboarding, demo data, incident categories, empty states) speaks
   to this vertical.
2. We ship a bilingual **Cleaning starter pack**: 8 short original Markdown regulations
   (`docs/seed-documents/cleaning/{en,ru}/`, manifest `pack.json`):
   furniture/property damage, key handover, chemical safety, no access to the property, compensation
   rules with caps, client reply templates, lost & found items, manager complaint playbook
   (manager-only). Moved by web-core in M1 to `apps/web/resources/starter-packs/cleaning/`.
3. In onboarding step 3 the admin can import the pack in RU, EN or both. Imported documents are
   **drafts** (ADR-0005): nothing is answerable until an admin reviews and approves it. The pack is a
   template, not company policy.
4. Incident categories are fixed to the vertical's real events: `damage`, `complaint`, `no_access`,
   `injury`, `lost_item`, `other`.
5. Liability guard: whenever an answer cites a chunk of a document tagged `compensation`, the ai
   service appends the localized line "The final amount is confirmed by your manager." /
   «Итоговую сумму подтверждает менеджер.» The line is appended by code after generation, not by the
   prompt, and is covered by a pytest.

## Consequences

- Demo, E2E and the golden RAG set (DOMAIN_CONTRACT §10.7) all run on the same pack, so retrieval
  quality is measured on realistic text.
- Expanding to another vertical = a new pack + incident category labels; no schema change except the
  category enum (deliberately closed so analytics stay comparable).
- Copy must never claim the pack is legally reviewed; the pack README and the import dialog say
  "Template — adapt to your company before approving".

## Alternatives rejected

- **Horizontal knowledge bot** — loses on distribution and on features to incumbents.
- **Free-form incident categories** — analytics and routing rules become unbounded; replaced by
  the fixed enum + free-text description.
