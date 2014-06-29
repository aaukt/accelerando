<?php

namespace Aaukt\Accelerando\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Aaukt\Accelerando\Command;
use Composer\Util\ErrorHandler;
use Composer\IO\ConsoleIO;

class Application extends BaseApplication
{
    protected $io;

    public function __construct()
    {
        parent::__construct('Accelerando', '1.0.0-dev');
        ErrorHandler::register();
    }

    /**
     * {@inheritDoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->registerCommands();
        $this->io = new ConsoleIO($input, $output, $this->getHelperSet());

        return parent::doRun($input, $output);
    }

    /**
     * Initializes all the Build Command
     */
    protected function registerCommands()
    {
        $this->add(new Command\BuildCommand());
    }
}
