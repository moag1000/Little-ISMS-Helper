# Policy-Wizard demo fixtures

Realistic demo content for exercising the Policy-Wizard end-to-end in
QA, training, and customer-demo scenarios. Three fixture files in this
directory:

| File | Purpose |
|---|---|
| `demo_tenant.yaml` | Mid-sized DACH industrial holding + DORA-scope subsidiary, with realistic legal name, branding and DPO/CISO/ISB assignments |
| `demo_wizard_run_iso.yaml` | Completed ISO 27001 wizard run with all 7 steps populated (full mode, no addons) |
| `demo_wizard_run_dora_addon.yaml` | Same parent group, regulated subsidiary, ISO 27001 + DORA addon enabled (Step 5b populated) |

## Loading the fixtures

These fixtures are reference YAML, not Doctrine fixtures. They document
the **shape** of a populated tenant + wizard run for use as:

1. Inputs to manual QA (paste into wizard step forms)
2. Reference data for screenshot generation
   (`npm run screenshots` with persona-driven YAML)
3. Training material for ops engineers and customer-success
4. Sanity-check templates for `app:policy-wizard:seed-bundles`
   verification

To load them as live data into a dev environment, use the
`app:policy-wizard:demo:load` command (TODO: implement in W7-G if
needed; current wizard supports manual entry from these YAMLs).

## Manual setup walkthrough

```bash
# 1. Create the demo tenant
php bin/console app:tenant:create \
    --name="Demo Industrial Holding" \
    --short-name=demo-industrial \
    --legal-name="Demo Industrial Holding GmbH"

# 2. Seed required PolicyTemplate rows (one-time per environment)
php bin/console app:policy-wizard:seed-bsi
php bin/console app:policy-wizard:seed-bcm
php bin/console app:policy-wizard:seed-dora
php bin/console app:policy-wizard:seed-privacy
php bin/console app:policy-wizard:seed-bundles

# 3. Open the wizard
# → /{locale}/policy-wizard
# → choose "Vollstaendiger Lauf" (full mode)

# 4. Use values from demo_wizard_run_iso.yaml as the input cribsheet
#    Each "inputs:" sub-key in the YAML maps to one wizard step.
```

For the DORA addon scenario:
- Create the subsidiary (`demo-finserv`) as child tenant of
  `demo-industrial`
- Set `dora_scope=inscope` on the subsidiary
- Run the wizard with the subsidiary tenant context active
- Use `demo_wizard_run_dora_addon.yaml` as the input cribsheet
- Step 5b will be visible because `dora_scope=inscope`

## Persona summary

The two demo wizard runs cover the most common deployment patterns:

| Run | Persona | Standards | Complexity |
|---|---|---|---|
| `demo_wizard_run_iso.yaml` | Industrial / non-regulated | ISO 27001 only | low (single-tenant, single-standard) |
| `demo_wizard_run_dora_addon.yaml` | Regulated FinServ subsidiary in a holding | ISO 27001 + DORA addon | medium-high (Konzern push-down, dual-signoff, Step 5a/5b split) |

Add a third fixture (`demo_wizard_run_full_stack.yaml`) when needed:
ISO + DORA + BSI + BCM + Privacy quintuple stack with ~52 documents.
Deferred until W8 to keep maintenance overhead reasonable.

## License & attribution

Demo content is intentionally generic and not based on any real
organisation. Use freely for product demos, training materials, and
internal QA. Any apparent similarity to existing companies is
coincidental.
