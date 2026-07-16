#!/bin/bash
#
# DriveAge Plugin Build Script
# Packages the plugin source into a Slackware .txz package
#

set -e  # Exit on error

# Plugin information
PLUGIN="driveage"
VERSION=$(date +"%Y.%m.%d")
ARCH="x86_64"

# Directories
SOURCE_DIR="source/$PLUGIN"
ARCHIVE_DIR="archive"
PLUGIN_DIR="plugins"

# Auto-increment build number for same-day builds
# Find the highest existing build number for today's version
mkdir -p "$ARCHIVE_DIR"
HIGHEST_BUILD=0
for file in "$ARCHIVE_DIR/${PLUGIN}-${VERSION}-${ARCH}"-*.txz; do
    if [ -f "$file" ]; then
        # Extract build number from filename (e.g., driveage-2025.11.27-x86_64-2.txz -> 2)
        filename=$(basename "$file" .txz)
        # Remove everything up to and including the last hyphen to get just the build number
        BUILD_NUM="${filename##*-}"
        if [ "$BUILD_NUM" -gt "$HIGHEST_BUILD" ] 2>/dev/null; then
            HIGHEST_BUILD=$BUILD_NUM
        fi
    fi
done
BUILD=$((HIGHEST_BUILD + 1))

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}DriveAge Plugin Build Script${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if source directory exists
if [ ! -d "$SOURCE_DIR" ]; then
    echo -e "${RED}Error: Source directory not found: $SOURCE_DIR${NC}"
    exit 1
fi

# Refuse to package editor backups / scratch files
if find "$SOURCE_DIR" \( -name '*.backup' -o -name '*.bak' -o -name '*.orig' -o -name '*~' \) | grep -q .; then
    echo -e "${RED}Error: backup/scratch files found in source tree - remove them before building:${NC}"
    find "$SOURCE_DIR" \( -name '*.backup' -o -name '*.bak' -o -name '*.orig' -o -name '*~' \)
    exit 1
fi

# Normalize line endings to LF on all shipped text files.
# Unraid splits a .page file's ini header from its PHP body on a bare "---"
# line; a CRLF working tree (e.g. edited on Windows) turns that into "---\r",
# the split fails, the body never executes, and the plugin renders a BLANK
# white page. This shipped once - strip CR here so it can never recur,
# regardless of how the working tree was checked out or edited.
echo -e "${YELLOW}Normalizing line endings (CRLF -> LF)...${NC}"
find "$SOURCE_DIR" -type f \( -name '*.page' -o -name '*.php' -o -name '*.js' \
    -o -name '*.css' -o -name '*.svg' -o -name '*.html' -o -name '*.txt' \) \
    -exec sed -i 's/\r$//' {} +

# Guard: abort if any parsed/executed file still contains a carriage return.
CR_FILES=$(grep -rlI $'\r' "$SOURCE_DIR" --include='*.page' --include='*.php' \
    --include='*.js' --include='*.css' 2>/dev/null || true)
if [ -n "$CR_FILES" ]; then
    echo -e "${RED}Error: carriage returns remain in shipped files after normalization:${NC}"
    echo "$CR_FILES"
    exit 1
fi
echo -e "${GREEN}✓ Line endings normalized to LF${NC}"

# Write the single-source version file consumed by the .page files
VERSION_FILE="$SOURCE_DIR/usr/local/emhttp/plugins/driveage/include/version.php"
cat > "$VERSION_FILE" <<EOF
<?php
/**
 * DriveAge Plugin - Version (single source of truth)
 *
 * This file is rewritten by build.sh on every build.
 * Pages use DRIVEAGE_VERSION for both display and cache-busting.
 */

if (!defined('DRIVEAGE_VERSION')) {
    define('DRIVEAGE_VERSION', '${VERSION}-${BUILD}');
}
EOF
echo -e "${GREEN}✓ version.php set to ${VERSION}-${BUILD}${NC}"

# Package filename
PACKAGE_NAME="${PLUGIN}-${VERSION}-${ARCH}-${BUILD}.txz"
PACKAGE_PATH="${ARCHIVE_DIR}/${PACKAGE_NAME}"

echo -e "${YELLOW}Building package...${NC}"
echo "  Plugin: $PLUGIN"
echo "  Version: $VERSION"
echo "  Architecture: $ARCH"
echo "  Package: $PACKAGE_NAME"
echo ""

# Change to source directory
cd "$SOURCE_DIR"

# Create the package using makepkg
echo -e "${YELLOW}Creating .txz package...${NC}"
if command -v makepkg &> /dev/null; then
    # Using Slackware makepkg
    makepkg -l y -c n "../../${PACKAGE_PATH}"
else
    # Fallback: use tar with xz compression
    echo -e "${YELLOW}makepkg not found, using tar...${NC}"
    tar --exclude='*.backup' --exclude='*.bak' -cJf "../../${PACKAGE_PATH}" .
fi

# Return to root directory
cd ../..

# Check if package was created
if [ ! -f "$PACKAGE_PATH" ]; then
    echo -e "${RED}Error: Package was not created${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Package created successfully${NC}"

# Generate MD5 checksum
echo -e "${YELLOW}Generating MD5 checksum...${NC}"
MD5_FILE="${ARCHIVE_DIR}/${PLUGIN}-${VERSION}-${ARCH}-${BUILD}.md5"
md5sum "$PACKAGE_PATH" | awk '{print $1}' > "$MD5_FILE"

if [ ! -f "$MD5_FILE" ]; then
    echo -e "${RED}Error: MD5 file was not created${NC}"
    exit 1
fi

MD5_VALUE=$(cat "$MD5_FILE")
echo -e "${GREEN}✓ MD5 checksum: $MD5_VALUE${NC}"

# Get package size
PACKAGE_SIZE=$(du -h "$PACKAGE_PATH" | cut -f1)
echo -e "${GREEN}✓ Package size: $PACKAGE_SIZE${NC}"
echo ""

# Update plugin manifest with MD5
echo -e "${YELLOW}Updating plugin manifest...${NC}"
PLG_FILE="${PLUGIN_DIR}/${PLUGIN}.plg"

if [ -f "$PLG_FILE" ]; then
    # Update MD5 entity
    sed -i "s/<!ENTITY md5.*>/<!ENTITY md5       \"$MD5_VALUE\">/" "$PLG_FILE"

    # Update version entity
    sed -i "s/<!ENTITY version.*>/<!ENTITY version   \"$VERSION\">/" "$PLG_FILE"

    # Update build entity
    sed -i "s/<!ENTITY build.*>/<!ENTITY build     \"$BUILD\">/" "$PLG_FILE"

    # Update version in install script (hardcoded in CDATA section)
    sed -i "s/echo \" Version: .* (Build .*)\"/echo \" Version: $VERSION (Build $BUILD)\"/" "$PLG_FILE"

    echo -e "${GREEN}✓ Plugin manifest updated${NC}"
else
    echo -e "${YELLOW}Warning: Plugin manifest not found: $PLG_FILE${NC}"
fi

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Build Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Package: $PACKAGE_PATH"
echo "MD5 File: $MD5_FILE"
echo "MD5 Sum: $MD5_VALUE"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Commit and push to GitHub"
echo "2. Upload package and MD5 to GitHub releases"
echo "3. Test installation on Unraid"
echo ""
