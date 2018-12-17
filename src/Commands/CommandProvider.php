<?php

namespace vardot\Composer\CommandProvider;

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
use Symfony\Component\Yaml\Yaml;
use Composer\EventDispatcher\Event;
use Composer\Json\JsonFile;
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return array(new RefactorComposerCommand);
    }
}

class RefactorComposerCommand implements BaseCommand{

  protected function configure()
  {
      $this->setName('varbase-refactor-composer');
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
      $output->writeln('Refactoring composer.json');
      $this->generate();
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

  protected function getPaths($package) {
    $paths = [];
    $projectExtras = $package->getExtra();

    $paths["composerPath"] = $this->getDrupalRoot(getcwd(), "../..");
    $paths["rootPath"] = "docroot";
    if(isset($projectExtras["install-path"])){
      $paths["rootPath"] = $projectExtras["install-path"];
    }
    $paths["contribModulesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/modules/contrib/";
    $paths["customModulesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/modules/custom/";
    $paths["contribThemesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/themes/contrib/";
    $paths["customThemesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/themes/custom/";
    $paths["librariesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/libraries/";
    $paths["profilesPath"] = $this->getDrupalRoot(getcwd(), $paths["rootPath"]) . "/profiles/";

    if(isset($projectExtras["installer-paths"])){
      foreach($projectExtras["installer-paths"] as $path => $types){
        foreach($types as $type){
          if($type == "type:drupal-module"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["contribModulesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-custom-module"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["customModulesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-theme"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["contribThemesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-custom-theme"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["customThemesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-profile"){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["profilesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
          if($type == "type:drupal-library" || $type == "type:bower-asset" || $type == "type:npm-asset" ){
            $typePath = preg_replace('/\{\$.*\}$/', "", $path);
            $paths["librariesPath"] = $this->getDrupalRoot(getcwd(), "") . $typePath;
            continue;
          }
        }
      }
    }

    return $paths;
  }

  public function array_merge_recursive_distinct(array &$array1, array &$array2, $drupalPath){
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

  public function generate() {
    $composer = $this->getComposer();
    $paths = $this->getPaths($composer->getPackage());

    $updateConfigPath = $paths["composerPath"] . "config/update-config.json";
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


    $projectPackage = $composer->getPackage();
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

    $io = $this->getIO();

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