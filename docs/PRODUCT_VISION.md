# LocAlmanac Product Vision

Updated: September 2026

Status: Product north star. This document describes the intended direction of LocAlmanac and provides a framework for evaluating future product and architecture decisions. It is not a commitment to ship every capability described here.

## Vision

LocAlmanac is the civic home page and trusted civic agent for any place.

It helps people understand what is happening across local, county, state, and federal government; why it matters to their community; what may happen next; and when they have an opportunity to participate.

The core promise is:

> Tell LocAlmanac where you live and what you care about. It will watch the public life of that place, explain meaningful developments with evidence, and help you respond with your permission.

LocAlmanac should make civic information useful without requiring someone to know which level of government, agency, board, website, document collection, or meeting archive contains the answer.

## The Opportunity

Public information is fragmented across government websites, agendas, legislation, meeting recordings, public notices, journalism, calendars, and community organizations. Finding a document is often difficult; understanding how it relates to a place or an ongoing issue is harder.

ParlLink, CongressLink, StateLink, MuniLink, CouncilLink, and BoardLink—the Link systems—create a shared civic-information foundation. They can provide public-document digitization, metadata, embeddings, search, and ultimately meeting transcripts across levels and bodies of government.

LocAlmanac should not duplicate that infrastructure. It should turn it into a coherent public experience by combining authoritative government material with local reporting, events, notices, deadlines, and practical community information.

The Link systems provide the civic record. LocAlmanac provides local context, continuity, explanation, personalization, and permissioned assistance.

## Product Position

LocAlmanac is not primarily:

- another government document archive;
- a replacement for local journalism;
- a generic news aggregator;
- a chatbot that answers questions without durable civic context; or
- an autonomous political-advocacy system.

LocAlmanac is the interpretation and access layer that connects people and AI agents to trustworthy civic information about a place.

Its durable value comes from:

- accurate geographic and jurisdictional context;
- continuous monitoring across official and non-official sources;
- connections among governments, bodies, meetings, documents, people, projects, votes, reporting, and events;
- change detection and issue history;
- canonical citations and source freshness;
- clear explanations of authority, process, and next steps;
- user-controlled subscriptions and civic memory; and
- safe, transparent tools for civic participation.

## User Needs

LocAlmanac should organize the product around five enduring needs.

### Discover

What is happening where I live?

The location page should surface important reporting, government activity, meetings, events, public notices, deadlines, new documents, and emerging topics without requiring the user to formulate a search.

### Understand

What does this mean, who has authority, and how did we get here?

Answers and summaries should connect current developments to earlier proposals, meetings, votes, governing processes, other levels of government, and relevant reporting. Every material claim should be traceable to evidence.

### Follow

Keep me informed about places, bodies, projects, and topics I care about.

Users should be able to explicitly follow jurisdictions, agencies, councils, boards, committees, people, projects, legislation, and topics. LocAlmanac should not infer political beliefs or build hidden political profiles.

### Participate

When can I act, and what is the appropriate process?

LocAlmanac should identify hearings, meetings, elections, comment periods, application windows, deadlines, and other participation opportunities. It may help prepare questions, testimony, or public-records requests, but it should never speak for or act as a user without clear authorization.

### Remember

What happened over time?

LocAlmanac should preserve a searchable civic history that shows how an issue, project, or decision developed across documents, meetings, votes, reporting, and jurisdictions.

## The Agentic LocAlmanac

In an agentic-AI environment, LocAlmanac evolves from a destination that waits for questions into a civic companion that can observe, interpret, anticipate, and assist.

### Observe

LocAlmanac continuously monitors Link systems, journalism, public notices, calendars, and other trusted sources. It detects new material, changed documents, approaching deadlines, completed votes, new transcripts, and discrepancies that may need attention.

### Interpret

It converts new information into structured civic context:

- what changed;
- which government or body is responsible;
- which place and people are affected;
- what happened previously;
- what arguments or uncertainties remain;
- what happens next; and
- which evidence supports the explanation.

### Anticipate

It recognizes meaningful future events: a final vote, expiring comment period, recurring unresolved item, implementation deadline, or state or federal action that may alter a local decision.

### Assist

With explicit permission, it can create watchlists, deliver briefings, add meetings to a calendar, draft questions, compare proposal versions, prepare research packets, or help someone navigate the correct participation process.

The agent should distinguish clearly among reading, interpretation, recommendation, and external action. External actions require a preview and user authorization.

## Product Layers and Options

LocAlmanac can support several complementary products on the same civic-information foundation.

| Product layer | Primary promise | Primary users |
| --- | --- | --- |
| Public civic utility | See and search what is happening in a location | Residents and the general public |
| Personal civic agent | Follow selected places and interests and receive proactive, cited briefings | Engaged residents |
| Alerts and public notices | Do not miss a relevant meeting, deadline, notice, or material change | Residents, organizations, and businesses |
| Professional monitoring workspace | Monitor multiple jurisdictions, bodies, issues, and transcripts | Journalists, researchers, nonprofits, and civic professionals |
| Community distribution platform | Publish newsletters, feeds, widgets, and community briefings | Local media and community organizations |
| Civic agent infrastructure | Supply verified local civic context and tools to other AI agents | Developers, platforms, and partner products |

The recommended strategy is layered rather than choosing only one of these options.

### Free public foundation

Every supported location should have a useful public page with local reporting, government activity, meetings and events, public notices, recent documents, cited explanations, and visible coverage gaps.

### Personal relationship

Accounts should allow people to select locations, bodies, projects, and topics; save questions; configure alerts; and receive daily or weekly briefings. Personalization should be explicit, understandable, and reversible.

### Professional sustainability

A professional workspace can add multi-jurisdiction monitoring, saved searches, rapid document alerts, transcript search, issue timelines, comparisons, exports, and collaborative watchlists. This can support a sustainable paid product while the core public utility remains broadly accessible.

### Distributed access

LocAlmanac should be useful even when users do not visit its website. Email, notifications, partner embeds, APIs, and agent tools can deliver civic information through the channels people already use.

## Relationship to the Link Systems

The Link systems should remain the systems of record for the material they process. They own canonical documents, government metadata, meeting records, transcripts, embeddings, and retrieval.

LocAlmanac should consume their existing APIs through a configurable provider layer rather than implement a separate integration for every Link product. Provider capabilities may include:

- semantic and keyword search;
- document and meeting details;
- transcript segments;
- jurisdictions and government bodies;
- canonical citations and URLs;
- incremental updates or webhooks; and
- coverage and capability metadata.

LocAlmanac should generally avoid copying Link documents or recreating their embeddings. It may retain lightweight projections needed for feeds, ranking, notifications, and user experience, including canonical identifiers, titles, dates, bodies, places, topics, summaries, URLs, freshness, and availability.

Coverage will be uneven. LocAlmanac should discover and display the capabilities available for each jurisdiction rather than assume that every location has documents, transcripts, votes, and complete history.

## Civic Context and Memory

To connect Link material with reporting and community information, LocAlmanac needs a durable civic context model. Over time, it should represent relationships among:

- places and jurisdiction boundaries;
- levels of government;
- councils, agencies, boards, committees, and offices;
- officials, candidates, staff, organizations, and other public actors;
- meetings, agenda items, documents, legislation, votes, and transcript segments;
- projects, programs, contracts, budgets, notices, deadlines, and events;
- news coverage and other external sources; and
- topics followed explicitly by users.

This civic memory allows LocAlmanac to answer not only “What documents mention housing?” but also “What has changed about this housing proposal, who can decide it, and what happens next?”

## Trust and Safety Principles

LocAlmanac's credibility is a core product capability, not a presentation detail.

### Evidence first

Material factual claims should cite the underlying document, agenda item, transcript passage, official notice, or article. Official records, reporting, and AI-generated interpretation should be visibly distinguished.

### Freshness and correction

The product should show when evidence was published or retrieved, recognize superseded documents, and propagate corrections from canonical sources.

### Coverage honesty

Missing coverage must not be presented as proof that nothing happened. LocAlmanac should disclose which sources and capabilities are available for a location.

### Neutral civic assistance

Explanations should represent uncertainty and material disagreement. Ranking, summaries, and alerts must not be influenced by sponsors, campaigns, or political preferences.

### Explicit personalization

Users choose what to follow. LocAlmanac should minimize personal data, avoid inferring political beliefs, explain why an item was included, and make subscriptions easy to inspect or remove.

### Permissioned action

The system must not impersonate users, submit public comments, contact officials, register for events, or take other external actions without an explicit preview and authorization. It should resist automated mass lobbying, spam, and astroturfing.

## Example Experience

A Lawrence resident follows housing, transit, and the City Commission.

LocAlmanac detects that a new agenda changes an affordability provision in a pending housing proposal. It connects the revision to an earlier commission discussion, a relevant state proposal, and recent local reporting. The resident receives a short alert explaining the change and why it was included, with links to the exact agenda item, document comparison, transcript passages, and reporting.

The resident asks what authority the city has, what arguments have been made, and when the public can comment. LocAlmanac provides a cited explanation and identifies the hearing deadline. With permission, it adds the hearing to the resident's calendar and helps draft a question. It does not submit or send anything on the resident's behalf without confirmation.

The same underlying information can support a journalist's monitoring workspace, a neighborhood newsletter, or an external personal assistant using LocAlmanac's agent tools.

## Phased Evolution

### Phase 1: Trusted location experience

- Complete reliable multi-jurisdiction news and event ingestion.
- Make every location page useful without authentication.
- Improve source health, provenance, citations, and coverage disclosure.
- Establish stable jurisdiction and government-body mappings.

### Phase 2: Link-powered retrieval

- Implement a shared provider adapter around existing ParlLink and CongressLink APIs.
- Add Link documents and meetings to city retrieval and activity feeds.
- Merge Link evidence with LocAlmanac reporting and event evidence.
- Validate relevance, latency, deduplication, and citation quality in selected jurisdictions.

### Phase 3: Following and briefings

- Allow users to follow places, bodies, projects, and topics explicitly.
- Add saved searches and durable issue timelines.
- Produce cited daily or weekly briefings.
- Introduce controlled alerts for meetings, documents, notices, and deadlines.

### Phase 4: Proactive civic agent

- Detect meaningful changes, conflicts, and approaching decisions.
- Add transcript-aware meeting summaries and comparisons.
- Provide permissioned calendar, drafting, and research assistance.
- Maintain transparent action previews and audit history.

### Phase 5: Civic agent platform

- Expose trusted civic retrieval and context through APIs and agent tools.
- Support professional multi-jurisdiction monitoring.
- Enable partner feeds, embeds, newsletters, and community agents.
- Expand across the full Link ecosystem without product-specific user experiences.

## Measures of Success

LocAlmanac should measure whether it improves civic understanding and access, not only traffic or chat volume.

Useful measures include:

- source and jurisdiction coverage;
- freshness and successful ingestion rates;
- citation completeness and evidence quality;
- answer usefulness and correction rates;
- percentage of alerts users consider relevant;
- return use through following and briefings;
- successful discovery of meetings, deadlines, and participation opportunities;
- time saved for residents and professional researchers; and
- user understanding of why an item was shown and where it came from.

## Decision Framework

When evaluating a proposed feature, integration, or business model, ask:

1. Does it help someone discover, understand, follow, participate, or remember?
2. Does it improve trustworthy civic context for a place?
3. Is LocAlmanac the correct layer, or does the capability belong in a Link system?
4. Can the result be supported with canonical evidence and freshness information?
5. Does personalization remain explicit and user-controlled?
6. Does any external action require and receive appropriate authorization?
7. Will the feature work across jurisdictions without hard-coded city assumptions?
8. Does it strengthen the public civic utility even if a professional product funds it?

## North-Star Description

> LocAlmanac is the trusted civic agent for where you live. It brings together government records, public meetings, local reporting, notices, and community information; explains what is changing and why it matters; and helps you follow and participate in public life with evidence, transparency, and control.
