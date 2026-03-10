# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| Pre-alpha (current) | Yes |

## Reporting a Vulnerability

We take security seriously at Alfa Commerce. If you discover a security vulnerability, please report it responsibly.

### How to Report

1. **Do NOT open a public GitHub issue** for security vulnerabilities
2. Send an email to **info@easylogic.gr** with the subject line: `[SECURITY] Alfa Commerce Vulnerability Report`
3. Include the following information:
   - Description of the vulnerability
   - Steps to reproduce the issue
   - Potential impact
   - Suggested fix (if any)

### What to Expect

- **Acknowledgment:** We will acknowledge receipt of your report within 48 hours
- **Assessment:** We will investigate and assess the vulnerability within 7 days
- **Resolution:** We aim to release a fix within 30 days of confirming the vulnerability
- **Credit:** We will credit reporters in the release notes (unless you prefer to remain anonymous)

### Scope

The following are in scope for security reports:

- SQL injection
- Cross-site scripting (XSS)
- Cross-site request forgery (CSRF)
- Authentication or authorization bypasses
- Remote code execution
- Sensitive data exposure
- Payment processing vulnerabilities

### Out of Scope

- Vulnerabilities in Joomla core (report these to the Joomla Security Strike Team)
- Denial of service attacks
- Social engineering
- Issues in third-party dependencies (report these to the respective maintainers)

## Security Best Practices for Contributors

- Always use Joomla's `DatabaseDriver` with parameterized queries — never concatenate user input into SQL
- Sanitize all user input using Joomla's `InputFilter`
- Use CSRF tokens for all form submissions
- Never commit secrets, API keys, or credentials to the repository
- Follow the principle of least privilege for database operations

## Contact

- **Email:** info@easylogic.gr
- **Website:** [easylogic.gr](https://easylogic.gr)
