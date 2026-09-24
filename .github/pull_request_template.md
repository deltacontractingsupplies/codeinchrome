## What this changes and why

<!-- One or two sentences. Link the issue it closes, if any. -->

## How it was tested

<!-- The tests you added or ran: `PAO_DISABLE=1 php artisan test`, `go test ./...`, an e2e spec. -->

## Checklist

- [ ] Tests cover the change (and fail without it)
- [ ] No secret, password, token, key or server address anywhere in the change
- [ ] Nothing a tenant could use to reach another tenant or the host
- [ ] Documentation updated where behaviour changed

Every pull request is reviewed by a maintainer before it can merge; CI (tests,
static checks and a secret scan) must pass first.
