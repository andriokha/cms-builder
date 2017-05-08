<?php

namespace tes\CmsBuilder\Command;

use GuzzleHttp\Client;
use mglaman\Docker\Compose;
use mglaman\Docker\Docker;
use mglaman\PlatformDocker\Config as PlatformDockerConfig;
use mglaman\PlatformDocker\Platform;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Builds a Tes site.
 *
 * Depends on docker, platform.sh cli, curl, gunzip, drush, mysql.
 */
class BuildCommand extends Command
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('build')
            ->addArgument('site', InputArgument::OPTIONAL, 'Builds a specific site if there repository has multiple')
            ->addOption('rebuild-volumes', 'r', InputOption::VALUE_NONE, 'Forces a rebuild of the code volume')
            ->setDescription('Builds a working site from a clone of a CMS repo from TES github.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->getApplication()->chooseSite($input, $output);
        $stopwatch = new Stopwatch();
        $stopwatch->start('build');
        /** @var \Symfony\Component\Console\Command\Command[] $commands */
        $commands = [];
        // Build the code base.
        $commands[] = $this->getApplication()->find('platform:build');
        // Build the docker containers.
        if (empty(PlatformDockerConfig::get())) {
            $commands[] = $this->getApplication()->find('platform-docker:init');
        }
        else {
            $commands[] = $this->getApplication()->find('docker:rebuild');
        }
        $commands[] = $this->getApplication()->find('database:get');
        $commands[] = $this->getApplication()->find('database:load');
        $commands[] = $this->getApplication()->find('post-build');

        foreach ($commands as $command) {
            $input_array = [];
            if ($command->getDefinition()->hasArgument('site')) {
                $input_array['site'] = $input->getArgument('site');
            }
            if ($command->getDefinition()->hasOption('volumes') && $input->getOption('rebuild-volumes')) {
                $input_array['--volumes'] = 1;
            }
            $command_input = new ArrayInput($input_array, $command->getDefinition());
            $return = $command->run($command_input, $output);
            if ($return !== 0) {
                $output->writeln('<error>Command '. $command->getName() . ' failed</error>');
                return $return;
            }
        }
        $event = $stopwatch->stop('build');
        $output->writeln(sprintf(
            '<info>Build completed in %s seconds using %s MB</info>',
            number_format($event->getDuration()/1000, 2),
            number_format($event->getMemory() / 1048576, 2)
        ));

        // Ensure the site is actually ready to use.
        $check = TRUE;
        $output->writeln('<comment>Ensuring the site is ready by getting front page.</comment>');
        $times = 0;
        $stopwatch->start('check');
        $client = new Client();
        $url = $this->getUri();
        while ($check) {
            $res = $client->request('GET', $url);
            if ($res->getStatusCode() == "200") {
                $check = FALSE;
            }
            else {
                $times++;
                if ($times > 12) {
                    $output->writeln('<error>Waited for 2 minutes and site still not available</error>');
                    $check = FALSE;
                }
            }
        }
        $event = $stopwatch->stop('check');
        if ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
          $output->writeln(sprintf('<info>Site check completed in %s seconds</info>', number_format($event->getDuration()/1000, 2)));
        }
        return 0;
    }

  /**
   * Returns the context-aware URI to access the site.
   *
   * It will return a different result depending on whether it is run from
   * inside or outside the container.
   *
   * @return string
   */
    protected function getUri() {
      if (getenv('CMS_BUILDER_DOCKER')) {
        $container = Compose::getContainerName(Platform::projectName(), 'nginx');
        $format = '{{.NetworkSettings.Networks.bridge.IPAddress}}';
        $inspect = Docker::inspect(["--format='$format'", $container], true);
        if (!$inspect->isSuccessful()) {
          throw new \RuntimeException("Cannot find nginx IP on bridge network");
        }
        $matches = [];
        preg_match('!\d+\.\d+\.\d+\.\d+!', $inspect->getOutput(), $matches);
        $host = $matches[0];

        return "http://$host";

      }
      else {
        return Platform::getUri();
      }
    }


}
