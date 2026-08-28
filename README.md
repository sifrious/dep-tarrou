# Tarrou

Tarrou owns desired state and approved change. It answers two questions and refuses the rest:

- **What would have to change** for a zone to match its declared desired state?
- **May that change be carried out**, and did it actually converge?

Tarrou never calls a provider API. Observation belongs to Aleph connectors and accepted history
belongs to Funes; Tarrou reads observed state through a contract and applies operations through a
capability the host supplies. Screens, commands and jobs invoke these contracts; they do not encode
DNS policy.

## Installation

```bash
composer require sifrious/tarrou
php artisan vendor:publish --tag=tarrou-config
```

Tarrou has no migrations and no tables. Plans, approvals and results are values; where they are
persisted is the host's decision until something forces the question.

## Planning

```php
use Sifrious\Tarrou\Plan\Planner;
use Sifrious\Tarrou\Policy\StandardRecordSet;

$desired = (new StandardRecordSet('203.0.113.10'))->desiredState('tryin.gg', $edgeNameservers);
$plan = (new Planner)->plan($desired, $reader->observe('tryin.gg'));

$plan->isConverged();   // nothing to do
$plan->hash;            // sha256; what an approval is bound to
$plan->summary();       // operation counts by risk
```

Three properties are load-bearing:

1. **Determinism.** The same desired and observed states always produce the same operations in the
   same order, and therefore the same hash. Declaration order does not matter; generation time and
   the human-readable reason are excluded from the hash, so re-rendering a plan does not invalidate
   consent already given for it.
2. **No inferred deletion.** A record type the desired state does not manage is never proposed for
   deletion. A desired state that describes only `A` and `CNAME` cannot strip `MX` or `TXT` records
   it has never heard of.
3. **Ordering with a reason.** Creations run before deletions so a replacement never leaves a window
   with no answer. Delegation runs last, because it is the operation whose effect is not immediately
   reversible.

### Risk

Every operation carries a risk label, which is a property of the operation rather than of the
operator's confidence:

| Risk | Meaning |
|---|---|
| `additive` | Nothing that resolves today stops resolving. |
| `replacing` | An existing answer changes; the previous value is captured. |
| `destructive` | An existing answer disappears; recovery means re-creating it. |
| `delegating` | Authority over the zone moves; propagation is not instant. |

A plan containing a destructive or delegating operation cannot be applied by an approval that did
not explicitly confirm that risk.

## Applying

```php
use Sifrious\Tarrou\Apply\{Applier, Approval, Verifier};

$approval = Approval::of($plan, 'mary', confirmedHighRisk: true);
$result = (new Applier)->apply($plan, $approval, $capability);
$report = (new Verifier($reader))->verify($desired, $result);
```

- An approval carries a plan hash, not a plan reference. A plan whose contents changed is refused,
  not silently re-approved.
- Execution halts on the first failure. A partially applied zone with a recorded stopping point is
  recoverable; one where later operations ran against an unexpected state is not.
- Every applied operation records what it replaced, so `ApplyResult::rollbackRecord()` is a rollback
  plan, newest change first.
- Verification re-observes and re-plans. It does not trust the apply result, because the failure it
  exists to catch is a provider that accepts an operation and does not honour it.

## Policy

`Sifrious\Tarrou\Policy` holds the rules from the DNS boundary decision — see
[`docs/decisions/0001-dns-authority.md`](docs/decisions/0001-dns-authority.md).

- `StandardRecordSet` — the record set every new site gets: an apex `A` at the origin address and a
  `www` CNAME to the apex.
- `DnsPolicy` — rejections, not warnings. Flexible TLS in front of a proxied HTTPS origin is
  refused; so is a CNAME at the apex, and a desired state missing its apex or `www` record.
- `DomainStanding` — a domain is eligible for migration only if it demonstrably serves public
  traffic. Eligibility is a positive assertion, never the absence of a reason to skip.

## Testing

`Sifrious\Tarrou\Testing\Fakes\InMemoryZone` implements both the observation contract and the
mutation capability. It is the reference implementation of the idempotency requirement: applying an
operation whose effect is already present reports `already_converged`, and a plan applied twice
changes nothing the second time.
