<?php
$nextVersions = [
  ['fromVersion' => "8.4.28", 'fromOperator' => "<", "toVersion" => "8.4.28"],
  ['fromVersion' => "8.4.28", 'fromOperator' => "==", "toVersion" => "8.6.2"],
  ['fromVersion' => "8.5.5", 'fromOperator' => "<", "toVersion" => "8.5.5"],
  ['fromVersion' => "8.5.5", 'fromOperator' => "==", "toVersion" => "8.6.2"],
  ['fromVersion' => "8.6.2", 'fromOperator' => "==", "toVersion" => "8.6.2"]
];

$jsonFile = getcwd() . "/composer.json";

if(sizeof($argv) <= 2){
  echo "Please provide version type\n";
  exit;
}

if(isset($argv[2])){
  $jsonFile = $argv[2];
}

$version = "";
$string = file_get_contents($jsonFile);
$json = json_decode($string,true);
switch ($argv[1]){
  case "current":
    $varbaseVersion = $json["require"]["vardot/varbase"];
    $varbaseVersion = preg_replace("/~|\^|=|<|>|>=|<=|==/", "", $varbaseVersion);
    echo $varbaseVersion;
  break;
  case "next":
    $varbaseVersion = $json["require"]["vardot/varbase"];
    $varbaseVersion = preg_replace("/~|\^|=|<|>|>=|<=|==/", "", $varbaseVersion);
    $currentVersion = explode("." , $varbaseVersion);
    foreach($nextVersions as $key => $value){
      $releaseVersion = explode("." , $value["fromVersion"]);
      if($currentVersion[0] == $releaseVersion[0] && $currentVersion[1] == $releaseVersion[1]){
        if(version_compare($varbaseVersion, $value["fromVersion"], $value["fromOperator"])){
          print $value["toVersion"];
        }
      }
    }
  break;
  case "current-message":
  $varbaseVersion = $json["require"]["vardot/varbase"];
  $varbaseVersion = preg_replace("/~|\^|=|<|>|>=|<=|==/", "", $varbaseVersion);
    $currentVersion = explode("." , $varbaseVersion);
    foreach($nextVersions as $key => $value){
      $releaseVersion = explode("." , $value["fromVersion"]);
      if($currentVersion[0] == $releaseVersion[0] && $currentVersion[1] == $releaseVersion[1]){
        if(version_compare($varbaseVersion, $value["fromVersion"], $value["fromOperator"])){
          if($varbaseVersion == $value["toVersion"]){
            print "You are on the latest Varbase version. No updates are required.\n";
          }else{
            print "Updating Varbase (" . $varbaseVersion . ") to Varbase (" . $value["toVersion"] . ")\n";
          }
        }
      }
    }
  break;
  case "next-message":
    $varbaseVersion = $json["require"]["vardot/varbase"];
    $varbaseVersion = preg_replace("/~|\^|=|<|>|>=|<=|==/", "", $varbaseVersion);
    $currentVersion = explode("." , $varbaseVersion);
    foreach($nextVersions as $key => $value){
      $releaseVersion = explode("." , $value["fromVersion"]);
      if($currentVersion[0] == $releaseVersion[0] && $currentVersion[1] == $releaseVersion[1]){
        if(version_compare($varbaseVersion, $value["fromVersion"], $value["fromOperator"])){
          if($varbaseVersion == $value["toVersion"]){
            print "Congratulations! You are on the latest Varbase version now.\n";
          }else{
            print "You are on Varbase (" . $varbaseVersion . "). A newer version (" . $value["toVersion"] . ") is now available.\n";
            print "Please run: ./scripts/update/update-varbase.sh to update to Varbase (" . $value["toVersion"] . ").\n";
          }
        }
      }
    }
  break;
}

?>
