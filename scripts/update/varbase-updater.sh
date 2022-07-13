#!/bin/bash

# Varbase Updater

function clear_stdin(){
  while read -e -t 1; do : ; done
}

BASEDIR=$(pwd);
ERRORLOG=${BASEDIR}/.update-error-log;
DRUPALPATH='docroot';
if [ -d "${BASEDIR}/web" ]; then
  DRUPALPATH='web';
fi
DRUSH="drush";
clear;
echo "$(tput setaf 4)";
cat << "EOF"
 __   __  _______  ______    __   __  _______
|  | |  ||   _   ||    _ |  |  | |  ||       |
|  |_|  ||  |_|  ||   | ||  |  | |  ||    _  |
|       ||       ||   |_||_ |  |_|  ||   |_| |
|       ||       ||    __  ||       ||    ___|
 |     | |   _   ||   |  | ||       ||   |
  |___|  |__| |__||___|  |_||_______||___|
EOF
echo "";
echo "$(tput setaf 4)Varbase Updater$(tput sgr 0)";
echo "";
echo "$(tput setaf 4)Project root           : ${BASEDIR} $(tput sgr 0)";
echo "";
clear_stdin;
echo "$(tput setaf 1)Please choose your Drupal installation folder. Type the folder name or hit enter to choose the default one: ($DRUPALPATH): $(tput sgr 0)";
read drupalfolder;
if [ "$drupalfolder" ] ;then
  DRUPALPATH=$drupalfolder;
fi;

backup () {
  cd ${BASEDIR};
  rm -rf ${BASEDIR}/update_backups;
  mkdir -p ${BASEDIR}/update_backups
  tar --exclude='sites' -zcf ./update_backups/${DRUPALPATH}.tgz ./${DRUPALPATH}
  tar -zcf ./update_backups/vendor.tgz ./vendor
  cp ${BASEDIR}/composer.json ${BASEDIR}/update_backups/composer.json;
  cd ${BASEDIR}/${DRUPALPATH};
  ${DRUSH} sql-dump --result-file=${BASEDIR}/update_backups/db.sql 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
  result="$?";
  if [ "$result" -ne 0 ]; then
      echo "$(tput setab 1)$(tput setaf 7)Error in creating a backup, exiting update process! Please check ${ERRORLOG} for more info.$(tput sgr 0)";
      cd ${BASEDIR};
      exit;
  fi
  cd ${BASEDIR};
}


backup_skipped_modules () {
  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.skip-update ]; then
    mkdir -p ${BASEDIR}/update_backups/skip
    while read p; do
      if [ -d "${BASEDIR}/${DRUPALPATH}/modules/contrib/${p}" ]; then
        cp -r ${BASEDIR}/${DRUPALPATH}/modules/contrib/${p} ${BASEDIR}/update_backups/skip/;
      fi
    done < ${BASEDIR}/vendor/vardot/varbase-updater/config/.skip-update
  fi
}

revert_backup () {
  cd ${BASEDIR}
  rm -rf mv ${BASEDIR}/update_backups/sites
  mv ${BASEDIR}/${DRUPALPATH}/sites ${BASEDIR}/update_backups/
  rm -rf ${BASEDIR}/${DRUPALPATH}
  tar -xf ./update_backups/${DRUPALPATH}.tgz
  mv ${BASEDIR}/update_backups/sites ${BASEDIR}/${DRUPALPATH}/
  rm -rf ${BASEDIR}/vendor
  tar -xf ./update_backups/vendor.tgz
  cp ${BASEDIR}/update_backups/composer.json ${BASEDIR}/composer.json;
  cd ${BASEDIR}/${DRUPALPATH};
  $DRUSH sql-drop --yes 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
  $DRUSH sql-cli < ${BASEDIR}/update_backups/db.sql --yes 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
  result="$?";
  if [ "$result" -ne 0 ]; then
      echo "$(tput setab 1)$(tput setaf 7)Failed to restore the backup. Please check ${ERRORLOG} for more info. You can find the backup to restore it manually in ${BASEDIR}/update_backups$(tput sgr 0)";
      exit;
  fi
  cd ${BASEDIR};
  rm -rf ${BASEDIR}/update_backups;
}

exit_and_revert(){
  clear_stdin;
  if [ -d ${BASEDIR}/update_backups ]; then
    echo "$(tput setaf 1)Would you like to abort the update process and restore the backup? (no): $(tput sgr 0)";
  else
    echo "$(tput setaf 1)Would you like to abort the update process? (no): $(tput sgr 0)";
  fi
  read answer </dev/tty;
  if [ "$answer" != "${answer#[Yy]}" ] ;then
    if [ -d ${BASEDIR}/update_backups ]; then
      echo -e "$(tput setab 1)$(tput setaf 7)Going back in time and restoring the snapshot before the update process!$(tput sgr 0)";
      revert_backup;
      exit;
    else
      echo -e "$(tput setab 1)$(tput setaf 7)Mission aborted.$(tput sgr 0)";
      exit;
    fi
  fi
}

cleanup(){
  if [ -d ${BASEDIR}/vendor/drupal-composer/drupal-scaffold ]; then
    rm -rf ${BASEDIR}/vendor/drupal-composer/drupal-scaffold;
  fi

  composer varbase-version-check composer-patches;
  result="$?";
  if [ "$result" -ne 0 ]; then
    if [ -d ${BASEDIR}/vendor/cweagans/composer-patches ]; then
      rm -rf ${BASEDIR}/vendor/cweagans/composer-patches;
    fi
  fi
  
  if [ -d ${BASEDIR}/${DRUPALPATH}/vendor ]; then
    rm -rf ${BASEDIR}/${DRUPALPATH}/vendor;
  fi
  if [ -f ${BASEDIR}/${DRUPALPATH}/composer.json ]; then
    rm -rf ${BASEDIR}/${DRUPALPATH}/composer.json;
  fi
  if [ -f ${BASEDIR}/${DRUPALPATH}/composer.lock ]; then
    rm -rf ${BASEDIR}/${DRUPALPATH}/composer.lock;
  fi
  if [ -f ${BASEDIR}/scripts/composer/ScriptHandler.php ]; then
    rm -rf ${BASEDIR}/scripts/composer/ScriptHandler.php;
  fi
  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.download-before-update ]; then
    rm -rf ${BASEDIR}/vendor/vardot/varbase-updater/config/.download-before-update;
  fi

  sudo chmod -R gu+rwx ${BASEDIR}/${DRUPALPATH};

  composer dump-autoload;
}

download_before_update(){
  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.download-before-update ]; then
    while read p; do
      echo -e "$(tput setaf 2)Downloading $p.$(tput sgr 0)";
      echo -e "$(tput setaf 2)Downloading $p.$(tput sgr 0)" >> ${ERRORLOG};
      $DRUSH up $p --pm-force --yes --strict=0 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
      result="$?";
      if [ "$result" -ne 0 ]; then
          echo "$(tput setab 1)$(tput setaf 7)Error while downloading $p. Please check ${ERRORLOG} for more info.$(tput sgr 0)";
          exit_and_revert;
      fi
    done < ${BASEDIR}/vendor/vardot/varbase-updater/config/.download-before-update
  fi
}

copy_after_update(){
  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.skip-update ]; then
    while read p; do
      if [ -d "${BASEDIR}/update_backups/skip/${p}" ]; then
        cp -r ${BASEDIR}/update_backups/skip/${p} ${BASEDIR}/${DRUPALPATH}/modules/contrib/;
      fi
    done < ${BASEDIR}/vendor/vardot/varbase-updater/config/.skip-update
  fi
}

enable_after_update(){
  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.enable-after-update ]; then
    while read p; do
      $DRUSH en $p --yes --strict=0 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
      result="$?";
      if [ "$result" -ne 0 ]; then
          echo "$(tput setab 1)$(tput setaf 7)Error while enabling $p. Please check ${ERRORLOG} for more info.$(tput sgr 0)";
          exit_and_revert;
      fi
    done < ${BASEDIR}/vendor/vardot/varbase-updater/config/.enable-after-update
  fi
}

add_git_safe_directories() {
  if [ -d ${BASEDIR}/vendor/drupal/coder ]; then
    git --version
    GIT_IS_AVAILABLE=$?
    if [ $GIT_IS_AVAILABLE -eq 0 ]; then
      git config --global --add safe.directory ${BASEDIR}/vendor/drupal/coder
    fi
  fi
}

echo "$(tput setab 2)";
composer varbase-version-check current-message;
echo "$(tput sgr 0)";
echo "$(tput setaf 2)This command will guide you to update your Varbase project.$(tput sgr 0)";
echo "";
echo "$(tput setab 214)$(tput setaf 0)The update process will go through several tasks to update your Drupal core and modules. Please run this script on a development environment.$(tput sgr 0)";
echo -e "$(tput setaf 2) \t$(tput sgr 0)";
echo "$(tput setaf 2)The command will go through the following steps:$(tput sgr 0)";
echo -e "$(tput setaf 2) \t 1. Backup your current installation (code and database)$(tput sgr 0)";
echo -e "$(tput setaf 2) \t 2. Cleanup and update your composer.json to prepare for Varbase updates$(tput sgr 0)";
echo -e "$(tput setaf 2) \t 3. Update Varbase using (composer update)$(tput sgr 0)";
echo -e "$(tput setaf 2) \t 4. Enable some required modules before running Drupal database updates$(tput sgr 0)";
echo -e "$(tput setaf 2) \t 5. Update Drupal database for latest changes (drush updatedb)$(tput sgr 0)";
echo -e "$(tput setaf 2) \t 6. Write log files and perform some cleanups$(tput sgr 0)";
echo -e "$(tput setaf 2) \t$(tput sgr 0)";
echo "$(tput setab 214)$(tput setaf 0)The update process will go through several tasks to update your Drupal core and modules. Please run this script on a development environment.$(tput sgr 0)";
clear_stdin;
if [ -d ${BASEDIR}/update_backups ]; then
  echo -e "$(tput setaf 1)What would you like to do?: $(tput sgr 0)";
  echo -e "$(tput setaf 1)- (u|update) To start the update process (default). $(tput sgr 0)"
  echo -e "$(tput setaf 1)- (r|revert) To revert the previews backup.$(tput sgr 0)";
  echo -e "$(tput setaf 1)- (e|exit) To exit.$(tput sgr 0)";
else
  echo -e "$(tput setaf 1)Do you want to start the update process? (yes): $(tput sgr 0)";
fi
read answer;
answer=${answer:-Yes}
if [ "$answer" != "${answer#[NnEe]}" ] ;then
  echo "$(tput setaf 2)Mission aborted.$(tput sgr 0)";
  exit;
elif [ "$answer" != "${answer#[Rr]}" ] ; then
  if [ -d ${BASEDIR}/update_backups ]; then
    echo "$(tput setaf 2)Reverting backup.$(tput sgr 0)";
    revert_backup;
    exit;
  else
     echo "$(tput setaf 2)Sorry there is no backup to revert!.$(tput sgr 0)";
     exit;
  fi
elif [ "$answer" != "${answer#[YyUu]}" ] ; then
  touch ${ERRORLOG};
  echo > ${ERRORLOG};

  clear_stdin;
  echo -e "$(tput setaf 1)Do you want to create a backup snapshot before starting the update process? (yes): $(tput sgr 0)";
  read answer;
  answer=${answer:-Yes}
  if [ "$answer" != "${answer#[Yy]}" ] ;then
    echo -e "$(tput setaf 2)Preparing a backup snapshot before performing updates...$(tput sgr 0)";
    backup;
  else
    echo -e "$(tput setaf 2)Backup snapshot skipped...$(tput sgr 0)";
    rm -rf ${BASEDIR}/update_backups;
  fi

  add_git_safe_directories;

  echo -e "$(tput setaf 2)Preparing composer.json for Varbase updates...$(tput sgr 0)";
  echo -e "$(tput setaf 2)Preparing composer.json for Varbase updates...$(tput sgr 0)" >> ${ERRORLOG};
  cleanup;
  composer varbase-refactor-composer ${BASEDIR}/composer.new.json ${DRUPALPATH};
  result="$?";
  if [ "$result" -ne 0 ]; then
      echo -e "$(tput setab 1)$(tput setaf 7)There was an error while preparing composer.json for Varbase updates. Please check ${ERRORLOG} for more information.$(tput sgr 0)";
      echo -e "$(tput setab 1)$(tput setaf 7)If you are running Varbase 8.x-4.x or 8.x-5.x version, make sure to update varbase-project using the update command: $(tput sgr 0)";
      echo -e "$(tput setaf 2)composer require vardot/varbase-updater$(tput sgr 0)";
      exit_and_revert;
  fi
  backup_skipped_modules;
  mv ${BASEDIR}/composer.new.json ${BASEDIR}/composer.json;
  clear_stdin;
  echo "$(tput setaf 4)composer.json has been updated. Now is your chance to perform any manual changes. Please do your changes (if any) then press enter to continue... $(tput sgr 0)";
  read answer;

  echo -e "$(tput setaf 2)Updating Varbase...$(tput sgr 0)";
  echo -e "$(tput setaf 2)Updating Varbase...$(tput sgr 0)" >> ${ERRORLOG};
  composer update 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
  result="$?";
  if [ "$result" -ne 0 ]; then
      echo -e "$(tput setab 1)$(tput setaf 7)There was an error while updating Varbase to the latest version. Please check ${ERRORLOG} for more information.$(tput sgr 0)";
      exit_and_revert;
  fi

  if [ -f ${BASEDIR}/failed-patches.txt ]; then
    echo -e "$(tput setaf 2)Log of all failed patches has been created, please check failed-patches.txt after the update process finishes...$(tput sgr 0)";
  fi

  copy_after_update;
  cd ${BASEDIR}/${DRUPALPATH};
  $DRUSH cr --strict=0 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
  result="$?";
  if [ "$result" -ne 0 ]; then
      echo -e "$(tput setab 1)$(tput setaf 7)Something went wrong while rebuilding the cache (drush cr), this might cause the update to fail.$(tput sgr 0)";
      exit_and_revert;
  fi
  echo -e "$(tput setaf 2)Enabling new required modules for the latest Varbase version...$(tput sgr 0)";
  echo -e "$(tput setaf 2)Enabling new required modules for the latest Varbase version...$(tput sgr 0)" >> ${ERRORLOG};
  enable_after_update;

  echo -e "$(tput setaf 2)Updating the database for latest changes.$(tput sgr 0)";
  echo -e "$(tput setaf 2)Updating the database for latest changes.$(tput sgr 0)" >> ${ERRORLOG};
  $DRUSH  cache-rebuild --yes;
  $DRUSH  updb --yes --strict=0 1> >(tee -a ${ERRORLOG} >&1) 2> >(tee -a ${ERRORLOG} >&2);
  result="$?";
  if [ "$result" -ne 0 ]; then
      echo -e "$(tput setab 1)$(tput setaf 7)There was an error while updating Drupal core. Please check ${ERRORLOG} for more information.$(tput sgr 0)";
      exit_and_revert;
  fi

  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.skip-update ]; then
    rm -rf ${BASEDIR}/vendor/vardot/varbase-updater/config/.skip-update;
  fi
  if [ -f ${BASEDIR}/vendor/vardot/varbase-updater/config/.enable-after-update ]; then
    rm -rf ${BASEDIR}/vendor/vardot/varbase-updater/config/.enable-after-update;
  fi

  cd ${BASEDIR};
  echo "$(tput setaf 2)Hoya! Updates are now done. We will add a link in the near future for here to link to common issues appearing after updates and how to fix them.$(tput sgr 0)";
  echo "$(tput setaf 2)Hoya! Updates are now done. We will add a link in the near future for here to link to common issues appearing after updates and how to fix them.$(tput sgr 0)" >> ${ERRORLOG};
  composer varbase-version-check next-message;
else
  echo "$(tput setaf 2)Unrecognized option, exiting...$(tput sgr 0)";
  exit;
fi
