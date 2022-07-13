<?php

namespace vardot\Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
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
use Symfony\Component\Yaml\Yaml;
use Composer\EventDispatcher\Event;
use Composer\Json\JsonFile;
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;

/**
 * Varbase Updater.
 */
class VarbaseUpdater implements PluginInterface, EventSubscriberInterface, Capable {

  /**
   * @var Composer $composer
   */
  protected $composer;

  /**
   * @var IOInterface $io
   */
  protected $io;

  /**
   * Apply plugin modifications to Composer
   *
   * @param Composer    $composer
   * @param IOInterface $io
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Remove any hooks from Composer
   *
   * This will be called when a plugin is deactivated before being
   * uninstalled, but also before it gets upgraded to a new version
   * so the old one can be deactivated and the new one activated.
   *
   * @param Composer    $composer
   * @param IOInterface $io
   */
  public function deactivate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Prepare the plugin to be uninstalled
   *
   * This will be called after deactivate.
   *
   * @param Composer    $composer
   * @param IOInterface $io
   */
  public function uninstall(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Get subscribed events.
   *
   * Returns an array of event names this subscriber wants to listen to.
   */
  public static function getSubscribedEvents() {
    $events = array();

    if (defined('cweagans\Composer\PatchEvents::PATCH_APPLY_ERROR')) {
      $events[PatchEvents::PATCH_APPLY_ERROR] = array('handlePackagePatchError', 11);
    }

    return $events;

  }

  /**
   * Get Capabilities.
   *
   * Return a list of plugin capabilities.
   *
   * @return array
   */
  public function getCapabilities() {
    return array(
        'Composer\Plugin\Capability\CommandProvider' => 'vardot\Composer\Commands\CommandsProvider'
    );
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
   * Get a Package object from an OperationInterface object.
   *
   * @param OperationInterface $operation
   * @return PackageInterface
   * @throws \Exception
   *
   * @todo Will this method ever get something other than an InstallOperation or UpdateOperation?
   */
  protected function getPackageFromOperation($operation) {
    if ($operation instanceof InstallOperation) {
      $package = $operation->getPackage();
    }
    elseif ($operation instanceof UpdateOperation) {
      $package = $operation->getTargetPackage();
    }
    else {
      throw new \Exception('Unknown operation: ' . get_class($operation));
    }
    return $package;
  }

  /**
   * Writes a patch report to the target directory.
   *
   * @param array $patches
   * @param string $directory
   */
  protected function writePatchReport($packageName, $packagePath, $patchUrl, $description, $isApplied, $directory) {
    $logFile = $directory . "failed-patches.txt";
    if (!file_exists($logFile)) {
        $output = "This file was automatically generated by Varbase Updater (https://github.com/Vardot/varbase-updater)\n";
        $output .= "Patches failed to apply:\n\n";
        file_put_contents($logFile, $output);
    }

    $reason = "Failed to apply patch";

    if ($isApplied) {
      $reason = "Patch already applied";
    }

    $output = "Patch: " . $patchUrl . "\n";
    $output .= "\t Reason: " . $reason . "\n";
    $output .= "\t Description: " . $description . "\n";
    $output .= "\t Package Name: " . $packageName . "\n";
    $output .= "\t Package Path: " . $packagePath . "\n\n\n";
    file_put_contents($logFile, $output, FILE_APPEND | LOCK_EX);
  }

  /**
   * Handle Package Patch Error.
   *
   * @param PatchEvent $event
   */
  public function handlePackagePatchError(PatchEvent $event) {
    $logPath = $this->getDrupalRoot(getcwd(), "");

    $io = $event->getIO();
    $composer = $event->getComposer();
    $rootPackage = $composer->getPackage();
    $rootPackageExtras = $rootPackage->getExtra();

    $package = $event->getPackage();
    $manager = $event->getComposer()->getInstallationManager();
    $installPath = $manager->getInstaller($package->getType())->getInstallPath($package);
    $patchUrl = $event->getUrl();

    $repositoryManager = $composer->getRepositoryManager();
    $localRepository = $repositoryManager->getLocalRepository();
    $packages = $localRepository->getPackages();

    // Set up a downloader.
    $downloader = new RemoteFilesystem($event->getIO(), $event->getComposer()->getConfig());

    if (file_exists($patchUrl)) {
      $filename = realpath($patchUrl);
    }
    else {
      // Generate random (but not cryptographically so) filename.
      $filename = uniqid(sys_get_temp_dir().'/') . ".patch";

      // Download file from remote filesystem to this location.
      $hostname = parse_url($patchUrl, PHP_URL_HOST);
      $downloader->copy($hostname, $patchUrl, $filename, FALSE);
    }

    $patchLevels = array('-p1', '-p0', '-p2', '-p4');

    $isApplied = false;
    $executor = new ProcessExecutor($event->getIO());
    foreach ($patchLevels as $patchLevel) {

      // Shell-escape all arguments except the command.
      $args = [
        "patch %s -R --dry-run --no-backup-if-mismatch -f -d %s < %s",
        $patchLevel,
        $installPath,
        $filename
      ];

      foreach ($args as $index => $arg) {
        if ($index !== 0) {
          $args[$index] = escapeshellarg($arg);
        }
      }

      // And replace the arguments.
      $command = call_user_func_array('sprintf', $args);
      $output = '';

      // Use --dry-run to check if patch applies to prevent partial patches same as --check in git apply.
      if ($executor->execute($command, $output) == 0) {
        $isApplied = true;
        break;
      }
    }

    if ($isApplied) {

      $io->write([
          "<warning>Patch: " . $event->getUrl() . "</warning>",
          "<warning>\t" . $event->getDescription() . "</warning>",
          "<warning>\tis already applied or committed in " . $package->getName() . " " . $package->getFullPrettyVersion() . "</warning>"
        ]
      );

      $answer = $io->ask("<info>Would you like to remove it form your composer.json patches list? (yes)</info>", "yes");

      if (preg_match("/yes/i", $answer)) {
        $io->write("<info>Removing patch: " . $event->getUrl() . "</info>", true);
        $patches = [];
        $patchesFile = "";
        if (isset($rootPackageExtras['patches'])) {
          $io->write('<info>Removing patch from root composer.json.</info>');
          $patches = $rootPackageExtras['patches'];
        } elseif (isset($rootPackageExtras['patches-file'])) {
          $io->write('<info>Removing patch from patches file: ' . $rootPackageExtras['patches-file'] . '.</info>');
          $patchesFile = file_get_contents($rootPackageExtras['patches-file']);
          $patchesFile = json_decode($patchesFile, TRUE);
          $error = json_last_error();

          if ($error != 0) {
            $io->write('<error>There was an error reading the patches file.</error>');
          }

          if (isset($patchesFile['patches'])) {
            $patches = $patchesFile['patches'];
          }
        }
        else {
          //shouldn't reach here!
          $io->write('<warning>Hmmm, no patches supplied!</warning>');
        }

        $found = false;
        if (isset($patches[$package->getName()])) {
          foreach ($patches[$package->getName()] as $key => $url) {
            if ($url == $event->getUrl()) {
              $found = true;
              unset($patches[$package->getName()][$key]);
            }
          }

          if (!sizeof($patches[$package->getName()])) {
            unset($patches[$package->getName()]);
          }
        }

        if ($found) {
          $io->write('<info>Saving changes.</info>');
          if (isset($rootPackageExtras['patches'])) {
            $rootPackageExtras['patches'] = $patches;
            $rootPackage->setExtra($rootPackageExtras);
            $dumper = new ArrayDumper();
            $json = $dumper->dump($rootPackage);
            $json["prefer-stable"] = true;
            $json = json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            $rootFile = $this->getDrupalRoot(getcwd(), "") . "composer.json";

            if (file_put_contents($rootFile, $json)) {
              $io->write('<info>Root composer.json is saved successfully.</info>');
            }
            else {
              $io->write('<error>Couldn\'t save the root composer.json.</error>');
              self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
            }
          }
          elseif ($rootPackageExtras['patches-file']) {
            $patchesFile["patches"] = $patches;
            $patchesFile = json_encode($patchesFile, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            if (file_put_contents($rootPackageExtras['patches-file'], $patchesFile)) {
              $io->write('<info>Patches file is saved successfully.</info>');
            }
            else {
              $io->write('<error>Couldn\'t save the patches file.</error>');
              self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
            }
          }
          else {
            //shouldn't reach here!
            $io->write("<warning>Can't save, no patches supplied!</warning>");
            self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
          }
        }
        else {
          $io->write("<warning>Couldn't find the patch inside root composer.json or patches file, probably it's provided from dependencies?</warning>", true);
          $answer = $io->ask("<info>Would you like to add this patch to the patches ignore list? (yes)</info>", "yes");

          if (preg_match("/yes/i", $answer)) {
            foreach ($packages as $parentPackage) {
              $parentExtra = $parentPackage->getExtra();
              if (isset($parentExtra['patches'])) {
                if (isset($parentExtra['patches'][$package->getName()])) {
                  foreach ($parentExtra['patches'][$package->getName()] as $key => $url){
                    if ($url == $event->getUrl()) {
                      if (isset($rootPackageExtras['patches-ignore'])) {
                        if (isset($rootPackageExtras['patches-ignore'][$parentPackage->getName()])) {
                          if (!isset($rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()])) {
                            $rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()] = array();
                            $rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()][$key] = $url;
                          }
                          elseif (isset($rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()])) {
                            if(!isset($rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()][$key])) {
                              $rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()][$key] = $url;
                            }
                          }
                        }
                        else {
                          $rootPackageExtras['patches-ignore'][$parentPackage->getName()] = array();
                          $rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()] = array();
                          $rootPackageExtras['patches-ignore'][$parentPackage->getName()][$package->getName()][$key] = $url;
                        }
                      }
                    }
                  }
                }
              }
            }

            $rootPackage->setExtra($rootPackageExtras);
            $dumper = new ArrayDumper();
            $json = $dumper->dump($rootPackage);
            $json["prefer-stable"] = true;
            $json = json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            $rootFile = $this->getDrupalRoot(getcwd(), "") . "composer.json";
            if (file_put_contents($rootFile, $json)) {
              $io->write('<info>Root composer.json is saved successfully.</info>');
            }
            else {
              $io->write('<error>Couldn\'t save the root composer.json.</error>');
              self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
            }

          }
          else {
            $io->write("<warning>Logging patch to the failed-patches.txt file instead of removing it.</warning>", true);
            self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
          }
        }
      }
      else {
        self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
      }
    }
    else{
      self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
    }
  }
}
