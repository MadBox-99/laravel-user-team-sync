# Callback error views — report

## What changed

`src/Client/Http/Controllers/IdentityCallbackController.php` no longer lets
`IdentityUnavailableException`, `IdentityRejectedException`, or
`IdentityConflictException` escape uncaught, and no longer `throw`s
`AccessDeniedHttpException`. Every failure path now returns a rendered
package view with a deliberate status code, mirroring the structure and
style of the pre-existing `resources/views/identity/not-entitled.blade.php`
(same card layout, same `__()` usage, no Tailwind/build step, inline CSS).

New views (`resources/views/identity/*.blade.php`):

- `unavailable.blade.php` — provider outage.
- `rejected.blade.php` — provider refused the code/token.
- `conflict.blade.php` — claims cannot be reconciled with local data. No
  interpolated data at all — not even a generic placeholder — so there is
  nothing to leak.
- `refused.blade.php` — shared by the state-mismatch and not-allowlisted
  paths. Generic wording only ("we could not sign you in... try again, or
  use your usual method"), never mentions SSO, the allowlist, or state.

New config key `client.login_url` (`IDENTITY_LOGIN_URL`, default `/login`),
following the `subscribe_url` pattern, used as the retry link on the
`unavailable`, `rejected`, and `refused` pages. `conflict` intentionally has
no action link — retrying does not help; only support can fix a data
conflict.

Controller changes in detail:
- `exchangeCode()`/`fetchClaims()` wrapped in try/catch for
  `IdentityUnavailableException` -> `unavailable()` (503) and
  `IdentityRejectedException` -> `rejected()` (401).
- `assertAllowlisted()` (which threw) became `isAllowlisted()` (a predicate,
  no exception, no message). The state-mismatch branch and the
  not-allowlisted branch both now call the same private `refused()` method,
  which builds one fixed 403 response — same status, same body, same
  headers — so the two are provably indistinguishable from the outside (see
  test below).
- `provisioner->provision($claims)` wrapped in try/catch for
  `IdentityConflictException` -> `conflict()` (409). The catch discards the
  exception itself (`catch (IdentityConflictException)`, no bound variable)
  so there's no way for a future edit to accidentally pass its message —
  which contains the colliding e-mail — into the view.
- `IdentitySession::forgetHandshake()` is now called on every failure
  path (outage, rejected, conflict, refused), not only on state mismatch as
  before. Reasoning: an authorization code is single-use regardless of why
  the exchange failed, so nothing is lost by clearing the handshake, and
  leaving it behind after e.g. an outage would let a stale retry attempt
  reuse a `code_verifier` against a code that has already been consumed or
  discarded by the provider.
- Return type widened from `RedirectResponse|View` to
  `RedirectResponse|View|Response` (`Illuminate\Http\Response`), since a
  custom status code requires `response()->view(...)` rather than a bare
  `View`. The unchanged `not-entitled` (200) and success-redirect paths keep
  their original return shapes.

`config/user-team-sync.php` and `README.md` updated with `login_url` /
`IDENTITY_LOGIN_URL`, plus a new "Callback failure pages" README section
documenting the status/view/notes table and the indistinguishability
guarantee. `tests/TestCase.php` gained an explicit
`user-team-sync.client.login_url` override for test determinism, matching
the existing `subscribe_url` override.

## Status codes chosen, and why

| Exception / condition | Status | Rationale |
|---|---|---|
| `IdentityUnavailableException` | 503 Service Unavailable | The provider itself is down, erroring, or answering garbage — a transient infrastructure fault, not a judgement about this login attempt. 503 is the honest "come back later" code and is what uptime/alert tooling typically treats as a distinct outage signal from 4xx auth failures. |
| `IdentityRejectedException` | 401 Unauthorized | The provider was reachable and explicitly said no to the code/token exchange — this is an authentication failure on this specific attempt, not an outage (503 would be dishonest — the service is fine) and not an authorization decision about an otherwise-valid identity (403/409 would misrepresent it). |
| `IdentityConflictException` | 409 Conflict | Literal HTTP semantics: the request conflicts with the current state of a resource (the e-mail is already attached to a different local identity). Distinct from both "try again" (503/401) and "you're not allowed" (403) — the correct next step is a human resolving data, not a retry. |
| State mismatch / not on allowlist (`AccessDeniedHttpException` previously) | 403 Forbidden | Unchanged from the framework default the existing tests already assert (`assertForbidden()`). Both causes are genuinely "this specific callback is not accepted," which is what 403 means; the two must render identically so 403 stays the single, deliberately non-diagnostic code for this class. |
| Not entitled (unchanged) | 200 OK | Successful authentication; the "failure" is a business/subscription state the app displays inline, not an HTTP-level error. |

These four codes are pairwise distinct, so monitoring/alerting can
distinguish "provider is down" (503) from "provider said no" (401) from
"data needs a human" (409) from "this callback was refused" (403) without
parsing response bodies.

## Keeping state-mismatch and not-allowlisted indistinguishable

Both branches now call the exact same private method, `refused()`, with no
parameters — there is no code path by which the two situations could produce
different bytes. `refused()` returns a fixed 403 response built from a
static view + the same `login_url` config value both times. A new test,
"renders the exact same 403 page for a mismatched state and a
non-allowlisted user", triggers both paths in the same test and asserts
`$mismatchedState->getContent() === $notAllowlisted->getContent()` (plus
both status 403) — this would fail immediately if the two branches ever
diverged in wording, links, or status.

`IdentitySession::forgetHandshake()` is called before `refused()` in both
branches, preserving the pre-existing security property (a refused callback
cannot be replayed with the same state/verifier) and extending it uniformly
rather than only for the state-mismatch case.

## Mutation result (load-bearing test check)

Temporarily made the conflict path leak data end-to-end: the controller's
`catch (IdentityConflictException $exception)` block was changed to pass
`$exception->getMessage()` (which embeds the colliding e-mail) into the view
as `mutationDebugMessage`, and `conflict.blade.php` was changed to print
`{{ $mutationDebugMessage }}`.

Running `vendor/bin/pest --filter="never prints the colliding"` then failed
as expected:

```
FAILED  Tests\Feature\Client\AuthFlowTest > it shows a 409 conflict page...
Not to contain: anna@example.test
```

Both the controller and the view were reverted to their prior state
immediately after (verified by re-running the full suite), confirming the
new `assertDontSee('anna@example.test')` assertion is load-bearing rather
than vacuous.

## Test output

```
vendor/bin/pest
Tests:    236 passed (555 assertions)
Duration: 6.34s

vendor/bin/pint
{"result":"pass"}
```

236 = the 232-test baseline plus 4 new tests in `AuthFlowTest.php`:
- "shows a 503 retry page when the identity provider is unreachable, with no
  secret in the body"
- "shows a 401 page when the identity provider rejects the code, with no
  secret in the body"
- "shows a 409 conflict page that never prints the colliding e-mail address"
- "renders the exact same 403 page for a mismatched state and a
  non-allowlisted user"

All pre-existing tests pass unchanged, including the two that already
asserted 403 (`assertForbidden()`) for state-mismatch and not-allowlisted —
neither needed modification, since `refused()` still returns 403.

## Files touched

- `src/Client/Http/Controllers/IdentityCallbackController.php`
- `resources/views/identity/unavailable.blade.php` (new)
- `resources/views/identity/rejected.blade.php` (new)
- `resources/views/identity/conflict.blade.php` (new)
- `resources/views/identity/refused.blade.php` (new)
- `config/user-team-sync.php`
- `tests/TestCase.php`
- `tests/Feature/Client/AuthFlowTest.php`
- `README.md`
