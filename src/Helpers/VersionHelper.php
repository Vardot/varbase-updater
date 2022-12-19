<?php

namespace vardot\Composer\Helpers;

/**
 * Version Helper.
 */
class VersionHelper {

  /**
   * Get latest version info.
   * 
   * @param type $metaData
   * @return string|array
   */
  public static function getLatestVersionInfo($metaData) {
    $latestTags = [];
    if (isset($metaData["packages"])
      && isset($metaData["packages"])
      && isset($metaData["packages"]["vardot/varbase"][0])) {

      $latestTags = $metaData["packages"]["vardot/varbase"][0];
    }

    return $latestTags;
  }

  /**
   * Get Version Info.
   *
   * @param type $packages
   * @param type $updateConfig
   * @param type $latestVersions
   * @return type
   */
  public static function getVersionInfo($packages, $updateConfig, $latestVersions) {
    $profile = null;
    if(!$updateConfig || !$packages){
      return null;
    }

    if(!isset($updateConfig['profile']) || !isset($updateConfig['package'])){
      return null;
    }

    foreach ($packages as $package) {
      if ($package->getName() == $updateConfig['package']) {
        $profile = $package;
        break;
      }
    }

    if (!$profile) {
      return null;
    }

    $profileVersion = $profile->getPrettyVersion();
    $profileName = $profile->getName();
    $profileVersion = preg_replace("/~|\^|=|<|>|>=|<=|==/", "", $profileVersion);
    $versionInfo = [
      "profile" => $profile,
      "profileName" => $profileName,
      "current" => $profileVersion
    ];

    foreach ($updateConfig['versions'] as $key => $conf) {

      if (isset($conf["from"]) && isset($conf["to"])) {

        $conf_from = preg_replace("/\*/", ".*", $conf["from"]);
        $conf_to = preg_replace("/\*/", ".*", $conf["to"]);

        if (($conf_from == $conf_to)
          && ($conf_from == $latestVersions['version'])
          && ($latestVersions['version'] == $profileVersion)) {
          unset($versionInfo["next"]);
        }

        foreach ($latestVersions as $key => $value) {
          if (preg_match("/" . $conf_to . "/", $key ?? '')) {
            $conf_to = $key;
            break;
          }
        }

        if (preg_match("/" . $conf_to . "/", $profileVersion ?? '')) {
          continue;
        }

        if (preg_match("/" . $conf_from . "/", $profileVersion ?? '')) {
          $versionInfo["next"] = $conf_to;
        }
      }
    }

    return $versionInfo;
  }

}
