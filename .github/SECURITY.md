# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅ Security fixes |
| < 1.0   | ❌ Please upgrade |

Security fixes land on the latest minor of the supported major.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately through GitHub's
[Report a vulnerability](https://github.com/gacela-project/container/security/advisories/new)
form. That opens a private advisory visible only to the maintainers.

If GitHub advisories are unavailable to you, email <gacela@chemaclass.com> with
`SECURITY` in the subject.

Please include:

- The affected version
- A description of the issue and its impact
- A minimal reproduction if you have one

## What to expect

- **Acknowledgement** within 5 working days
- An assessment, and a fix timeline if the report is confirmed
- Credit in the release notes, unless you would rather stay anonymous

This is a volunteer-maintained library; there is no bug bounty.

## Scope

This container resolves and instantiates classes from identifiers the calling
application supplies. Passing **untrusted input** as a service id or binding
means letting a user choose which classes get instantiated, which is a risk in
your application rather than a vulnerability in this library. Treat service ids
as trusted, developer-controlled values.
