# Analysis Layer & Civic Relevance — Architecture Notes

This document explains **how the Analysis layer works**, **what civic relevance means in Localmanac**, and **how those scores plug into search ranking and chatbot retrieval**.

This is a *design + implementation reference* intended to be read **before** writing code. It reflects decisions already made and aligns with the current PLAN.md.

---

## Why an Analysis Layer Exists

Localmanac is not optimizing for clicks, engagement, or virality.

It is optimizing for **civic efficacy** — a user’s ability to:

- understand what is happening locally
- know who is involved
- recognize whether action is possible
- act in time if action is possible

The Analysis layer exists to:

- normalize these concepts into **explicit signals**
- make ranking and retrieval *intentional*, not accidental
- support transparent, explainable answers in search and chat

---

## Core Principle

**All content is already normalized into Articles.**

It does **not matter** whether an article originated from:

- RSS
- HTML scraping
- PDF extraction
- OCR

Once ingested, every piece of content becomes:

- an `Article`
- with optional `ArticleBody.cleaned_text`
- scoped to a `city_id`

The Analysis layer operates *on top of Articles* — never in parallel.

---

## Analysis Data Model (Conceptual)

Analysis outputs are **not stored directly on Articles**.

They live in a companion record (e.g. `article_analyses`) that represents:

- how the system *interprets* an article
- not the article’s factual content itself

### Conceptual fields

- `article_id`
- `heuristic_scores` (json)
- `llm_scores` (json)
- `final_scores` (json)
- `civic_relevance_score` (float 0–1)
- `score_version` (e.g. `crf_v1`)
- `model` / `prompt_version` (nullable)
- `confidence` (nullable)
- `status` (pending / heuristics\_done / llm\_done / failed)
- timestamps

This separation ensures:

- deterministic ingestion
- auditable AI outputs
- safe iteration on scoring logic

---

## Civic Relevance Framework (Summary)

Each article is evaluated across **six dimensions**, each scored from 0.0–1.0:

1. **Comprehensibility** — is this understandable to a non-expert?
2. **Orientation** — does it explain what the issue/process actually is?
3. **Representation** — does it name who is involved or affected?
4. **Agency** — does it describe how a resident can act or participate?
5. **Relevance** — does it materially affect local residents?
6. **Timeliness** — is there a current or upcoming decision/deadline?

These are combined into a single **civic\_relevance\_score** using fixed weights (as defined in the steering framework).

The LLM never decides the weights — only the inputs.

---

## Two-Phase Scoring Strategy

### Phase 1 — Heuristic Scoring (Fast, deterministic)

Runs automatically when an `ArticleBody` is written or updated.

Signals include:

- reading level / jargon density
- detection of future dates or deadlines
- process language ("public hearing", "comment period", "vote")
- source type (government, news, nonprofit, etc.)

Outputs:

- rough dimension scores
- raw signals (counts, dates, matches)

Purpose:

- establish a baseline
- cheaply identify high-value content
- support ranking even without LLMs

---

### Phase 2 — LLM Scoring (Selective, explainable)

Only runs for **high-value content**, e.g.:

- government sources
- articles with detected future action
- articles above a heuristic relevance threshold

The LLM produces:

- refined dimension scores
- short justifications per dimension
- extracted participation opportunities (dates, locations, URLs)
- a confidence score

All outputs are stored with:

- model name
- prompt version
- score version

No scores are written back to the Article itself.

---

## How Civic Relevance Affects Search

### Retrieval vs Ranking

Meilisearch handles **text relevance**.

Civic relevance influences **ordering and prioritization** *after retrieval*.

### V1 Search Flow

1. Query Meilisearch (always filtered by `city_id`)
2. Retrieve top N candidates (e.g. 30)
3. Load analysis data for those articles
4. Rerank in application code

### Example Reranking Logic

**General informational queries**:

```
final_score = (text_relevance * 0.65)
            + (civic_relevance * 0.35)
```

**Action-oriented queries**:

```
final_score = (text_relevance * 0.55)
            + (agency * 0.25)
            + (timeliness * 0.15)
            + (civic_relevance * 0.05)
```

This keeps search predictable while elevating meaningful civic content.

---

## How Civic Relevance Affects Chatbot Retrieval

The chatbot never searches raw text blindly.

It retrieves **evidence** using the same search pipeline, then applies civic relevance as a second-pass filter.

### Query Intent Detection (Lightweight)

If the user asks:

- "What can I do"
- "How do I comment"
- "When is the meeting"
- "Is there a deadline"

→ treat as **actionable intent**.

Otherwise → general informational intent.

### Evidence Selection Rules

- Always city-scoped
- Prefer articles with:
  - higher civic relevance
  - higher agency + timeliness for actionable queries
- Avoid low-text / boilerplate-only content unless unavoidable

### Why this matters

This prevents:

- fluff outranking important notices
- expired articles dominating answers
- hallucinated participation guidance

---

## What This Enables (Product-Wise)

Because civic relevance is explicit:

- search results feel *purposeful*
- chat answers can explain *why* something matters
- participation opportunities can be surfaced reliably
- future personalization becomes possible without re-architecture

---

## What Is Explicitly Deferred

This document does NOT imply:

- agent-based scraping
- embeddings or vector search
- automated fact writing
- autonomous decision-making

Those are future layers that can be added *on top of this foundation*.

---

## Relationship to PLAN.md

This document corresponds directly to:

- **Milestone 3.5 — Analysis layer**

Nothing here contradicts the existing plan. It only clarifies *how* that milestone is intended to work.

---

## Final Note

If you read this document before writing code and the implementation feels simpler than expected — that’s intentional.

The complexity lives in **deciding what matters**, not in the code itself.

