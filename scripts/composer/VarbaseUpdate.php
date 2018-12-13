<?php

namespace Varbase\composer;

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
use Composer\Installer\PackageEvent;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;

/**
 * Varbase Composer Script Handler.
 */
class VarbaseUpdate {

  /**
   * Get the Drupal root directory.
   *
   * @param string $project_root
   *    Project root.
   *
   * @return string
   *    Drupal root path.
   */
  protected static function getDrupalRoot($project_root, $rootPath = "docroot") {
    return $project_root . '/' . $rootPath;
  }

  protected static function getPaths($package) {
    $paths = [];
    $projectExtras = $package->getExtra();

    $paths["composerPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "");
    $paths["rootPath"] = "docroot";
    if(isset($projectExtras["install-path"])){
      $paths["rootPath"] = $projectExtras["install-path"];
    }
    $paths["contribModulesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), $paths["rootPath"]) . "/modules/contrib/";
    $paths["customModulesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), $paths["rootPath"]) . "/modules/custom/";
    $paths["contribThemesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), $paths["rootPath"]) . "/themes/contrib/";
    $paths["customThemesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), $paths["rootPath"]) . "/themes/custom/";
    $paths["librariesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), $paths["rootPath"]) . "/libraries/";
    $paths["profilesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), $paths["rootPath"]) . "/profiles/";

    if(isset($projectExtras["installer-paths"])){
      foreach($projectExtras["installer-paths"] as $path => $types){
        foreach($types as $type){
          if($type == "type:drupal-module"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["contribModulesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-custom-module"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["customModulesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-theme"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["contribThemesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-custom-theme"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["customThemesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-profile"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["profilesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-library" || $type == "type:bower-asset" || $type == "type:npm-asset" ){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["librariesPath"] = VarbaseUpdate::getDrupalRoot(getcwd(), "") . $typePath;
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
  protected static function getPackageFromOperation($operation)
  {
      if ($operation instanceof InstallOperation) {
          $package = $operation->getPackage();
      } elseif ($operation instanceof UpdateOperation) {
          $package = $operation->getTargetPackage();
      } else {
          throw new \Exception('Unknown operation: ' . get_class($operation));
      }
      return $package;
  }

  public static function handlePackageTags(PackageEvent $event) {
    $tagsPath = VarbaseUpdate::getDrupalRoot(getcwd(), "") . "scripts/update/tags.json";
    if(!file_exists($tagsPath)) return;

    $installedPackage = VarbaseUpdate::getPackageFromOperation($event->getOperation());
    $loader = new JsonLoader(new ArrayLoader());
    $configPath = VarbaseUpdate::getDrupalRoot(getcwd(), "") . "composer.json";

    $config = JsonFile::parseJson(file_get_contents($configPath), $configPath);
    if(!isset($config['version'])){
      $config['version'] = "0.0.0"; //dummy version just to handle UnexpectedValueException
    }
    $config = JsonFile::encode($config);
    $package = $loader->load($config);

    $tagsFile = file_get_contents($tagsPath);
    $tags = json_decode($tagsFile, true);

    $paths = VarbaseUpdate::getPaths($package);

    $modulePath = $paths["contribModulesPath"];
    $themePath = $paths["contribThemesPath"];

    foreach ($tags as $name => $constraint) {
      if($name != $installedPackage->getName()) continue;
      $version = $constraint;
      $projectName = preg_replace('/.*\//', "", $name);
      if(preg_match('/\#/', $version)){
        $commitId = preg_replace('/.*\#/', "", $version);
        $result = [];
        print $projectName . ": \n";
        if(file_exists($modulePath . $projectName)){
          exec('cd ' . $modulePath . $projectName . '; git checkout ' . $commitId, $result);
          foreach ($result as $line) {
              print($line . "\n");
          }
        }else if(file_exists($themePath . $projectName)){
          exec('cd ' . $themePath . $projectName . '; git checkout ' . $commitId, $result);
          foreach ($result as $line) {
              print($line . "\n");
          }
        }
      }
    }

  }

  /**
   * Writes a patch report to the target directory.
   *
   * @param array $patches
   * @param string $directory
   */
  protected static function writePatchReport($packageName, $packagePath, $patchUrl, $description, $isApplied, $directory) {
    $logFile = $directory . "failed-patches.txt";
    if (!file_exists($logFile)) {
        $output = "This file was automatically generated by Vardot Updater (https://github.com/Vardot/varbase-updater)\n";
        $output .= "Patches failed to apply:\n\n";
        file_put_contents($logFile, $output);
    }

    $reason = "Failed to apply patch";
    if($isApplied){
      $reason = "Patch already applied";
    }
    $output = "Patch: " . $patchUrl . "\n";
    $output .= "\t Reason: " . $reason . "\n";
    $output .= "\t Description: " . $description . "\n";
    $output .= "\t Package Name: " . $packageName . "\n";
    $output .= "\t Package Path: " . $packagePath . "\n\n\n";
    file_put_contents($logFile, $output, FILE_APPEND | LOCK_EX);
  }

  public static function handlePackagePatchError(PatchEvent $event) {
    $logPath = VarbaseUpdate::getDrupalRoot(getcwd(), "");

    $io = $event->getIO();
    $composer = $event->getComposer();
    $rootPackage = $event->getComposer()->getPackage();
    $rootPackageExtras = $rootPackage->getExtra();

    $package = $event->getPackage();
    $manager = $event->getComposer()->getInstallationManager();
    $installPath = $manager->getInstaller($package->getType())->getInstallPath($package);
    $patchUrl = $event->getUrl();

    // Set up a downloader.
    $downloader = new RemoteFilesystem($event->getIO(), $event->getComposer()->getConfig());

    if (file_exists($patchUrl)) {
      $filename = realpath($patchUrl);
    } else {
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

      //use --dry-run to check if patch applies to prevent partial patches same as --check in git apply
      if ($executor->execute($command, $output) == 0) {
        $isApplied = true;
        break;
      }
    }

    if($isApplied){

      $io->write([
          "<warning>Patch: " . $event->getUrl() . "</warning>",
          "<warning>\t" . $event->getDescription() . "</warning>",
          "<warning>\tIs already applied on  " . $package->getName() . " " . $package->getFullPrettyVersion() . "</warning>"
        ]
      );

      $answer = $io->ask("<info>Would you like to remove it form patches list ? (yes)</info>", "yes");

      if(preg_match("/yes/i", $answer)){
        $io->write("<info>Removing Patch: " . $event->getUrl() . "</info>", true);
        $patches = [];
        $patchesFile = "";
        if (isset($rootPackageExtras['patches'])) {
          $io->write('<info>Removing patch from root package.</info>');
          $patches = $rootPackageExtras['patches'];
        } elseif (isset($rootPackageExtras['patches-file'])) {
          $io->write('<info>Removing patch from patches file. ' . $rootPackageExtras['patches-file'] . '.</info>');
          $patchesFile = file_get_contents($rootPackageExtras['patches-file']);
          $patchesFile = json_decode($patchesFile, TRUE);
          $error = json_last_error();
          if ($error != 0) {
            $io->write('<error>There was an error reading patches file.</error>');
          }

          if (isset($patchesFile['patches'])) {
            $patches = $patchesFile['patches'];
          }
        }else{
          //shouldn't reach here!
          $io->write('<warning>Hmmm, no patches supplied!</warning>');
        }

        $found = false;
        if(isset($patches[$package->getName()])){
          foreach($patches[$package->getName()] as $key => $url){
            if($url == $event->getUrl()){
              $found = true;
              unset($patches[$package->getName()][$key]);
            }
          }
          if(!sizeof($patches[$package->getName()])){
            unset($patches[$package->getName()]);
          }
        }

        if($found){
          $io->write('<info>Saving changes.</info>');
          if (isset($rootPackageExtras['patches'])) {
            $rootPackageExtras['patches'] = $patches;
            $rootPackage->setExtra($rootPackageExtras);
            $dumper = new ArrayDumper();
            $json = $dumper->dump($rootPackage);
            $json["prefer-stable"] = true;
            $json = json_encode($json, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            $rootFile = VarbaseUpdate::getDrupalRoot(getcwd(), "") . "composer.json";
            if(file_put_contents($rootFile, $json)){
              $io->write('<info>Root package is saved successfully.</info>');
            }else{
              $io->write('<error>Couldn\'t save root package.</error>');
              self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
            }
          } elseif ($rootPackageExtras['patches-file']) {
            $patchesFile["patches"] = $patches;
            $patchesFile = json_encode($patchesFile, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            if(file_put_contents($rootPackageExtras['patches-file'], $patchesFile)){
              $io->write('<info>Patches file is saved successfully.</info>');
            }else{
              $io->write('<error>Couldn\'t save patches file.</error>');
              self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
            }
          } else {
            //shouldn't reach here!
            $io->write("<warning>Can't save, no patches supplied!</warning>");
            self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
          }
        }else{
          $io->write("<warning>Couldn't find the patch inside root composer.json or patches file, probably it's provided from dependencies. </warning>", true);
          $io->write("<warning>Logging patch instead of removing.</warning>", true);
          self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
        }
      }else{
        self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
      }
    }else{
        self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
    }
  }

  public static function handlePackagePatchTags(PatchEvent $event) {
    $tagsPath = VarbaseUpdate::getDrupalRoot(getcwd(), "") . "scripts/update/tags.json";
    if(!file_exists($tagsPath)) return;

    $installedPackage = $event->getPackage();

    $loader = new JsonLoader(new ArrayLoader());
    $configPath = VarbaseUpdate::getDrupalRoot(getcwd(), "") . "composer.json";
    $config = JsonFile::parseJson(file_get_contents($configPath), $configPath);
    if(!isset($config['version'])){
      $config['version'] = "0.0.0"; //dummy version just to handle UnexpectedValueException
    }
    $config = JsonFile::encode($config);
    $package = $loader->load($config);

    $tagsFile = file_get_contents($tagsPath);
    $tags = json_decode($tagsFile, true);

    $paths = VarbaseUpdate::getPaths($package);

    $modulePath = $paths["contribModulesPath"];
    $themePath = $paths["contribThemesPath"];

    foreach ($tags as $name => $constraint) {
      if($name != $installedPackage->getName()) continue;
      $version = $constraint;
      $projectName = preg_replace('/.*\//', "", $name);
      if(preg_match('/\#/', $version)){
        $commitId = preg_replace('/.*\#/', "", $version);
        $result = [];
        print $projectName . ": \n";
        if(file_exists($modulePath . $projectName)){
          exec('cd ' . $modulePath . $projectName . '; git checkout ' . $commitId, $result);
          foreach ($result as $line) {
              print($line . "\n");
          }
        }else if(file_exists($themePath . $projectName)){
          exec('cd ' . $themePath . $projectName . '; git checkout ' . $commitId, $result);
          foreach ($result as $line) {
              print($line . "\n");
          }
        }
      }
    }
  }

  public static function handleTags(Event $event) {

    $loader = new JsonLoader(new ArrayLoader());
    $varbaseConfigPath = VarbaseUpdate::getDrupalRoot(getcwd()) . "/profiles/varbase/composer.json";
    $varbaseConfig = JsonFile::parseJson(file_get_contents($varbaseConfigPath), $varbaseConfigPath);
    if(!isset($varbaseConfig['version'])){
      $varbaseConfig['version'] = "0.0.0"; //dummy version just to handle UnexpectedValueException
    }
    $varbaseConfig = JsonFile::encode($varbaseConfig);
    $varbasePackage = $loader->load($varbaseConfig);
    $varbasePackageRequires = $varbasePackage->getRequires();

    $paths = VarbaseUpdate::getPaths($event->getComposer()->getPackage());

    $modulePath = $paths["contribModulesPath"];
    $themePath = $paths["contribThemesPath"];


    foreach ($varbasePackageRequires as $name => $packageLink) {
      $version = $packageLink->getPrettyConstraint();
      $projectName = preg_replace('/.*\//', "", $name);
      if(preg_match('/\#/', $version)){
        $commitId = preg_replace('/.*\#/', "", $version);
        $result = [];
        print $projectName . ": \n";
        if(file_exists($modulePath . $projectName)){
          exec('cd ' . $modulePath . $projectName . '; git checkout ' . $commitId, $result);
          foreach ($result as $line) {
              print($line . "\n");
          }
        }else if(file_exists($themePath . $projectName)){
          exec('cd ' . $themePath . $projectName . '; git checkout ' . $commitId, $result);
          foreach ($result as $line) {
              print($line . "\n");
          }
        }
      }
    }
  }

  public static function array_merge_recursive_distinct(array &$array1, array &$array2, $drupalPath){
    $merged = $array1;
    foreach ($array2 as $key => &$value) {
        $newKey = preg_replace('/{\$drupalPath}/', $drupalPath, $key);
        if(!isset($merged[$newKey])){
          $merged[$newKey] = [];
        }
        if (is_array($value) && isset($merged[$newKey]) && is_array($merged[$newKey])) {
            $merged[$newKey] = self::array_merge_recursive_distinct($merged[$newKey], $value, $drupalPath);
        } else {
            $newValue = preg_replace('/{\$drupalPath}/', $drupalPath, $value);
            $merged[$newKey] = $newValue;
        }
    }
    return $merged;
  }

  public static function generate(Event $event) {
    $paths = VarbaseUpdate::getPaths($event->getComposer()->getPackage());

    $updateConfigPath = $paths["composerPath"] . "scripts/update/update-config.json";
    $extraConfig = [];
    if(file_exists($paths["composerPath"] . "update-config.json")){
      $extraConfig = json_decode(file_get_contents($paths["composerPath"] . "update-config.json"), TRUE);
    }
    $updateConfig = json_decode(file_get_contents($updateConfigPath), TRUE);
    $error = json_last_error();
    $updateConfig = array_replace_recursive($updateConfig, $extraConfig);

    if(!isset($updateConfig['profile']) || !isset($updateConfig['package'])){
      $dumper = new ArrayDumper();
      $json = $dumper->dump($projectPackage);
      $projectConfig = JsonFile::encode($json);
      print_r($projectConfig);
      return;
    }

    $composer = $event->getComposer();
    $projectPackage = $event->getComposer()->getPackage();
    $projectPackageRequires = $projectPackage->getRequires();
    $projectPackageExtras = $projectPackage->getExtra();
    $projectPackageRepos = $projectPackage->getRepositories();
    $projectScripts = $projectPackage->getScripts();
    $projectPackagePatches = [];

    if(isset($projectPackageExtras["patches"])){
      $projectPackagePatches = $projectPackageExtras["patches"];
    }

    $loader = new JsonLoader(new ArrayLoader());
    $profileConfigPath = $paths['profilesPath'] . $updateConfig['profile'] . "/composer.json";
    $profileConfig = JsonFile::parseJson(file_get_contents($profileConfigPath), $profileConfigPath);
    $profileVersion = $projectPackageRequires[$updateConfig['package']]->getPrettyConstraint();

    if(!isset($profileConfig['version'])){
      $profileConfig['version'] = "0.0.0"; //dummy version just to handle UnexpectedValueException
    }

    $profileConfig = JsonFile::encode($profileConfig);
    $profilePackage = $loader->load($profileConfig);

    $io = $event->getIO();

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
        if(preg_match('/' . $conf['to'] . '/', $profileVersion)){
          continue;
        }
        if(preg_match('/' . $conf["from"] . '/', $profileVersion)){
          $profileLinkConstraint = new Constraint(">=", $conf["to"]);
          $profileLinkConstraint->setPrettyString("~" . $conf["to"]);
          $profileLink = new Link($projectPackage->getName(), $updateConfig['package'], $profileLinkConstraint , "", "~".$conf["to"]);
          $requiredPackageLinks = [];
          $requiredPackageLinks[$updateConfig['package']] = $profileLink;

          if(isset($conf["packages"]["crucial"])){
            $crucialPackages = array_replace_recursive($crucialPackages, $conf["packages"]["crucial"]);
          }
          $enableAfterUpdatePath = $paths["composerPath"] . "scripts/update/.enable-after-update";
          if(isset($conf["enable-after-update"])){
            $output = "";
            foreach ($conf["enable-after-update"] as $key => $value) {
              $output .= $value . PHP_EOL;
            }
            file_put_contents($enableAfterUpdatePath, $output);
          }else{
            file_put_contents($enableAfterUpdatePath, "");
          }
          $skipUpdatePath = $paths["composerPath"] . "scripts/update/.skip-update";
          if(isset($conf["skip"])){
            $output = "";
            foreach ($conf["skip"] as $key => $value) {
              $output .= $value . PHP_EOL;
            }
            file_put_contents($skipUpdatePath, $output);
          }else{
            file_put_contents($skipUpdatePath, "");
          }
          if(isset($conf["scripts"])){
            $scripts = array_replace_recursive($scripts, $conf["scripts"]);
          }
          if(isset($conf["repos"])){
            $repos = array_replace_recursive($repos, $conf["repos"]);
          }
          if(isset($conf["extras"])){
            $extras = array_replace_recursive($extras, $conf["extras"]);
          }
        }
      } elseif ($key == "all"){
        if(isset($conf["packages"]["crucial"])){
          $crucialPackages = array_replace_recursive($crucialPackages, $conf["packages"]["crucial"]);
        }
        if(isset($conf["enable-after-update"])){
          $output = "";
          foreach ($conf["enable-after-update"] as $key => $value) {
            $output .= $value . PHP_EOL;
          }
          $enableAfterUpdatePath = $paths["composerPath"] . "scripts/update/.enable-after-update";
          file_put_contents($enableAfterUpdatePath, $output);
        }
        if(isset($conf["skip"])){
          $output = "";
          foreach ($conf["skip"] as $key => $value) {
            $output .= $value . PHP_EOL;
          }
          $skipUpdatePath = $paths["composerPath"] . "scripts/update/.skip-update";
          file_put_contents($skipUpdatePath, $output);
        }
        if(isset($conf["scripts"])){
          $scripts = array_replace_recursive($scripts, $conf["scripts"]);
        }
        if(isset($conf["repos"])){
          $repos = array_replace_recursive($repos, $conf["repos"]);
        }
        if(isset($conf["extras"])){
          $extras = array_replace_recursive($extras, $conf["extras"]);
        }
      }
    }

    foreach (glob($paths['contribModulesPath'] . "*/*.info.yml") as $file) {
      $yaml = Yaml::parse(file_get_contents($file));
      if(isset($yaml["project"]) && isset($yaml["version"]) && $yaml["project"] != $updateConfig['profile']){
        $composerRepo = "drupal";
        $composerName = $composerRepo . "/" . $yaml["project"];
        $composerVersion = str_replace("8.x-", "", $yaml["version"]);

        if(isset($projectPackagePatches[$composerName])){
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion, "patch" => true];
        }else if(!isset($profilePackageRequires[$composerName])){
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion];
        }
      }
    }

    foreach (glob($paths['contribThemesPath'] . "*/*.info.yml") as $file) {
      $yaml = Yaml::parse(file_get_contents($file));
      if(isset($yaml["project"]) && isset($yaml["version"]) && $yaml["project"] != $updateConfig['profile']){
        $composerRepo = "drupal";
        $composerName = $composerRepo . "/" . $yaml["project"];
        $composerVersion = str_replace("8.x-", "", $yaml["version"]);
        if(!isset($profilePackageRequires[$composerName])){
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion];
        }
      }
    }


    foreach (glob($paths['contribModulesPath'] . "*/composer.json") as $file) {
      $pluginConfig = JsonFile::parseJson(file_get_contents($file), $file);
      if(!isset($pluginConfig['version'])){
        $pluginConfig['version'] = "6.2.0";
      }
      $pluginConfig = JsonFile::encode($pluginConfig);
      $pluginPackage = $loader->load($pluginConfig);
      $pluginPackageRequires = $pluginPackage->getRequires();

      foreach ($requiredPackages as $name => $package) {
        if(isset($projectPackagePatches[$name])){
          continue;
        }
        if(isset($pluginPackageRequires[$name])){
          unset($requiredPackages[$name]);
        }
      }
    }

    foreach (glob($paths['contribThemesPath'] . "*/composer.json") as $file) {
      $pluginConfig = JsonFile::parseJson(file_get_contents($file), $file);
      if(!isset($pluginConfig['version'])){
        $pluginConfig['version'] = "6.2.0";
      }
      $pluginConfig = JsonFile::encode($pluginConfig);
      $pluginPackage = $loader->load($pluginConfig);
      $pluginPackageRequires = $pluginPackage->getRequires();

      foreach ($requiredPackages as $name => $package) {
        if(isset($pluginPackageRequires[$name])){
          unset($requiredPackages[$name]);
        }
      }
    }

    foreach ($requiredPackages as $name => $package) {
      if(isset($projectPackageRequires[$name])){
        $requiredPackageLinks[] = $projectPackageRequires[$name];
      }else{
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
        $requiredPackageLinks[] = $projectPackageLink;
      }
    }

    if(!$projectPackageExtras){
      $projectPackageExtras = [];
    }

    $mergedExtras = self::array_merge_recursive_distinct($projectPackageExtras, $extras, $paths["rootPath"]);
    $mergedRepos = self::array_merge_recursive_distinct($projectPackageRepos, $repos, $paths["rootPath"]);
    $mergedScripts = self::array_merge_recursive_distinct($projectScripts, $scripts, $paths["rootPath"]);

    //Make sure to run the Varbase postDrupalScaffoldProcedure insetad of the current project postDrupalScaffoldProcedure
    if(isset($mergedScripts["post-drupal-scaffold-cmd"])){
      //$mergedScripts["post-drupal-scaffold-cmd"]=["Varbase\\composer\\ScriptHandler::postDrupalScaffoldProcedure"];
    }

    $projectPackage->setExtra($mergedExtras);
    $projectPackage->setRepositories($mergedRepos);
    $projectPackage->setRequires($requiredPackageLinks);
    $projectPackage->setScripts($mergedScripts);

    $dumper = new ArrayDumper();
    $json = $dumper->dump($projectPackage);
    $json["prefer-stable"] = true;
    $json["extra"]["composer-exit-on-patch-failure"] = false;

    //Fixing the position of installer path web/libraries/{$name} as it should be after slick and ace so it won't override them
    if(isset($extras["installer-paths"][$paths["rootPath"].'/libraries/{$name}'])){
      unset($json["extra"]["installer-paths"][$paths["rootPath"].'/libraries/{$name}']);
      $extraLibsArray=[
        $paths["rootPath"].'/libraries/{$name}' => $extras["installer-paths"][$paths["rootPath"].'/libraries/{$name}']
      ];
      $json["extra"]["installer-paths"] = $json["extra"]["installer-paths"] + $extraLibsArray;
    }
    $projectConfig = JsonFile::encode($json);
    print_r($projectConfig);
  }
}
