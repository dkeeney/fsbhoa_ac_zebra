#!/bin/bash
# deploy_zebra.sh (Zebra Printer Plugin)

INSTALL_BIN="/usr/local/bin"
INSTALL_ASSETS="/var/lib/fsbhoa"
PLUGIN_DIR="/var/www/html/wp-content/plugins/fsbhoa_zebra"
REPO_DIR="$(pwd)"

echo "--- Starting Zebra Printer Deployment ---"

# 1. BUILD & INSTALL SERVICE VIA BUILD.SH
echo "[1/2] Building Printer Service..."
./build.sh install

# Deploy Templates if they exist
if [ -d "$REPO_DIR/templates" ]; then
    echo "    Deploying Printer Templates..."
    sudo mkdir -p "$INSTALL_ASSETS/zebra/templates"
    sudo cp -r templates/* "$INSTALL_ASSETS/zebra/templates/"
fi

# 2. DEPLOY WORDPRESS PLUGIN
echo "[2/2] Deploying Zebra WordPress Plugin..."
sudo mkdir -p "$PLUGIN_DIR"
sudo rsync -av --delete ./ "$PLUGIN_DIR/" --exclude="zebra_print_service" --exclude="build.sh" --exclude="deploy_zebra.sh" --exclude=".git"
sudo chown -R www-data:www-data "$PLUGIN_DIR"

echo "--- Restarting Printer Service ---"
sudo systemctl daemon-reload
sudo systemctl start fsbhoa_printer

echo "Zebra Deployment Complete."
echo "Check status: sudo systemctl status fsbhoa_printer"

