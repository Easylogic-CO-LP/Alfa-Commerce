# Contributing to Alfa Commerce

Welcome! This guide covers the contribution **process**. For how the codebase is organized and how the component
works, see the **[developer manual](https://manual.alfacommerce.gr)** — the single source of truth.

## Table of Contents

- [Getting Started](#getting-started)
- [How to Contribute](#how-to-contribute)
- [Branching Strategy](#branching-strategy)
- [Automated Checks](#automated-checks)
- [Coding Standards](#coding-standards)
- [Project Structure](#project-structure)
- [Need Help?](#need-help)

---

## Getting Started

### Prerequisites
- PHP 8.2 or higher
- A Joomla 6 or 7 installation for testing
- Git installed on your machine
- A GitHub account

### Setting Up Locally
1. **Fork** the repository on GitHub
2. **Clone** your fork locally
3. Set up a local Joomla installation (using XAMPP, WAMP, Laravel Herd, or similar)
4. Install the component by downloading the ZIP and uploading via Joomla Extension Manager

---

## How to Contribute

### Step-by-Step Process

```
1. Fork the repository on GitHub
2. Create a new branch from "developer" (not main)
3. Make your changes
4. Commit and push to your fork
5. Open a Pull Request targeting the "developer" branch
6. Wait for automated checks to run (usually 2-5 minutes)
7. Address any issues the checks find
8. Get your PR reviewed and merged
```

### Quick Example

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/Alfa-Commerce.git
cd Alfa-Commerce

# Create a branch from developer
git checkout developer
git checkout -b feat/my-new-feature

# Make your changes...

# Commit and push
git add .
git commit -m "Add my new feature"
git push origin feat/my-new-feature

# Then go to GitHub and open a Pull Request
```

---

## Branching Strategy

| Branch | Purpose |
|--------|---------|
| `main` | Stable releases only. Never commit directly to main. |
| `developer` | Active development branch. Create your branches from here. |
| `feat/*` | New features (e.g., `feat/paypal-payment`) |
| `fix/*` | Bug fixes (e.g., `fix/cart-total-calculation`) |

**Always target your Pull Requests to the `developer` branch**, unless you're told otherwise.

---

## Automated Checks

Every Pull Request runs **PHP CS Fixer**, **PHPStan**, the **Claude AI reviewer**, and **security scans** (CodeQL,
secret-scanning, dependency checks). **Wait for every check to finish — and read the Claude review — before a PR is
merged.**

See **[CI/CD & Tooling](https://manual.alfacommerce.gr/docs/tooling/workflows)** in the manual for what each check
does, how to read the results, and how to run them locally.

---

## Coding Standards

### PHP
- Follow **PSR-12** (enforced automatically by PHP CS Fixer)
- **4 spaces** for indentation (not tabs)
- **Single quotes** for strings without variables; **short array syntax** `[]`
- Sort `use` imports alphabetically; type-hint parameters and return types
- Use Joomla's `DatabaseDriver` for all queries — never raw SQL; sanitize input with `InputFilter`

### JavaScript
- `const` / `let` (not `var`), single quotes, semicolons

### General
- Meaningful commit messages; one feature or fix per PR; keep PRs small and focused

---

## Project Structure

See the manual's **[Project Structure](https://manual.alfacommerce.gr/docs/getting-started/project-structure)** for the
directory layout, namespaces, and conventions (single source of truth).

---

## Need Help?

- **Questions about the code?** Open an issue on GitHub or ask `@claude` on a PR
- **Found a bug?** Open an issue with steps to reproduce
- **Want to discuss a feature?** Open an issue with the `enhancement` label
- **Contact the team:** info@easylogic.gr
- **Website:** [Easylogic](https://easylogic.gr)

Thank you for contributing to Alfa Commerce!
