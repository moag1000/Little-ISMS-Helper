#!/usr/bin/env python3
"""
Deterministic parser for the official BSI IT-Grundschutz-Kompendium 2023 DocBook XML.

Source (official, machine-readable):
  https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/IT-GS-Kompendium/XML_Kompendium_2023.xml
  (DocBook 5.0 XML, "XML-Version des IT-Grundschutz-Kompendiums (Edition 2023)")

It reads the XML and emits one layer YAML per BSI layer, driven off the OFFICIAL
111-Baustein inventory of the 2023 Kompendium (baustein_inventory.json -> "official"),
NOT the old src fixture list (which contained 7 phantom Bausteine and missed CON.11.1).

For each official Baustein it emits:
  - id + title (verbatim from the official inventory / XML),
  - description: reuse the old src fixture description if that Baustein id existed
    in src; otherwise derive a 1-line description from the XML Baustein "Zielsetzung"
    (fallback: "Einleitung") intro paragraph (first sentence),
  - basis/standard/hoch Anforderungen parsed verbatim from the authoritative XML.

Phantom Bausteine NOT in the official 2023 XML are dropped (CON.4, CON.5, CON.11,
OPS.1.2.1, OPS.1.2.3, OPS.2.1, OPS.3.1). The official Baustein the old fixture
missed (CON.11.1) is included.

The layer-level title/description and the file header comments are preserved from the
EXISTING catalogue fixtures (fixtures/library/catalogues/bsi-it-grundschutz-2023/<LAYER>.yml).

NO hand-transcription, NO LLM-generated requirement text. All Anforderung titles and
requirement texts come verbatim from the XML <para> nodes (whitespace-normalised).
Real umlauts from the XML are preserved. ENTFALLEN/withdrawn requirements are skipped.
"""
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

XML_PATH = "/tmp/gs/XML_Kompendium_2023.xml"
SRC_TMPL = "/tmp/gs/src_{}.yml"
INVENTORY_PATH = "/tmp/gs/baustein_inventory.json"
OUT_TMPL = "/tmp/gs/out_{}.yml"

# Repo-root-relative location of the catalogue fixtures we (re)generate. We also
# read the existing fixture to preserve the layer-level title/description + header.
REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
FIXTURE_TMPL = os.path.join(
    REPO_ROOT, "fixtures", "library", "catalogues", "bsi-it-grundschutz-2023", "{}.yml"
)

LAYERS = ["ISMS", "ORP", "CON", "OPS", "DER", "APP", "SYS", "IND", "NET", "INF"]

DB = "{http://docbook.org/ns/docbook}"

# tier section heading -> tier key.  Note: a handful of Bausteine use the
# singular form ("Basis-Anforderung") instead of the plural, so we classify
# headings by prefix rather than exact match.
def heading_to_tier(title):
    t = (title or "").strip()
    if t.startswith("Basis-Anforderung"):
        return "basis"
    if t.startswith("Standard-Anforderung"):
        return "standard"
    if t.startswith("Anforderungen bei erhöhtem Schutzbedarf"):
        return "hoch"
    return None

# Anforderung id pattern: LAYER(.sub)*.A<num>  e.g. ORP.4.A1, OPS.1.1.3.A12
ANF_ID_RE = re.compile(r'^([A-Z]+(?:\.\d+)+\.A\d+)\b')


def local(tag):
    return tag.split('}', 1)[-1] if '}' in tag else tag


def text_of_paras(section_el):
    """Concatenate the direct-child <para> text of a section, whitespace-normalised."""
    parts = []
    for child in section_el:
        if local(child.tag) == "para":
            # itertext() to capture text inside <emphasis> etc.
            txt = "".join(child.itertext())
            txt = re.sub(r'\s+', ' ', txt).strip()
            if txt:
                parts.append(txt)
    return " ".join(parts).strip()


def clean_anf_title(raw):
    """Strip the trailing tier marker '(B)/(S)/(H)' and the role brackets '[...]'.
    Keep the human title only.  Input e.g.
      'ORP.4.A1 Regelung ... von Benutzenden und Benutzendengruppen (B) [IT-Betrieb]'
    -> ('ORP.4.A1', 'Regelung ... von Benutzenden und Benutzendengruppen')
    """
    raw = re.sub(r'\s+', ' ', raw).strip()
    m = ANF_ID_RE.match(raw)
    anf_id = m.group(1)
    rest = raw[m.end():].strip()
    # remove trailing role brackets (may be multiple), then trailing tier marker
    # iterate stripping from the end
    changed = True
    while changed:
        changed = False
        rb = re.search(r'\s*\[[^\]]*\]\s*$', rest)
        if rb:
            rest = rest[:rb.start()].rstrip()
            changed = True
        tb = re.search(r'\s*\((?:B|S|H)\)\s*$', rest)
        if tb:
            rest = rest[:tb.start()].rstrip()
            changed = True
    return anf_id, rest.strip()


def find_layer_chapters(root):
    """Return dict layer-code -> chapter element for the 10 ISMS/ORP/... chapters."""
    out = {}
    for chap in root.iter(DB + "chapter"):
        title_el = chap.find(DB + "title")
        if title_el is None or not title_el.text:
            continue
        code = title_el.text.strip().split()[0]
        if code in LAYERS:
            out[code] = chap
    return out


def iter_sections(parent):
    """Yield direct-child <section> elements."""
    for child in parent:
        if local(child.tag) == "section":
            yield child


def section_title(sec):
    t = sec.find(DB + "title")
    if t is None:
        return ""
    return re.sub(r'\s+', ' ', "".join(t.itertext())).strip()


def parse_baustein(bsec, baustein_id):
    """Given a Baustein-level <section>, return {basis:[],standard:[],hoch:[]}."""
    result = {"basis": [], "standard": [], "hoch": []}
    # The tier sections ('Basis-Anforderungen' etc.) live under the
    # 'Anforderungen' subsection (or sometimes directly). Search recursively
    # for tier-heading sections that belong to THIS baustein.
    for sec in bsec.iter(DB + "section"):
        title = section_title(sec)
        tier = heading_to_tier(title)
        if tier is None:
            continue
        # collect anforderung sub-sections directly under this tier section
        for anf in iter_sections(sec):
            atitle = section_title(anf)
            m = ANF_ID_RE.match(atitle)
            if not m:
                continue
            anf_id = m.group(1)
            # ensure it belongs to this baustein (prefix match on baustein id)
            if not anf_id.startswith(baustein_id + ".A"):
                continue
            _, clean_title = clean_anf_title(atitle)
            req_text = text_of_paras(anf)
            # skip withdrawn requirements (ENTFALLEN) -> not actionable content
            if clean_title.upper() == "ENTFALLEN" or req_text.upper().startswith("DIESE ANFORDERUNG IST ENTFALLEN"):
                continue
            result[tier].append({
                "id": anf_id,
                "title": clean_title,
                "requirement_text": req_text,
            })
    return result


def _first_sentence(text, max_len=300):
    """Return the first sentence of a paragraph (up to the first '. '), trimmed."""
    text = re.sub(r'\s+', ' ', text).strip()
    if not text:
        return ""
    # split on sentence boundary; keep it conservative
    m = re.search(r'(?<=[.!?])\s', text)
    sent = text[:m.start() + 1].strip() if m else text
    if len(sent) > max_len:
        sent = sent[:max_len].rsplit(' ', 1)[0].rstrip() + ' …'
    return sent


def baustein_description(bsec):
    """Derive a 1-line description from the XML Baustein 'Beschreibung' block.

    Prefer the 'Zielsetzung' subsection intro, fall back to 'Einleitung', then to
    any first <para> under 'Beschreibung'. Returns '' if nothing found.
    """
    if bsec is None:
        return ""
    beschreibung = None
    for sec in iter_sections(bsec):
        if section_title(sec) == "Beschreibung":
            beschreibung = sec
            break
    if beschreibung is None:
        return ""
    # gather candidate sub-sections by title
    subs = {section_title(s): s for s in iter_sections(beschreibung)}
    for key in ("Zielsetzung", "Einleitung"):
        if key in subs:
            txt = text_of_paras(subs[key])
            if txt:
                return _first_sentence(txt)
    # fallback: first para directly under Beschreibung
    txt = text_of_paras(beschreibung)
    if txt:
        return _first_sentence(txt)
    # fallback: first sub-section's paras
    for s in iter_sections(beschreibung):
        txt = text_of_paras(s)
        if txt:
            return _first_sentence(txt)
    return ""


def build_baustein_index(chap, layer):
    """Map baustein-id -> Baustein-level <section> element.

    A Baustein-level section is one whose title starts with the layer code and a
    dotted number, but is NOT an Anforderung (no '.A<num>') and NOT a tier heading.
    We additionally require it to contain at least one tier-heading section
    descendant, which uniquely identifies real Bausteine.
    """
    index = {}
    for sec in chap.iter(DB + "section"):
        title = section_title(sec)
        # candidate baustein id = leading token like ORP.4 or OPS.1.1.3
        m = re.match(r'^([A-Z]+(?:\.\d+)+)(?:\s|$)', title)
        if not m:
            continue
        cand = m.group(1)
        if not cand.startswith(layer + ".") and cand != layer:
            continue
        if ANF_ID_RE.match(title):  # it's an Anforderung, skip
            continue
        # must contain a tier heading somewhere inside to be a real baustein
        has_tier = any(
            heading_to_tier(section_title(s)) is not None
            for s in sec.iter(DB + "section")
        )
        if not has_tier:
            continue
        # prefer the OUTERMOST section for a given id (first seen via document order
        # is outermost because iter() is depth-first pre-order)
        if cand not in index:
            index[cand] = sec
    return index


# ----------------------------- YAML emission --------------------------------
def yq(s):
    """Single-quote a YAML scalar, escaping embedded single quotes."""
    return "'" + s.replace("'", "''") + "'"


def _header_comment_lines(fixture_doc_path):
    """Read the leading '#'-comment block of an existing fixture, verbatim.

    Returns a list of comment lines (without trailing newline). Falls back to the
    canonical XML-source header if the fixture is missing or has no comment block.
    """
    fallback = [
        "# BSI IT-Grundschutz-Kompendium 2023",
        "# Quelle: BSI XML-Version des IT-Grundschutz-Kompendiums (Edition 2023)",
        "# https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/IT-GS-Kompendium/XML_Kompendium_2023.xml",
    ]
    if not os.path.exists(fixture_doc_path):
        return fallback
    out = []
    with open(fixture_doc_path, encoding="utf-8") as fh:
        for line in fh:
            if line.startswith("#"):
                out.append(line.rstrip("\n"))
            elif line.strip() == "":
                # allow a blank line only if it's still inside the leading block
                if out:
                    break
                continue
            else:
                break
    return out or fallback


def emit_layer_yaml(header_lines, layer_meta, bausteine, layer):
    """Render the layer YAML.

    header_lines: list of '#' comment lines preserved from the existing fixture.
    layer_meta:   dict with 'layer', 'title', 'description' (preserved from fixture).
    bausteine:    ordered list of dicts {id, title, description, tiers}.
    """
    lines = list(header_lines)
    lines.append("")
    lines.append("layer: %s" % layer_meta["layer"])
    lines.append("title: %s" % yq(layer_meta["title"]))
    lines.append("description: %s" % yq(layer_meta["description"]))
    lines.append("bausteine:")
    for b in bausteine:
        lines.append("  - id: %s" % b["id"])
        lines.append("    title: %s" % yq(b["title"]))
        lines.append("    description: %s" % yq(b["description"]))
        lines.append("    anforderungen:")
        tiers = b["tiers"]
        for tier in ("basis", "standard", "hoch"):
            items = tiers.get(tier, [])
            if not items:
                lines.append("      %s: []" % tier)
                continue
            lines.append("      %s:" % tier)
            for it in items:
                lines.append("        - id: %s" % it["id"])
                lines.append("          title: %s" % yq(it["title"]))
                lines.append("          requirement_text: %s" % yq(it["requirement_text"]))
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def main():
    import yaml
    from collections import defaultdict

    tree = ET.parse(XML_PATH)
    root = tree.getroot()
    chapters = find_layer_chapters(root)

    inventory = json.load(open(INVENTORY_PATH, encoding="utf-8"))
    official = inventory["official"]  # {baustein_id: title} — the 111 authoritative Bausteine

    # group official Bausteine by layer, preserving inventory order
    official_by_layer = defaultdict(list)
    for bid in official:
        official_by_layer[bid.split(".")[0]].append(bid)

    report = {}
    for layer in LAYERS:
        # preserve layer-level meta + header comments from the EXISTING fixture
        fixture_path = FIXTURE_TMPL.format(layer)
        header_lines = _header_comment_lines(fixture_path)
        existing = {}
        if os.path.exists(fixture_path):
            existing = yaml.safe_load(open(fixture_path, encoding="utf-8")) or {}
        layer_meta = {
            "layer": existing.get("layer", layer),
            "title": existing.get("title", layer),
            "description": existing.get("description", ""),
        }
        # build a lookup of old src descriptions by baustein id (to reuse where present)
        src_desc = {}
        try:
            src_doc = yaml.safe_load(open(SRC_TMPL.format(layer), encoding="utf-8"))
            for b in src_doc.get("bausteine", []):
                src_desc[b["id"]] = b.get("description", "")
        except FileNotFoundError:
            pass

        chap = chapters.get(layer)
        if chap is None:
            print("WARN: no XML chapter for layer %s" % layer, file=sys.stderr)
            continue
        bindex = build_baustein_index(chap, layer)

        bausteine = []
        for bid in official_by_layer[layer]:
            bsec = bindex.get(bid)
            tiers = parse_baustein(bsec, bid) if bsec is not None else {"basis": [], "standard": [], "hoch": []}
            # description: reuse old src description if present, else derive from XML
            desc = src_desc.get(bid, "").strip()
            if not desc:
                desc = baustein_description(bsec)
            bausteine.append({
                "id": bid,
                "title": official[bid],
                "description": desc,
                "tiers": tiers,
            })

        out = emit_layer_yaml(header_lines, layer_meta, bausteine, layer)
        open(OUT_TMPL.format(layer), "w", encoding="utf-8").write(out)
        open(fixture_path, "w", encoding="utf-8").write(out)
        report[layer] = bausteine

    return report


if __name__ == "__main__":
    rep = main()
    # summary
    tb = ts = th = 0
    print("layer  bausteine  basis  standard  hoch")
    for layer in LAYERS:
        bs = rep.get(layer, [])
        b = sum(len(x["tiers"]["basis"]) for x in bs)
        s = sum(len(x["tiers"]["standard"]) for x in bs)
        h = sum(len(x["tiers"]["hoch"]) for x in bs)
        tb += b
        ts += s
        th += h
        print("%-6s %9d %6d %9d %5d" % (layer, len(bs), b, s, h))
    total_b = sum(len(rep.get(l, [])) for l in LAYERS)
    print("TOTAL  %9d %6d %9d %5d   (anforderungen=%d)" % (total_b, tb, ts, th, tb + ts + th))
    print("done")
