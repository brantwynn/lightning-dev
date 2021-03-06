<?php

namespace Acquia\Lightning\Commands;

use cebe\markdown\GithubMarkdown;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use GuzzleHttp\Client;
use Robo\Tasks;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class RoboFile extends Tasks
{

    /**
     * The default URL of the Drupal site.
     *
     * @var string
     */
    const BASE_URL = 'http://127.0.0.1:8080';

    /**
     * Builds a task to execute a Drush command.
     *
     * @param string $command
     *   The command to execute.
     *
     * @return \Robo\Task\Base\Exec
     *   The task to execute.
     */
    protected function taskDrush ($command)
    {
        return $this->taskExec('vendor/bin/drush')->rawArg($command);
    }

    /**
     * Checks if Drush or Console implements a specific command.
     *
     * @param string $executable
     *   The relative path to the executable, e.g. 'vendor/bin/drush'.
     * @param string $command
     *   The command to check for.
     *
     * @return bool
     *   TRUE if the command exists, FALSE otherwise.
     */
    protected function commandExists ($executable, $command)
    {
        $list = $this->taskExec($executable)
            ->rawArg('list')
            ->option('format', 'json')
            ->printOutput(FALSE)
            ->run()
            ->getMessage();

        $list = Json::decode($list);

        foreach ($list['commands'] as $command_info)
        {
            if ($command_info['name'] === $command)
            {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Installs Lightning and, optionally, the developer tools.
     *
     * @param string $db_url
     *   (optional) The URL of the Drupal database.
     * @param string $profile
     *   (optional) The installation profile to use.
     * @param string $base_url
     *   (optional) The URL of the Drupal site.
     * @param array $options
     *   (optional) Additional command options.
     *
     * @option $no-dev Do not install developer tools or configure test runners.
     * @option $from-config Reinstall from exported config.
     * @option $no-components Do not install sub-components.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     */
    public function install ($db_url = 'sqlite://localhost/db.sqlite', $profile = 'lightning', $base_url = NULL, array $options = [
        'no-dev' => FALSE,
        'from-config' => FALSE,
        'no-components' => FALSE,
    ])
    {
        $db_url = stripslashes($db_url);
        $settings = 'docroot/sites/default/settings.php';

        $bootstrap_container_definition = <<<END
\$settings['bootstrap_container_definition'] = [
  'parameters' => [],
  'services' => [
    'database' => [
      'class' => 'Drupal\Core\Database\Connection',
      'factory' => 'Drupal\Core\Database\Database::getConnection',
      'arguments' => ['default'],
    ],
    'cache.container' => [
      'class' => 'Drupal\Core\Cache\MemoryBackend',
    ],
    'cache_tags_provider.container' => [
      'class' => 'Drupal\Core\Cache\DatabaseCacheTagsChecksum',
      'arguments' => ['@database'],
    ],
  ],
];
END;
        
        $uri = $base_url;
        if ($uri == NULL) {
          $uri = 'http://default';
        }

        $tasks = $this->collectionBuilder()
            ->addTask(
                $this->taskDrush('site-install')
                    ->arg($profile)
                    ->option('yes')
                    ->option('account-pass', 'admin')
                    ->option('db-url', $db_url)
                    ->option('uri', $uri)
            )
            ->addTask(
                $this->taskFilesystemStack()
                    ->chmod([
                        dirname($settings),
                        $settings,
                    ], 0755)
            )
            ->addTask(
                $this->taskWriteToFile($settings)
                    ->append()
                    ->text($bootstrap_container_definition)
            );

        /** @var Finder $finder */
        $finder = Finder::create()->in('.')->files()->depth(0)->name('*.info.yml');

        foreach ($finder as $file)
        {
            $extension = $file->getBasename('.info.yml');
            $info = Yaml::parse($file->getContents());
            break;
        }

        if (isset($extension, $info) && $info['type'] === 'module' && empty($options['no-components']))
        {
            $task = $this->taskDrush('pm-enable')
                ->option('yes')
                ->arg($extension);

            if (isset($info['components']))
            {
                $task->args($info['components']);
            }
            $tasks->addTask($task);
        }

        if ($options['no-dev'] == FALSE)
        {
            $tasks->addTask(
                $this->installDev($db_url, $base_url)
            );
        }

        if ($options['from-config'])
        {
            $tasks
                ->addTask(
                    $this->taskDrush('config:export')->option('yes')
                )
                ->addTask(
                    $this->taskReplaceInFile($settings)
                        ->from("\$settings['install_profile'] = '$profile';")
                        ->to(NULL)
                )
                ->addTask(
                    $this->taskDrush('site:install')
                        ->option('yes')
                        ->option('existing-config')
                        ->option('account-pass', 'admin')
                        ->option('db-url', $db_url)
                );
        }

        return $tasks;
    }

    /**
     * Installs developer tools and configures test runners.
     *
     * @param string $db_url
     *   The URL of the Drupal database.
     * @param string $base_url
     *   (optional) The URL of the Drupal site.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     */
    public function installDev ($db_url, $base_url = NULL)
    {
        return $this->collectionBuilder()
            ->addTask(
                $this->taskDrush('pm-enable')
                    ->arg('lightning_dev')
                    ->option('yes')
            )
            ->addCode(function ()
            {
                $task = $this->makeExtender();
                if ($task)
                {
                    $task->run();
                }
            })
            ->addTask(
                $this->configurePhpUnit($db_url, $base_url)
            )
            ->addTask(
                $this->configureBehat($base_url)
            );
    }

    /**
     * Reinstalls Drupal.
     *
     * @param string $base_url
     *   (optional) The URL of the Drupal site.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     */
    public function reinstall ($base_url = NULL)
    {
        $info = $this->taskDrush('core:status')
            ->option('format', 'json')
            ->option('fields', 'db-driver,db-username,db-password,db-hostname,db-port,db-name,install-profile')
            ->printOutput(FALSE)
            ->run()
            ->getMessage();

        $info = Json::decode($info);

        $db_url = sprintf(
          '%s://%s%s@%s%s/%s',
          $info['db-driver'],
          $info['db-username'],
          $info['db-password'] ? ':' . $info['db-password'] : NULL,
          $info['db-hostname'],
          $info['db-port'] ? ':' . $info['db-port'] : NULL,
          $info['db-name']
        );

        return $this->install($db_url, $info['install-profile'], $base_url);
    }

    /**
     * Generates a sub-profile of Lightning.
     *
     * @param string $name
     *   (optional) The machine name of the profile. Defaults to
     *   'lightning_extender'.
     * @param array $options
     *   (optional) Additional command options.
     *
     * @option $overwrite Overwrite the sub-profile if it already exists.
     *
     * @return \Robo\Contract\TaskInterface|NULL
     *   The task to execute, or NULL if nothing should be done.
     */
    public function makeExtender ($name = 'lightning_extender', array $options = [
        'overwrite' => FALSE,
    ])
    {
        $console = 'vendor/bin/drupal';
        $command = 'lightning:subprofile';

        if ($this->commandExists($console, $command))
        {
            $tasks = $this->collectionBuilder();

            $dir = "docroot/profiles/custom/$name";
            if (is_dir($dir))
            {
                if ($options['overwrite'])
                {
                    $tasks->addTask(
                        $this->taskDeleteDir($dir)
                    );
                }
                else
                {
                    return $this->say("$dir already exists.");
                }
            }

            return $tasks->addTask(
                $this->taskExec($console)
                    ->rawArg($command)
                    ->option('no-interaction')
                    ->option('name', 'Lightning Extender')
                    ->option('machine-name', basename($dir))
                    ->option('include', 'devel')
                    ->option('exclude', 'lightning_search')
            );
        }

        return $this->say("The $command command is not available.");
    }

    /**
     * Generates a compressed database fixture.
     *
     * @param string $version
     *   The version the fixture represents.
     *
     * @option $update-from The version from which to update before generating
     * the fixture. If omitted, the fixture is created by updating from the
     * most recent fixture, unless --clean is specified.
     * @option $clean If specified, the fixture will be generated from a clean
     * install. This will override the --update-from option.
     * @option $no-ci If specified, the new fixture will not be added to
     * .travis.yml for testing.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     */
    public function makeFixture ($version, array $options = [
        'update-from' => NULL,
        'clean' => FALSE,
        'no-ci' => FALSE,
    ])
    {
        if (empty($options['clean']) && empty($options['update-from']) && is_dir('tests/fixtures'))
        {
            $map = function ($fixture)
            {
                return basename($fixture, '.sql.gz');
            };
            $versions = array_map($map, glob('tests/fixtures/*.sql.gz'));

            if ($versions)
            {
                // Sort the available fixtures in descending order.
                $sort = function ($a, $b)
                {
                    return version_compare($a, $b, '<=');
                };
                uasort($versions, $sort);

                $options['update-from'] = reset($versions);

                $this->say('Auto-detected most recent fixture: ' . $options['update-from']);
            }
        }

        $this->say(
            $options['update-from']
                ? 'Generating fixture from ' . $options['update-from'] . '.'
                : 'Generating fixture from clean install.'
        );

        $tasks = $this->collectionBuilder()
            ->addTask(
                $options['update-from']
                    ? $this->update($options['update-from'])
                    : $this->reinstall()
            )
            ->addTask(
                $this->taskDrush('sql:dump')
                    ->option('gzip')
                    ->option('result-file', "../tests/fixtures/$version.sql")
            );

        if ($options['update-from'] && empty($options['no-ci']) && file_exists('.travis.yml'))
        {
            $tasks->addTask(
                $this->taskReplaceInFile('.travis.yml')
                    ->from('  - VERSION=' . $options['update-from'])
                    ->to("  - VERSION=$version")
            );
        }
        return $tasks;
    }

    /**
     * Generates a change log from CHANGELOG.md.
     *
     * @param string $file
     *   (optional) The file from which to generate the change log, relative to
     *   the current working directory. Defaults to CHANGELOG.md.
     *
     * @option $format The format in which to generate the change log. Defaults
     * to 'html'. Any other value will generate GitHub-flavored markdown.
     *
     * @throws \RuntimeException if $file does not exist or cannot be read.
     */
    public function makeChangeLog ($file = 'CHANGELOG.md', array $options = [
        'format' => 'html',
        'file' => 'CHANGELOG.md',
    ])
    {
        if (! file_exists($file))
        {
            throw new \RuntimeException("Cannot find $file.");
        }

        $handle = fopen($file, 'r');
        if ($handle === FALSE)
        {
            throw new \RuntimeException("Cannot open $file for reading.");
        }

        $log = [];
        while ($line = rtrim(fgets($handle)))
        {
            array_push($log, $line);
        }
        fclose($handle);

        $log = implode("\n", $log);

        if ($options['format'] === 'html')
        {
            $log = (new GithubMarkdown())->parse($log);
        }

        $this->io()->write($log);
    }

    /**
     * Restores a database fixture.
     *
     * @param string $version
     *   The fixture version to restore. Must exist in the tests/fixtures
     *   directory as $version.sql.gz.
     *
     * @return \Robo\Contract\TaskInterface|NULL
     *   The task to execute, or NULL if the fixture does not exist.
     */
    public function restore ($version)
    {
        $fixture = "tests/fixtures/$version";

        $fixtures = array_filter(["$fixture.php.gz", "$fixture.sql.gz"], 'file_exists');

        if ($fixtures)
        {
            $fixture = reset($fixtures);

            $tasks = $this->collectionBuilder()
                ->addTask(
                    $this->taskDrush('sql:drop')->option('yes')
                );

            if (preg_match('/.php.gz$/', $fixture))
            {
                return $tasks->addTask(
                    $this->taskExec(PHP_BINARY)
                        ->args([
                            'core/scripts/db-tools.php',
                            'import',
                            realpath($fixture)
                        ])
                        ->dir('docroot')
                );
            }
            else
            {
                $out = dirname($fixture) . DIRECTORY_SEPARATOR . "$version.sql";

                return $tasks->addTask(
                    $this->taskExec('gunzip')
                        ->arg($fixture)
                        ->option('keep')
                        ->option('force')
                )
                ->addTask(
                    $this->taskDrush('sql:query')->option('file', "../$out")
                )
                ->completion(
                    $this->taskFilesystemStack()->remove($out)
                );
            }
        }
        else
        {
            $this->say("$version fixture does not exist.");
        }
    }

    /**
     * Restores a database fixture and runs all available updates.
     *
     * @param string $from_version
     *   The fixture version to restore. Must exist in the tests/fixtures
     *   directory as $version.sql.gz.
     *
     * @return \Robo\Contract\TaskInterface|NULL
     *   The task to execute, or NULL if the fixture does not exist.
     */
    public function update ($from_version)
    {
        /** @var \Robo\Collection\CollectionBuilder $tasks */
        $tasks = $this->restore($from_version);

        if ($tasks)
        {
            return $tasks
                ->addTask(
                    $this->taskDrush('updatedb')->option('yes')
                )
                ->addTask(
                    $this->taskDrush('update:lightning')->option('no-interaction')
                );
        }
    }

    /**
     * Prepares settings.php for use with Acquia Cloud.
     *
     * @param string $subscription
     *   (optional) The Cloud subscription ID.
     * @param string $install_profile
     *   (optional) The machine name of the install profile to write to
     *   settings.php.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     */
    public function configureCloud ($subscription = 'lightningnightly', $install_profile = 'lightning')
    {
        $settings = 'docroot/sites/default/settings.php';
        $site_dir = dirname($settings);

        // settings.php and the site directory may have received restrictive
        // permissions during installation. We need to loosen those up in order
        // to overwrite settings.php.
        $this->_chmod([$settings, $site_dir], 0755);

        return $this->collectionBuilder()
            ->addTask(
                $this->taskFilesystemStack()
                    ->mkdir('config/default')
                    ->touch('config/default/.gitkeep')
                    ->copy("$site_dir/default.settings.php", $settings, TRUE)
            )
            ->addTask(
                $this->taskWriteToFile($settings)
                    ->append()
                    ->lines([
                        "if (file_exists('/var/www/site-php')) {",
                        "  require '/var/www/site-php/$subscription/$subscription-settings.inc';",
                        "}",
                        "\$settings['install_profile'] = '$install_profile';",
                    ])
            );
    }

    /**
     * Configures Behat.
     *
     * @param string $base_url
     *  (optional) The URL of the Drupal site.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     */
    public function configureBehat ($base_url = NULL)
    {
        $configuration = [];

        /** @var Finder $partials */
        $partials = Finder::create()
            ->in('docroot')
            ->files()
            ->name('behat.partial.yml');

        foreach ($partials as $partial)
        {
            $partial = str_replace(
                '%paths.base%',
                $partial->getPathInfo()->getRealPath(),
                $partial->getContents()
            );

            $configuration = NestedArray::mergeDeep($configuration, Yaml::parse($partial));
        }

        if ($configuration)
        {
            $configuration = str_replace(
                [
                    '%base_url%',
                    '%drupal_root%',
                ],
                [
                    $base_url ?: static::BASE_URL,
                    'docroot',
                ],
                Yaml::dump($configuration)
            );

            return $this->taskWriteToFile('.behat.yml')->text($configuration);
        }
    }

    /**
     * Configures PHPUnit.
     *
     * @param string $db_url
     *   The URL of the Drupal database.
     * @param string $base_url
     *   (optional) The URL of the Drupal site.
     *
     * @option $with-deprecations If set, enable PHPUnit deprecation testing.
     *
     * @return \Robo\Contract\TaskInterface
     *   The task to execute.
     *
     * @command configure:phpunit
     */
    public function configurePhpUnit ($db_url, $base_url = NULL, array $options = [
        'with-deprecations' => FALSE,
    ])
    {
        $conf = 'docroot/core/phpunit.xml';

        $search = [
            'bootstrap="tests/bootstrap.php"',
            '<env name="SIMPLETEST_DB" value=""/>',
            '<env name="SIMPLETEST_BASE_URL" value=""/>',
        ];
        $replace = [
            'bootstrap="../../vendor/weitzman/drupal-test-traits/src/bootstrap.php"',
            '<env name="SIMPLETEST_DB" value="' . stripslashes($db_url) . '"/>',
            '<env name="SIMPLETEST_BASE_URL" value="' . ($base_url ?: static::BASE_URL) . '"/>',
        ];

        if (empty($options['with-deprecations']))
        {
            $search[] = '<!-- <env name="SYMFONY_DEPRECATIONS_HELPER" value="disabled"/> -->';
            $search[] = '<env name="SYMFONY_DEPRECATIONS_HELPER" value="weak_vendors"/>';
            $replace[] = '<env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>';
            $replace[] = '<env name="SYMFONY_DEPRECATIONS_HELPER" value="weak"/>';
        }

        return $this->collectionBuilder()
            ->addTask(
                $this->taskFilesystemStack()
                    ->copy("$conf.dist", $conf, TRUE)
                    ->mkdir([
                        'docroot/modules',
                        'docroot/profiles',
                        'docroot/themes',
                    ])
            )
            ->addTask(
                $this->taskReplaceInFile($conf)->from($search)->to($replace)
            );
    }

    /**
     * Switches all Composer dependencies back to dev.
     *
     * @return \Robo\Task\Composer\RequireDependency
     *   The task to execute.
     */
    public function useDev ()
    {
        $composer = file_get_contents('composer.json');
        $composer = json_decode($composer, TRUE);

        /** @var \Robo\Task\Composer\RequireDependency $task */
        $task = $this->taskComposerRequire()->option('no-update');

        foreach ($composer['require'] as $package => $constraint)
        {
            if ($package === 'drupal/core')
            {
                $composer_constraint = new ComposerConstraint($constraint);
                $task->dependency($package, $composer_constraint->getCoreDev());
            }
            elseif (strpos($package, 'drupal/lightning_') === 0)
            {
                $composer_constraint = new ComposerConstraint($constraint);
                $task->dependency($package, $composer_constraint->getLightningDev());
            }
        }

        return $task;
    }

    /**
     * Switches all Composer dependencies to stable.
     *
     * @return \Robo\Task\Composer\RequireDependency
     *   The task to execute.
     */
    public function useStable ()
    {
        $composer = file_get_contents('composer.json');
        $composer = json_decode($composer, TRUE);

        /** @var \Robo\Task\Composer\RequireDependency $task */
        $task = $this->taskComposerRequire()->option('no-update');
        $client = new ReleaseHistoryClient(new Client());

        foreach ($composer['require'] as $package => $constraint)
        {
            $callback = NULL;

            if ($package === 'drupal/core')
            {
                $callback = function ($range) use ($client) {
                    $release = $client->getLatestStableRelease('drupal', $range);

                    return $release ? "~$release" : $range;
                };
            }
            elseif (strpos($package, 'drupal/lightning_') === 0)
            {
                $name = strstr($package, 'lightning_');
                $callback = function ($range) use ($client, $name) {
                    $release = $client->getLatestStableRelease($name, $range);

                    return $release ? "^$release" : $range;
                };
            }

            if ($callback) {
                $version = (new ComposerConstraint($constraint))->mapRanges($callback);
                $task->dependency($package, $version);
            }
        }

        return $task;
    }
}
