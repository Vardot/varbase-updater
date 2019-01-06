# Varbase Updater: Making Varbase Updates Easier

A set of scripts and tools that will help you to update to the newer versions of Varbase.


## Install with Composer

To install the most recent stable release of Varbase Updater run this command:
```
composer require vardot/varbase-updater
```

## Run the Updater

Updating Varbase is best done through Composer. We will assume that you have [installed Varbase the recommended way](https://docs.varbase.vardot.com/getting-started/installing-varbase) through the Composer-based project template [varbase-project](https://github.com/Vardot/varbase-project).

This will create the Varbase project directory that will look like this: `/path/to/YOUR_PROJECT` with the Drupal 8 codebase installed via Varbase installation profile in `/path/to/YOUR_PROJECT/docroot`.

Follow the these commands to run the updater:

1. From a command prompt window, navigate to your project:
`cd /path/to/YOUR_PROJECT`

2. If you're using Varbase 8.6.2 or older, install varbase-updater through Composer.
`composer require vardot/varbase-updater `. If you're using Varbase 8.6.3 or newer, skip this step; varbase-updater comes pre-installed with your Varbase project.

3. Run the Varbase update tool.
`./bin/update-varbase.sh`

4. Follow the wizard. 


Lear more on this Varbase documentation site:
https://docs.varbase.vardot.com/updating-varbase#option-1-automated-process-using-varbase-updater
