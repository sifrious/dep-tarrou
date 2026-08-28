# Tarrou — working notes

Tarrou owns desired state and approved change. It does not observe, it does not call providers, and
it does not persist anything.

## Boundaries that are not negotiable

- **No provider calls.** If a class here needs an HTTP client, the design is wrong. Observation is
  an Aleph connector's job; the result arrives through `Contracts\ObservedStateReader`.
- **No policy outside `Policy\`.** A rule about what DNS should look like belongs in `DnsPolicy`,
  `StandardRecordSet` or `DomainStanding`, expressed once. Screens and commands read those; they do
  not restate them.
- **No mutation without an approval that covers the plan's hash.** Every path to `Applier::apply()`
  goes through `Approval`.

## Things that will look like bugs and are not

- A changed `A` record content produces a create and a delete, not an update. Repeatable types carry
  content in their identity because two `A` records at one name are two records. Only singular types
  (`CNAME`) update in place.
- A desired state that omits `MX` records never deletes them. `managedTypes` is the fence; it
  defaults to the types actually present in the desired records.
- `Operation::canonical()` deliberately excludes `reason` and `risk`. Rewording an explanation must
  not invalidate an approval.

## Tests

`vendor/bin/pest`. Unit tests need no application; the Feature suite uses Testbench only to prove
the container bindings resolve. `Testing\Fakes\InMemoryZone` is the reference provider — if a change
makes idempotency awkward to express against it, the change is probably wrong.
