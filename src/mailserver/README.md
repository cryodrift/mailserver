# POP3/SMTP Test Servers (Implicit TLS)

Minimal POP3 and SMTP servers intended for local account-setup and client integration tests.
POP3 serves messages from a filesystem maildir. SMTP accepts messages and relays them using `src/mail/Smtp`.

This is not for production use.

## What it does
- POP3: Listens on a TLS socket (implicit TLS) — default `0.0.0.0:1995`.
- POP3: Serves messages from a configurable `maildir` directory (recursively scanned, oldest first).
- POP3: Accepts any `USER`/`PASS` (no verification) — for testing only.
- POP3: Supports core commands: `CAPA`, `USER`, `PASS`, `STAT`, `LIST`, `UIDL`, `RETR`, `TOP`, `NOOP`, `RSET`, `QUIT`.
- SMTP: Listens on implicit TLS (default `0.0.0.0:465`). Accepts `EHLO/HELO`, `AUTH` (accepts any), `MAIL FROM`, `RCPT TO`, `DATA`, `RSET`, `NOOP`, `QUIT`.
- SMTP: On end of `DATA`, relays via `\cryodrift\mail\Smtp` using configured user accounts.
- SMTP (receiver mode): Optionally saves each received message to a Maildir so POP3 can serve it.
- POP3: Multi-line responses and dot-stuffing per POP3.

## Requirements
- PHP 8.4+
- TLS certificate and key files (self-signed is fine for local testing)
- A maildir-like folder with message files (POP3)
- add domain to hosts eg: 127.0.0.1   mail.lab.lan
- create cert for this domain

## Minimal usage examples
# POP3
php vendor/bin/cryodrift.php -echo /mailserver/cli/ pop3

# SMTP
php vendor/bin/cryodrift.php -echo /mailserver/cli/ smtp

## Connecting for quick tests
POP3 over TLS (implicit):
```sh
openssl s_client -connect 127.0.0.1:1995 -quiet
```

SMTP over TLS (implicit):
```sh
openssl s_client -connect 127.0.0.1:465 -quiet
EHLO localhost
AUTH PLAIN
MAIL FROM:<test@example.com>
RCPT TO:<dest@example.com>
DATA
Subject: hello

body
.
QUIT
```

## Maildir notes (POP3)
- The server recursively scans the configured `maildir` and serves every file it finds as a message.
- Messages are sorted by file modification time (oldest first).
- `UIDL` uses the filename (before any `:2,` suffix) for stability.

## Limitations
- POP3: No user authentication or authorization (accepts any credentials). Read-only (`DELE` disabled).
- SMTP: Accepts any `AUTH` and relays based on local account config; intended only for local tests.
- Designed for local/test environments only.

 
