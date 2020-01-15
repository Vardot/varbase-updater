<?php

function get_file($url, $newfilename) {
  $err_msg = '';
  echo "Downloading $url";
  echo "\n";
  $out = fopen($newfilename, "wrxb");
  if ($out == FALSE){
    print "File not opened.<br>";
    exit;
  }

  $ch = curl_init();

  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Vardot/varbase-updater/1.0; +https://github.com/Vardot/varbase-updater)');
  curl_setopt($ch, CURLOPT_FILE, $out);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_URL, $url);

  curl_exec($ch);

  curl_close($ch);
  //fclose($handle);

} //end function

  echo "Starting varbase-project updater!\n";

  $path = getcwd()."/composer.json";
  if (!file_exists($path)) {
    echo "\n";
    echo "Please run this command from your varbase-project root directory";
    echo "\n";
    exit;
  }
  $string = file_get_contents(getcwd()."/composer.json");
  $json=json_decode($string,true);

  if (isset($json["name"]) && $json["name"] != "vardot/varbase-project") {
    echo "\n";
    echo "Please run this command from your varbase-project root directory";
    echo "\n";
    exit;
  }

  if (!isset($json["name"])) {
    echo "\n";
    echo "Please run this command from your varbase-project root directory";
    echo "\n";
    exit;
  }

  if (!isset($json["autoload"])) {
    $json["autoload"] = [
      "psr-4" => [
        "Varbase\\composer\\" => "scripts/composer"
      ]
    ];
  }
  else if(isset($json["autoload"]["psr-4"])) {
    $json["autoload"]["psr-4"]["Varbase\\composer\\"] = "scripts/composer";
  }
  else {
    $json["autoload"]["psr-4"] = [
      "Varbase\\composer\\" => "scripts/composer"
    ];
  }

  if (!isset($json["scripts"])) {
    $json["scripts"] = [
      "varbase-composer-generate" => [
        "Varbase\\composer\\VarbaseUpdate::generate"
      ]
    ];
  }
  else if(isset($json["scripts"])) {
    $json["scripts"]["varbase-composer-generate"]= [
      "Varbase\\composer\\VarbaseUpdate::generate"
    ];
  }

  $drupalPath = "docroot";
  if (file_exists(getcwd().'/web')) {
    $drupalPath = "web";
  }

  echo "Drupal root set to " . $drupalPath . " if your Drupal root is different than this, please change install-path inside your composer.json under the 'extra' section.\n";

  if (!isset($json["extra"])) {
    $json["extra"] = [
      "install-path" => $drupalPath
    ];
  }
  else {
    $json["extra"]["install-path"] = $drupalPath;
  }

  $jsondata = json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);


  if (!file_exists(getcwd().'/scripts/composer')) {
    mkdir(getcwd().'/scripts/composer', 0777, true);
  }

  if (!file_exists(getcwd().'/scripts/update')) {
    mkdir(getcwd().'/scripts/update', 0777, true);
  }

  if (!file_exists(getcwd().'/drush')) {
    mkdir(getcwd().'/drush', 0777, true);
  }

  if (!file_exists(getcwd().'/bin')) {
    mkdir(getcwd().'/bin', 0777, true);
  }

  // Get the latest release for Varbase Updater.
  $varbaseUpdaterLatestRelease = [];
  $varbaseUpdaterJsonUrl = "https://api.github.com/repos/vardot/varbase-updater/tags";
  $varbaseUpdaterFilename = tempnam(sys_get_temp_dir(), 'json');
  get_file($varbaseUpdaterJsonUrl, $varbaseUpdaterFilename);

  if (file_exists($varbaseUpdaterFilename)) {
    $varbaseUpdaterLatestRelease = JsonFile::parseJson(file_get_contents($varbaseUpdaterFilename), $varbaseUpdaterFilename);
  }

  // Varbase Updater Latest release tag name.
  $tagName = $varbaseUpdaterLatestRelease[0]['name'];

  $base_path = "https://raw.githubusercontent.com/vardot/varbase-updater/" . $tagName . "/";
  get_file($base_path . "scripts/composer/VarbaseUpdate.php", getcwd().'/scripts/composer/VarbaseUpdate.php');
  get_file($base_path . "scripts/update/update-varbase.sh", getcwd().'/scripts/update/update-varbase.sh');
  get_file($base_path . "scripts/update/version-check.php", getcwd().'/scripts/update/version-check.php');
  get_file($base_path . "scripts/update/update-config.json", getcwd().'/scripts/update/update-config.json');

  // Only download them if they don't exist.
  if (!file_exists(getcwd().'/drush/policy.drush.inc')) {
    get_file($base_path . "drush/policy.drush.inc", getcwd().'/drush/policy.drush.inc');
  }

  if (!file_exists(getcwd().'/drush/README.md')) {
    get_file($base_path . "drush/README.md", getcwd().'/drush/README.md');
  }

  chmod(getcwd().'/scripts/update/update-varbase.sh', 0755);
  chmod(getcwd().'/scripts/update/version-check.php', 0755);
  chmod(getcwd().'/scripts/composer/VarbaseUpdate.php', 0755);

  if (file_put_contents($path, $jsondata)) {
    echo "varbase-project successfully updated.\n";
    echo "Now you can run ./scripts/update/update-varbase.sh to update Varbase to the latest version.\n";
    echo "Enjoy!\n";
  }
  else {
    echo "Error while updating varbase-project.\n";
    echo ":(\n";
  }
