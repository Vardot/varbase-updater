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
    $tags = [];
    $latestTags = [];
    $versionsArray = [];
    if (isset($metaData["package"])) {
      $versionsArray = $metaData["package"]["versions"];
    }
    else {
      return $latestTags;
    }

    if ($versionsArray && sizeof($versionsArray)) {
      foreach ($versionsArray as $version => $meta) {
        if (preg_match('/\d+\.\d+.\d+/', $version)) {
          $numbers = [];
          preg_match('/(\d+\.\d+).(\d+)/', $version, $numbers);
          if (sizeof($numbers)) {
            $major = $numbers[1];
            $minor = $numbers[2];
            if (isset($tags[$major])) {
              if($tags[$major] < $minor) {
                $tags[$major] = $minor;
              }
            }
            else {
              $tags[$major] = $minor;
            }
          }
        }
      }

      foreach ($tags as $major => $minor) {
        $latestTags[$major.".".$minor] = $major.".".$minor;
      }

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

    foreach ($updateConfig as $key => $conf) {
      if (isset($conf["from"]) && isset($conf["to"])) {
        $conf["from"] = preg_replace("/\*/", ".*", $conf["from"]);
        $conf["to"] = preg_replace("/\*/", ".*", $conf["to"]);

        foreach ($latestVersions as $key => $value) {
          if (preg_match('/' . $conf['to'] . '/', $key)){
            $conf["to"] = $key;
            break;
          }
        }

        if (preg_match('/' . $conf['to'] . '/', $profileVersion)) {
          continue;
        }

        if (preg_match('/' . $conf["from"] . '/', $profileVersion)) {
          $versionInfo["next"] = $conf["to"];
        }
      }
    }

    return $versionInfo;
  }

}
