<?php

namespace vardot\Composer\Commands;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Loader\JsonLoader;
use Composer\Config;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Link;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Yaml\Yaml;
use Composer\EventDispatcher\Event;
use Composer\Json\JsonFile;
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use vardot\Composer\Helpers\VersionHelper;

/**
 * Refactor composer command.
 */
class RefactorComposerCommand extends BaseCommand {

  /**
   * Configure.
   */
  protected function configure() {
    $this->setName('varbase-refactor-composer');
    $this->addArgument('file', InputArgument::REQUIRED, 'Where do you want to save the output');
    $this->addArgument('drupal-path', InputArgument::REQUIRED, 'Drupal installation path');
  }

  /**
   * Execute.
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    try {
      $output->writeln('Refactoring composer.json');
      $path = $input->getArgument('file');
      $drupalPath = $input->getArgument('drupal-path');
      $this->generate($path, $drupalPath);
      return 0;
    } catch (\Exception $e) {
      throw new \Exception('Refactor Composer Command: ' . $e->getMessage(), 0, $e);
      return $e->getCode();
    }
  }

  /**
   * Get the Drupal root directory.
   *
   * @param string $project_root
   *    Project root.
   *
   * @return string
   *    Drupal root path.
   */
  protected function getDrupalRoot($project_root, $rootPath = "docroot") {
    return $project_root . '/' . $rootPath;
  }

  /**
   * Get Paths.
   *
   * @param type $package
   * @param type $drupalPath
   * @return string
   */
  protected function getPaths($package, $drupalPath = "docroot") {
    $paths = [];
    $projectExtras = $package->getExtra();

    $scriptPath = dirname(__FILE__);
    $paths["composerPath"] = $this->getDrupalRoot(getcwd(), "");
    $paths["pluginPath"] = $this->getDrupalRoot($scriptPath, "../../");
    $paths["rootPath"] = $drupalPath;
    $paths["contribModulesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/modules/contrib/";
    $paths["customModulesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/modules/custom/";
    $paths["contribThemesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/themes/contrib/";
    $paths["customThemesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/themes/custom/";
    $paths["librariesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/libraries/";
    $paths["profilesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/profiles/";

    if (isset($projectExtras["installer-paths"])) {
      foreach ($projectExtras["installer-paths"] as $path => $types) {
        foreach ($types as $type) {
          if ($type == "type:drupal-module") {
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["contribModulesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }

          if ($type == "type:drupal-custom-module") {
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["customModulesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }

          if ($type == "type:drupal-theme") {
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["contribThemesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }

          if ($type == "type:drupal-custom-theme") {
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["customThemesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }

          if ($type == "type:drupal-profile") {
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["profilesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }

          if ($type == "type:drupal-library"
            || $type == "type:bower-asset"
            || $type == "type:npm-asset" ) {

            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["librariesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
        }
      }
    }

    return $paths;
  }

  /**
   *  Array merge recursive distinct.
   *
   * @param array $array1
   * @param array $array2
   * @param type $drupalPath
   * @return type
   */
  public function array_merge_recursive_distinct(array &$array1, array &$array2, $drupalPath) {
    $merged = $array1;
    foreach ($array2 as $key => &$value) {
      $newKey = preg_replace('/{\$drupalPath}/', $drupalPath, $key);
      $newKey = preg_replace('/docroot/', $drupalPath, $newKey);

      if (!isset($merged[$newKey])) {
        $merged[$newKey] = [];
      }

      if (is_array($value) && isset($merged[$newKey]) && is_array($merged[$newKey])) {
        $merged[$newKey] = self::array_merge_recursive_distinct($merged[$newKey], $value, $drupalPath);
      }
      else {
        $newValue = preg_replace('/{\$drupalPath}/', $drupalPath, $value);
        $newValue = preg_replace('/docroot/', $drupalPath, $newValue);
        $merged[$newKey] = $newValue;
      }
    }

    return $merged;
  }

  /**
   * Generate.
   *
   * @param type $savePath
   * @param type $drupalPath
   * @return type
   */
  public function generate($savePath, $drupalPath) {
    $composer = $this->getComposer();
    $latestProjectJsonPackage = null;
    $repositoryManager = $composer->getRepositoryManager();
    $localRepository = $repositoryManager->getLocalRepository();
    $packages = $localRepository->getPackages();
    $projectPackage = $composer->getPackage();
    $projectPackageRequires = $projectPackage->getRequires();
    $projectPackageExtras = $projectPackage->getExtra();
    $projectPackageRepos = $composer->getConfig()->getRepositories();
    $projectScripts = $projectPackage->getScripts();
    $projectPackagePatches = [];
    $continue = true;
    $paths = $this->getPaths($composer->getPackage(), $drupalPath);
    $loader = new JsonLoader(new ArrayLoader());
    $downloader = new RemoteFilesystem($this->getIO(), $this->getComposer()->getConfig());

    $updateConfigPath = $paths["pluginPath"] . "config/update-config.json";
    $extraConfig = [];
    if (file_exists($paths["composerPath"] . "update-config.json")) {
      $extraConfig = json_decode(file_get_contents($paths["composerPath"] . "update-config.json"), TRUE);
    }

    $updateConfig = json_decode(file_get_contents($updateConfigPath), TRUE);
    $error = json_last_error();
    $updateConfig = array_replace_recursive($updateConfig, $extraConfig);

    $varbaseMetaData = [];
    $composerProjectJsonUrl = "https://packagist.org/packages/vardot/varbase.json";
    $filename = uniqid(sys_get_temp_dir().'/') . ".json";
    $hostname = parse_url($composerProjectJsonUrl, PHP_URL_HOST);
    $downloader->copy($hostname, $composerProjectJsonUrl, $filename, FALSE);

    if (file_exists($filename)) {
      $varbaseMetaData = JsonFile::parseJson(file_get_contents($filename), $filename);
    }

    $latestTags = VersionHelper::getLatestVersionInfo($varbaseMetaData);
    $versionInfo = VersionHelper::getVersionInfo($packages, $updateConfig, $latestTags);

    if (!$versionInfo) {
      $continue = false;
    }

    if (!isset($updateConfig['profile']) || !isset($updateConfig['package'])) {
      $continue = false;
    }

    if (!isset($versionInfo['next'])) {
      $continue = false;
    }

    if (!$continue) {
      $dumper = new ArrayDumper();
      $json = $dumper->dump($projectPackage);
      $json["prefer-stable"] = true;
      $projectConfig = JsonFile::encode($json);
      file_put_contents($savePath, $projectConfig);
      return;
    }

    if (isset($projectPackageExtras["patches"])) {
      $projectPackagePatches = $projectPackageExtras["patches"];
    }

    $profilePackage = $versionInfo["profile"];
    $profileVersion = $profilePackage->getVersion();
    $profilePackageRequires = $profilePackage->getRequires();

    $profileLink = $projectPackageRequires[$updateConfig['package']];
    $requiredPackages = [];
    $crucialPackages = [];
    $requiredPackageLinks = [];
    $requiredPackageLinks[$updateConfig['package']] = $profileLink;
    $extras = [];
    $repos = [];
    $scripts = [];


    foreach ($updateConfig as $key => $conf) {

      if (isset($conf["from"]) && isset($conf["to"])) {
        $conf["from"] = preg_replace("/\*/", ".*", $conf["from"]);
        $conf["to"] = preg_replace("/\*/", ".*", $conf["to"]);

        foreach($latestTags as $key => $value){
          if(preg_match('/' . $conf['to'] . '/', $key)){
            $conf["to"] = $key;
            break;
          }
        }

        if (preg_match('/' . $conf['to'] . '/', $profileVersion)) {
          continue;
        }

        if (preg_match('/' . $conf["from"] . '/', $profileVersion)) {
          
          if (isset($conf["composer-project-json-url"])) {

            if ($conf["composer-project-json-url"] == 'latest') {

              // Get the latest release for Varbase project.
              $varbaseProjectTargetRelease = [];
              $varbaseProjectTargetJsonUrl = "https://api.github.com/repos/Vardot/varbase-project/tags";
              $varbaseProjectTargetFilename = tempnam(sys_get_temp_dir(), 'json');
              $this->getFileFromURL($varbaseProjectTargetJsonUrl, $varbaseProjectTargetFilename);

              if (file_exists($varbaseProjectTargetFilename)) {
                $varbaseProjectTargetRelease = JsonFile::parseJson(file_get_contents($varbaseProjectTargetFilename), $varbaseProjectTargetFilename);
              }

              // Varbase Project Latest release tag name.
              $tagName = $varbaseProjectTargetRelease[0]['name'];

              $composerProjectJsonUrl = "https://raw.githubusercontent.com/vardot/varbase-project/" . $tagName . "/composer.json";
            }
            else {
              $composerProjectJsonUrl = "https://raw.githubusercontent.com/vardot/varbase-project/" . $conf["composer-project-json-url"] . "/composer.json";
            }
          }
          elseif (isset($conf['to'])) {
            $tagNameInTo = str_replace("*","0", $conf['to']);
            $composerProjectJsonUrl = "https://raw.githubusercontent.com/vardot/varbase-project/" . $tagNameInTo . "/composer.json";
          }

          $filename = uniqid(sys_get_temp_dir().'/') . ".json";
          $hostname = parse_url($composerProjectJsonUrl, PHP_URL_HOST);
          $downloader->copy($hostname, $composerProjectJsonUrl, $filename, FALSE);

          if (file_exists($filename)) {
            $latestProjectJsonConfig = JsonFile::parseJson(file_get_contents($filename), $filename);
            $config = new Config();
            $config->merge($latestProjectJsonConfig);
            $rootLoader = new RootPackageLoader($repositoryManager, $config);
            $latestProjectJsonPackage = $rootLoader->load($latestProjectJsonConfig);
          }
          
          $profileLinkConstraint = new Constraint(">=", $conf["to"]);
          $profileLinkConstraint->setPrettyString($conf["final_target_version"]);
          $profileLink = new Link($projectPackage->getName(), $updateConfig['package'], $profileLinkConstraint , "", $conf["final_target_version"]);

          $requiredPackageLinks = [];
          $requiredPackageLinks[$updateConfig['package']] = $profileLink;

          if (isset($conf["packages"]["crucial"])) {
            $crucialPackages = array_replace_recursive($crucialPackages, $conf["packages"]["crucial"]);
          }
          $enableAfterUpdatePath = $paths["pluginPath"] . "config/.enable-after-update";
          if (isset($conf["enable-after-update"])) {
            $output = "";
            foreach ($conf["enable-after-update"] as $key => $value) {
              $output .= $value . PHP_EOL;
            }
            file_put_contents($enableAfterUpdatePath, $output);
          }
          else {
            file_put_contents($enableAfterUpdatePath, "");
          }
          $skipUpdatePath = $paths["pluginPath"] . "config/.skip-update";
          if (isset($conf["skip"])) {
            $output = "";
            foreach ($conf["skip"] as $key => $value) {
              $output .= $value . PHP_EOL;
            }
            file_put_contents($skipUpdatePath, $output);
          }
          else {
            file_put_contents($skipUpdatePath, "");
          }
          if (isset($conf["scripts"])) {
            $scripts = array_replace_recursive($scripts, $conf["scripts"]);
          }
          if (isset($conf["repos"])) {
            $repos = array_replace_recursive($repos, $conf["repos"]);
          }
          if (isset($conf["extras"])) {
            $extras = array_replace_recursive($extras, $conf["extras"]);
          }
        }
      }
      elseif ($key == "all" && false) {
        if (isset($conf["packages"]["crucial"])) {
          $crucialPackages = array_replace_recursive($crucialPackages, $conf["packages"]["crucial"]);
        }
        if (isset($conf["enable-after-update"])) {
          $output = "";
          foreach ($conf["enable-after-update"] as $key => $value) {
            $output .= $value . PHP_EOL;
          }
          $enableAfterUpdatePath = $paths["pluginPath"] . "config/.enable-after-update";
          file_put_contents($enableAfterUpdatePath, $output);
        }
        if (isset($conf["skip"])) {
          $output = "";
          foreach ($conf["skip"] as $key => $value) {
            $output .= $value . PHP_EOL;
          }
          $skipUpdatePath = $paths["pluginPath"] . "config/.skip-update";
          file_put_contents($skipUpdatePath, $output);
        }
        if (isset($conf["scripts"])) {
          $scripts = array_replace_recursive($scripts, $conf["scripts"]);
        }
        if (isset($conf["repos"])) {
          $repos = array_replace_recursive($repos, $conf["repos"]);
        }
        if (isset($conf["extras"])) {
          $extras = array_replace_recursive($extras, $conf["extras"]);
        }
      }
    }

    foreach (glob($paths['contribModulesPath'] . "*/*.info.yml") as $file) {
      $yaml = Yaml::parse(file_get_contents($file));
      if (isset($yaml["project"])
        && isset($yaml["version"])
        && $yaml["project"] != $updateConfig['profile']) {

        $composerRepo = "drupal";
        $composerName = $composerRepo . "/" . $yaml["project"];
        $composerVersion = str_replace("8.x-", "", $yaml["version"]);

        if (isset($projectPackagePatches[$composerName])) {
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion, "patch" => true];
        }
        elseif (!isset($profilePackageRequires[$composerName])) {
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion];
        }
      }
    }

    foreach (glob($paths['contribThemesPath'] . "*/*.info.yml") as $file) {
      $yaml = Yaml::parse(file_get_contents($file));
      if (isset($yaml["project"])
        && isset($yaml["version"])
        && $yaml["project"] != $updateConfig['profile']) {

        $composerRepo = "drupal";
        $composerName = $composerRepo . "/" . $yaml["project"];
        $composerVersion = str_replace("8.x-", "", $yaml["version"]);
        if (!isset($profilePackageRequires[$composerName])) {
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion];
        }
      }
    }


    foreach (glob($paths['contribModulesPath'] . "*/composer.json") as $file) {
      $pluginConfig = JsonFile::parseJson(file_get_contents($file), $file);
      if (!isset($pluginConfig['version'])) {
        $pluginConfig['version'] = "0.0.0";
      }
      $pluginConfig = JsonFile::encode($pluginConfig);
      $pluginPackage = $loader->load($pluginConfig);
      $pluginPackageRequires = $pluginPackage->getRequires();

      foreach ($requiredPackages as $name => $package) {
        if (isset($projectPackagePatches[$name])) {
          continue;
        }
        if (isset($pluginPackageRequires[$name])) {
          unset($requiredPackages[$name]);
        }
      }
    }

    foreach (glob($paths['contribThemesPath'] . "*/composer.json") as $file) {
      $pluginConfig = JsonFile::parseJson(file_get_contents($file), $file);
      if (!isset($pluginConfig['version'])) {
        $pluginConfig['version'] = "0.0.0";
      }
      $pluginConfig = JsonFile::encode($pluginConfig);
      $pluginPackage = $loader->load($pluginConfig);
      $pluginPackageRequires = $pluginPackage->getRequires();

      foreach ($requiredPackages as $name => $package) {
        if (isset($pluginPackageRequires[$name])) {
          unset($requiredPackages[$name]);
        }
      }
    }

    foreach ($requiredPackages as $name => $package) {
      if (isset($projectPackageRequires[$name])) {
        $requiredPackageLinks[$name] = $projectPackageRequires[$name];
      }
      else {
        $link = new Link($projectPackage->getName(), $package["name"], new Constraint(">=", $package["version"]), "", "^".$package["version"]);
        $requiredPackageLinks[$name] = $link;
      }
    }

    foreach ($crucialPackages as $key => $version) {
      $link = new Link($projectPackage->getName(), $key, new Constraint("==", $version), "", $version);
      $requiredPackageLinks[$key] = $link;
    }

    foreach ($projectPackageRequires as $projectName => $projectPackageLink) {
      if(!isset($profilePackageRequires[$projectName]) && !isset($requiredPackageLinks[$projectName])){
        $requiredPackageLinks[$projectName] = $projectPackageLink;
      }
    }

    if (!$projectPackageExtras) {
      $projectPackageExtras = [];
    }

    $mergedExtras = self::array_merge_recursive_distinct($projectPackageExtras, $extras, $paths["rootPath"]);
    $mergedRepos = self::array_merge_recursive_distinct($projectPackageRepos, $repos, $paths["rootPath"]);
    $mergedScripts = self::array_merge_recursive_distinct($projectScripts, $scripts, $paths["rootPath"]);

    if (!$latestProjectJsonPackage) {
      $projectPackage->setExtra($mergedExtras);
      $projectPackage->setRepositories($mergedRepos);
      $projectPackage->setRequires($requiredPackageLinks);
      $projectPackage->setScripts($mergedScripts);

      $dumper = new ArrayDumper();
      $json = $dumper->dump($projectPackage);
      $json["prefer-stable"] = true;
      $json["extra"]["composer-exit-on-patch-failure"] = false;

      // Fixing the position of installer path web/libraries/{$name} as it should be after slick and ace so it won't override them
      if (isset($json["extra"])
        && isset($json["extra"]["installer-paths"])
        && isset($json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}'])) {

        $libsPathExtra = $json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}'];
        unset($json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}']);
        $extraLibsArray=[
          $paths["rootPath"].'/libraries/{$name}' => $libsPathExtra
        ];
        $json["extra"]["installer-paths"] = $json["extra"]["installer-paths"] + $extraLibsArray;
      }

      if (isset($json["repositories"]["packagist.org"])) {
        unset($json["repositories"]["packagist.org"]);
      }

      if (isset($json["version"])) {
        unset($json["version"]);
      }

      if (isset($json["version_normalized"])) {
        unset($json["version_normalized"]);
      }

      foreach ($json["repositories"] as $key => $value) {
        if ($key == "packagist.org") {
          unset($json["repositories"][$key]);
        }
        if (isset($json["repositories"]["drupal"])
          && $key != "drupal"
          && isset($value["url"])
          && $value["url"] == "https://packages.drupal.org/8") {

          unset($json["repositories"][$key]);
        }
      }

      $projectConfig = JsonFile::encode($json);
      file_put_contents($savePath, $projectConfig);
    }
    else {
      $latestExtras = $latestProjectJsonPackage->getExtra();
      $latestRepos = $latestProjectJsonPackage->getRepositories();
      $latestRequires = $latestProjectJsonPackage->getRequires();
      $latestScripts = $latestProjectJsonPackage->getScripts();


      $latestMergedExtras = self::array_merge_recursive_distinct($mergedExtras, $latestExtras, $paths["rootPath"]);
      $latestMergedRepos = self::array_merge_recursive_distinct($mergedRepos, $latestRepos, $paths["rootPath"]);
      // $latestMergedRequires = self::array_merge_recursive_distinct($requiredPackageLinks, $latestRequires, $paths["rootPath"]);
      $latestMergedScripts = self::array_merge_recursive_distinct($mergedScripts, $latestScripts, $paths["rootPath"]);

      foreach ($latestRequires as $projectName => $projectPackageLink) {
        if ($projectName == $updateConfig['package']) {
          continue;
        }

        $requiredPackageLinks[$projectName] = $projectPackageLink;
      }

      foreach ($crucialPackages as $key => $version) {
        $link = new Link($projectPackage->getName(), $key, new Constraint("==", $version), "", $version);
        $requiredPackageLinks[$key] = $link;
      }

      $latestProjectJsonPackage->setExtra($latestMergedExtras);
      $latestProjectJsonPackage->setRepositories($latestMergedRepos);
      $latestProjectJsonPackage->setRequires($requiredPackageLinks);
      $latestProjectJsonPackage->setScripts($latestMergedScripts);


      $dumper = new ArrayDumper();
      $json = $dumper->dump($latestProjectJsonPackage);
      $json["prefer-stable"] = true;
      $json["extra"]["composer-exit-on-patch-failure"] = false;

      // Fixing the position of installer path web/libraries/{$name} as it
      // should be after slick and ace so it won't override them.
      if (isset($json["extra"])
        && isset($json["extra"]["installer-paths"])
        && isset($json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}'])) {

        $libsPathExtra = $json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}'];
        unset($json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}']);
        $extraLibsArray=[
          $paths["rootPath"].'/libraries/{$name}' => $libsPathExtra
        ];
        $json["extra"]["installer-paths"] = $json["extra"]["installer-paths"] + $extraLibsArray;
      }

      if (isset($json["repositories"]["packagist.org"])) {
        unset($json["repositories"]["packagist.org"]);
      }

      if (isset($json["version"])) {
        unset($json["version"]);
      }

      if (isset($json["version_normalized"])) {
        unset($json["version_normalized"]);
      }

      foreach ($json["repositories"] as $key => $value) {
        if ($key == "packagist.org"){
          unset($json["repositories"][$key]);
        }

        if (isset($json["repositories"]["drupal"])
          && $key != "drupal"
          && isset($value["url"])
          && $value["url"] == "https://packages.drupal.org/8") {

          unset($json["repositories"][$key]);
        }
      }

      $latestProjectConfig = JsonFile::encode($json);
      file_put_contents($savePath, $latestProjectConfig);
    }
  }
  
  /**
   * Get file from URL
   *
   * @param type $url
   * @param type $newfilename
   */
  public function getFileFromURL($url, $newfilename) {
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

  }
}
