#!/bin/bash

# Re-enable tenant association in ISMSContext entity after successful migration
# This script uncomments the tenant property and methods

set -e

echo "🔧 Re-enabling tenant association in ISMSContext entity..."

FILE="src/Entity/ISMSContext.php"

if [ ! -f "$FILE" ]; then
    echo "❌ Error: $FILE not found!"
    exit 1
fi

# Check if already enabled
if ! grep -q "// use App\\\Entity\\\Tenant;" "$FILE"; then
    echo "✅ Tenant association is already enabled in ISMSContext"
    exit 0
fi

# Create backup
cp "$FILE" "${FILE}.bak"
echo "📦 Created backup: ${FILE}.bak"

# Uncomment: use App\Entity\Tenant;
sed -i 's|^// use App\\Entity\\Tenant;|use App\\Entity\\Tenant;|' "$FILE"

# Uncomment tenant property (3 lines)
sed -i '/\/\/ TODO: Re-enable after migration/,/\/\/ private ?Tenant \$tenant = null;/ {
    s|^    // ||
}' "$FILE"

# Uncomment getTenant method
sed -i '/\/\/ public function getTenant/,/\/\/ }/ {
    s|^    // ||
}' "$FILE"

# Uncomment setTenant method
sed -i '/\/\/ public function setTenant/,/\/\/ }/ {
    s|^    // ||
}' "$FILE"

# Remove the first TODO comment block (the one before the property)
sed -i '/\/\/ TODO: Re-enable after migration Version20251113120000 is successfully executed/d' "$FILE"

echo "✅ Successfully re-enabled tenant association in ISMSContext"
echo ""
echo "📝 Next steps:"
echo "   1. Clear cache: php bin/console cache:clear"
echo "   2. Test the application"
echo "   3. Commit changes: git add src/Entity/ISMSContext.php && git commit -m 'feat: Re-enable tenant association in ISMSContext'"
echo ""
echo "💡 To revert: mv ${FILE}.bak $FILE"
