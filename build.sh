#!/bin/bash
# build.sh
# Purpose: Compile the Zebra Printer service.
# Usage: ./build.sh        (Builds only)
#        ./build.sh install (Builds, Installs to /usr/local/bin, and Restarts service)

BASE_DIR="$(pwd)"
echo "--- Starting Zebra Printer Build Process ---"

echo "Building Printer Service..."
cd "$BASE_DIR/zebra_print_service" || exit
go build -o fsbhoa_printer .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_printer built"; else echo "   [FAIL] Printer build failed"; exit 1; fi

# --- OPTIONAL: INSTALL ---
if [ "$1" == "install" ]; then
    echo ""
    echo "--- Installing and Restarting Service (Sudo Required) ---"

    echo "Stopping service..."
    sudo systemctl stop fsbhoa_printer

    echo "Copying binary..."
    sudo cp "$BASE_DIR/zebra_print_service/fsbhoa_printer" /usr/local/bin/

    echo "Setting permissions..."
    sudo chown root:root /usr/local/bin/fsbhoa_printer
    sudo chmod 755 /usr/local/bin/fsbhoa_printer

    echo "Starting Systemd Service..."
    sudo systemctl start fsbhoa_printer

    echo "--- Install Complete ---"
    sleep 1
    sudo systemctl status fsbhoa_printer --no-pager | grep "Active:"
else
    echo ""
    echo "--- Build Complete (No Install) ---"
    echo "To install and restart the service, run: ./build.sh install"
fi

