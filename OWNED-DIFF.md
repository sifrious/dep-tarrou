# Owned diff

Deviations from a bare `composer init` package. Every entry is a loan; the register says who
services it.

## No database layer — 2026-08-28, MME-1559

SEAM: none authored. The package ships no migrations, no models, and no `illuminate/database` usage,
despite requiring the package for the host's benefit.

PAYS WHEN: nothing. It is the absence of a cost.

CHARGES WHEN: a plan or an approval has to outlive the process that made it — a plan reviewed in one
session and applied in another, or an audit trail of who approved what. At that point the persistence
question is real and gets its own trigger and its own tables.

TRIGGER: not fired. Plans and approvals are values; the first host composes them within one
invocation. Recording a table before the second invocation exists would be building the abstraction
before the second consumer.

## Risk as an enum rather than a boolean — 2026-08-28, MME-1567

SEAM: authored — the boundary between what an operation costs if it is wrong and how a review
surface groups it.

PAYS WHEN: a review screen must separate "this adds a record" from "this moves authority over the
zone" without re-deriving the difference. Two consumers already need it: the plan summary and the
approval's high-risk confirmation gate.

CHARGES WHEN: a fifth risk category appears and the four cases stop partitioning cleanly.

TRIGGER: fired now — MME-1570 requires create, preserve, destructive and nameserver operations to
carry explicit risk labels, and the approval gate needs to distinguish two of them from the others.

## Approval bound to a hash rather than to a plan object — 2026-08-28, MME-1564

SEAM: authored — the boundary between consent and the plan consent was given for.

PAYS WHEN: a plan is regenerated between review and apply, which happens whenever the observed state
is re-read. A hash makes the stale-approval case a refusal instead of a silent application of
something nobody read.

CHARGES WHEN: a plan's canonical form has to change, invalidating every approval in flight. The hash
is versioned (`version: 1`) so the change is at least detectable.

TRIGGER: fired now — MME-1570 requires approval captured for an exact plan hash.

## Conflicts and freshness as refusals — 2026-08-28, MME-1567 / MME-1564

SEAM: authored — the boundary between "these operations are plausible" and "the evidence behind them is good enough to act on".

PAYS WHEN: an observation did not finish, a TLS mode could not be read, or a plan sat for an hour between review and apply. Each of those is a case where the operations look right and applying them is guessing.

CHARGES WHEN: a legitimate apply is refused because an observation aged out, and the operator has to re-observe and re-approve. That friction is the price and it is paid every time.

TRIGGER: fired now — MME-1567 requires conflicts and incomplete observations to block application, and MME-1564 requires stale observations to invalidate a plan. Neither can be expressed as a warning without making them optional.
