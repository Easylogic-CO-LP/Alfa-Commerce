# Contributing to Alfa Commerce

Welcome! This guide explains how to contribute to Alfa Commerce, step by step. Whether you're a seasoned developer or just getting started, this document covers everything you need to know.

## Table of Contents

- [Getting Started](#getting-started)
- [How to Contribute](#how-to-contribute)
- [Branching Strategy](#branching-strategy)
- [Automated Checks (What Happens When You Open a PR)](#automated-checks)
- [Understanding PHP CS Fixer (Code Style)](#php-cs-fixer)
- [Understanding PHPStan (Static Analysis)](#phpstan)
- [Understanding Claude AI Review](#claude-ai-review)
- [Understanding Security Scans](#security-scans)
- [How to Read Check Results](#how-to-read-check-results)
- [Fixing Issues Found by Checks](#fixing-issues-found-by-checks)
- [Coding Standards](#coding-standards)
- [Project Structure](#project-structure)
- [Need Help?](#need-help)

---

## Getting Started

### Prerequisites
- PHP 8.2 or higher
- A Joomla 4.x / 5.x installation for testing
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
git checkout -b feature/my-new-feature

# Make your changes...

# Commit and push
git add .
git commit -m "Add my new feature"
git push origin feature/my-new-feature

# Then go to GitHub and open a Pull Request
```

---

## Branching Strategy

| Branch | Purpose |
|--------|---------|
| `main` | Stable releases only. Never commit directly to main. |
| `developer` | Active development branch. Create your branches from here. |
| `feature/*` | New features (e.g., `feature/paypal-payment`) |
| `fix/*` | Bug fixes (e.g., `fix/cart-total-calculation`) |

**Always target your Pull Requests to the `developer` branch**, unless you're told otherwise.

---

## Automated Checks

When you open or update a Pull Request, **four automated checks** run on your code. You don't need to do anything to trigger them — they run automatically.

Here's what happens:

```
You open a Pull Request
        |
        v
+------------------+     +------------------+     +------------------+     +------------------+
|  PHP CS Fixer    |     |    PHPStan       |     | Claude AI Review |     | Security Scan    |
|                  |     |                  |     |                  |     |                  |
| Checks code      |     | Finds bugs and   |     | AI reviews your  |     | Checks for       |
| formatting and   |     | type errors in   |     | code and leaves  |     | vulnerabilities  |
| FIXES it for you |     | your PHP code    |     | comments on the  |     | and leaked       |
| automatically    |     |                  |     | Pull Request     |     | secrets          |
+------------------+     +------------------+     +------------------+     +------------------+
        |                         |                        |                        |
   Auto-commits             Shows errors            Posts comments           Shows warnings
   fixes to your            as red markers          with suggestions         in Checks tab
   branch                   on changed lines
```

### Where to See Results

On your Pull Request page on GitHub:

1. **Checks tab** — Shows green (passed) or red (failed) for each check
2. **Files changed tab** — Shows inline annotations (yellow/red markers on specific lines)
3. **Conversation tab** — Shows Claude AI review comments

---

## PHP CS Fixer

### What It Does
PHP CS Fixer automatically formats your PHP code to follow our coding standards (PSR-12). Think of it like auto-correct for code formatting.

### What It Fixes (Examples)

```php
// BEFORE (your code):
function getPrice($id){
    $result=$price+$tax;
    if($result>0){
        return $result;
    }
}

// AFTER (auto-fixed):
function getPrice($id)
{
    $result = $price + $tax;
    if ($result > 0) {
        return $result;
    }
}
```

Common things it fixes:
- **Indentation** — Converts tabs to 4 spaces
- **Spacing** — Adds spaces around operators (`=`, `+`, `>`, etc.)
- **Braces** — Ensures consistent brace placement
- **Imports** — Sorts `use` statements alphabetically, removes unused ones
- **Arrays** — Converts `array()` to `[]` short syntax
- **Trailing commas** — Adds trailing commas in multiline arrays
- **Quotes** — Converts double quotes to single quotes when no variables are inside

### What You Need to Do
**Nothing!** PHP CS Fixer runs automatically and commits the fixes directly to your branch. After it runs:

1. You'll see a new commit on your PR by `github-actions[bot]` with the message: `style: auto-fix code style with PHP CS Fixer`
2. **Pull the latest changes** to your local machine before making more commits:
   ```bash
   git pull origin your-branch-name
   ```

### Running It Locally (Optional)
If you want to fix formatting before pushing:
```bash
# Install PHP CS Fixer
composer global require friendsofphp/php-cs-fixer

# Check what would change (dry run)
php-cs-fixer fix --config=.php-cs-fixer.php --allow-risky=yes --dry-run --diff

# Auto-fix everything
php-cs-fixer fix --config=.php-cs-fixer.php --allow-risky=yes
```

---

## PHPStan

### What It Does
PHPStan analyses your PHP code **without running it** and finds potential bugs. It catches things like:
- Calling methods that don't exist
- Passing wrong argument types to functions
- Using undefined variables
- Logic errors

### What It Catches (Examples)

```php
// PHPStan will flag these:

$order->getTotel();
// Error: Method getTotel() not found. Did you mean getTotal()?

function calculateTax(float $price): float {
    return $price * $taxRate;
    // Error: Variable $taxRate might not be defined
}

if ($status = 'active') {
    // Error: Assignment in condition. Did you mean == or ===?
}
```

### How to Read PHPStan Errors

On the **Files changed** tab of your PR, you'll see annotations like:

```
Line 45: Call to method getTotel() on class OrderModel.
         Did you mean getTotal()?
```

Each annotation tells you:
- **The line number** where the problem is
- **What the problem is** in plain English
- Sometimes a **suggestion** for how to fix it

### What You Need to Do
PHPStan does **NOT** auto-fix. You need to fix these manually:
1. Read the error message on the PR
2. Go to that file and line in your code
3. Fix the issue
4. Commit and push — PHPStan will re-run automatically

### Running It Locally (Optional)
```bash
# Install PHPStan
composer global require phpstan/phpstan

# Run analysis
phpstan analyse --configuration=phpstan.neon --memory-limit=512M
```

---

## Claude AI Review

### What It Does
Claude is an AI that reviews your code like a human reviewer would. It reads your changes and posts comments on the Pull Request with:
- Suggestions for improvement
- Potential bugs it spotted
- Questions about your approach
- Best practice recommendations

### How It Works
- **Automatic:** Claude reviews every new PR automatically
- **On-demand:** You can ask Claude questions by commenting `@claude` followed by your question on the PR

### Examples of What Claude Might Comment

```
"This database query doesn't sanitize the user input on line 34.
Consider using $db->quote() to prevent SQL injection."

"This function is doing too many things. Consider splitting the
order validation and payment processing into separate methods."

"Good implementation! One suggestion: you could use early returns
to reduce nesting in this method."
```

### Asking Claude Questions
You can comment on the PR like:
- `@claude is this the right way to handle cart calculations?`
- `@claude can you explain what this method does?`
- `@claude are there any security issues with this approach?`

### What You Need to Do
Read Claude's comments and decide which suggestions to apply. Claude's suggestions are recommendations, not requirements — use your judgment.

---

## Security Scans

### What They Do
Three security tools run on every PR:

| Tool | What It Checks |
|------|---------------|
| **CodeQL** | Scans JavaScript/TypeScript for security vulnerabilities (XSS, injection, etc.) |
| **PHPStan Security** | Checks PHP code for security anti-patterns |
| **TruffleHog** | Scans for accidentally committed secrets (API keys, passwords, tokens) |

### What You Need to Do
- **Never commit** API keys, passwords, or secrets to the repository
- If TruffleHog flags something, **immediately** rotate that secret/key
- Fix any security issues CodeQL reports before the PR can be merged

---

## How to Read Check Results

### On the Pull Request Page

At the bottom of your PR, you'll see a section like:

```
Checks
  ✅ PHP CS Fixer        — All checks passed (fixes were auto-committed)
  ❌ PHPStan             — 3 errors found
  ✅ Claude PR Review    — Review complete
  ✅ Security Scan       — No vulnerabilities found
```

- **Green checkmark** = passed, no action needed
- **Red X** = issues found, click to see details
- **Yellow dot** = still running, wait a moment

### Clicking Into a Failed Check
1. Click on the failed check name
2. You'll see the **log output** showing exactly what failed
3. For PHPStan, errors also appear as **inline annotations** on the Files changed tab

---

## Fixing Issues Found by Checks

### Quick Reference

| Check | Auto-fixes? | What to do if it fails |
|-------|-------------|----------------------|
| PHP CS Fixer | Yes | Just pull the latest changes from your branch |
| PHPStan | No | Read the error annotations, fix the code, push again |
| Claude Review | No | Read the comments, apply suggestions you agree with |
| Security Scan | No | Fix vulnerabilities, never commit secrets |

### The Fix Cycle

```
Check fails
    |
    v
Read the error/comment
    |
    v
Fix the issue in your code
    |
    v
Commit and push
    |
    v
Checks run again automatically
    |
    v
All green? --> Ready for merge!
```

---

## Coding Standards

### PHP
- Follow **PSR-12** coding standard (enforced automatically by PHP CS Fixer)
- Use **4 spaces** for indentation (not tabs)
- Use **single quotes** for strings without variables
- Always use **short array syntax** `[]` instead of `array()`
- Sort `use` import statements alphabetically
- Use type hints for function parameters and return types where possible
- Use Joomla's `DatabaseDriver` for all database queries — never write raw SQL
- Always sanitize user input using Joomla's `InputFilter`

### JavaScript
- Use `const` and `let` instead of `var`
- Use single quotes for strings
- Add semicolons at the end of statements

### General
- Write meaningful commit messages
- One feature or fix per Pull Request
- Keep PRs small and focused — large PRs are harder to review

---

## Project Structure

```
Alfa-Commerce/
|-- administrator/          # Backend admin panel (models, views, controllers, forms)
|-- site/                   # Frontend customer-facing code
|-- api/                    # REST JSON-API controllers
|-- plugins/
|   |-- alfa-payments/      # Payment plugins (standard, revolut, viva)
|   |-- alfa-shipments/     # Shipping plugins (standard, boxnow)
|   |-- alfa-fields/        # Custom field type plugins
|   |-- webservices/        # API routing plugin
|-- modules/
|   |-- mod_alfa_cart/      # Shopping cart module
|   |-- mod_alfa_search/    # Product search module
|-- media/com_alfa/         # CSS, JavaScript, images
|-- alfa.xml                # Joomla package manifest
|-- script.php              # Install/update/uninstall script
|-- .php-cs-fixer.php       # Code style configuration
|-- phpstan.neon            # Static analysis configuration
|-- .github/workflows/      # Automated check definitions
```

---

## Need Help?

- **Questions about the code?** Open an issue on GitHub or ask `@claude` on a PR
- **Found a bug?** Open an issue with steps to reproduce
- **Want to discuss a feature?** Open an issue with the `enhancement` label
- **Contact the team:** info@easylogic.gr
- **Website:** [Easylogic](https://easylogic.gr)

Thank you for contributing to Alfa Commerce!
