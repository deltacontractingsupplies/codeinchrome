# Contributing to codeinchrome

Thank you for helping. Contributions of every size are welcome: fixes, tests,
documentation, security hardening and new features.

## How a change gets in

1. **Open an issue first** for anything bigger than a small fix, so we can agree
   on the approach before you spend time on it.
2. **Fork, branch, change.** Keep a pull request to one purpose.
3. **Test it.** Every change comes with tests that fail without it:
   - control plane: `cd control && PAO_DISABLE=1 php artisan test`
   - agent: `cd agent && go vet ./... && go test -race ./...`
   - end to end (optional locally): `tests/e2e`, see its README
4. **Open a pull request** using the template. On your first one you will be
   asked to agree to the [Contributor License Agreement](CLA.md) with a comment.
5. **Review.** CI must pass - tests, static checks and a secret scan - and a
   maintainer reviews every pull request. Nothing reaches `main` without that
   review; `main` accepts no direct pushes.

## Rules that are never relaxed

- **No secrets and no infrastructure details** in code, tests, fixtures, logs or
  commit messages: no passwords, tokens, keys, `.env` contents, or server
  addresses. Test data uses obviously fake values and reserved example
  addresses (`203.0.113.x`, `example.test`).
- **Tenant isolation first.** Nothing may let one site read or change another
  site's files, database, logs or `.env`, reach the host, or serve anything from
  outside a site's `public/` directory. A change touching isolation gets a test
  that proves the boundary holds.
- **No test may reset a database** with `RefreshDatabase`, `migrate:fresh` or
  similar: tests run in transactions on an in-memory or dedicated test database.
- Match the style of the code around your change.

## Security issues

Do not open a public issue or pull request for a vulnerability - see
[SECURITY.md](SECURITY.md).

## License

codeinchrome is source-available under the Functional Source License
([LICENSE.md](LICENSE.md), FSL-1.1-ALv2): you may use, modify and contribute to
it for any purpose except a competing product or service; each version becomes
Apache-2.0 two years after its release. Contributions are accepted under the
[CLA](CLA.md).
