# Compliance UI Workflow Design

## Problem Statement
Nutzer haben keinen klaren, klickbaren Workflow um Compliance Frameworks zu managen.

## Ziel
Jeden Nutzer von "Framework laden" bis "100% Compliance" führen - nur mit Klicks, ohne Dokumentation lesen zu müssen.

---

## User Journey Map

### **Journey 1: Neues Framework (z.B. NIS2) hinzufügen**

```
START → Compliance → 📦 Load Framework → Framework Dashboard → Requirements → Controls → DONE
```

#### Detaillierter Flow:

**1. Einstieg: Compliance Overview** (`/de/compliance/`)
```
┌─────────────────────────────────────────────────────┐
│ 🎯 Compliance Frameworks                            │
├─────────────────────────────────────────────────────┤
│                                                     │
│ [⚙️ Manage Frameworks]  [🔗 Cross-Framework]       │
│                                                     │
│ ┌─────────────┐  ┌─────────────┐                  │
│ │ ISO 27001   │  │  GDPR       │                  │
│ │ 100% ✅     │  │  95% 🟡     │                  │
│ └─────────────┘  └─────────────┘                  │
│                                                     │
│ ❌ No frameworks? → [➕ Load Your First Framework]  │
└─────────────────────────────────────────────────────┘
```

**2. Framework Loader** (`/de/admin/compliance`)
```
┌─────────────────────────────────────────────────────┐
│ 📦 Compliance Framework Management                  │
├─────────────────────────────────────────────────────┤
│ [← Back to Overview]                                │
│                                                     │
│ Statistics: 20 Available | 2 Loaded | 18 Not Loaded│
│                                                     │
│ ┌──────────── NIS2 ────────────┐                   │
│ │ Version: 2023                │                   │
│ │ Industry: Critical Infrastructure                │
│ │ Requirements: 82              │                   │
│ │                               │                   │
│ │ [⬇️ Load Framework]           │                   │
│ └───────────────────────────────┘                   │
└─────────────────────────────────────────────────────┘

Click "Load Framework"
  ↓
Loading... (AJAX)
  ↓
✅ Success! Framework loaded with 82 requirements
  ↓
[🎉 Start Working on NIS2 →]  ← NEW BUTTON!
```

**3. Framework Dashboard** (`/de/compliance/framework/{id}`) - **FEHLT!**
```
┌─────────────────────────────────────────────────────┐
│ NIS2 - Network and Information Security Directive 2│
├─────────────────────────────────────────────────────┤
│ [← Back to All Frameworks]                          │
│                                                     │
│ Progress: ███████░░░ 45%                            │
│                                                     │
│ 📋 Workflow Steps:                                  │
│ ┌──────────────────────────────────────────────┐   │
│ │ ✅ 1. Framework Loaded (82 requirements)     │   │
│ │ 🔄 2. Review Requirements (38/82 reviewed)   │   │
│ │ ⏳ 3. Map to Controls (21/82 mapped)         │   │
│ │ ❌ 4. Achieve 100% Compliance (45% done)     │   │
│ └──────────────────────────────────────────────┘   │
│                                                     │
│ Quick Actions:                                      │
│ [📝 View All Requirements]                          │
│ [🔗 Cross-Framework Mappings]                       │
│ [📊 Gap Analysis Report]                            │
│ [📈 Fulfillment Progress]                           │
│                                                     │
│ 🎯 Next Recommended Action:                         │
│ "You have 44 requirements with 0% fulfillment"     │
│ [Start Reviewing Requirements →]                    │
└─────────────────────────────────────────────────────┘
```

**4. Requirements List** (`/de/compliance/requirement/`)
```
┌─────────────────────────────────────────────────────┐
│ 📝 NIS2 Requirements                                │
├─────────────────────────────────────────────────────┤
│ [← Back to NIS2 Dashboard]                          │
│                                                     │
│ Filter: [NIS2 ▼]  Sort: [Fulfillment (Low→High) ▼] │
│                                                     │
│ ┌──────────────────────────────────────────────┐   │
│ │ NIS2-7.1: Network Segmentation               │   │
│ │ Priority: Critical 🔴                        │   │
│ │ Fulfillment: 0% ████████░░░░░░░░             │   │
│ │ [👁️ View] [✏️ Edit] [➕ Add Control]        │   │
│ └──────────────────────────────────────────────┘   │
│ │ NIS2-6.1: Supply Chain Security              │   │
│ │ Priority: High 🟡                            │   │
│ │ Fulfillment: 20% ████░░░░░░░░░░░░            │   │
│ │ [👁️ View] [✏️ Edit] [➕ Add Control]        │   │
│ └──────────────────────────────────────────────┘   │
│                                                     │
│ 💡 Tip: Start with Critical (🔴) requirements first!│
└─────────────────────────────────────────────────────┘
```

**5. Requirement Detail** (`/de/compliance/requirement/{id}`)
```
┌─────────────────────────────────────────────────────┐
│ NIS2-7.1: Network Segmentation                      │
├─────────────────────────────────────────────────────┤
│ [← Back to Requirements] [NIS2 Dashboard]           │
│                                                     │
│ Priority: Critical 🔴                               │
│ Category: Technical Measures                        │
│                                                     │
│ Description:                                        │
│ "Implement network segmentation to separate        │
│  critical systems from less critical ones..."      │
│                                                     │
│ ┌──────── Fulfillment Status ────────┐             │
│ │ Current: 0%                        │             │
│ │                                     │             │
│ │ Quick Update:                       │             │
│ │ [Slider: 0% ─────────── 100%]      │             │
│ │ ☐ Applicable                        │             │
│ │ [💾 Quick Save]                     │             │
│ └─────────────────────────────────────┘             │
│                                                     │
│ 🔗 Mapped Controls (0):                             │
│ ❌ No controls mapped yet!                          │
│                                                     │
│ 💡 Suggestions:                                     │
│ "This requirement is similar to ISO 27001 A.8.30"  │
│ [🔗 View ISO 27001 A.8.30]                          │
│ [➕ Create New Control for this Requirement]        │
│                                                     │
│ ──────────────────────────────────────              │
│ Next Requirement: NIS2-7.2 →                        │
└─────────────────────────────────────────────────────┘
```

**6. Create Control** (`/de/soa/new` or `/de/compliance/requirement/{id}/add-control`)
```
┌─────────────────────────────────────────────────────┐
│ ➕ Create Control for NIS2-7.1                      │
├─────────────────────────────────────────────────────┤
│                                                     │
│ Control ID: [AUTO: NIS2-CTL-7.1]                    │
│ Name: [Network Segmentation Implementation]        │
│ Description: [Implementation of network zones...]   │
│                                                     │
│ Implementation Status: [Not Started ▼]              │
│ Implementation %: [0%]                              │
│                                                     │
│ ☑ Automatically map to NIS2-7.1                     │
│                                                     │
│ Cross-Framework Mapping:                            │
│ ☑ Also maps to ISO 27001 A.8.30                     │
│                                                     │
│ [💾 Save & Return] [💾 Save & Add Another]          │
└─────────────────────────────────────────────────────┘
```

---

### **Journey 2: Existierendes Framework verwalten**

**Start:** Compliance Overview → Click Framework Card → Framework Dashboard

---

### **Journey 3: Von ISO 27001 zu NIS2**

**Start:** Compliance Overview → NIS2 Dashboard

```
NIS2 Dashboard shows:
  "You can reuse 75% of your ISO 27001 controls!"
  [🔗 View Cross-Framework Mappings]
  [🚀 Auto-Map ISO Controls to NIS2]
```

Click "Auto-Map" →
```
✅ Mapped 62 of 82 NIS2 requirements to existing ISO controls
⏳ 20 requirements still need attention
[📊 View Gap Analysis Report]
```

---

## Required UI Components

### **Component 1: Framework Dashboard** (NEU!)
- **Route:** `/de/compliance/framework/{id}`
- **Controller:** `ComplianceController::frameworkDashboard()`
- **Template:** `templates/compliance/framework/dashboard.html.twig`

**Features:**
- Progress visualization
- Workflow steps checklist
- Quick actions
- Next recommended action
- Statistics cards

### **Component 2: Enhanced Requirements List**
- **Add:** "Back to Dashboard" button
- **Add:** "Add Control" quick action per requirement
- **Add:** Cross-framework suggestions

### **Component 3: Enhanced Requirement Detail**
- **Add:** Quick fulfillment update slider
- **Add:** "Create Control" button
- **Add:** Cross-framework mapping suggestions
- **Add:** "Next/Previous Requirement" navigation

### **Component 4: Control Creation Wizard**
- **Add:** Framework-aware control creation
- **Add:** Auto-mapping checkbox
- **Add:** Cross-framework mapping options

### **Component 5: Progress Widget** (Reusable)
```twig
{% include '_components/_compliance_progress.html.twig' with {
    framework: framework,
    show_steps: true
} %}
```

### **Component 6: Next Action Card** (Reusable)
```twig
{% include '_components/_next_action.html.twig' with {
    framework: framework
} %}
```

---

## Implementation Priority

### Phase 1: Critical Path (2-3 hours)
1. ✅ Framework Dashboard page
2. ✅ "Start Working" button after framework load
3. ✅ Quick fulfillment update in requirement detail
4. ✅ "Add Control" button in requirements list

### Phase 2: Guided Experience (1-2 hours)
5. ✅ Progress visualization component
6. ✅ Next action recommendations
7. ✅ Breadcrumb navigation
8. ✅ Workflow steps checklist

### Phase 3: Advanced (1-2 hours)
9. ✅ Auto-mapping wizard
10. ✅ Cross-framework suggestions
11. ✅ Gap analysis integration
12. ✅ Fulfillment timeline

---

## User Testing Scenarios

### Scenario 1: Complete Beginner
"I want to comply with NIS2 but have no idea where to start"

Expected Flow:
1. See empty Compliance page
2. Click "Load Your First Framework"
3. See all frameworks, pick NIS2
4. Click "Load Framework"
5. See "Start Working on NIS2" button
6. Click → Land on Framework Dashboard
7. See clear steps: "1. Review Requirements"
8. Click "View All Requirements"
9. See list sorted by priority
10. Click first requirement
11. See "Create Control" button
12. Fill form, save
13. See fulfillment update to 1.2%
14. See "Next Requirement" button
15. Continue...

### Scenario 2: ISO 27001 User adding NIS2
"I have ISO 27001, now I need NIS2 too"

Expected Flow:
1. See Compliance page with ISO card (100%)
2. Click "Manage Frameworks"
3. See NIS2, click "Load"
4. Click "Start Working on NIS2"
5. Dashboard shows: "Reuse 75% of ISO controls"
6. Click "Auto-Map Controls"
7. See progress jump to 75%
8. See "20 gaps remaining"
9. Click "View Gaps"
10. Work through gaps...

### Scenario 3: Quick Update
"I just implemented a control, update fulfillment"

Expected Flow:
1. Compliance → NIS2 Dashboard
2. See "45% fulfillment"
3. Click "View Requirements"
4. Find requirement
5. Click requirement
6. Use quick update slider: 0% → 100%
7. Check "Applicable"
8. Click "Quick Save"
9. See toast: "Fulfillment updated!"
10. See next requirement button
11. Continue...

---

## Success Metrics

- ✅ User can go from "no frameworks" to "first requirement reviewed" in < 5 clicks
- ✅ User can update fulfillment without opening "Edit" page
- ✅ User always knows "what's next"
- ✅ User can see overall progress at any time
- ✅ No dead ends - every page has "Next" button

---

## Technical Notes

### New Routes Needed
```php
#[Route('/compliance/framework/{id}', name: 'app_compliance_framework_dashboard')]
#[Route('/compliance/framework/{id}/auto-map', name: 'app_compliance_framework_automap')]
#[Route('/compliance/requirement/{id}/create-control', name: 'app_compliance_requirement_create_control')]
```

### New Services Needed
```php
ComplianceWorkflowService::getNextRecommendedAction(Framework)
ComplianceWorkflowService::getProgressSteps(Framework)
ComplianceAutoMapService::autoMapFrameworks(source, target)
```

### Database Changes
None required - all data structures exist!

---

## Design Mockups Reference

Color Coding:
- 🔴 Critical Priority
- 🟡 High Priority
- 🟢 Medium/Low Priority
- ✅ Completed
- 🔄 In Progress
- ⏳ Not Started
- ❌ Gap/Missing
