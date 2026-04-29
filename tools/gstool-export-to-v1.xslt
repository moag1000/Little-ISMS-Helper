<?xml version="1.0" encoding="UTF-8"?>
<!--
  GSTOOL XML-Export → gstool_xml_v1 transform.

  Real GSTOOL exports use SQL-Server-table-shaped XML:
  - <NZIELOBJEKT>      (target objects)
  - <NTYP>             (target object type catalogue)
  - <NZB>              (Schutzbedarf records, may be inline on NZIELOBJEKT)
  - <MOD_BAUSTEIN>     (modelled-baustein assignments per Zielobjekt)
  - <MB_BAUSTEIN>      (Baustein catalogue)
  - <MOD_MASSNAHME>    (per-Zielobjekt measure status records)
  - <MB_MASSNAHME>     (Maßnahmen catalogue)
  - <RA_*>             (risk-analysis records)

  Field names vary slightly between GSTOOL-Export-Tool versions
  (4.x vs 5.x) — adjust the @match attributes if your export uses
  different prefixes. Common variations are noted inline.

  Output: docs/features/GSTOOL_IMPORT.md schema (gstool_xml_v1).

  Run with PHP:
    php tools/gstool-transform.php /path/to/real-gstool.xml > out.xml

  Or with command-line xsltproc:
    xsltproc tools/gstool-export-to-v1.xslt real-gstool.xml > out.xml
-->
<xsl:stylesheet
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    version="1.0">

    <xsl:output method="xml" indent="yes" encoding="UTF-8"/>
    <xsl:strip-space elements="*"/>

    <!-- Schutzbedarf-Werte sind in GSTOOL meist numerisch (1=normal,
         2=hoch, 3=sehr hoch) — map zurück auf Worte für die
         menschen-lesbare v1-Form. -->
    <xsl:template name="schutzbedarf-text">
        <xsl:param name="value"/>
        <xsl:choose>
            <xsl:when test="$value = '1' or $value = '0'">normal</xsl:when>
            <xsl:when test="$value = '2'">hoch</xsl:when>
            <xsl:when test="$value = '3'">sehr hoch</xsl:when>
            <xsl:when test="$value = '4'">sehr hoch</xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="$value"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Umsetzungsstatus aus GSTOOL: 1=ja / 2=nein / 3=teilweise / 4=entbehrlich
         (Zahlen variieren je Version — Mapping unten anpassen) -->
    <xsl:template name="umsetzungsstatus-text">
        <xsl:param name="value"/>
        <xsl:choose>
            <xsl:when test="$value = '1'">ja</xsl:when>
            <xsl:when test="$value = '2'">nein</xsl:when>
            <xsl:when test="$value = '3'">teilweise</xsl:when>
            <xsl:when test="$value = '4'">entbehrlich</xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="$value"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="/">
        <gstool-export version="1.0">
            <metadata>
                <created>
                    <xsl:value-of select="//META_INFO/CREATED | //GSTOOL/EXPORT_DATE"/>
                </created>
                <bsi-version>2023</bsi-version>
                <tenant-hint>migrated-from-gstool</tenant-hint>
            </metadata>

            <zielobjekte>
                <xsl:apply-templates select="//NZIELOBJEKT"/>
            </zielobjekte>

            <modellierung>
                <xsl:apply-templates select="//MOD_ZIELOBJEKT_DEPENDENCY | //ZO_DEPENDENCY"/>
            </modellierung>

            <bausteine>
                <xsl:apply-templates select="//MOD_BAUSTEIN"/>
            </bausteine>

            <massnahmen>
                <xsl:apply-templates select="//MOD_MASSNAHME"/>
            </massnahmen>

            <risikoanalyse>
                <xsl:apply-templates select="//RA_RISIKO | //RISIKO"/>
            </risikoanalyse>
        </gstool-export>
    </xsl:template>

    <xsl:template match="NZIELOBJEKT">
        <xsl:variable name="nid" select="N_ID | NID | ID"/>
        <xsl:variable name="ntypId" select="N_NTYP_ID | NTYP_ID"/>
        <zielobjekt>
            <xsl:attribute name="id">
                <xsl:text>ZO-</xsl:text>
                <xsl:value-of select="$nid"/>
            </xsl:attribute>
            <xsl:attribute name="type">
                <xsl:value-of select="//NTYP[N_ID=$ntypId]/N_BEZ | //NTYP[NID=$ntypId]/BEZ"/>
            </xsl:attribute>

            <name>
                <xsl:value-of select="N_NAME | NAME"/>
            </name>
            <kurzbeschreibung>
                <xsl:value-of select="N_KURZBESCHREIBUNG | KURZBESCHREIBUNG"/>
            </kurzbeschreibung>
            <verantwortlich>
                <xsl:value-of select="N_VERANTWORTLICH | VERANTWORTLICH"/>
            </verantwortlich>
            <standort>
                <xsl:value-of select="N_STANDORT | STANDORT"/>
            </standort>

            <schutzbedarf>
                <vertraulichkeit>
                    <xsl:call-template name="schutzbedarf-text">
                        <xsl:with-param name="value" select="NZB_VERTRAULICHKEIT | VERTRAULICHKEIT"/>
                    </xsl:call-template>
                </vertraulichkeit>
                <integritaet>
                    <xsl:call-template name="schutzbedarf-text">
                        <xsl:with-param name="value" select="NZB_INTEGRITAET | INTEGRITAET"/>
                    </xsl:call-template>
                </integritaet>
                <verfuegbarkeit>
                    <xsl:call-template name="schutzbedarf-text">
                        <xsl:with-param name="value" select="NZB_VERFUEGBARKEIT | VERFUEGBARKEIT"/>
                    </xsl:call-template>
                </verfuegbarkeit>
            </schutzbedarf>
        </zielobjekt>
    </xsl:template>

    <xsl:template match="MOD_ZIELOBJEKT_DEPENDENCY | ZO_DEPENDENCY">
        <abhaengigkeit>
            <xsl:attribute name="von">
                <xsl:text>ZO-</xsl:text>
                <xsl:value-of select="MO_NID_FROM | FROM_ID | VON"/>
            </xsl:attribute>
            <xsl:attribute name="zu">
                <xsl:text>ZO-</xsl:text>
                <xsl:value-of select="MO_NID_TO | TO_ID | ZU"/>
            </xsl:attribute>
        </abhaengigkeit>
    </xsl:template>

    <xsl:template match="MOD_BAUSTEIN">
        <xsl:variable name="bstId" select="MO_BSTID | BSTID | BST_ID"/>
        <baustein-ref>
            <xsl:attribute name="id">
                <!-- BST_BEZ enthält typischerweise "B 3.101 Allgemeiner Server".
                     Wir brauchen nur den ID-Teil → erste Whitespace-Sequenz. -->
                <xsl:value-of select="substring-before(
                    concat(//MB_BAUSTEIN[BST_ID=$bstId]/BST_BEZ, ' '),
                    ' ')"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="substring-before(
                    substring-after(//MB_BAUSTEIN[BST_ID=$bstId]/BST_BEZ, ' '),
                    ' ')"/>
            </xsl:attribute>
            <xsl:attribute name="zielobjekt">
                <xsl:text>ZO-</xsl:text>
                <xsl:value-of select="MO_NID | NID"/>
            </xsl:attribute>
        </baustein-ref>
    </xsl:template>

    <xsl:template match="MOD_MASSNAHME">
        <xsl:variable name="msnId" select="MO_MSNID | MSNID | MS_ID"/>
        <xsl:variable name="bstId" select="MO_BSTID | BSTID | BST_ID"/>
        <massnahme>
            <xsl:attribute name="id">
                <xsl:value-of select="//MB_MASSNAHME[MSN_ID=$msnId]/MSN_BEZ"/>
            </xsl:attribute>
            <xsl:attribute name="baustein">
                <xsl:value-of select="substring-before(concat(//MB_BAUSTEIN[BST_ID=$bstId]/BST_BEZ, ' '), ' ')"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="substring-before(substring-after(//MB_BAUSTEIN[BST_ID=$bstId]/BST_BEZ, ' '), ' ')"/>
            </xsl:attribute>
            <xsl:attribute name="zielobjekt">
                <xsl:text>ZO-</xsl:text>
                <xsl:value-of select="MO_NID | NID"/>
            </xsl:attribute>
            <titel>
                <xsl:value-of select="//MB_MASSNAHME[MSN_ID=$msnId]/MSN_TITEL | //MB_MASSNAHME[MSN_ID=$msnId]/MSN_BEZ"/>
            </titel>
            <umsetzungsstatus>
                <xsl:call-template name="umsetzungsstatus-text">
                    <xsl:with-param name="value" select="MO_UMSETZUNGSSTATUS | UMSETZUNGSSTATUS"/>
                </xsl:call-template>
            </umsetzungsstatus>
        </massnahme>
    </xsl:template>

    <xsl:template match="RA_RISIKO | RISIKO">
        <risiko>
            <xsl:attribute name="id">
                <xsl:text>R-</xsl:text>
                <xsl:value-of select="RA_ID | ID"/>
            </xsl:attribute>
            <xsl:attribute name="zielobjekt">
                <xsl:text>ZO-</xsl:text>
                <xsl:value-of select="RA_NID | NID | ZIELOBJEKT_ID"/>
            </xsl:attribute>
            <titel>
                <xsl:value-of select="RA_TITEL | TITEL"/>
            </titel>
            <gefaehrdung>
                <xsl:value-of select="RA_GEFAEHRDUNG | GEFAEHRDUNG"/>
            </gefaehrdung>
            <eintrittshaeufigkeit>
                <xsl:value-of select="RA_HAEUFIGKEIT | HAEUFIGKEIT | EINTRITTSHAEUFIGKEIT"/>
            </eintrittshaeufigkeit>
            <schadenshoehe>
                <xsl:value-of select="RA_SCHADEN | SCHADEN | SCHADENSHOEHE"/>
            </schadenshoehe>
            <risikobehandlung>
                <xsl:value-of select="RA_BEHANDLUNG | BEHANDLUNG | RISIKOBEHANDLUNG"/>
            </risikobehandlung>
        </risiko>
    </xsl:template>
</xsl:stylesheet>
