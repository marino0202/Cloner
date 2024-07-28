<?php

namespace App\Command;

use App\Service\ClonerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'clone',
    description: 'Crawl & save copy of a website for reuse/editing',
)]
class ClonerCommand extends Command
{
    private SymfonyStyle $io;

    private Filesystem $fs;

    private string|null $arg;

    public function __construct(private ValidatorInterface $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'Base url for site to be cloned i.e http(s)://host.com:{port}')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Crawl & save all instances of base_url in further pages')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output):void
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->arg = trim($input->getArgument('url'), "/ \n\r\t\v\x00");
        $this->fs = new Filesystem();
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $emailConstraint = [new Assert\NotBlank(), new Assert\Url(['message' => 'The url "{{ value }}" is not a valid url.', 'requireTld' => true])];
        $errors = $this->validator->validate($this->arg, $emailConstraint);
        if ($errors->count()) {
            $this->arg = $this->io->ask($errors[0]->getMessage()."\n Enter a valid base url to proceed i.e http(s)://host.com:{port}", null, Validation::createCallable(new Assert\NotBlank(), new Assert\Url(['message' => 'The url "{{ value }}" is not a valid url.', 'requireTld' => true])));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $crawler = new ClonerService($this->io, $this->arg);

        return Command::SUCCESS;
    }
}
