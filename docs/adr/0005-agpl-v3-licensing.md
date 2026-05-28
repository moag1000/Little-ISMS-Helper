# ADR-0005: AGPL-3.0 Copyleft Licensing

**Status:** Accepted  
**Date:** 2025-09-01 (retroactive documentation)  
**Deciders:** moag1000  
**Tags:** licensing, legal, open-source, market-positioning

---

## Context

When preparing the first public release, four licence categories were evaluated:

| Category | Representative licence | Key property |
|---|---|---|
| Permissive | MIT, Apache-2.0 | Anyone can take, close-source, and sell without attribution |
| Weak copyleft | LGPL-2.1 | Libraries can be used in proprietary products; modifications to the library itself must be shared |
| Strong copyleft | GPL-3.0 | Binaries must ship corresponding source; network distribution not covered |
| Network copyleft | **AGPL-3.0** | GPL-3.0 + anyone offering the software **as a service** over a network must publish source |

### Market-positioning considerations

Little ISMS Helper competes in a space dominated by expensive SaaS platforms (five-digit annual
contracts). The value proposition for SME and DACH public-sector customers is:
- No SaaS lock-in
- Self-hosted, data stays on-premise (important for NIS2-critical and BSI-KRITIS operators)
- Open governance — customers can audit the code themselves

MIT/Apache-2.0 would allow a hosting provider to white-label the software as a closed SaaS product
and undercut the project without contributing back. This would drain the competitive advantage the
open-source positioning creates while providing no community benefit.

GPL-3.0 has the same problem for SaaS use: the "distribution" trigger does not cover running the
software over a network without distributing binaries.

**AGPL-3.0 closes the SaaS loophole**: any operator who exposes the application to users over a
network must publish their modifications under the same licence. This keeps derivative SaaS offerings
open and forces competitive differentiation to happen through hosting quality and support, not
through code forking.

### Dependency compatibility

All direct runtime dependencies in `composer.json` are licence-compatible with AGPL-3.0:
- Symfony (MIT) — permissive, compatible
- Doctrine (MIT) — permissive, compatible
- API Platform (MIT) — permissive, compatible
- Bootstrap (MIT) — permissive, compatible
- PHPUnit (BSD-3-Clause, dev-only) — compatible in dev context

AGPL-3.0 does not impose obligations on end-users who run the unmodified software for their own
internal compliance purposes (ISO 27001, GDPR, etc.). The licence only triggers upon distribution
or network provision of a modified version.

---

## Decision

**License the project under GNU Affero General Public License v3.0 (AGPL-3.0).**

`LICENSE` file in the repository root contains the full AGPL-3.0 text. `CITATION.cff` and
`composer.json` carry `"license": "AGPL-3.0-only"`. All new source files include the standard
AGPL header comment.

Dual licensing (AGPL + commercial) is not currently offered but remains a structural option: the
sole contributor holds all copyright, which makes CLA-free dual licensing feasible if a commercial
licence tier is introduced later.

---

## Consequences

### Positive

- **SaaS protection:** Prevents proprietary white-labelling of the project on competitive hosting
  platforms without contributing back.
- **Trust signal:** DACH public-sector and NIS2-regulated operators consider AGPL-3.0 a strong
  "no vendor lock-in" signal. Code is auditable and forkable.
- **Community return:** If a consultancy adds GDPR-Art. 35 workflow improvements to a customer
  deployment, AGPL-3.0 requires them to publish those changes. This feeds back to the upstream.

### Negative

- **Enterprise integration friction:** Some enterprise procurement policies prohibit AGPL-3.0
  software in internal toolchains due to network-copyleft concerns (even for internal-only use
  where the trigger would not fire). This may slow adoption in large enterprises.
- **Contributor CLA consideration:** External contributors who submit substantial PRs implicitly
  grant AGPL-3.0 rights only. If dual-licensing is introduced later, a CLA or DCO process must be
  established retroactively.
- **CITATION.cff field gap:** The `CITATION.cff` file lacks a formal `license-url` field. This
  should be added to comply with REUSE specification standards.

---

## What This Means for Contributors

- Code you contribute is licenced AGPL-3.0 the moment it merges to `main`.
- You retain copyright in your contributions; you grant the project a perpetual AGPL-3.0 licence.
- If you deploy a modified version for internal use only (no network provision), no source
  publication is required.
- If you deploy a modified version as a service accessible to users over a network, you must
  publish the corresponding modified source under AGPL-3.0.

---

## References

- `LICENSE` — full AGPL-3.0 text
- `composer.json` — `"license": "AGPL-3.0-only"`
- `CITATION.cff` — metadata
- [AGPL-3.0 full text](https://www.gnu.org/licenses/agpl-3.0.html)
- [SPDX identifier AGPL-3.0-only](https://spdx.org/licenses/AGPL-3.0-only.html)
- [REUSE Specification](https://reuse.software/spec/) — for future SPDX header compliance
