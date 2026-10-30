# ADR-0006 — Deterministic confidence gate and the golden set

- Status: accepted (amended 2026-10-22, plan review 2)
- Date: 2026-10-22
- Overrides: PLATFORM.md §4.6 wording "fused top score < RAG_MIN_CONFIDENCE" (RRF is not a confidence)
- Related: ADR-0005, ADR-0009, DOMAIN_CONTRACT §10.4, §10.7

## Context

RRF scores depend only on ranks (rank 1 in both lists ≈ 2/61 with k = 60), so a threshold on the fused
score cannot tell a strong hit from junk. With the `hash` embeddings used in CI, cosine similarity is a
bag-of-features signal, not semantics. Refusing when unsure is OpsBot's key promise, so the gate must
be deterministic and testable.

The foundation ai service already separates the two concerns (`apps/ai/app/retrieval/search.py`):
RRF orders hits; a per-hit *confidence* fuses calibrated cosine with lexical coverage.

## Decision

1. **Ordering** = RRF over pgvector cosine and FTS (`simple`, `russian`, `english`, unaccent), k = 60.
   RRF is never compared with a threshold.
2. **Hit confidence** (kept from the foundation, now pinned):
   ```
   hit_confidence = 0.55 * calibrate(cosine, model.calibration)
                  + 0.45 * coverage(content_terms(question), title + section_path + content)
   calibrate(s, (floor, ceiling)) = clamp((s - floor) / (ceiling - floor), 0, 1)
   coverage = share of the question's stemmed non-stop-word terms present in the hit
   ```
   Calibration per embedding model is declared by the embedder (`hash` = (0.05, 0.55);
   `intfloat/multilingual-e5-small` = (0.74, 0.90); others as in `embeddings/local.py`).
3. **Answer confidence** = max hit confidence over the top 3 hits **after** ACL, approval and date
   filters and conflict resolution.
4. **Gate** (amended in plan review 2 — see "Amendment" below): refuse (`refused = true`,
   `refusal_reason = low_confidence`) when answer confidence `< context.min_confidence`, unless the
   message was routed to a data tool by a deterministic intent rule and the answer is a templated
   tool-data answer. `min_confidence` is a per-workspace
   setting (`ops_settings.min_confidence`, range 0.20–0.80, default **0.40**) sent by the caller in the
   `/v1/chat` request; env `RAG_MIN_CONFIDENCE` remains only the fallback when the field is absent.
5. A refusal always carries the localized "not found in approved documents" text and an `escalate`
   action (ADR-0007). It never contains invented policy.
6. **Golden set** (DOMAIN_CONTRACT §10.7): 20 questions (10 RU, 10 EN) against the approved Cleaning
   starter pack — 10 must answer and cite the expected document, 10 must refuse. Runs in pytest with the
   `mock` provider and `hash` embeddings (lexical coverage dominates), and as an opt-in job with the
   `local` e5-small embedder (`-m golden_local`). File: `apps/ai/tests/golden/cleaning.yaml`.

## Decision notes on the review findings

- The reviewer proposed `0.6 * cosine(rescaled 0.2..0.9) + 0.4 * coverage` with default threshold 0.45.
  We keep the foundation's 0.55 / 0.45 split and per-model calibration because it is already implemented
  and unit-tested, and a fixed 0.2..0.9 rescale is wrong for e5 models (their unrelated-text cosines
  start around 0.7). The default threshold 0.40 is accepted only if the golden set passes; the ai
  engineer may move it within [0.30, 0.50] by amending this ADR with the golden-set report — never by
  editing a test expectation.

## Amendment (plan review 2, 2026-10-22) — the foundation gate bypass

The foundation code (`apps/ai/app/agent/pipeline.py`, payflow-guard `002cb4e`) skips the pre-gate when
**any** data tool is *available*: `if retrieval.confidence < min_confidence and not any(tool.data_tool for
tool in tools)`, and the post-gate passes any answer once the model called a data tool. Every OpsBot user
has `who_handles` (`assistant.use`, `data_tool = true`), so the gate would never fire: off-topic questions
reach the LLM (external tokens) and can come back uncited.

1. **Pre-gate.** Bypass only when a deterministic intent rule (`app/tools/intents.py`, RU + EN patterns of
   DOMAIN_CONTRACT §10.2) selected a data tool **for this message** (`IntentMatch.tool.data_tool`).
   Otherwise `confidence < min_confidence` → refuse **before any provider call**.
2. **Post-gate.** Exempt only answers whose body is rendered from tool data by a code template
   (`answer_kind = 'tool_data'`; the LLM may not add free text to it). Every other answer
   (`answer_kind = 'document'`) needs retrieval confidence ≥ `min_confidence` **and** ≥ 1 citation, else
   it is replaced by the refusal.
3. The PLAN §8 invariant reads "100 % of non-refused **document** answers cite ≥ 1 source"; tool-data
   answers name their source in the template ("from the routing rules", "from your task list").
4. Implementation lives in OpsBot's `apps/ai` (owned by OpsBot from M0, ADR-0019); the change is a
   product override, not a PR to PayFlow.
5. Tests (pytest): a fake LLM that always calls `who_handles` on golden questions 11–20 → all refuse and
   the provider call counter is **0**; "кому передать жалобу?" → templated `who_handles` answer with
   `answer_kind = 'tool_data'`; a free-text answer after a data tool call with confidence below the
   threshold → refused.

## Consequences

- The gate is reproducible in CI; a regression in chunking or stemming shows up as a golden-set failure.
- Admins can trade answer rate vs. caution in **Settings → Assistant**; the UI shows the current refusal
  rate next to the slider (from analytics, ADR-0016).
