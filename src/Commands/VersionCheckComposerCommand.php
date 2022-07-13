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
use Composer\Package\Loader\JsonLoader;
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
 * Version check composer command.
 */
class VersionCheckComposerCommand extends BaseCommand {

  /**
   * Configure.
   */
  protected function configure() {
    $this->setName('varbase-version-check');
    $this->addArgument('type', InputArgument::REQUIRED, 'Version type');
  }

  /**
   * Execute. 
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    try {
      $type = $input->getArgument('type');
      $this->getVersion($type);
      return 0;
    } catch (\Exception $e) {
      throw new \Exception('Version Check Composer Command: ' . $e->getMessage(), 0, $e);
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
   * @return string
   */
  protected function getPaths($package) {
    $paths = [];
    $projectExtras = $package->getExtra();

    $scriptPath = dirname(__FILE__);
    $paths["composerPath"] = $this->getDrupalRoot(getcwd(), "");
    $paths["pluginPath"] = $this->getDrupalRoot($scriptPath, "../../");
    $paths["rootPath"] = "docroot";

    if (isset($projectExtras["install-path"])) {
      $paths["rootPath"] = $projectExtras["install-path"];
    }

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
   * Array merge recursive distinct. 
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
      if (!isset($merged[$newKey])) {
        $merged[$newKey] = [];
      }

      if (is_array($value) && isset($merged[$newKey]) && is_array($merged[$newKey])) {
        $merged[$newKey] = self::array_merge_recursive_distinct($merged[$newKey], $value, $drupalPath);
      }
      else {
        $newValue = preg_replace('/{\$drupalPath}/', $drupalPath, $value);
        $merged[$newKey] = $newValue;
      }
    }
    return $merged;

  }

  /**
   * Get version.
   *
   * @param type $type
   * @return type
   */
  public function getVersion($type) {
    $composer = $this->getComposer();
    $repositoryManager = $composer->getRepositoryManager();
    $localRepository = $repositoryManager->getLocalRepository();
    $packages = $localRepository->getPackages();
    $paths = $this->getPaths($composer->getPackage());
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
      return;
    }

    switch ($type) {
      case "composer-patches":
        if (!defined('cweagans\Composer\PatchEvents::PATCH_APPLY_ERROR')) {
          exit(1);
        }
        else {
          exit(0);
        }
      break;
      case "current":
        print $versionInfo["current"];
      break;
      case "next":
        if(isset($versionInfo['next'])){
          print $versionInfo["next"];
        }
      break;
      case "current-message":
        $profileName = $versionInfo["profileName"];
        if (isset($versionInfo['next'])) {
          print "Updating $profileName (" . $versionInfo["current"] . ") to $profileName (" . $versionInfo["next"] . ")\n";
        }
        else {
          print "You are on the latest $profileName version. No updates are required.\n";
        }
      break;
      case "next-message":
        $profileName = $versionInfo["profileName"];
        if (isset($versionInfo['next'])) {
          print "You are on $profileName (" . $versionInfo["current"] . "). A newer version (" . $versionInfo["next"] . ") is now available.\n";
          print "Please run: ./bin/update-varbase.sh to update to $profileName (" . $versionInfo["next"] . ").\n";
        }
        else {
          print "Congratulations! You are on the latest $profileName version now.\n";
        }
      break;
    }
  }
}
