# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.x     | Yes       |
| 1.x     | No        |

## Reporting a Vulnerability

If you discover a security vulnerability in this package, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, send an email to **sebastianberrio45@hotmail.com** with:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### Response Timeline

- **Acknowledgment**: Within 48 hours
- **Initial assessment**: Within 5 business days
- **Fix release**: As soon as possible, depending on severity

### What to Expect

1. You will receive an acknowledgment confirming receipt of your report
2. We will investigate and validate the issue
3. A fix will be developed and tested
4. A new release will be published with the fix
5. You will be credited in the release notes (unless you prefer anonymity)

### Scope

The following are considered security issues:

- Cache poisoning or unauthorized cache manipulation
- Permission bypass or privilege escalation
- Information disclosure through Redis keys
- Injection vulnerabilities in permission/role names

The following are **not** security issues:

- Redis server misconfiguration (not managed by this package)
- Laravel framework vulnerabilities (report to Laravel directly)
- Denial of service through large permission sets (operational concern)

## Best Practices

When using this package in production:

- Use a dedicated Redis connection for permissions (not shared with sessions/cache)
- Enable Redis AUTH and TLS in production environments
- Use a unique `prefix` in config to isolate keys from other applications
- Restrict network access to your Redis instance
