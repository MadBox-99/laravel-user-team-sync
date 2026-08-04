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

---

# Fix report — review follow-up

## 1. Important: vacuous 503/401 leak tests

The reviewer showed that `assertDontSee('test-client-secret')` on both the 503 and
401 tests measured nothing: that string was never reachable through
`IdentityUnavailableException`/`IdentityRejectedException` in the first place, so
leaking the real exception message into the view left the test green.

Fix: both assertions now check for the exact prefix `IdentityClient` produces for
the fixture each test already sets up:
- 503 test (`Http::fake` returns 500 from the token endpoint, hitting
  `serverError()` in `IdentityClient::send()`): asserts the body does not contain
  `'The identity provider answered with HTTP'`.
- 401 test (`Http::fake` returns 400, hitting `clientError()`): asserts the body
  does not contain `'The identity provider rejected the request with HTTP'`.

This is the fifth instance of this pattern per the coordinator's note, so the fix
targets the actual string the production code would emit under the mutation,
not a plausible-looking but unreachable one.

### Mutation proof — 503 page

Reapplied the reviewer's exact mutation: `catch (IdentityUnavailableException
$mutationException)` now called `unavailable($mutationException->getMessage())`,
and `unavailable()`/the view were changed to accept and print
`mutationDebugMessage`.

```
vendor/bin/pest --filter="shows a 503 retry page"
FAILED  ... > it shows a 503 retry page when the identity provider is unreachable...
Not to contain: The identity provider answered with HTTP
Tests: 1 failed (3 assertions)
```

Reverted the controller and view; re-ran the same filter — passes again, and the
full suite is green (see below).

### Mutation proof — 401 page

Same treatment for `IdentityRejectedException`/`rejected()`:

```
vendor/bin/pest --filter="shows a 401 page"
FAILED  ... > it shows a 401 page when the identity provider rejects the code...
Not to contain: The identity provider rejected the request with HTTP
Tests: 1 failed (3 assertions)
```

Reverted; full suite green afterward.

## 2. Important: timing side channel — documented, not fixed

Per instruction, the timing gap between the two `refused()` callers is **not**
equalised. What changed is the claim itself, in two places:

- `IdentityCallbackController`: the comment at the state-mismatch check and the
  docblock on `refused()` now state plainly that the *response* (status, body,
  headers) is identical, but the two call sites do a different amount of work
  before reaching it — state mismatch returns immediately, not-allowlisted only
  after `exchangeCode()` + `fetchClaims()` — so a network-level observer can
  distinguish them by latency. The docblock also records the accepted-risk
  reasoning: exploiting this needs the attacker's own valid `state`/`code` pair,
  i.e. their own handshake, which does not obviously generalise into enumerating
  *other* people's e-mail addresses against the allowlist, and names what would
  make that stop being true (replayable codes, or reaching the not-allowlisted
  branch without a real handshake).
- `README.md`: the "Callback failure pages" section's claim that the two 403
  causes are indistinguishable was narrowed to "at the response level"
  (previously implied indistinguishable, full stop), and a new paragraph states
  the timing asymmetry, why it's accepted, and what would require revisiting it.

No code path changed for this item — only comments and documentation, as
instructed.

## 3. Minor: `WWW-Authenticate` on 401

Added a `WWW-Authenticate: Bearer realm="<client.identity_url>"` header to the
`rejected()` response (`->header('WWW-Authenticate', 'Bearer realm="..."')`),
satisfying RFC 7235's requirement that a 401 carry a challenge. Chose to add the
header rather than switch to 403: the page is interactive HTML consumed by a
browser, not an API response any client parses or retries against, so the header
is inert for every real caller — there is no browser/proxy behavior it could
trigger (no popup, no automatic retry) — while 401 remains the more accurate code
for "the provider explicitly refused this authentication attempt." Verified with
a new assertion, `assertHeader('WWW-Authenticate', 'Bearer realm="https://identity.test"')`,
using the test fixture's `client.identity_url`.

## Re-run after all three fixes

```
vendor/bin/pest
Tests:    236 passed (557 assertions)
Duration: 6.69s

vendor/bin/pint
{"result":"pass"}
```

Same 236-test count as before (fixes were assertion/documentation changes to
existing tests plus one new header assertion within an existing test, not new
test cases), with 557 assertions (up from 555: the added prefix checks plus the
`WWW-Authenticate` header assertion, net of the two vacuous `assertDontSee`
calls they replaced).

## Files touched (this follow-up)

- `src/Client/Http/Controllers/IdentityCallbackController.php`
- `tests/Feature/Client/AuthFlowTest.php`
- `README.md`
