#!/bin/bash

# Script to fix remaining hardcoded text translation issues

BASE_DIR="/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/templates"

echo "Fixing DPIA PDF footer text..."
# dpia/dpia_pdf.html.twig - long footer text
sed -i '' 's|<p>This Data Protection Impact Assessment (DPIA) has been prepared in accordance with Article 35 of the General Data Protection Regulation (GDPR) and documents the assessment of processing operations likely to result in high risk to the rights and freedoms of natural persons\.</p>|<p>{{ '\''dpia.pdf.footer_text'\''\|trans }}</p>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"

echo "Fixing PDF transitive compliance report..."
# pdf/transitive_compliance_report.html.twig - second occurrence
sed -i '' 's|<td>Solid mappings with minor deviations</td>|<td>{{ '\''pdf.framework_comparison.solid_mappings_minor'\''\|trans }}</td>|g' "$BASE_DIR/pdf/transitive_compliance_report.html.twig"

echo "Fixing Processing Activity templates..."
# processing_activity/edit.html.twig
sed -i '' 's|<p>Permanently delete this processing activity\.</p>|<p>{{ '\''processing_activity.permanently_delete'\''\|trans }}</p>|g' "$BASE_DIR/processing_activity/edit.html.twig"

# processing_activity/index.html.twig
sed -i '' 's|view all activities|{{ '\''processing_activity.view_all_activities'\''\|trans }}|g' "$BASE_DIR/processing_activity/index.html.twig"

# processing_activity/vvt_pdf.html.twig
sed -i '' 's|<p style="font-size: 11pt; color: #7f8c8d;">Art\. 30 GDPR - Record of Processing Activities</p>|<p style="font-size: 11pt; color: #7f8c8d;">{{ '\''processing_activity.pdf.art_30_title'\''\|trans }}</p>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h2>Table of Contents</h2>|<h2>{{ '\''processing_activity.pdf.table_of_contents'\''\|trans }}</h2>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h3>Art\. 30(1)(a) - Name and Purposes</h3>|<h3>{{ '\''processing_activity.pdf.art_30_1a'\''\|trans }}</h3>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<td>Purposes of Processing</td>|<td>{{ '\''processing_activity.pdf.purposes_processing'\''\|trans }}</td>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h3>Art\. 30(1)(b) - Categories of Data Subjects</h3>|<h3>{{ '\''processing_activity.pdf.art_30_1b'\''\|trans }}</h3>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h3>Art\. 30(1)(c) - Categories of Personal Data</h3>|<h3>{{ '\''processing_activity.pdf.art_30_1c'\''\|trans }}</h3>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h3>Art\. 30(1)(d) - Categories of Recipients</h3>|<h3>{{ '\''processing_activity.pdf.art_30_1d'\''\|trans }}</h3>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h3>Art\. 30(1)(e) - Transfers to Third Countries</h3>|<h3>{{ '\''processing_activity.pdf.art_30_1e'\''\|trans }}</h3>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<td>Legal Basis for Retention</td>|<td>{{ '\''processing_activity.pdf.legal_basis_retention'\''\|trans }}</td>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"
sed -i '' 's|<h3>Art\. 30(1)(g) - Technical and Organizational Measures</h3>|<h3>{{ '\''processing_activity.pdf.art_30_1g'\''\|trans }}</h3>|g' "$BASE_DIR/processing_activity/vvt_pdf.html.twig"

echo "Fixing Reports templates..."
# reports/overview.html.twig
sed -i '' 's|<li>Integrieren Sie die Report-Generierung in Ihre CI/CD-Pipeline</li>|<li>{{ '\''reports.integrate_ci_cd'\''\|trans }}</li>|g' "$BASE_DIR/reports/overview.html.twig"

echo "Fixing Setup templates..."
# setup/index.html.twig
sed -i '' 's|<h5>Was wird eingerichtet?</h5>|<h5>{{ '\''setup.what_will_be_setup'\''\|trans }}</h5>|g' "$BASE_DIR/setup/index.html.twig"

# setup/step10_sample_data.html.twig
sed -i '' 's|<li>Sie können später in einem Produktivsystem gelöscht werden</li>|<li>{{ '\''setup.can_delete_later'\''\|trans }}</li>|g' "$BASE_DIR/setup/step10_sample_data.html.twig"

echo "Fixing SoA templates..."
# soa/category.html.twig
sed -i '' 's|<span class="badge bg-warning">🔄 In Arbeit</span>|<span class="badge bg-warning">{{ '\''soa.in_progress_badge_emoji'\''\|trans }}</span>|g' "$BASE_DIR/soa/category.html.twig"

# soa/export.html.twig
sed -i '' 's|<h1>Statement of Applicability (SoA)</h1>|<h1>{{ '\''soa.title'\''\|trans }}</h1>|g' "$BASE_DIR/soa/export.html.twig"
sed -i '' 's|<span class="badge bg-warning">In Arbeit</span>|<span class="badge bg-warning">{{ '\''soa.in_progress_badge'\''\|trans }}</span>|g' "$BASE_DIR/soa/export.html.twig"
sed -i '' 's|<span class="badge bg-warning">🔄 In Arbeit</span>|<span class="badge bg-warning">{{ '\''soa.in_progress_badge_emoji'\''\|trans }}</span>|g' "$BASE_DIR/soa/export.html.twig"

# soa/report_pdf_v2.html.twig
sed -i '' 's|<h1>Statement of Applicability (SoA)</h1>|<h1>{{ '\''soa.title'\''\|trans }}</h1>|g' "$BASE_DIR/soa/report_pdf_v2.html.twig"
sed -i '' 's|<td>Information security, cybersecurity and privacy protection — Information security management systems — Requirements</td>|<td>{{ '\''soa.iso27001_full_title'\''\|trans }}</td>|g' "$BASE_DIR/soa/report_pdf_v2.html.twig"

echo "Fixing User Management templates..."
# user_management/import.html.twig
sed -i '' 's|<li>Der Import kann nicht rückgängig gemacht werden</li>|<li>{{ '\''user_management.import_irreversible'\''\|trans }}</li>|g' "$BASE_DIR/user_management/import.html.twig"

echo "All remaining hardcoded text issues fixed!"
