<?php

namespace vardot\Composer\Helpers;

class VersionHelper{
  public static function getVersionInfo($packages, $updateConfig) {
    $profile = null;
    if(!$updateConfig || !$packages){
      return null;
    }
    if(!isset($updateConfig['profile']) || !isset($updateConfig['package'])){
      return null;
    }
    foreach ($packages as $package) {
      if($package->getName() == $updateConfig['package']){
        $profile = $package;
        break;
      }
    }
    if(!$profile){
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
        if(preg_match('/' . $conf['to'] . '/', $profileVersion)){
          continue;
        }
        if(preg_match('/' . $conf["from"] . '/', $profileVersion)){
          $versionInfo["next"] = $conf["to"];
        }
      }
    }
    return $versionInfo;
  }
}
