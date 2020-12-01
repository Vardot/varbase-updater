#!/bin/bash

# Update Varbase

BASEDIR=$(pwd);

echo "$(tput setaf 2)Checking varbase-updater version and updating if needed:$(tput sgr 0)";
composer update vardot/varbase-updater;

# Running the updater.
bash ${BASEDIR}/vendor/vardot/varbase-updater/scripts/update/varbase-updater.sh
