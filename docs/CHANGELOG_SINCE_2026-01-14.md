# Changelog Since 2026-01-14

This document tracks notable delivered scope after the previous documentation baseline on January 14, 2026.

## 2026-01 to 2026-02: Chat and Scheduling Expansion

- Added chat sources management UI and source selection/search behavior
- Added ask pipeline migration to Laravel AI with event-aware tooling support
- Added scheduled ingestion for event sources and scrapers
- Added conversation persistence support for streaming dashboard chat

User impact:

- Better local answer quality and stronger citation continuity
- More reliable unattended ingestion operations
- Persistent chat context for authenticated dashboard users

## 2026-02: Ingestion Reliability and Fetch Hardening

- Stabilized Visit Wichita ingestion with direct token endpoint path and fallback behavior
- Hardened sequence drift recovery in ingestion and enrichment write paths
- Improved document extraction and article title quality handling
- Strengthened strict schema enforcement for AI structured payloads
- Added UTF-8 sanitization before enrichment requests

User impact:

- Fewer ingestion stalls and fewer malformed-content failures
- Better extracted article readability and resilience across noisy sources

## 2026-02 to 2026-03: Admin and Dashboard Access Controls

- Added super-admin access controls for admin routes and raw scraper config management
- Expanded admin/dashboard UI polish and role-appropriate navigation behavior
- Added branded favicon/logo asset integration across app surfaces

User impact:

- Clear separation between standard user and administrative capability
- More consistent cross-surface branding and UI behavior

## 2026-03: Feedback + Ingestion Quality Guard

- Added `site_feedback` model, migration, enum, widget capture flow, and admin feedback index/filtering
- Added `ArticleQualityGuard` with configurable rejection rules
- Added `articles:prune-low-quality` command with dry-run and force-delete modes
- Expanded tests around quality guard logic, prune command behavior, and feedback workflows

User impact:

- Direct in-product channel for user feedback capture and triage
- Reduced low-value article noise entering the content corpus

## 2026-03: Scraper Assistant and Browser-Fetcher Hardening

- Added stronger assistant drafting/refinement rules and selector preservation behavior
- Added preview validation requirements for non-super-admin scraper saves
- Added expanded Playwright option handling across fetchers and runtime script
- Added anti-bot recovery/failure signaling improvements across fetch workflows

User impact:

- Safer scraper onboarding and fewer broken scraper configs
- Better reliability for JS-heavy or challenge-protected sources
