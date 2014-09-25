<?php
namespace Aaukt\Accelerando\Command;

use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Filesystem;

class BuildCommand extends BaseCommand
{
    protected $url;

    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds a composer repository out of a json file')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null)
            ))
            ->setHelp(<<<EOT
The <info>build</info> command takes an existing satis repository
and splits it up into single packages with proper provider includes
instead of one big package.json in the given output-dir.
EOT
            )
        ;
    }

    /**
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getArgument('file');
        $config = $this->readJson($configFile);
        $this->url = rtrim($config['homepage'], '/');

        if (!$outputDir = $input->getArgument('output-dir')) {
            $outputDir = isset($config['output-dir']) ? $config['output-dir'] : null;
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside '.$input->getArgument('file'));
        }

        $fileSystem = new Filesystem();

        $buildDir = $outputDir . '/_p/';
        $filename = $outputDir . '/packages.json';

        if (false === ($packages = $this->loadDumpedPackages($filename))) {
            $output->writeln('<info>Repository already optimized</info>');
            return 0;
        }
        $providers = array();
        $uid = 0;

        $output->writeln('<info>Writing single packages</info>');

        // prepopulate uid
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as &$versionConfig) {
                $versionConfig['uid'] = $uid++;
            }
            $packages[$packageName] = $packageConfig;
        }

        foreach ($packages as $packageName => $packageConfig) {
            $dumpPackages = $this->findReplacee($packages, $packageName);

            $packageFile = $this->dumpSinglePackageJson($buildDir, $packageName, array_merge($dumpPackages, array($packageName => $packageConfig)));
            $packageFileHash = hash_file('sha256', $packageFile);
            $providers[$packageName] = array('sha256' => $packageFileHash);
        }

        $providersFile = $this->dumpProvidersJson($buildDir . 'provider-active', $providers, $output);
        $providersFileHash = hash_file('sha256', $providersFile);

        // backup original package.json
        $fileSystem->copy($outputDir . '/packages.json', $outputDir . '/packages-all.json', true);

        $this->dumpPackagesJson($filename, $providersFileHash, $output);

        // move build folder to target
        $fileSystem->remove($outputDir . '/p/');
        $fileSystem->rename($buildDir, $outputDir . '/p/', true);
    }

    protected function findReplacee($packages, $replaced)
    {
        $replacees = array();
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as $versionConfig) {
                if (!empty($versionConfig['replace'])) {
                    if (in_array($replaced, array_keys($versionConfig['replace']))) {
                        $replacees[$packageName] = $packageConfig;
                        break;
                    }
                }
            }
        }

        return $replacees;
    }

    protected function readJson($filename)
    {
        try {
            $content = json_decode(file_get_contents($filename), true);
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not read ' . $filename . PHP_EOL . PHP_EOL . $e->getMessage());
        }

        return $content;
    }

    protected function writeJson($filename, array $content)
    {
        try {
            file_put_contents($filename, json_encode($content, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not write ' . $filename . PHP_EOL . PHP_EOL . $e->getMessage());
        }
    }

    protected function writeAndHash($filename, array $content)
    {
        $this->writeJson($filename, $content);
        $hash = hash_file('sha256', $filename);
        $filenameWithHash = $filename . '$' . $hash . '.json';
        rename($filename, $filenameWithHash);
        return $filenameWithHash;
    }

    protected function loadDumpedPackages($filename)
    {
        $packages = array();
        $packagesJson = $this->readJson($filename);
        $dirName  = dirname($filename);

        if (!empty($packagesJson['provider-includes'])) {
            return false;
        }

        $jsonIncludes = isset($packagesJson['includes']) && is_array($packagesJson['includes'])
            ? $packagesJson['includes']
            : array();

        foreach ($jsonIncludes as $includeFile => $includeConfig) {
            $jsonInclude = $this->readJson($dirName . '/' . $includeFile);
            $jsonPackages = isset($jsonInclude['packages']) && is_array($jsonInclude['packages'])
                ? $jsonInclude['packages']
                : array();

                $packages = $packages + $jsonPackages;
        }

        return $packages;
    }

    protected function dumpProvidersJson($filename, $providers, OutputInterface $output)
    {
        $content = array('providers' => $providers);

        $output->writeln('<info>Writing providers.json</info>');
        return $this->writeAndHash($filename, $content);
    }

    protected function dumpSinglePackageJson($filename, $packageName, $packages)
    {
        $filePrefix = $filename . $packageName;
        $dir = dirname($filePrefix);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $content = array(
            'packages' => $packages
        );

        return $this->writeAndHash($filePrefix, $content);
    }

    protected function dumpPackagesJson($filename, $providersFileHash, OutputInterface $output)
    {
        $repo = array(
            'packages'          => array(),
            'providers-url'     => $this->getPath($this->url . '/p/%package%$%hash%.json'),
            'provider-includes' => array(
                'p/provider-active$' . $providersFileHash . '.json' => array(
                    'sha256' => $providersFileHash
                )
            )
        );

        $output->writeln('<info>Writing packages.json</info>');
        $this->writeJson($filename, $repo);
    }

    protected function getPath($url)
    {
        return preg_replace('{(?:https?://[^/]+)(.*)}i', '$1', $url);
    }
}
