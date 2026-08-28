# 0001 — DNS authority and the registrar–edge boundary

**Status:** accepted, 2026-08-28.
**Source:** vault `Projects/Cloud/C-07 — DNS and the Namecheap–Cloudflare boundary`.
**Implements:** MME-1559 (TARROU-007).

## Context

Domains are registered at Namecheap. Some use Namecheap DNS; public-facing properties benefit from
Cloudflare's API, CDN, protection and Workers. Which provider is authoritative was decided per
domain, from memory, at the moment each one was set up. That is the actual problem: not that either
choice is wrong, but that the rule was never written down, so no two domains can be compared and no
change can be reviewed.

Roughly seventy-three domains are registered. Most serve nothing.

## Decision

**Authority follows traffic.** A domain that demonstrably serves public traffic is delegated to the
edge provider. Every other domain stays where it is registered.

"Serves public traffic" is a positive assertion with three parts, all required: the domain is
attached to a site, that site is live or committed, and the registration has not expired. This is
`Sifrious\Tarrou\Policy\DomainStanding::servesPublicTraffic()`, and
`AuthoritativeProvider::forStanding()` is the only place the rule is expressed.

### Alternatives considered

- **Registrar DNS everywhere.** Fewer moving parts, already working, no migration. Rejected: it
  forecloses the API-driven automation this whole capability exists to provide, and the Workers
  escape hatch in C-17 depends on the edge provider.
- **Edge DNS everywhere.** One provider, one API, one mental model. Rejected: it means migrating
  seventy-odd zones that serve nothing, each migration carrying delegation risk, to buy nothing.
- **Per-domain judgement, as today.** Rejected: it is the status quo, and the reason a boundary
  ticket exists.

## Standard record set

Every new site gets exactly two records:

| Name | Type | Content | TTL | Proxied |
|---|---|---|---|---|
| apex | `A` | origin address | 3600 | yes |
| `www` | `CNAME` | apex | 3600 | yes |

Anything beyond these two is site-specific and is declared explicitly, not inherited.

## Proxy and TLS

**Proxied HTTPS origins require Full (strict). Flexible is not a supported choice.**

Flexible terminates TLS at the edge and speaks plaintext to the origin. In front of an origin that
redirects HTTP to HTTPS, that produces a redirect loop. This is enforced as a rejection in
`DnsPolicy`, not as a warning, and a desired state that declares proxied records with no TLS mode at
all is rejected for the same reason: the mode must be explicit.

## Migration and rollback checkpoints

1. **Observe.** Capture the current nameservers, records, proxy state and TLS mode. Nothing proceeds
   without a captured prior state.
2. **Plan.** Produce a desired-versus-observed plan. A converged plan ends the procedure.
3. **Review.** The plan is read with its risk labels. Destructive and delegating operations require
   explicit confirmation.
4. **Approve.** Consent is bound to the plan's hash.
5. **Apply.** Records first, delegation last, halting on the first failure.
6. **Verify.** Re-observe and re-plan. A non-empty residual plan means the zone did not converge,
   whatever the provider reported.
7. **Roll back.** `ApplyResult::rollbackRecord()` replays the captured prior state newest-first.

Delegation is the checkpoint that is not instantly reversible: nameserver changes propagate on the
old TTL, so rollback restores authority but not immediately. That is why delegation is ordered last
and labelled `delegating` rather than `replacing`.

## Constraints

- Tarrou never calls a provider API. Every observation arrives through `ObservedStateReader`; every
  mutation goes through a `MutationCapability` the host supplies.
- Mutation is never an ingestion side effect.
- Inactive domains are never selected for migration automatically, no matter how convenient a bulk
  operation would be.

## Open questions

- The origin address is a single value today. When more than one origin exists, the standard record
  set needs a target selector rather than a constant.
- Certificate issuance is out of scope here and is not modelled.
- Registrar-side operations — renewals, privacy, release — are registrar state, not zone state, and
  are not covered by this record.
