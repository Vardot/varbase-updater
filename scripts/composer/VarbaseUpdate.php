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

    self::writePatchReport($package->getName(), $installPath, $event->getUrl(), $event->getDescription(), $isApplied, $logPath);
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

  public static function generate(Event $event) {
    $paths = VarbaseUpdate::getPaths($event->getComposer()->getPackage());

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
    $varbaseConfigPath = $paths['profilesPath'] . "varbase/composer.json";
    $varbaseConfig = JsonFile::parseJson(file_get_contents($varbaseConfigPath), $varbaseConfigPath);
    $varbaseVersion = $projectPackageRequires["vardot/varbase"]->getPrettyConstraint();

    if(!isset($varbaseConfig['version'])){
      $varbaseConfig['version'] = "0.0.0"; //dummy version just to handle UnexpectedValueException
    }

    $varbaseConfig = JsonFile::encode($varbaseConfig);
    $varbasePackage = $loader->load($varbaseConfig);

    $io = $event->getIO();

    $varbasePackageRequires = $varbasePackage->getRequires();

    $varbaseLink = $projectPackageRequires["vardot/varbase"];
    $requiredPackages = [];
    $crucialPackages = [];
    $requiredPackageLinks = ["vardot/varbase" => $varbaseLink];
    $extras = [];
    $repos = [];
    $sripts = [];


    if(preg_match('/8\.4/', $varbaseVersion) || preg_match('/8\.5/', $varbaseVersion)){
      $crucialPackages["drupal/page_manager"] = ["name"=> "drupal/page_manager", "version" => "4.0-beta3"];
      if(preg_match('/8\.4\.28/', $varbaseVersion) || preg_match('/8\.5/', $varbaseVersion)){
        $varbaseLinkConstraint = new Constraint(">=", "8.6.2");
        $varbaseLinkConstraint->setPrettyString("~8.6.2");
        $varbaseLink = new Link("vardot/varbase-project", "vardot/varbase", $varbaseLinkConstraint , "", "~8.6.2");
        $requiredPackageLinks = ["vardot/varbase" => $varbaseLink];

        $crucialPackages["drupal/varbase_carousels"] = ["name"=> "drupal/varbase_carousels", "version" => "6.0"];
        $crucialPackages["drupal/entity_browser"] = ["name"=> "drupal/entity_browser", "version" => "2.0"];
        $crucialPackages["drupal/video_embed_media"] = ["name"=> "drupal/video_embed_field", "version" => "2.0"];
        $crucialPackages["drupal/media_entity"] = ["name"=> "drupal/media_entity", "version" => "2.0-beta3"];
        $crucialPackages["drupal/panelizer"] = ["name"=> "drupal/panelizer", "version" => "4.1"];

        $enableAfterUpdatePath = $paths["composerPath"] . "scripts/update/.enable-after-update";
        file_put_contents($enableAfterUpdatePath, "entity_browser_generic_embed".PHP_EOL);
      }else{
        $varbaseLinkConstraint = new Constraint("=", "8.4.28");
        $varbaseLinkConstraint->setPrettyString("8.4.28");
        $varbaseLink = new Link("vardot/varbase-project", "vardot/varbase", $varbaseLinkConstraint , "", "8.4.28");
        $requiredPackageLinks = ["vardot/varbase" => $varbaseLink];

        $enableAfterUpdatePath = $paths["composerPath"] . "scripts/update/.enable-after-update";
        file_put_contents($enableAfterUpdatePath, "");
      }

      $sripts = [
        "varbase-handle-tags" => [
            "Varbase\\composer\\VarbaseUpdate::handleTags"
        ],
        "pre-patch-apply" => [
            "Varbase\\composer\\VarbaseUpdate::handlePackagePatchTags"
        ],
        "post-package-update" => [
            "Varbase\\composer\\VarbaseUpdate::handlePackageTags"
        ],
        "post-package-install" => [
            "Varbase\\composer\\VarbaseUpdate::handlePackageTags"
        ],
        "patch-apply-error" => [
            "Varbase\\composer\\VarbaseUpdate::handlePackagePatchError"
        ]
      ];

      $repos["assets"] = [
        "type" => "composer",
        "url" => "https://asset-packagist.org"
      ];

      $repos["composer-patches"] = [
        "type" => "vcs",
        "url" => "https://github.com/waleedq/composer-patches"
      ];


      $extras["installer-paths"][$paths["rootPath"].'/libraries/{$name}'] = ["type:bower-asset", "type:npm-asset"];
      $extras["installer-paths"][$paths["rootPath"]."/libraries/slick"] = ["npm-asset/slick-carousel"];
      $extras["installer-paths"][$paths["rootPath"]."/libraries/ace"] = ["npm-asset/ace-builds"];
      $extras["installer-types"] = [
        "bower-asset",
        "npm-asset"
      ];

      $extras["varbase-update"] = [
        "generated" => true
      ];
      $extras["drupal-libraries"] = [
        "library-directory" => $paths["rootPath"]."/libraries",
        "libraries" => [
            [
                "name" => "dropzone",
                "package" => "npm-asset/dropzone"
            ],
            [
                "name" => "blazy",
                "package" => "npm-asset/blazy"
            ],
            [
                "name" => "slick",
                "package" => "npm-asset/slick-carousel"
            ],
            [
                "name" => "ace",
                "package" => "npm-asset/ace-builds"
            ]
        ]
      ];
    }


    foreach (glob($paths['contribModulesPath'] . "*/*.info.yml") as $file) {
      $yaml = Yaml::parse(file_get_contents($file));
      if(isset($yaml["project"]) && isset($yaml["version"]) && $yaml["project"] != "varbase"){
        $composerRepo = "drupal";
        $composerName = $composerRepo . "/" . $yaml["project"];
        $composerVersion = str_replace("8.x-", "", $yaml["version"]);

        if(isset($projectPackagePatches[$composerName])){
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion, "patch" => true];
        }else if(!isset($varbasePackageRequires[$composerName])){
          $requiredPackages[$composerName] = ["name"=> $composerName, "version" => $composerVersion];
        }
      }
    }



    foreach (glob($paths['contribThemesPath'] . "*/*.info.yml") as $file) {
      $yaml = Yaml::parse(file_get_contents($file));
      if(isset($yaml["project"]) && isset($yaml["version"]) && $yaml["project"] != "varbase"){
        $composerRepo = "drupal";
        $composerName = $composerRepo . "/" . $yaml["project"];
        $composerVersion = str_replace("8.x-", "", $yaml["version"]);
        if(!isset($varbasePackageRequires[$composerName])){
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
        $link = new Link("vardot/varbase-project", $package["name"], new Constraint(">=", $package["version"]), "", "^".$package["version"]);
        $requiredPackageLinks[$name] = $link;
      }
    }


    foreach ($crucialPackages as $name => $package) {
      $link = new Link("vardot/varbase-project", $package["name"], new Constraint("==", $package["version"]), "", $package["version"]);
      $requiredPackageLinks[$name] = $link;
    }

    foreach ($projectPackageRequires as $projectName => $projectPackageLink) {
      if(!isset($varbasePackageRequires[$projectName]) && !isset($requiredPackageLinks[$projectName])){
        $requiredPackageLinks[] = $projectPackageLink;
      }
    }


    if(!$projectPackageExtras){
      $projectPackageExtras = [];
    }

    $mergedExtras = $projectPackageExtras;
    if(!isset($projectPackageExtras["varbase-update"]["generated"])){
      $mergedExtras = array_merge_recursive($projectPackageExtras, $extras);
    }


    $mergedRepos = $projectPackageRepos;
    if(!isset($projectPackageExtras["varbase-update"]["generated"])){
      $mergedRepos = array_merge_recursive($projectPackageRepos, $repos);
    }

    $mergedScripts = $projectScripts;
    if(!isset($projectPackageExtras["varbase-update"]["generated"])){
      $mergedScripts = array_merge_recursive($projectScripts, $sripts);
    }

    //Make sure to run the Varbase postDrupalScaffoldProcedure insetad of the current project postDrupalScaffoldProcedure
    if(isset($mergedScripts["post-drupal-scaffold-cmd"])){
      $mergedScripts["post-drupal-scaffold-cmd"]=["Varbase\\composer\\ScriptHandler::postDrupalScaffoldProcedure"];
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
