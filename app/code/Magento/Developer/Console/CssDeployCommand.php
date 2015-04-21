<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Developer\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Asset\Source;
use Magento\Framework\App\State;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\View\Asset\SourceFileGeneratorPool;
use Magento\Framework\View\Asset\PreProcessor\ChainFactoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Class CssDeployCommand
 */
class CssDeployCommand extends Command
{
    /**
     * Locale option key
     */
    const LOCALE_OPTION = 'locale';

    /**
     * Area option key
     */
    const AREA_OPTION = 'area';

    /**
     * Theme option key
     */
    const THEME_OPTION = 'theme';

    /**
     * Type argument key
     */
    const TYPE_ARGUMENT = 'type';

    /**
     * Files argument key
     */
    const FILES_ARGUMENT = 'files';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var Repository
     */
    private $assetRepo;

    /**
     * @var ConfigLoader
     */
    private $configLoader;

    /**
     * @var State
     */
    private $state;

    /**
     * @var SourceFileGeneratorPool
     */
    private $sourceFileGeneratorPool;

    /**
     * @var Source
     */
    private $assetSource;

    /**
     * @var ChainFactoryInterface
     */
    private $chainFactory;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * Inject dependencies
     *
     * @param ObjectManagerInterface $objectManager
     * @param Repository $assetRepo
     * @param ConfigLoader $configLoader
     * @param State $state
     * @param Source $assetSource
     * @param SourceFileGeneratorPool $sourceFileGeneratorPoll
     * @param ChainFactoryInterface $chainFactory
     * @param Filesystem $filesystem
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Repository $assetRepo,
        ConfigLoader $configLoader,
        State $state,
        Source $assetSource,
        SourceFileGeneratorPool $sourceFileGeneratorPoll,
        ChainFactoryInterface $chainFactory,
        Filesystem $filesystem
    ) {
        $this->state = $state;
        $this->objectManager = $objectManager;
        $this->configLoader = $configLoader;
        $this->assetRepo = $assetRepo;
        $this->sourceFileGeneratorPool = $sourceFileGeneratorPoll;
        $this->assetSource = $assetSource;
        $this->chainFactory = $chainFactory;
        $this->filesystem = $filesystem;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('dev:css:deploy')
            ->setDescription('Collects, processes and publishes source files like LESS or SASS')
            ->setDefinition([
                new InputArgument(
                    self::TYPE_ARGUMENT,
                    InputArgument::REQUIRED,
                    'Type of dynamic stylesheet language: [less|sass]'
                ),
                new InputArgument(
                    self::FILES_ARGUMENT,
                    InputArgument::IS_ARRAY,
                    'Files to pre-process (accept more than one file type as comma-separate values)',
                    ['css/styles-m']
                ),
                new InputOption(
                    self::LOCALE_OPTION,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Locale',
                    'en_US'
                ),
                new InputOption(
                    self::AREA_OPTION,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Area, one of [frontend|adminhtml|doc]',
                    'frontend'
                ),
                new InputOption(
                    self::THEME_OPTION,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Theme in format Vendor/theme',
                    'Magento/blank'
                ),

            ]);

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $locale = $input->getOption(self::LOCALE_OPTION);

        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
            throw new \InvalidArgumentException(
                $locale . ' argument has invalid value format'
            );
        }

        $area = $input->getOption(self::AREA_OPTION);
        $theme = $input->getOption(self::THEME_OPTION);

        $type = $input->getArgument(self::TYPE_ARGUMENT);

        $this->state->setAreaCode($area);
        $this->objectManager->configure($this->configLoader->load($area));

        $sourceFileGenerator = $this->sourceFileGeneratorPool->create($type);

        foreach ($input->getArgument(self::FILES_ARGUMENT) as $file) {
            $file .= '.' . $type;

            $output->writeln("Gathering {$file} sources.");

            $asset = $this->assetRepo->createAsset(
                $file,
                [
                    'area' => $area,
                    'theme' => $theme,
                    'locale' => $locale,
                ]
            );

            $sourceFile = $this->assetSource->findSource($asset);
            $content = \file_get_contents($sourceFile);

            $chain = $this->chainFactory->create(
                [
                    'asset'           => $asset,
                    'origContent'     => $content,
                    'origContentType' => $asset->getContentType()
                ]
            );

            $processedCoreFile = $sourceFileGenerator->generateFileTree($chain);

            $targetDir = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);
            $rootDir = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
            $source = $rootDir->getRelativePath($processedCoreFile);
            $destination = $asset->getPath();
            $rootDir->copyFile($source, $destination, $targetDir);

            $output->writeln("Done");
        }
    }
}
