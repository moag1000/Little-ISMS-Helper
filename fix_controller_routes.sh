#!/bin/bash

# Fix locale-prefixed routes in controller tests
# Routes need /en/ or /de/ prefix

for file in tests/Controller/*.php; do
    if [ -f "$file" ]; then
        # Skip files that already have locale prefixes
        if grep -q "'/en/" "$file" || grep -q "'/de/" "$file"; then
            echo "  Skipping $file (already has locale)"
            continue
        fi

        # Add /en/ prefix to common route patterns
        # Pattern: '/resource/' -> '/en/resource/'
        sed -i.bak -E \
            -e "s#'/asset/#'/en/asset/#g" \
            -e "s#'/risk/#'/en/risk/#g" \
            -e "s#'/control/#'/en/control/#g" \
            -e "s#'/incident/#'/en/incident/#g" \
            -e "s#'/audit/#'/en/audit/#g" \
            -e "s#'/document/#'/en/document/#g" \
            -e "s#'/supplier/#'/en/supplier/#g" \
            -e "s#'/location/#'/en/location/#g" \
            -e "s#'/person/#'/en/person/#g" \
            -e "s#'/training/#'/en/training/#g" \
            -e "s#'/compliance/#'/en/compliance/#g" \
            -e "s#'/bcm/#'/en/bcm/#g" \
            -e "s#'/workflow/#'/en/workflow/#g" \
            -e "s#'/admin/#'/en/admin/#g" \
            -e "s#'/dashboard/#'/en/dashboard/#g" \
            -e "s#'/profile/#'/en/profile/#g" \
            -e "s#'/settings/#'/en/settings/#g" \
            -e "s#'/tenant/#'/en/tenant/#g" \
            -e "s#'/user/#'/en/user/#g" \
            -e "s#'/role/#'/en/role/#g" \
            -e "s#'/permission/#'/en/permission/#g" \
            -e "s#\"/asset/#\"/en/asset/#g" \
            -e "s#\"/risk/#\"/en/risk/#g" \
            -e "s#\"/control/#\"/en/control/#g" \
            -e "s#\"/incident/#\"/en/incident/#g" \
            -e "s#\"/audit/#\"/en/audit/#g" \
            -e "s#\"/document/#\"/en/document/#g" \
            -e "s#\"/supplier/#\"/en/supplier/#g" \
            -e "s#\"/location/#\"/en/location/#g" \
            -e "s#\"/person/#\"/en/person/#g" \
            -e "s#\"/training/#\"/en/training/#g" \
            -e "s#\"/compliance/#\"/en/compliance/#g" \
            -e "s#\"/bcm/#\"/en/bcm/#g" \
            -e "s#\"/workflow/#\"/en/workflow/#g" \
            -e "s#\"/admin/#\"/en/admin/#g" \
            -e "s#\"/dashboard/#\"/en/dashboard/#g" \
            -e "s#\"/profile/#\"/en/profile/#g" \
            -e "s#\"/settings/#\"/en/settings/#g" \
            -e "s#\"/tenant/#\"/en/tenant/#g" \
            -e "s#\"/user/#\"/en/user/#g" \
            -e "s#\"/role/#\"/en/role/#g" \
            -e "s#\"/permission/#\"/en/permission/#g" \
            "$file"

        if [ -f "$file.bak" ]; then
            # Check if anything changed
            if diff -q "$file" "$file.bak" > /dev/null; then
                echo "  No changes: $file"
                rm "$file.bak"
            else
                echo "✓ Fixed: $file"
                rm "$file.bak"
            fi
        fi
    fi
done

echo "Done!"
