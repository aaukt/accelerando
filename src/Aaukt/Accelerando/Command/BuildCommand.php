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
        
        if ($fileSystem->exists($outputDir . '/packages-all.json')) {
            $fileSystem->copy($outputDir . '/packages-all.json', $outputDir . '/packages.json', true);
        }

        $buildDir = $outputDir . '/_p/';
        $filename = $outputDir . '/packages.json';
        
        $packages = $this->loadDumpedPackages($filename);
        $providers = array();
        $uid = 0;
        
        $output->writeln('<info>Writing single packages</info>');
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as $version => &$versionConfig) {
                $packageConfig[$version]['uid'] = $uid++;
            }
            $packageFile = $this->dumpSinglePackageJson($buildDir, $packageName, $packageConfig, $output);
            $packageFileHash = hash_file('sha256', $packageFile);       
            $providers[$packageName] = array('sha256' => $packageFileHash);
        }
        
        $providersFile = $this->dumpProvidersJson($buildDir . 'provider-active', $providers, $output);
        $providersFileHash = hash_file('sha256', $providersFile);
        
        $fileSystem->copy($outputDir . '/packages.json', $outputDir . '/packages-all.json', true);
        $this->dumpPackagesJson($filename, $providersFileHash, $output);
        $fileSystem->remove($outputDir . '/p/');
        $fileSystem->rename($buildDir, $outputDir . '/p/', true);
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
    
    protected function dumpSinglePackageJson($filename, $packageName, $packageConfig)
    {
        $filePrefix = $filename . $packageName;
        $dir = dirname($filePrefix);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $content = array(
            'packages' => array($packageName => $packageConfig)
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
