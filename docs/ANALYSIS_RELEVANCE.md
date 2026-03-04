# Analysis and Civic Relevance (Current)

Updated: March 2026

## Objective

Localmanac analysis converts article text into structured civic signal used by UI and downstream retrieval behavior.

## Implemented Analysis Model

Analysis is persisted on `article_analyses` and currently includes:

- dimension scores
- justifications
- opportunities
- process timeline payload
- explainer payload
- `civic_relevance_score`
- model, prompt version, confidence, and status metadata

`civic_relevance_score` is computed by `CivicRelevanceCalculator` from normalized dimensions.

## Dimension Set

Implemented dimensions:

- comprehensibility
- orientation
- representation
- agency
- relevance
- timeliness

## Relationship to Enrichment

Current implementation is multi-pass enrichment with analysis outputs merged into a single final payload.

Agents involved:

- `CivicAnalysisAgent`
- `EntityEnrichmentAgent`
- `ExplainerAgent`

This is not a single-pass model.

## Claims and Projections

Analysis is not the same as fact storage.

- extracted facts/entities are written as claims
- projection tables are rebuilt from claims
- analysis/explainer/timeline payloads are stored separately for UI and interpretation

## Where Analysis is Consumed

- article explainer Livewire page (`articles/{article}`)
- projected participation actions
- projected process timeline
- article text/title refresh support flows

## Guardrails

- no direct fact-table writes from AI extraction outputs
- city scoping remains mandatory for downstream retrieval and rendering
- confidence and provenance are persisted for auditability
