# Contributing to Alfa Commerce

Thanks for helping improve **Alfa Commerce**! This document describes the contribution **process**. For how the
codebase is organized and how the component works, see the **[developer manual](https://manual.alfacommerce.gr)** —
the single source of truth for architecture, structure, and conventions.

---

## Quick start

```bash
git clone https://github.com/YOUR-USERNAME/Alfa-Commerce.git
cd Alfa-Commerce
git checkout developer
git checkout -b feat/short-description      # or fix/short-description
# …make your changes…
git commit -m "Add coupon validation"
git push origin feat/short-description       # then open a PR against `developer`
```

---

## Contents

- [Prerequisites](#prerequisites)
- [Workflow](#workflow)
- [Branching](#branching)
- [Commit messages](#commit-messages)
- [Coding standards](#coding-standards)
- [Automated checks](#automated-checks)
- [Opening a pull request](#opening-a-pull-request)
- [Where things live](#where-things-live)
- [Getting help](#getting-help)

---

## Prerequisites

| You'll need | Notes |
|-------------|-------|
| **PHP 8.2+** | Matches the component's runtime requirement |
| **Joomla 6 or 7** | A local install for testing (XAMPP, WAMP, MAMP, Laravel Herd, …) |
| **Git + a GitHub account** | Fork-and-pull-request workflow |

Install the component into your test site by uploading the package ZIP via **System → Install → Extensions**.

---

## Workflow

1. **Fork** the repository, then **clone** your fork.
2. Branch from **`developer`** (never `main`).
3. Make focused changes — one feature or fix per branch.
4. **Push** and open a **Pull Request against `developer`**.
5. Wait for the automated checks, address any feedback, and a maintainer merges it.

---

## Branching

| Branch | Purpose |
|--------|---------|
| `main` | Released, stable code only — **never** commit directly. |
| `developer` | Integration branch — **branch from here, PR back here.** |
| `feat/*` | New features — e.g. `feat/paypal-payment` |
| `fix/*` | Bug fixes — e.g. `fix/cart-total-rounding` |

Release flow: `feat/*` or `fix/*` → **`developer`** → **`main`** (release).

---

## Commit messages

- Use the **imperative mood**: *"Add coupon validation"*, not *"Added…"*.
- One logical change per commit; keep the subject concise (~72 chars).
- Reference issues when relevant: *"Fix cart total rounding (#123)"*.

---

## Coding standards

PHP CS Fixer enforces formatting automatically, so focus on substance.

**PHP**
- **PSR-12**; 4-space indentation; short array syntax `[]`; single quotes unless interpolating.
- Type-hint parameters and return types; keep `use` imports alphabetical.
- All database access through Joomla's `DatabaseDriver` (never raw SQL); sanitise input with `InputFilter`.
- Keep `tmpl/` and `layouts/` **presentation-only** — business logic belongs in models/helpers.

**JavaScript**
- `const` / `let` (never `var`), single quotes, semicolons.

> Full conventions and namespaces live in the **[manual](https://manual.alfacommerce.gr/docs/getting-started/project-structure)**.

---

## Automated checks

Every Pull Request runs:

| Check | What it does |
|-------|--------------|
| **PHP CS Fixer** | Auto-formats to PSR-12 and commits the fixes back to your branch |
| **PHPStan** | Static analysis for bugs and type errors |
| **Claude AI review** | Specialist review for correctness, security, and Joomla-API misuse |
| **Security scans** | CodeQL, secret-scanning, and dependency checks |

**Wait for every check to pass — and read the Claude review — before a PR is merged.** What each check does, examples,
and how to run them locally: **[CI/CD & Tooling](https://manual.alfacommerce.gr/docs/tooling/workflows)**.

---

## Opening a pull request

Before you open a PR, please confirm:

- [ ] Branched from `developer` and targeting `developer`.
- [ ] One focused change — unrelated edits split into separate PRs.
- [ ] **Schema change?** Included the migration *and* the install-schema update *and* a version bump (see the manual).
- [ ] User-facing strings use `Text::_()` with keys defined in the `.ini`.
- [ ] Ran the component locally and confirmed it works.

Then push and open the PR — the checks and the review will guide the rest.

---

## Where things live

| Topic | Source of truth |
|-------|-----------------|
| Architecture, directory layout, namespaces | [Manual → Project Structure](https://manual.alfacommerce.gr/docs/getting-started/project-structure) |
| Building plugins (payment / shipment / field / media) | [Manual → Plugin Development](https://manual.alfacommerce.gr/docs/plugins/overview) |
| CI/CD & tooling | [Manual → CI/CD & Tooling](https://manual.alfacommerce.gr/docs/tooling/workflows) |
| Contribution process | This file |

---

## Getting help

- **Found a bug?** Open an issue with steps to reproduce.
- **Have a feature idea?** Open an issue with the `enhancement` label.
- **Question on a PR?** Mention `@claude` for an automated review, or tag a maintainer.
- **Contact:** info@easylogic.gr · [easylogic.gr](https://easylogic.gr)

Thank you for helping make Alfa Commerce better! 🙌
