<?php

namespace AppZap\Migrator\Command;

use AppZap\Migrator\DirectoryIterator\SortableDirectoryIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

class MigrateCommand extends \Symfony\Component\Console\Command\Command
{

    /**
     * @var array
     */
    protected $extensionConfiguration;

    /**
     * @var string
     */
    protected $shellCommandTemplate = '%s --verbose --default-character-set=UTF8 -u"%s" -p"%s" -h "%s" -D "%s" -e "source %s" 2>&1';

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('Execute all pending migrations');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $this->extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['migrator'];

        /** @var ObjectManager $objectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);

        $registry = $objectManager->get(Registry::class);

        $pathFromConfig = Environment::getPublicPath() . '/' . $this->extensionConfiguration['migrationFolderPath'];
        $migrationFolderPath = realpath($pathFromConfig);
        if (!$migrationFolderPath) {
            $io->error('Migration folder not found. Please make sure "' . htmlspecialchars($pathFromConfig) . '" exists!');
            return 2;
        }

        $io->text('Migration path: ' . $migrationFolderPath);

        $highestExecutedVersion = 0;
        $errors = [];
        $executedFiles = 0;

        /** @var SortableDirectoryIterator $iterator */
        $iterator = $objectManager->get(SortableDirectoryIterator::class, ($migrationFolderPath));

        foreach ($iterator as $fileinfo) {
            /** @var SplFileInfo $fileinfo */
            $fileVersion = (int)$fileinfo->getBasename('.' . $fileinfo->getExtension());

            if ($fileinfo->getType() !== 'file') {
                continue;
            }

            $migrationStatus = $registry->get(
                'AppZap\\Migrator',
                'migrationStatus:' . $fileinfo->getBasename(),
                ['tstamp' => null, 'success' => false]
            );

            if ($migrationStatus['success']) {
                // already successfully executed
                continue;
            }

            $io->section($fileinfo->getBasename());

            $migrationErrors = [];
            $migrationOutput = '';
            switch ($fileinfo->getExtension()) {
                case 'sql':
                    $success = $this->migrateSqlFile($fileinfo, $migrationErrors, $migrationOutput);
                    break;
                case 'typo3cms':
                    $success = $this->migrateTypo3CmsFile($fileinfo, $migrationErrors, $migrationOutput);
                    break;
                case 'sh':
                    $success = $this->migrateShellFile($fileinfo, $migrationErrors, $migrationOutput);
                    break;
                default:
                    // ignore other files
                    $success = true;
            }

            $io->block($migrationOutput);

            $registry->set(
                'AppZap\\Migrator',
                'migrationStatus:' . $fileinfo->getBasename(),
                ['tstamp' => time(), 'success' => $success]
            );

            if ($success && count($migrationErrors) === 0) {
                $io->success('done ' . $fileinfo->getBasename());
                $executedFiles++;
                $highestExecutedVersion = max($highestExecutedVersion, $fileVersion);
            } else {
                $io->error($migrationErrors);
                $errors[$fileinfo->getFilename()] = $migrationErrors;
                break; // stop at first error
            }
        }

        if ($executedFiles === 0 && count($errors) === 0) {
            $io->success('No migrations executed');
        }

        return count($errors) > 0 ? 1 : 0;
    }

    /**
     * @param SplFileInfo $fileinfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateSqlFile(SplFileInfo $fileinfo, &$errors, &$output)
    {
        $filePath = $fileinfo->getPathname();

        $shellCommand = sprintf(
            $this->shellCommandTemplate,
            $this->extensionConfiguration['mysqlBinaryPath'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'],
            $filePath
        );

        $output = shell_exec($shellCommand);

        $outputMessages = explode("\n", $output);
        foreach ($outputMessages as $outputMessage) {
            if (trim($outputMessage) && strpos($outputMessage, 'ERROR') !== false) {
                $errors[] = $outputMessage;
            }
        }

        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileinfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateTypo3CmsFile($fileinfo, &$errors, &$output)
    {
        $migrationContent = file_get_contents($fileinfo->getPathname());
        foreach (explode(PHP_EOL, $migrationContent) as $task) {
            $task = trim($task);
            if (!empty($task)
                && strpos($task, '#') !== 0
                && strpos($task, '//') !== 0) {
                $outputLines = [];
                $status = null;
                $shellCommand =
                    ($this->extensionConfiguration['typo3cmsBinaryPath'] ?: './vendor/bin/typo3cms')
                    . ' '
                    . $task
                    . ' 2>&1';

                chdir(Environment::getPublicPath());
                exec($shellCommand, $outputLines, $status);

                $output .= '$ '
                    . $shellCommand
                    . PHP_EOL
                    . implode(PHP_EOL, $outputLines)
                    . PHP_EOL;

                if ($status !== 0) {
                    $errors[] = implode(PHP_EOL, $outputLines);
                    break;
                }
            }
        }
        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileinfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateShellFile($fileinfo, &$errors, &$output)
    {
        $command = $fileinfo->getPathname()
            . ' 2>&1';
        $outputLines = [];
        $status = null;

        chdir(Environment::getPublicPath());
        exec($command, $outputLines, $status);

        $output .= '$ '
            . $command
            . PHP_EOL
            . implode(PHP_EOL, $outputLines);

        if ($status !== 0) {
            $errors[] = implode(PHP_EOL, $outputLines);
        }
        return count($errors) === 0;
    }
}
