#!/bin/bash

# Script to fix all hardcoded text translation issues

BASE_DIR="/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/templates"

echo "Fixing Data Breach templates..."
# data_breach/data_breach_pdf.html.twig
sed -i '' 's|<h2>1\. Art\. 33(3)(a) - Nature of the Personal Data Breach</h2>|<h2>{{ '\''data_breach.pdf.art_33_3a_nature'\''\|trans }}</h2>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h3>Categories of Personal Data Concerned:</h3>|<h3>{{ '\''data_breach.pdf.categories_personal_data'\''\|trans }}</h3>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h3>Categories of Data Subjects Concerned:</h3>|<h3>{{ '\''data_breach.pdf.categories_data_subjects'\''\|trans }}</h3>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h3>Approximate Number of Data Subjects Concerned:</h3>|<h3>{{ '\''data_breach.pdf.approx_number_subjects'\''\|trans }}</h3>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h2>2\. Art\. 33(3)(b) - Likely Consequences of the Breach</h2>|<h2>{{ '\''data_breach.pdf.art_33_3b_consequences'\''\|trans }}</h2>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h2>3\. Art\. 33(3)(c) & (d) - Measures Taken or Proposed</h2>|<h2>{{ '\''data_breach.pdf.art_33_3c_d_measures'\''\|trans }}</h2>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h3>Measures Taken to Address the Breach:</h3>|<h3>{{ '\''data_breach.pdf.measures_taken'\''\|trans }}</h3>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h3>Measures to Mitigate Possible Adverse Effects:</h3>|<h3>{{ '\''data_breach.pdf.measures_mitigate'\''\|trans }}</h3>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"
sed -i '' 's|<h2>5\. Art\. 34 - Communication to Data Subjects</h2>|<h2>{{ '\''data_breach.pdf.art_34_communication'\''\|trans }}</h2>|g' "$BASE_DIR/data_breach/data_breach_pdf.html.twig"

# data_breach/index.html.twig
sed -i '' 's|View all breaches|{{ '\''data_breach.index.view_all_breaches'\''\|trans }}|g' "$BASE_DIR/data_breach/index.html.twig"

echo "Fixing DPIA templates..."
# dpia/dpia_pdf.html.twig
sed -i '' 's|<h2>Art\. 35(7)(a) - Systematic Description of Processing Operations</h2>|<h2>{{ '\''dpia.pdf.art_35_7a_description'\''\|trans }}</h2>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<h3>Purposes of Processing</h3>|<h3>{{ '\''dpia.pdf.purposes_processing'\''\|trans }}</h3>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<h2>Art\. 35(7)(b) - Assessment of Necessity and Proportionality</h2>|<h2>{{ '\''dpia.pdf.art_35_7b_assessment'\''\|trans }}</h2>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<h2>Art\. 35(7)(c) - Assessment of Risks to Rights and Freedoms</h2>|<h2>{{ '\''dpia.pdf.art_35_7c_risks'\''\|trans }}</h2>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<h3>Specific Risks to Data Subjects</h3>|<h3>{{ '\''dpia.pdf.specific_risks'\''\|trans }}</h3>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<h2>Art\. 35(7)(d) - Measures Envisaged to Address Risks</h2>|<h2>{{ '\''dpia.pdf.art_35_7d_measures'\''\|trans }}</h2>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<td>Next Review Date</td>|<td>{{ '\''dpia.pdf.next_review_date'\''\|trans }}</td>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"
sed -i '' 's|<td>Approved By</td>|<td>{{ '\''dpia.pdf.approved_by'\''\|trans }}</td>|g' "$BASE_DIR/dpia/dpia_pdf.html.twig"

# dpia/index.html.twig
sed -i '' 's|Create your first DPIA|{{ '\''dpia.index.create_first_dpia'\''\|trans }}|g' "$BASE_DIR/dpia/index.html.twig"

# dpia/show.html.twig
sed -i '' 's|>Cancel</button>|>{{ '\''dpia.show.cancel_button'\''\|trans }}</button>|g' "$BASE_DIR/dpia/show.html.twig"

echo "Fixing Document templates..."
# document/index.html.twig
sed -i '' 's|<th scope="col">Uploaded By</th>|<th scope="col">{{ '\''document.uploaded_by'\''\|trans }}</th>|g' "$BASE_DIR/document/index.html.twig"
sed -i '' 's|<th scope="col">Uploaded At</th>|<th scope="col">{{ '\''document.uploaded_at'\''\|trans }}</th>|g' "$BASE_DIR/document/index.html.twig"
sed -i '' 's|>Edit</a>|>{{ '\''document.edit_button'\''\|trans }}</a>|g' "$BASE_DIR/document/index.html.twig"

# document/new_modern.html.twig
sed -i '' 's|<li>Ziehen Sie Dateien direkt aus dem Datei-Explorer in die blaue Drop-Zone</li>|<li>{{ '\''document.drag_files'\''\|trans }}</li>|g' "$BASE_DIR/document/new_modern.html.twig"

echo "Fixing Audit templates..."
# audit/index_modern.html.twig
sed -i '' 's|<div class="status-label text-muted mb-2">In Bearbeitung</div>|<div class="status-label text-muted mb-2">{{ '\''audit.status_in_progress'\''\|trans }}</div>|g' "$BASE_DIR/audit/index_modern.html.twig"

echo "Fixing Data Management templates..."
# data_management/export.html.twig
sed -i '' 's|<li>Exported data includes all records from selected entities</li>|<li>{{ '\''data_management.export.includes_all_records'\''\|trans }}</li>|g' "$BASE_DIR/data_management/export.html.twig"
sed -i '' 's|<li>Sensitive data (passwords, tokens) are included - store exports securely</li>|<li>{{ '\''data_management.export.sensitive_data_warning'\''\|trans }}</li>|g' "$BASE_DIR/data_management/export.html.twig"
sed -i '' 's|<li>Large exports may cause browser timeouts - use database backups for full data exports</li>|<li>{{ '\''data_management.export.large_exports_warning'\''\|trans }}</li>|g' "$BASE_DIR/data_management/export.html.twig"
sed -i '' 's|<li>Relationships between entities are not automatically maintained in CSV format</li>|<li>{{ '\''data_management.export.relationships_warning'\''\|trans }}</li>|g' "$BASE_DIR/data_management/export.html.twig"

# data_management/import.html.twig
sed -i '' 's|<li>Upload your JSON export file</li>|<li>{{ '\''data_management.import.step_upload'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Preview the data to be imported</li>|<li>{{ '\''data_management.import.step_preview'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Confirm import (currently shows preview only)</li>|<li>{{ '\''data_management.import.step_confirm'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Data will be validated and imported</li>|<li>{{ '\''data_management.import.step_validate'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Must be valid JSON format</li>|<li>{{ '\''data_management.import.format_json'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Should be exported from this system'\''s Data Export function</li>|<li>{{ '\''data_management.import.format_export'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Ensure you have sufficient disk space</li>|<li>{{ '\''data_management.import.ensure_disk_space'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Plan for potential system downtime during large imports</li>|<li>{{ '\''data_management.import.plan_downtime'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Test with a small dataset first</li>|<li>{{ '\''data_management.import.test_small'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Full import functionality requires additional validation logic</li>|<li>{{ '\''data_management.import.validation_required'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"
sed -i '' 's|<li>Duplicate detection is not yet implemented</li>|<li>{{ '\''data_management.import.no_duplicate_detection'\''\|trans }}</li>|g' "$BASE_DIR/data_management/import.html.twig"

echo "Fixing Incident templates..."
# incident/show.html.twig
sed -i '' 's|<p class="text-muted">Controls that should prevent/detect this type of incident:</p>|<p class="text-muted">{{ '\''incident.controls_should_prevent'\''\|trans }}</p>|g' "$BASE_DIR/incident/show.html.twig"

# incident/nis2_report_pdf.html.twig
sed -i '' 's|<th scope="col">Reported At</th>|<th scope="col">{{ '\''incident.pdf.reported_at'\''\|trans }}</th>|g' "$BASE_DIR/incident/nis2_report_pdf.html.twig"
sed -i '' 's|<div class="impact-cell impact-label">Reported By</div>|<div class="impact-cell impact-label">{{ '\''incident.pdf.reported_by'\''\|trans }}</div>|g' "$BASE_DIR/incident/nis2_report_pdf.html.twig"

echo "Fixing Monitoring templates..."
# monitoring/errors.html.twig
sed -i '' 's|<h5 class="card-title text-muted">By Level</h5>|<h5 class="card-title text-muted">{{ '\''monitoring.by_level'\''\|trans }}</h5>|g' "$BASE_DIR/monitoring/errors.html.twig"
sed -i '' 's|<label for="limit" class="col-form-label">Show entries:</label>|<label for="limit" class="col-form-label">{{ '\''monitoring.show_entries'\''\|trans }}</label>|g' "$BASE_DIR/monitoring/errors.html.twig"
sed -i '' 's|<p class="mt-2">No errors found in the log file!</p>|<p class="mt-2">{{ '\''monitoring.no_errors_found'\''\|trans }}</p>|g' "$BASE_DIR/monitoring/errors.html.twig"
sed -i '' 's|<li>Log entries are shown in reverse chronological order (most recent first)</li>|<li>{{ '\''monitoring.reverse_order'\''\|trans }}</li>|g' "$BASE_DIR/monitoring/errors.html.twig"
sed -i '' 's|<li>Only the most recent entries are displayed based on the selected limit</li>|<li>{{ '\''monitoring.recent_entries_only'\''\|trans }}</li>|g' "$BASE_DIR/monitoring/errors.html.twig"
sed -i '' 's|<li>For full log analysis, consider downloading the log file or using log management tools</li>|<li>{{ '\''monitoring.full_log_analysis'\''\|trans }}</li>|g' "$BASE_DIR/monitoring/errors.html.twig"
sed -i '' 's|<li>Large log files may impact performance - consider log rotation</li>|<li>{{ '\''monitoring.large_files_warning'\''\|trans }}</li>|g' "$BASE_DIR/monitoring/errors.html.twig"

echo "Fixing Home templates..."
# home/index_modern.html.twig
sed -i '' 's|<p class="lead text-muted">Alles, was Sie für ein erfolgreiches Informationssicherheits-Management benötigen</p>|<p class="lead text-muted">{{ '\''home.tagline'\''\|trans }}</p>|g' "$BASE_DIR/home/index_modern.html.twig"
sed -i '' 's|<h2 class="display-5 fw-bold">Schnellstart in 4 Schritten</h2>|<h2 class="display-5 fw-bold">{{ '\''home.quick_start'\''\|trans }}</h2>|g' "$BASE_DIR/home/index_modern.html.twig"

echo "Fixing PDF Base template..."
# pdf/_base_document.html.twig
sed -i '' 's|<p class="text-muted">Bitte definieren Sie den Block '\''document_content'\'' in Ihrem Template\.</p>|<p class="text-muted">{{ '\''pdf.base.define_content_block'\''\|trans }}</p>|g' "$BASE_DIR/pdf/_base_document.html.twig"

echo "Fixing PDF report templates..."
# pdf/data_reuse_insights_report.html.twig
sed -i '' 's|<h2>Recommendations for Data Reuse</h2>|<h2>{{ '\''pdf.data_reuse.recommendations_title'\''\|trans }}</h2>|g' "$BASE_DIR/pdf/data_reuse_insights_report.html.twig"

# pdf/framework_comparison_report.html.twig
sed -i '' 's|<td>Solid mappings with minor deviations</td>|<td>{{ '\''pdf.framework_comparison.solid_mappings_minor'\''\|trans }}</td>|g' "$BASE_DIR/pdf/framework_comparison_report.html.twig"

# pdf/gap_analysis_report.html.twig
sed -i '' 's|<h2>Gap Distribution by Severity</h2>|<h2>{{ '\''pdf.gap_analysis.distribution_by_severity'\''\|trans }}</h2>|g' "$BASE_DIR/pdf/gap_analysis_report.html.twig"
sed -i '' 's|<td>Requirements with 0% fulfillment - not yet addressed</td>|<td>{{ '\''pdf.gap_analysis.not_addressed'\''\|trans }}</td>|g' "$BASE_DIR/pdf/gap_analysis_report.html.twig"
sed -i '' 's|<td>Very low fulfillment (&lt;30%) - control missing or insufficient</td>|<td>{{ '\''pdf.gap_analysis.very_low'\''\|trans }}</td>|g' "$BASE_DIR/pdf/gap_analysis_report.html.twig"
sed -i '' 's|<td>Partial fulfillment (30-80%) - implementation in progress</td>|<td>{{ '\''pdf.gap_analysis.partial'\''\|trans }}</td>|g' "$BASE_DIR/pdf/gap_analysis_report.html.twig"

# pdf/risk_report.html.twig
sed -i '' 's|<h2>Distribution by Risk Level</h2>|<h2>{{ '\''pdf.risk_report.distribution_by_level'\''\|trans }}</h2>|g' "$BASE_DIR/pdf/risk_report.html.twig"
sed -i '' 's|<h3 class="mt-2">Distribution by Status</h3>|<h3 class="mt-2">{{ '\''pdf.risk_report.distribution_by_status'\''\|trans }}</h3>|g' "$BASE_DIR/pdf/risk_report.html.twig"

# pdf/transitive_compliance_report.html.twig
sed -i '' 's|<th scope="col" class="w-18">From Framework</th>|<th scope="col" class="w-18">{{ '\''pdf.transitive_compliance.from_framework'\''\|trans }}</th>|g' "$BASE_DIR/pdf/transitive_compliance_report.html.twig"
sed -i '' 's|<th scope="col" class="w-18">To Framework</th>|<th scope="col" class="w-18">{{ '\''pdf.transitive_compliance.to_framework'\''\|trans }}</th>|g' "$BASE_DIR/pdf/transitive_compliance_report.html.twig"
sed -i '' 's|<th scope="col" class="w-15">From Framework</th>|<th scope="col" class="w-15">{{ '\''pdf.transitive_compliance.from_framework'\''\|trans }}</th>|g' "$BASE_DIR/pdf/transitive_compliance_report.html.twig"
sed -i '' 's|<th scope="col" class="w-15">To Framework</th>|<th scope="col" class="w-15">{{ '\''pdf.transitive_compliance.to_framework'\''\|trans }}</th>|g' "$BASE_DIR/pdf/transitive_compliance_report.html.twig"
sed -i '' 's|<td>Establish Compliance Hub with ROI Dashboard, automated tracking, continuous optimization</td>|<td>{{ '\''pdf.transitive_compliance.establish_hub'\''\|trans }}</td>|g' "$BASE_DIR/pdf/transitive_compliance_report.html.twig"

echo "All templates fixed!"
