# Documentation

## Structure

```
docs/
├── rfc/   — Design proposals and technical specifications
└── wiki/  — Knowledge base and reference guides
```

## RFC

Request for Comments — design documents that propose new features, architectural changes, or significant improvements before implementation. Each RFC follows a standard structure: TL;DR, context (what/why), components, acceptance criteria.

File naming convention: `YYYY-MM-DD-short-slug.md`

See the [rfc-writer skill](../../.agents/skills/rfc-writer/SKILL.md) for the RFC template.

## Wiki

Knowledge base — reference guides, onboarding docs, troubleshooting, conventions, and how-tos.

| Page | Description |
|------|-------------|
| [Product Overview](wiki/product-overview.md) | What AgileTracker is, use cases, audience |
| [Architecture](wiki/architecture.md) | Tech stack, core models, directory structure, patterns |
| [Development Guide](wiki/development.md) | Setup, code style, testing, conventions |
| [Bunny.net S3 Storage](wiki/bunny-s3-storage.md) | Create an S3-compatible Storage Zone, point the uploaders at it, and verify item and comment attachments |
