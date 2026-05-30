// scripts/workflows/eu_mapping_audit.workflow.js
//
// Layer-2 specialist audit of EU framework mappings.
// Run via the Workflow tool with scriptPath: this file.
// args = [{ framework, specialist, groundTruth, dossierJson, note? }, ...]
//
// Pipelines each EU framework through the correct specialist for a correctness
// audit (2a) + breadth proposal (2b), then adversarially verifies each
// confirmed/proposed finding. Hypotheses skip verify and go to the human queue
// with reasoning. The deterministic Layer-1 dossier is injected as context — the
// agent never recomputes metrics.
export const meta = {
  name: 'eu-mapping-audit',
  description: 'Specialist audit of EU framework mappings (correctness + breadth) with adversarial verify and reasoned human-queue hypotheses',
  phases: [
    { title: 'Audit', detail: 'one specialist per EU framework: correctness + breadth' },
    { title: 'Verify', detail: 'adversarial refute per confirmed/proposed finding' },
  ],
}

const FINDINGS_SCHEMA = {
  type: 'object',
  required: ['confirmed', 'suspect', 'proposed', 'hypotheses'],
  properties: {
    confirmed: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'pct', 'ground_truth_cite'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        pct: { type: 'integer' }, ground_truth_cite: { type: 'string' },
      } } },
    suspect: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'current_pct', 'issue', 'recommended_action'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        current_pct: { type: 'integer' }, issue: { type: 'string' },
        recommended_action: { type: 'string', enum: ['fix_pct', 'remove', 'add_provenance'] },
      } } },
    proposed: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'pct', 'ground_truth_cite'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        pct: { type: 'integer' }, ground_truth_cite: { type: 'string' },
      } } },
    hypotheses: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'hypothesis_pct', 'reasoning', 'uncertainty_reason', 'resolution_hint', 'confidence_band'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        hypothesis_pct: { type: 'integer' }, reasoning: { type: 'string' },
        uncertainty_reason: { type: 'string' }, resolution_hint: { type: 'string' },
        confidence_band: { type: 'string', enum: ['low', 'med'] },
      } } },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  required: ['verdict', 'evidence'],
  properties: {
    verdict: { type: 'string', enum: ['hold', 'refute'] },
    evidence: { type: 'string' },
  },
}

const FRAMEWORKS = typeof args === 'string' ? JSON.parse(args) : args

const results = await pipeline(
  FRAMEWORKS,
  // Stage A — specialist correctness + breadth audit
  fw => agent(
    `FIRST STEP — load domain expertise: invoke the Skill tool with skill='${fw.specialist}' before doing anything else. That skill gives you the regulatory depth (norm clauses, official crosswalks) this audit requires.

You are auditing the **${fw.framework}** cross-framework mappings of an ISMS tool for an EU-compliance critique that said the mappings are too thin and not audit-defensible.

DETERMINISTIC BASELINE (computed by script — treat as ground truth, do NOT recompute):
${fw.dossierJson}

GROUND-TRUTH SOURCE you must cite against: ${fw.groundTruth}
${fw.note ? `\nFRAMEWORK-SPECIFIC NOTE: ${fw.note}\n` : ''}
Two tasks:
(2a) CORRECTNESS: For existing mappings, judge whether each mapping_percentage is defensible against the actual target-norm clause text. Flag wrong/overstated ones as 'suspect' with a recommended_action.
(2b) BREADTH: For the dossier's unmapped requirements, propose high-value mappings that you can ground in the cited source.

HARD RULES:
- Every 'confirmed' and 'proposed' item MUST carry a ground_truth_cite quoting the real clause text or an official published crosswalk. No cite => it is NOT confirmed/proposed.
- If you cannot ground a mapping but have a reasoned guess, put it in 'hypotheses' with hypothesis_pct + reasoning + uncertainty_reason + resolution_hint + confidence_band. NEVER invent a clause id or percentage as fact.
- Use WebSearch against the official source (${fw.groundTruth}) to verify before asserting.
- Default to skepticism. When unsure, hypothesize — do not confirm.`,
    { label: `audit:${fw.framework}`, phase: 'Audit', schema: FINDINGS_SCHEMA }
  ),
  // Stage B — adversarial verify of confirmed + proposed (hypotheses skip to human queue)
  (findings, fw) => {
    const toVerify = [
      ...(findings?.confirmed || []).map(f => ({ ...f, kind: 'confirmed' })),
      ...(findings?.proposed || []).map(f => ({ ...f, kind: 'proposed' })),
    ]
    return parallel(toVerify.map(f => () =>
      agent(
        `FIRST STEP — invoke the Skill tool with skill='${fw.specialist}' to load regulatory depth, then refute.

Adversarially REFUTE this ${fw.framework} mapping. Default verdict: refute unless the cited ground-truth clearly supports it.

Mapping: ${f.source_req} -> ${f.target_req} at ${f.pct}%
Claimed ground-truth: ${f.ground_truth_cite}
Source to re-check: ${fw.groundTruth}

Re-read the cited clause via WebSearch. If the percentage is overstated, the clause does not say what is claimed, or the cite is unverifiable, return verdict 'refute' with evidence. Only 'hold' if the cite genuinely supports the mapping.`,
        { label: `verify:${fw.framework}:${f.source_req}`, phase: 'Verify', schema: VERDICT_SCHEMA }
      ).then(v => ({ ...f, framework: fw.framework, verify: v }))
    )).then(verified => ({ framework: fw.framework, findings, verified }))
  }
)

return results.filter(Boolean)
